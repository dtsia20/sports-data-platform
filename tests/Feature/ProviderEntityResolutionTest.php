<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Application\Providers\Services\ApproveProviderEntityResolution;
use App\Application\Providers\Services\RecordUnresolvedEntityService;
use App\Domain\Competitions\Models\Competition;
use App\Domain\Providers\Models\ProviderCompetitionReference;
use App\Domain\Providers\Models\ProviderTeamReference;
use App\Domain\Providers\Models\SportsDataProvider;
use App\Domain\Providers\Models\UnresolvedProviderEntity;
use App\Domain\Teams\Models\Team;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;
use Tests\TestCase;

final class ProviderEntityResolutionTest extends TestCase
{
    use RefreshDatabase;

    private function provider(): SportsDataProvider
    {
        return SportsDataProvider::query()->firstOrCreate(['slug' => 'api-football'], ['name' => 'API-Football', 'enabled' => false]);
    }

    private function pending(string $type = 'team', string $externalId = '126'): UnresolvedProviderEntity
    {
        return (new RecordUnresolvedEntityService)->record($this->provider(), $type, $externalId, 'Sao Paulo', ['competition_country' => 'Brazil']);
    }

    public function test_reviewed_team_mappings_allow_a_previously_skipped_match_to_be_imported(): void
    {
        config(['services.api_football.key' => 'review-test', 'cache.default' => 'array']);
        Http::preventStrayRequests();
        $sample = json_decode(file_get_contents(base_path('tests/Fixtures/api-football/fixtures-2026-10-11.json')), true, flags: JSON_THROW_ON_ERROR);
        Http::fake(['*' => Http::response(['errors' => [], 'response' => [$sample['response'][0]]])]);
        $league = Competition::query()->create(['name' => 'Serie A', 'slug' => 'brazil-serie-a']);
        ProviderCompetitionReference::query()->create(['provider_id' => $this->provider()->id, 'external_id' => '71', 'competition_id' => $league->id]);
        foreach ([['126', 'Sao Paulo', 'sao-paulo'], ['136', 'Vitoria', 'vitoria']] as [$externalId, $name, $slug]) {
            $team = Team::query()->create(compact('name', 'slug'));
            $this->artisan('sports:sync-api-football', ['--date' => '2026-10-11'])->assertSuccessful();
            $this->assertDatabaseCount('matches', 0);
            $pending = UnresolvedProviderEntity::query()->where('external_id', $externalId)->sole();
            $this->assertSame('Brazil', $pending->context['competition_country']);
            $this->assertSame('71', $pending->context['competition_external_id']);
            $this->artisan('sports:resolve-entity', ['unresolved' => $pending->id, 'canonical' => $team->id,
                '--reviewer' => 'lesson-reviewer', '--reason' => 'Verified Brazilian club and competition context.'])->assertSuccessful();
            $this->assertSame('resolved', $pending->refresh()->status);
        }
        $this->artisan('sports:sync-api-football', ['--date' => '2026-10-11'])->assertSuccessful();
        $this->assertDatabaseCount('matches', 1);
        $this->assertDatabaseCount('provider_entity_resolutions', 2);
        $this->getJson('/api/v1/matches?date=2026-10-11')->assertOk()->assertJsonPath('data.0.homeTeam.slug', 'sao-paulo');
    }

    public function test_competition_approval_and_repeat_approval_preserve_original_audit(): void
    {
        $entity = $this->pending('competition', '71');
        $competition = Competition::query()->create(['name' => 'Serie A', 'slug' => 'serie-a']);
        $service = new ApproveProviderEntityResolution;
        $first = $service->approve($entity->id, $competition->id, 'Alice', 'Verified country and season.');
        $again = $service->approve($entity->id, $competition->id, 'Bob', 'Repeat request.');
        $this->assertSame($first->id, $again->id);
        $this->assertSame('Alice', $again->reviewer);
        $this->assertSame('Verified country and season.', $again->reason);
        $this->assertSame($entity->context, $again->context);
        $this->assertNotNull($entity->refresh()->resolved_at);
        $this->assertDatabaseCount('provider_competition_references', 1);
        $this->assertDatabaseCount('provider_entity_resolutions', 1);
    }

    public function test_conflicting_mapping_is_rejected_without_partial_review(): void
    {
        $entity = $this->pending();
        $first = Team::query()->create(['name' => 'First', 'slug' => 'first']);
        $second = Team::query()->create(['name' => 'Second', 'slug' => 'second']);
        ProviderTeamReference::query()->create(['provider_id' => $entity->provider_id, 'external_id' => $entity->external_id, 'team_id' => $first->id]);
        $this->artisan('sports:resolve-entity', ['unresolved' => $entity->id, 'canonical' => $second->id,
            '--reviewer' => 'Alice', '--reason' => 'A conflicting request.'])->assertFailed();
        $this->assertSame('pending', $entity->refresh()->status);
        $this->assertNull($entity->resolved_at);
        $this->assertDatabaseCount('provider_entity_resolutions', 0);
        $this->assertDatabaseHas('provider_team_references', ['team_id' => $first->id]);
    }

    public function test_missing_target_and_missing_review_evidence_make_no_changes(): void
    {
        $entity = $this->pending();
        $this->artisan('sports:resolve-entity', ['unresolved' => $entity->id, 'canonical' => 999,
            '--reviewer' => 'Alice', '--reason' => 'Verified evidence.'])->assertFailed();
        $team = Team::query()->create(['name' => 'Sao Paulo', 'slug' => 'sao-paulo']);
        $this->artisan('sports:resolve-entity', ['unresolved' => $entity->id, 'canonical' => $team->id])->assertFailed();
        $this->assertDatabaseCount('provider_team_references', 0);
        $this->assertDatabaseCount('provider_entity_resolutions', 0);
        $this->assertSame('pending', $entity->refresh()->status);
    }

    public function test_a_second_external_id_cannot_take_an_existing_provider_target(): void
    {
        $entity = $this->pending();
        $team = Team::query()->create(['name' => 'Sao Paulo', 'slug' => 'sao-paulo']);
        ProviderTeamReference::query()->create(['provider_id' => $entity->provider_id, 'external_id' => 'other-id', 'team_id' => $team->id]);
        $this->artisan('sports:resolve-entity', ['unresolved' => $entity->id, 'canonical' => $team->id,
            '--reviewer' => 'Alice', '--reason' => 'Conflicting identity.'])->assertFailed();
        $this->assertSame('pending', $entity->refresh()->status);
        $this->assertDatabaseCount('provider_team_references', 1);
        $this->assertDatabaseCount('provider_entity_resolutions', 0);
    }

    public function test_approved_entity_cannot_be_reassigned(): void
    {
        $entity = $this->pending();
        $first = Team::query()->create(['name' => 'First', 'slug' => 'first']);
        $second = Team::query()->create(['name' => 'Second', 'slug' => 'second']);
        $service = new ApproveProviderEntityResolution;
        $service->approve($entity->id, $first->id, 'Alice', 'Verified evidence.');
        $this->expectException(InvalidArgumentException::class);
        $service->approve($entity->id, $second->id, 'Bob', 'Reassignment.');
    }

    public function test_pending_list_is_filterable_and_bounded(): void
    {
        $this->pending();
        $this->artisan('sports:unresolved', ['--provider' => 'api-football'])->expectsOutputToContain('Sao Paulo')->assertSuccessful();
        $this->artisan('sports:unresolved', ['--limit' => 101])->assertFailed();
    }
}

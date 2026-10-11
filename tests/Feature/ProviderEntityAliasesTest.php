<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Application\Providers\Services\CurateProviderEntityAliases;
use App\Application\Providers\Services\RecordUnresolvedEntityService;
use App\Application\Providers\Services\SuggestProviderEntityCandidates;
use App\Domain\Competitions\Models\Competition;
use App\Domain\Providers\Models\SportsDataProvider;
use App\Domain\Teams\Models\Team;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Tests\TestCase;

final class ProviderEntityAliasesTest extends TestCase
{
    use RefreshDatabase;

    private function provider(string $slug = 'api-football'): SportsDataProvider
    {
        return SportsDataProvider::query()->firstOrCreate(['slug' => $slug], ['name' => $slug, 'enabled' => false]);
    }

    public function test_a_verified_alternative_name_adds_a_candidate_without_creating_a_mapping(): void
    {
        $team = Team::query()->create(['name' => 'Tottenham Hotspur', 'slug' => 'tottenham']);
        $pending = (new RecordUnresolvedEntityService)->record($this->provider(), 'team', '47', 'Spurs');
        $service = new SuggestProviderEntityCandidates;
        $this->assertSame([], $service->suggest($pending));
        $this->artisan('sports:add-entity-alias', ['provider' => 'api-football', 'type' => 'team', 'canonical' => $team->id,
            'alias' => 'Spurs', '--reviewer' => 'Alice', '--reason' => 'Verified alternative club name.'])->assertSuccessful();
        $candidate = $service->suggest($pending)[0];
        $this->assertSame($team->id, $candidate['id']);
        $this->assertSame(50, $candidate['score']);
        $this->assertSame('weak', $candidate['confidence']);
        $this->assertStringContainsString('Verified alternative club name.', implode(' ', $candidate['evidence']));
        $this->assertDatabaseCount('provider_team_references', 0);
        $this->assertSame('pending', $pending->refresh()->status);
        $this->assertSame('Tottenham Hotspur', $team->refresh()->name);
    }

    public function test_aliases_are_provider_scoped_and_entity_type_scoped(): void
    {
        $team = Team::query()->create(['name' => 'Tottenham', 'slug' => 'tottenham']);
        $competition = Competition::query()->create(['name' => 'League', 'slug' => 'league']);
        $service = new CurateProviderEntityAliases;
        $service->add($this->provider('another-provider'), 'team', $team->id, 'Spurs', 'Alice', 'Verified name.');
        $service->add($this->provider(), 'competition', $competition->id, 'Spurs', 'Alice', 'Different entity type.');
        $pending = (new RecordUnresolvedEntityService)->record($this->provider(), 'team', '47', 'Spurs');
        $this->assertSame([], (new SuggestProviderEntityCandidates)->suggest($pending));
        $competitionPending = (new RecordUnresolvedEntityService)->record($this->provider(), 'competition', '55', 'Spurs');
        $this->assertSame($competition->id, (new SuggestProviderEntityCandidates)->suggest($competitionPending)[0]['id']);
    }

    public function test_normalized_duplicate_aliases_preserve_original_evidence(): void
    {
        $team = Team::query()->create(['name' => 'Club', 'slug' => 'club']);
        $service = new CurateProviderEntityAliases;
        $first = $service->add($this->provider(), 'team', $team->id, 'São-Paulo', 'Alice', 'Original verified evidence.');
        $again = $service->add($this->provider(), 'team', $team->id, 'SAO PAULO', 'Bob', 'Repeat.');
        $this->assertSame($first->id, $again->id);
        $this->assertSame('Alice', $again->reviewer);
        $this->assertSame('Original verified evidence.', $again->reason);
        $this->assertDatabaseCount('provider_entity_aliases', 1);
    }

    public function test_a_shared_alias_stays_ambiguous(): void
    {
        $service = new CurateProviderEntityAliases;
        foreach (['first', 'second'] as $slug) {
            $team = Team::query()->create(['name' => $slug, 'slug' => $slug]);
            $service->add($this->provider(), 'team', $team->id, 'United', 'Alice', 'Verified shared alternative.');
        }
        $pending = (new RecordUnresolvedEntityService)->record($this->provider(), 'team', 'unknown', 'United');
        $candidates = (new SuggestProviderEntityCandidates)->suggest($pending);
        $this->assertCount(2, $candidates);
        $this->assertSame(['ambiguous', 'ambiguous'], array_column($candidates, 'confidence'));
    }

    public function test_revocation_removes_suggestions_and_preserves_original_and_revocation_evidence(): void
    {
        $team = Team::query()->create(['name' => 'Tottenham', 'slug' => 'tottenham']);
        $service = new CurateProviderEntityAliases;
        $alias = $service->add($this->provider(), 'team', $team->id, 'Spurs', 'Alice', 'Verified name.');
        $pending = (new RecordUnresolvedEntityService)->record($this->provider(), 'team', '47', 'Spurs');
        $this->artisan('sports:revoke-entity-alias', ['alias' => $alias->id, '--reviewer' => 'Bob', '--reason' => 'Evidence withdrawn.'])->assertSuccessful();
        $this->assertSame([], (new SuggestProviderEntityCandidates)->suggest($pending));
        $again = $service->revoke($alias->id, 'Carol', 'Repeat revoke.');
        $this->assertSame('Bob', $again->revoked_by);
        $this->assertSame('Evidence withdrawn.', $again->revocation_reason);
        $this->assertSame('Alice', $again->reviewer);
        $this->assertNotNull($again->revoked_at);
        $this->expectException(InvalidArgumentException::class);
        $service->add($this->provider(), 'team', $team->id, 'Spurs', 'Carol', 'Attempt to reactivate.');
    }

    public function test_missing_targets_and_missing_evidence_are_rejected(): void
    {
        $this->provider();
        $this->artisan('sports:add-entity-alias', ['provider' => 'api-football', 'type' => 'team', 'canonical' => 999,
            'alias' => 'Spurs', '--reviewer' => 'Alice', '--reason' => 'Verified name.'])->assertFailed();
        $team = Team::query()->create(['name' => 'Club', 'slug' => 'club']);
        $this->provider();
        $this->artisan('sports:add-entity-alias', ['provider' => 'api-football', 'type' => 'team', 'canonical' => $team->id, 'alias' => 'Club'])->assertFailed();
        $this->assertDatabaseCount('provider_entity_aliases', 0);
    }

    public function test_alias_and_canonical_name_do_not_stack_points(): void
    {
        $team = Team::query()->create(['name' => 'United', 'slug' => 'united']);
        (new CurateProviderEntityAliases)->add($this->provider(), 'team', $team->id, 'United', 'Alice', 'Verified alternative spelling.');
        $pending = (new RecordUnresolvedEntityService)->record($this->provider(), 'team', 'unknown', 'United');
        $candidate = (new SuggestProviderEntityCandidates)->suggest($pending)[0];
        $this->assertSame(50, $candidate['score']);
        $this->assertSame('weak', $candidate['confidence']);
    }
}

<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Application\Providers\Services\RecordUnresolvedEntityService;
use App\Application\Providers\Services\SuggestProviderEntityCandidates;
use App\Domain\Competitions\Models\Competition;
use App\Domain\Matches\Models\SportsMatch;
use App\Domain\Providers\Models\ProviderCompetitionReference;
use App\Domain\Providers\Models\ProviderTeamReference;
use App\Domain\Providers\Models\SportsDataProvider;
use App\Domain\Providers\Models\UnresolvedProviderEntity;
use App\Domain\Teams\Models\Team;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class ProviderEntityCandidatesTest extends TestCase
{
    use RefreshDatabase;

    private function pending(string $name = 'United', string $type = 'team', array $context = []): UnresolvedProviderEntity
    {
        $provider = SportsDataProvider::query()->firstOrCreate(['slug' => 'api-football'], ['name' => 'API-Football', 'enabled' => false]);

        return (new RecordUnresolvedEntityService)->record($provider, $type, 'unknown', $name, $context);
    }

    public function test_competition_history_ranks_a_team_above_an_identical_name(): void
    {
        $entity = $this->pending(context: ['competition_external_id' => '55']);
        $unrelated = Team::query()->create(['name' => 'United', 'slug' => 'unrelated-united']);
        $supported = Team::query()->create(['name' => 'United', 'slug' => 'supported-united']);
        $opponent = Team::query()->create(['name' => 'Opponent', 'slug' => 'opponent']);
        $competition = Competition::query()->create(['name' => 'League', 'slug' => 'league']);
        ProviderCompetitionReference::query()->create(['provider_id' => $entity->provider_id, 'external_id' => '55', 'competition_id' => $competition->id]);
        SportsMatch::query()->create(['competition_id' => $competition->id, 'home_team_id' => $opponent->id,
            'away_team_id' => $supported->id, 'starts_at' => '2026-10-11T12:00:00Z', 'status' => 'scheduled']);
        $candidates = (new SuggestProviderEntityCandidates)->suggest($entity);
        $this->assertSame([$supported->id, $unrelated->id], array_column($candidates, 'id'));
        $this->assertSame(90, $candidates[0]['score']);
        $this->assertSame('strong', $candidates[0]['confidence']);
        $this->assertStringContainsString('current season membership unverified', implode(' ', $candidates[0]['evidence']));
        $this->assertSame('weak', $candidates[1]['confidence']);
        $this->assertDatabaseCount('provider_team_references', 0);
        $this->assertSame('pending', $entity->refresh()->status);
    }

    public function test_ties_are_ambiguous_even_when_the_output_limit_hides_another_candidate(): void
    {
        $entity = $this->pending('São Paulo');
        $first = Team::query()->create(['name' => 'Sao-Paulo', 'slug' => 'first']);
        Team::query()->create(['name' => 'SAO PAULO', 'slug' => 'second']);
        $candidates = (new SuggestProviderEntityCandidates)->suggest($entity, 1);
        $this->assertCount(1, $candidates);
        $this->assertSame($first->id, $candidates[0]['id']);
        $this->assertSame('ambiguous', $candidates[0]['confidence']);
        $this->assertSame(50, $candidates[0]['score']);
    }

    public function test_existing_same_provider_mappings_are_excluded_but_other_providers_are_allowed(): void
    {
        $entity = $this->pending();
        $mapped = Team::query()->create(['name' => 'United', 'slug' => 'mapped']);
        $other = Team::query()->create(['name' => 'United', 'slug' => 'other']);
        $provider = SportsDataProvider::query()->create(['slug' => 'another-provider', 'name' => 'Another', 'enabled' => false]);
        ProviderTeamReference::query()->create(['provider_id' => $entity->provider_id, 'external_id' => 'already-known', 'team_id' => $mapped->id]);
        ProviderTeamReference::query()->create(['provider_id' => $provider->id, 'external_id' => 'known-elsewhere', 'team_id' => $other->id]);
        $candidates = (new SuggestProviderEntityCandidates)->suggest($entity);
        $this->assertSame([$other->id], array_column($candidates, 'id'));
        $this->assertSame('weak', $candidates[0]['confidence']);
    }

    public function test_unmapped_competition_context_adds_no_points(): void
    {
        $entity = $this->pending(context: ['competition_external_id' => 'missing', 'competition_country' => 'Brazil']);
        Team::query()->create(['name' => 'United', 'slug' => 'united']);
        $candidate = (new SuggestProviderEntityCandidates)->suggest($entity)[0];
        $this->assertSame(50, $candidate['score']);
        $this->assertSame('weak', $candidate['confidence']);
    }

    public function test_partial_names_have_explained_lower_scores_and_unrelated_names_are_omitted(): void
    {
        $entity = $this->pending('Manchester United');
        $partial = Team::query()->create(['name' => 'United', 'slug' => 'united']);
        Team::query()->create(['name' => 'Chelsea', 'slug' => 'chelsea']);
        $candidate = (new SuggestProviderEntityCandidates)->suggest($entity);
        $this->assertCount(1, $candidate);
        $this->assertSame($partial->id, $candidate[0]['id']);
        $this->assertSame(25, $candidate[0]['score']);
        $this->assertStringContainsString('Shared name tokens', $candidate[0]['evidence'][0]);
    }

    public function test_competition_country_is_not_guessed_from_its_name(): void
    {
        $entity = $this->pending('Premier League', 'competition', ['country' => 'England']);
        Competition::query()->create(['name' => 'Premier League', 'slug' => 'premier-league']);
        $candidate = (new SuggestProviderEntityCandidates)->suggest($entity)[0];
        $this->assertSame(50, $candidate['score']);
        $this->assertSame('weak', $candidate['confidence']);
    }

    public function test_command_shows_suggestions_without_writing_and_validates_inputs(): void
    {
        $entity = $this->pending();
        Team::query()->create(['name' => 'United', 'slug' => 'united']);
        $this->artisan('sports:entity-candidates', ['unresolved' => $entity->id])->expectsOutputToContain('United')->assertSuccessful();
        $this->assertDatabaseCount('provider_team_references', 0);
        $this->assertDatabaseCount('provider_entity_resolutions', 0);
        $this->artisan('sports:entity-candidates', ['unresolved' => $entity->id, '--limit' => 21])->assertFailed();
        $this->artisan('sports:entity-candidates', ['unresolved' => 999])->assertFailed();
        $entity->update(['status' => 'resolved']);
        $this->artisan('sports:entity-candidates', ['unresolved' => $entity->id])->assertFailed();
    }
}

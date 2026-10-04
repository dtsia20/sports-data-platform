<?php

declare(strict_types=1);

namespace Tests\Feature\Application\Matches;

use App\Application\Matches\Services\SyncMatchesService;
use App\Domain\Competitions\DTOs\CompetitionData;
use App\Domain\Competitions\Models\Competition;
use App\Domain\Matches\Contracts\SportsDataProviderInterface;
use App\Domain\Matches\DTOs\MatchData;
use App\Domain\Matches\Models\SportsMatch;
use App\Domain\Providers\Models\ProviderCompetitionReference;
use App\Domain\Providers\Models\ProviderMatchReference;
use App\Domain\Providers\Models\ProviderTeamReference;
use App\Domain\Providers\Models\SportsDataProvider;
use App\Domain\Teams\DTOs\TeamData;
use App\Domain\Teams\Models\Team;
use App\Infrastructure\SportsData\FakeProviderA\FakeProviderAAdapter;
use DateTimeImmutable;
use RuntimeException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class SyncMatchesServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_creates_a_match_from_mapped_provider_data(): void
    {
        $provider = SportsDataProvider::query()->create([
            'name' => 'Fake Provider A',
            'slug' => 'fake-provider-a',
            'enabled' => true,
        ]);

        $competition = Competition::query()->create([
            'name' => 'Premier League',
            'slug' => 'premier-league',
        ]);

        $arsenal = Team::query()->create([
            'name' => 'Arsenal',
            'slug' => 'arsenal',
        ]);

        $chelsea = Team::query()->create([
            'name' => 'Chelsea',
            'slug' => 'chelsea',
        ]);

        ProviderCompetitionReference::query()->create([
            'provider_id' => $provider->id,
            'competition_id' => $competition->id,
            'external_id' => '55',
        ]);

        ProviderTeamReference::query()->create([
            'provider_id' => $provider->id,
            'team_id' => $arsenal->id,
            'external_id' => '100',
        ]);

        ProviderTeamReference::query()->create([
            'provider_id' => $provider->id,
            'team_id' => $chelsea->id,
            'external_id' => '101',
        ]);

        $service = new SyncMatchesService(
            new FakeProviderAAdapter
        );

        $service->sync(
            $provider,
            new DateTimeImmutable('2026-10-04')
        );

        $this->assertDatabaseCount('matches', 1);
        $this->assertDatabaseCount('provider_match_references', 1);

        $match = SportsMatch::query()->firstOrFail();

        self::assertSame(
            $competition->id,
            $match->competition_id
        );

        self::assertSame(
            $arsenal->id,
            $match->home_team_id
        );

        self::assertSame(
            $chelsea->id,
            $match->away_team_id
        );

        self::assertSame(
            'finished',
            $match->status
        );

        self::assertSame(
            2,
            $match->home_score
        );

        self::assertSame(
            1,
            $match->away_score
        );

        $reference = ProviderMatchReference::query()
            ->firstOrFail();

        self::assertSame(
            '14567',
            $reference->external_id
        );

        self::assertSame(
            $match->id,
            $reference->match_id
        );
    }

    public function test_it_does_not_duplicate_a_match_when_sync_runs_twice(): void
    {
        $provider = SportsDataProvider::query()->create([
            'name' => 'Fake Provider A',
            'slug' => 'fake-provider-a',
            'enabled' => true,
        ]);

        $competition = Competition::query()->create([
            'name' => 'Premier League',
            'slug' => 'premier-league',
        ]);

        $arsenal = Team::query()->create([
            'name' => 'Arsenal',
            'slug' => 'arsenal',
        ]);

        $chelsea = Team::query()->create([
            'name' => 'Chelsea',
            'slug' => 'chelsea',
        ]);

        ProviderCompetitionReference::query()->create([
            'provider_id' => $provider->id,
            'competition_id' => $competition->id,
            'external_id' => '55',
        ]);

        ProviderTeamReference::query()->create([
            'provider_id' => $provider->id,
            'team_id' => $arsenal->id,
            'external_id' => '100',
        ]);

        ProviderTeamReference::query()->create([
            'provider_id' => $provider->id,
            'team_id' => $chelsea->id,
            'external_id' => '101',
        ]);

        $service = new SyncMatchesService(
            new FakeProviderAAdapter
        );

        $date = new DateTimeImmutable('2026-10-04');

        $service->sync($provider, $date);
        $service->sync($provider, $date);

        $this->assertDatabaseCount('matches', 1);
        $this->assertDatabaseCount('provider_match_references', 1);
    }

    public function test_it_updates_an_existing_match_when_provider_data_changes(): void
    {
        $provider = SportsDataProvider::query()->create([
            'name' => 'Fake Provider A',
            'slug' => 'fake-provider-a',
            'enabled' => true,
        ]);

        $competition = Competition::query()->create([
            'name' => 'Premier League',
            'slug' => 'premier-league',
        ]);

        $arsenal = Team::query()->create([
            'name' => 'Arsenal',
            'slug' => 'arsenal',
        ]);

        $chelsea = Team::query()->create([
            'name' => 'Chelsea',
            'slug' => 'chelsea',
        ]);

        ProviderCompetitionReference::query()->create([
            'provider_id' => $provider->id,
            'competition_id' => $competition->id,
            'external_id' => '55',
        ]);

        ProviderTeamReference::query()->create([
            'provider_id' => $provider->id,
            'team_id' => $arsenal->id,
            'external_id' => '100',
        ]);

        ProviderTeamReference::query()->create([
            'provider_id' => $provider->id,
            'team_id' => $chelsea->id,
            'external_id' => '101',
        ]);

        $firstResponse = new ConfigurableSportsDataProvider([
            new MatchData(
                externalId: '14567',
                competition: new CompetitionData(
                    externalId: '55',
                    name: 'Premier League',
                ),
                homeTeam: new TeamData(
                    externalId: '100',
                    name: 'Arsenal',
                ),
                awayTeam: new TeamData(
                    externalId: '101',
                    name: 'Chelsea',
                ),
                startsAt: new DateTimeImmutable('2026-10-04T18:30:00+00:00'),
                status: 'live',
                homeScore: 1,
                awayScore: 1,
            ),
        ]);

        $service = new SyncMatchesService($firstResponse);

        $service->sync(
            $provider,
            new DateTimeImmutable('2026-10-04')
        );

        $updatedResponse = new ConfigurableSportsDataProvider([
            new MatchData(
                externalId: '14567',
                competition: new CompetitionData(
                    externalId: '55',
                    name: 'Premier League',
                ),
                homeTeam: new TeamData(
                    externalId: '100',
                    name: 'Arsenal',
                ),
                awayTeam: new TeamData(
                    externalId: '101',
                    name: 'Chelsea',
                ),
                startsAt: new DateTimeImmutable('2026-10-04T18:30:00+00:00'),
                status: 'finished',
                homeScore: 2,
                awayScore: 1,
            ),
        ]);

        $service = new SyncMatchesService($updatedResponse);

        $service->sync(
            $provider,
            new DateTimeImmutable('2026-10-04')
        );

        $this->assertDatabaseCount('matches', 1);
        $this->assertDatabaseCount('provider_match_references', 1);

        $match = SportsMatch::query()->firstOrFail();

        self::assertSame('finished', $match->status);
        self::assertSame(2, $match->home_score);
        self::assertSame(1, $match->away_score);
    }

    public function test_it_rolls_back_when_a_team_mapping_is_missing(): void
    {
        $provider = SportsDataProvider::query()->create([
            'name' => 'Fake Provider A',
            'slug' => 'fake-provider-a',
            'enabled' => true,
        ]);

        $competition = Competition::query()->create([
            'name' => 'Premier League',
            'slug' => 'premier-league',
        ]);

        $arsenal = Team::query()->create([
            'name' => 'Arsenal',
            'slug' => 'arsenal',
        ]);

        ProviderCompetitionReference::query()->create([
            'provider_id' => $provider->id,
            'competition_id' => $competition->id,
            'external_id' => '55',
        ]);

        ProviderTeamReference::query()->create([
            'provider_id' => $provider->id,
            'team_id' => $arsenal->id,
            'external_id' => '100',
        ]);

        $service = new SyncMatchesService(
            new FakeProviderAAdapter
        );

        try {
            $service->sync(
                $provider,
                new DateTimeImmutable('2026-10-04')
            );

            self::fail('Expected RuntimeException was not thrown.');
        } catch (RuntimeException $exception) {
            self::assertSame(
                'Team mapping not found for external ID 101.',
                $exception->getMessage()
            );
        }

        $this->assertDatabaseCount('matches', 0);
        $this->assertDatabaseCount('provider_match_references', 0);
    }
}

final class ConfigurableSportsDataProvider implements SportsDataProviderInterface
{
    /**
     * @param  array<MatchData>  $matches
     */
    public function __construct(
        private array $matches,
    ) {}

    public function getMatches(DateTimeImmutable $date): array
    {
        return $this->matches;
    }
}

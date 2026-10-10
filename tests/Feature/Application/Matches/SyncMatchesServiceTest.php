<?php

declare(strict_types=1);

namespace Tests\Feature\Application\Matches;

use App\Application\Competitions\Services\ResolveCompetitionService;
use App\Application\Matches\Services\SyncMatchesService;
use App\Application\Providers\Services\RecordUnresolvedEntityService;
use App\Application\Teams\Services\ResolveTeamService;
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
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
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

        $service = $this->makeService(new FakeProviderAAdapter);

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

        $service = $this->makeService(new FakeProviderAAdapter);

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

        $service = $this->makeService($firstResponse);

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

        $service = $this->makeService($updatedResponse);

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

    public function test_it_skips_a_match_when_a_team_mapping_is_missing(): void
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

        $service = $this->makeService(new FakeProviderAAdapter);

        $report = $service->sync(
            $provider,
            new DateTimeImmutable('2026-10-04')
        );

        self::assertSame(1, $report->processed);
        self::assertSame(0, $report->succeeded);
        self::assertSame(1, $report->unresolved);

        $this->assertDatabaseCount('matches', 0);
        $this->assertDatabaseCount('provider_match_references', 0);
    }

    public function test_it_skips_a_match_when_a_competition_mapping_is_missing(): void
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

        ProviderCompetitionReference::query()->delete();

        $service = $this->makeService(new FakeProviderAAdapter);

        $report = $service->sync(
            $provider,
            new DateTimeImmutable('2026-10-04')
        );

        self::assertSame(1, $report->processed);
        self::assertSame(0, $report->succeeded);
        self::assertSame(1, $report->unresolved);

        $this->assertDatabaseCount('matches', 0);
        $this->assertDatabaseCount('provider_match_references', 0);
    }

    public function test_it_rolls_back_the_match_when_provider_reference_creation_fails(): void
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

        $service = $this->makeService(new FakeProviderAAdapter);

        $dispatcher = ProviderMatchReference::getEventDispatcher();
        ProviderMatchReference::setEventDispatcher(clone $dispatcher);
        $failure = new RuntimeException('Simulated provider reference failure.');

        ProviderMatchReference::creating(function (ProviderMatchReference $reference) use ($failure): void {
            $this->assertDatabaseHas('matches', ['id' => $reference->match_id]);

            throw $failure;
        });

        try {
            $service->sync($provider, new DateTimeImmutable('2026-10-04'));
            self::fail('Expected RuntimeException was not thrown.');
        } catch (RuntimeException $exception) {
            self::assertSame($failure, $exception);
        } finally {
            ProviderMatchReference::setEventDispatcher($dispatcher);
        }

        $this->assertDatabaseCount('matches', 0);
        $this->assertDatabaseCount('provider_match_references', 0);
    }

    public function test_it_records_an_unresolved_team_when_mapping_is_missing(): void
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

        $service = $this->makeService(
            new FakeProviderAAdapter
        );

        $report = $service->sync(
            $provider,
            new DateTimeImmutable('2026-10-04')
        );

        self::assertSame(1, $report->processed);
        self::assertSame(0, $report->succeeded);
        self::assertSame(1, $report->unresolved);

        $this->assertDatabaseHas(
            'unresolved_provider_entities',
            [
                'provider_id' => $provider->id,
                'entity_type' => 'team',
                'external_id' => '101',
                'external_name' => 'Chelsea',
                'status' => 'pending',
                'occurrences_count' => 1,
            ]
        );
    }

    private function makeService(
        SportsDataProviderInterface $providerAdapter
    ): SyncMatchesService {
        $unresolvedRecorder = new RecordUnresolvedEntityService;

        return new SyncMatchesService(
            $providerAdapter,
            new ResolveCompetitionService(
                $unresolvedRecorder
            ),
            new ResolveTeamService(
                $unresolvedRecorder
            ),
        );
    }

    public function test_it_increments_occurrences_for_repeated_unresolved_team(): void
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

        $service = $this->makeService(
            new FakeProviderAAdapter
        );

        $date = new DateTimeImmutable('2026-10-04');

        for ($i = 0; $i < 2; $i++) {
            $report = $service->sync($provider, $date);

            self::assertSame(1, $report->processed);
            self::assertSame(0, $report->succeeded);
            self::assertSame(1, $report->unresolved);
        }

        $this->assertDatabaseCount(
            'unresolved_provider_entities',
            1
        );

        $this->assertDatabaseHas(
            'unresolved_provider_entities',
            [
                'provider_id' => $provider->id,
                'entity_type' => 'team',
                'external_id' => '101',
                'external_name' => 'Chelsea',
                'status' => 'pending',
                'occurrences_count' => 2,
            ]
        );
    }

    public function test_it_records_an_unresolved_competition_when_mapping_is_missing(): void
    {
        $provider = SportsDataProvider::query()->create([
            'name' => 'Fake Provider A',
            'slug' => 'fake-provider-a',
            'enabled' => true,
        ]);

        $service = $this->makeService(
            new FakeProviderAAdapter
        );

        $report = $service->sync(
            $provider,
            new DateTimeImmutable('2026-10-04')
        );

        self::assertSame(1, $report->processed);
        self::assertSame(0, $report->succeeded);
        self::assertSame(1, $report->unresolved);

        $this->assertDatabaseHas(
            'unresolved_provider_entities',
            [
                'provider_id' => $provider->id,
                'entity_type' => 'competition',
                'external_id' => '55',
                'external_name' => 'Premier League',
                'status' => 'pending',
                'occurrences_count' => 1,
            ]
        );
    }

    public function test_it_continues_after_an_unresolved_match(): void
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

        foreach ([
            '100' => $arsenal->id,
            '101' => $chelsea->id,
        ] as $externalId => $teamId) {
            ProviderTeamReference::query()->create([
                'provider_id' => $provider->id,
                'team_id' => $teamId,
                'external_id' => (string) $externalId,
            ]);
        }

        $competitionData = new CompetitionData('55', 'Premier League');
        $arsenalData = new TeamData('100', 'Arsenal');
        $chelseaData = new TeamData('101', 'Chelsea');
        $unknownData = new TeamData('999', 'Unknown FC');

        $matches = [
            new MatchData(
                '2001',
                $competitionData,
                $arsenalData,
                $chelseaData,
                new DateTimeImmutable('2026-10-04T18:30:00+00:00'),
                'finished',
                2,
                1,
            ),
            new MatchData(
                '2002',
                $competitionData,
                $arsenalData,
                $unknownData,
                new DateTimeImmutable('2026-10-04T19:30:00+00:00'),
                'scheduled',
                null,
                null,
            ),
            new MatchData(
                '2003',
                $competitionData,
                $chelseaData,
                $arsenalData,
                new DateTimeImmutable('2026-10-04T20:30:00+00:00'),
                'finished',
                1,
                0,
            ),
        ];

        $service = $this->makeService(
            new ConfigurableSportsDataProvider($matches)
        );

        $report = $service->sync(
            $provider,
            new DateTimeImmutable('2026-10-04')
        );

        self::assertSame(3, $report->processed);
        self::assertSame(2, $report->succeeded);
        self::assertSame(1, $report->unresolved);

        $this->assertDatabaseCount('matches', 2);
        $this->assertDatabaseCount('provider_match_references', 2);

        $this->assertDatabaseHas('provider_match_references', [
            'provider_id' => $provider->id,
            'external_id' => '2001',
        ]);

        $this->assertDatabaseHas('provider_match_references', [
            'provider_id' => $provider->id,
            'external_id' => '2003',
        ]);

        $this->assertDatabaseMissing('provider_match_references', [
            'provider_id' => $provider->id,
            'external_id' => '2002',
        ]);

        $this->assertDatabaseHas('unresolved_provider_entities', [
            'provider_id' => $provider->id,
            'entity_type' => 'team',
            'external_id' => '999',
            'external_name' => 'Unknown FC',
            'status' => 'pending',
        ]);
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

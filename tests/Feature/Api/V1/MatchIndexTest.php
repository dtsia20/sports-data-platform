<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1;

use App\Domain\Competitions\Models\Competition;
use App\Domain\Matches\Models\SportsMatch;
use App\Domain\Teams\Models\Team;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class MatchIndexTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_returns_canonical_matches(): void
    {
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

        SportsMatch::query()->create([
            'competition_id' => $competition->id,
            'home_team_id' => $arsenal->id,
            'away_team_id' => $chelsea->id,
            'starts_at' => '2026-10-04 18:30:00+00',
            'status' => 'finished',
            'home_score' => 2,
            'away_score' => 1,
        ]);

        $response = $this->getJson('/api/v1/matches');

        $response
            ->assertOk()
            ->assertJsonPath('data.0.status', 'finished')
            ->assertJsonPath('data.0.score.home', 2)
            ->assertJsonPath('data.0.score.away', 1)
            ->assertJsonPath('data.0.competition.name', 'Premier League')
            ->assertJsonPath('data.0.homeTeam.name', 'Arsenal')
            ->assertJsonPath('data.0.awayTeam.name', 'Chelsea');
    }

    public function test_it_filters_matches_by_date(): void
    {
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

        SportsMatch::query()->create([
            'competition_id' => $competition->id,
            'home_team_id' => $arsenal->id,
            'away_team_id' => $chelsea->id,
            'starts_at' => '2026-10-04 18:30:00+00',
            'status' => 'finished',
            'home_score' => 2,
            'away_score' => 1,
        ]);

        SportsMatch::query()->create([
            'competition_id' => $competition->id,
            'home_team_id' => $chelsea->id,
            'away_team_id' => $arsenal->id,
            'starts_at' => '2026-10-05 18:30:00+00',
            'status' => 'scheduled',
            'home_score' => null,
            'away_score' => null,
        ]);

        $response = $this->getJson(
            '/api/v1/matches?date=2026-10-04'
        );

        $response
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath(
                'data.0.startsAt',
                '2026-10-04T18:30:00+00:00'
            );
    }

    public function test_it_rejects_an_invalid_date_filter(): void
    {
        $response = $this->getJson(
            '/api/v1/matches?date=04-10-2026'
        );

        $response
            ->assertUnprocessable()
            ->assertJsonValidationErrors('date');
    }
}

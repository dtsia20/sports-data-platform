<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Domain\Competitions\Models\Competition;
use App\Domain\Matches\Models\SportsMatch;
use App\Domain\Providers\Models\ProviderCompetitionReference;
use App\Domain\Providers\Models\ProviderMatchReference;
use App\Domain\Providers\Models\ProviderTeamReference;
use App\Domain\Providers\Models\SportsDataProvider;
use App\Domain\Teams\Models\Team;
use Illuminate\Database\Seeder;

final class SportsDataSeeder extends Seeder
{
    public function run(): void
    {
        $provider = SportsDataProvider::updateOrCreate(
            [
                'slug' => 'fake-provider-a',
            ],
            [
                'name' => 'Fake Provider A',
                'enabled' => true,
            ]
        );

        $competition = Competition::updateOrCreate(
            [
                'slug' => 'premier-league',
            ],
            [
                'name' => 'Premier League',
            ]
        );

        $arsenal = Team::updateOrCreate(
            [
                'slug' => 'arsenal',
            ],
            [
                'name' => 'Arsenal',
            ]
        );

        $chelsea = Team::updateOrCreate(
            [
                'slug' => 'chelsea',
            ],
            [
                'name' => 'Chelsea',
            ]
        );

        $match = SportsMatch::updateOrCreate(
            [
                'competition_id' => $competition->id,
                'home_team_id' => $arsenal->id,
                'away_team_id' => $chelsea->id,
                'starts_at' => '2026-10-04 18:30:00+00',
            ],
            [
                'status' => 'finished',
                'home_score' => 2,
                'away_score' => 1,
            ]
        );

        ProviderCompetitionReference::updateOrCreate(
            [
                'provider_id' => $provider->id,
                'external_id' => '55',
            ],
            [
                'competition_id' => $competition->id,
            ]
        );

        ProviderTeamReference::updateOrCreate(
            [
                'provider_id' => $provider->id,
                'external_id' => '100',
            ],
            [
                'team_id' => $arsenal->id,
            ]
        );

        ProviderTeamReference::updateOrCreate(
            [
                'provider_id' => $provider->id,
                'external_id' => '101',
            ],
            [
                'team_id' => $chelsea->id,
            ]
        );

        ProviderMatchReference::updateOrCreate(
            [
                'provider_id' => $provider->id,
                'external_id' => '14567',
            ],
            [
                'match_id' => $match->id,
            ]
        );
    }
}
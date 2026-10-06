<?php

declare(strict_types=1);

namespace App\Application\Matches\Services;

use App\Application\Competitions\Services\ResolveCompetitionService;
use App\Application\Teams\Services\ResolveTeamService;
use App\Domain\Matches\Contracts\SportsDataProviderInterface;
use App\Domain\Matches\DTOs\MatchData;
use App\Domain\Matches\Models\SportsMatch;
use App\Domain\Providers\Models\ProviderMatchReference;
use App\Domain\Providers\Models\SportsDataProvider;
use DateTimeImmutable;
use Illuminate\Support\Facades\DB;

final readonly class SyncMatchesService
{
    public function __construct(
        private SportsDataProviderInterface $providerAdapter,
        private ResolveCompetitionService $competitionResolver,
        private ResolveTeamService $teamResolver,
    ) {}

    public function sync(
        SportsDataProvider $provider,
        DateTimeImmutable $date,
    ): void {
        foreach ($this->providerAdapter->getMatches($date) as $matchData) {
            $this->syncMatch($provider, $matchData);
        }
    }

    private function syncMatch(
        SportsDataProvider $provider,
        MatchData $matchData,
    ): void {
        $competition = $this->competitionResolver->resolve(
            $provider,
            $matchData->competition
        );

        $homeTeam = $this->teamResolver->resolve(
            $provider,
            $matchData->homeTeam
        );

        $awayTeam = $this->teamResolver->resolve(
            $provider,
            $matchData->awayTeam
        );

        DB::transaction(function () use ($provider, $matchData, $competition, $homeTeam, $awayTeam): void {
            $matchReference = ProviderMatchReference::query()
                ->where('provider_id', $provider->id)
                ->where('external_id', $matchData->externalId)
                ->first();

            if ($matchReference !== null) {
                $matchReference->match->update([
                    'competition_id' => $competition->id,
                    'home_team_id' => $homeTeam->id,
                    'away_team_id' => $awayTeam->id,
                    'starts_at' => $matchData->startsAt,
                    'status' => $matchData->status,
                    'home_score' => $matchData->homeScore,
                    'away_score' => $matchData->awayScore,
                ]);

                return;
            }

            $match = SportsMatch::query()->create([
                'competition_id' => $competition->id,
                'home_team_id' => $homeTeam->id,
                'away_team_id' => $awayTeam->id,
                'starts_at' => $matchData->startsAt,
                'status' => $matchData->status,
                'home_score' => $matchData->homeScore,
                'away_score' => $matchData->awayScore,
            ]);

            ProviderMatchReference::query()->create([
                'provider_id' => $provider->id,
                'match_id' => $match->id,
                'external_id' => $matchData->externalId,
            ]);
        });
    }
}

<?php

declare(strict_types=1);

namespace App\Application\Matches\Services;

use App\Domain\Matches\Contracts\SportsDataProviderInterface;
use App\Domain\Matches\DTOs\MatchData;
use App\Domain\Matches\Models\SportsMatch;
use App\Domain\Providers\Models\ProviderCompetitionReference;
use App\Domain\Providers\Models\ProviderMatchReference;
use App\Domain\Providers\Models\ProviderTeamReference;
use App\Domain\Providers\Models\SportsDataProvider;
use DateTimeImmutable;
use Illuminate\Support\Facades\DB;
use RuntimeException;

final readonly class SyncMatchesService
{
    public function __construct(
        private SportsDataProviderInterface $providerAdapter,
    ) {}

    public function sync(
        SportsDataProvider $provider,
        DateTimeImmutable $date,
    ): void {
        foreach ($this->providerAdapter->getMatches($date) as $matchData) {
            DB::transaction(
                fn (): mixed => $this->syncMatch(
                    $provider,
                    $matchData,
                )
            );
        }
    }

    private function syncMatch(
        SportsDataProvider $provider,
        MatchData $matchData,
    ): void {
        $competitionId = ProviderCompetitionReference::query()
            ->where('provider_id', $provider->id)
            ->where(
                'external_id',
                $matchData->competition->externalId
            )
            ->value('competition_id');

        if ($competitionId === null) {
            throw new RuntimeException(
                sprintf(
                    'Competition mapping not found for external ID %s.',
                    $matchData->competition->externalId
                )
            );
        }

        $homeTeamId = $this->resolveTeamId(
            $provider,
            $matchData->homeTeam->externalId
        );

        $awayTeamId = $this->resolveTeamId(
            $provider,
            $matchData->awayTeam->externalId
        );

        $matchReference = ProviderMatchReference::query()
            ->where('provider_id', $provider->id)
            ->where('external_id', $matchData->externalId)
            ->first();

        if ($matchReference !== null) {
            $matchReference->match->update([
                'competition_id' => $competitionId,
                'home_team_id' => $homeTeamId,
                'away_team_id' => $awayTeamId,
                'starts_at' => $matchData->startsAt,
                'status' => $matchData->status,
                'home_score' => $matchData->homeScore,
                'away_score' => $matchData->awayScore,
            ]);

            return;
        }

        $match = SportsMatch::query()->create([
            'competition_id' => $competitionId,
            'home_team_id' => $homeTeamId,
            'away_team_id' => $awayTeamId,
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
    }

    private function resolveTeamId(
        SportsDataProvider $provider,
        string $externalId,
    ): int {
        $teamId = ProviderTeamReference::query()
            ->where('provider_id', $provider->id)
            ->where('external_id', $externalId)
            ->value('team_id');

        if ($teamId === null) {
            throw new RuntimeException(
                sprintf(
                    'Team mapping not found for external ID %s.',
                    $externalId
                )
            );
        }

        return $teamId;
    }
}

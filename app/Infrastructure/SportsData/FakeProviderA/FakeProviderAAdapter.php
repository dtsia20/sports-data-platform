<?php

declare(strict_types=1);

namespace App\Infrastructure\SportsData\FakeProviderA;

use App\Domain\Competitions\DTOs\CompetitionData;
use App\Domain\Matches\Contracts\SportsDataProviderInterface;
use App\Domain\Matches\DTOs\MatchData;
use App\Domain\Teams\DTOs\TeamData;
use DateTimeImmutable;

final class FakeProviderAAdapter implements SportsDataProviderInterface
{
    public function getMatches(DateTimeImmutable $date): array
    {
        return [
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
        ];
    }
}

<?php

declare(strict_types=1);

namespace App\Domain\Matches\DTOs;

use App\Domain\Competitions\DTOs\CompetitionData;
use App\Domain\Teams\DTOs\TeamData;
use DateTimeImmutable;

final readonly class MatchData
{
    public function __construct(
        public string $externalId,
        public CompetitionData $competition,
        public TeamData $homeTeam,
        public TeamData $awayTeam,
        public DateTimeImmutable $startsAt,
        public string $status,
        public ?int $homeScore,
        public ?int $awayScore,
    ) {}
}

<?php

declare(strict_types=1);

namespace App\Domain\Matches\Contracts;

use App\Domain\Matches\DTOs\MatchData;
use DateTimeImmutable;

interface SportsDataProviderInterface
{
    /**
     * @return array<MatchData>
     */
    public function getMatches(DateTimeImmutable $date): array;
}
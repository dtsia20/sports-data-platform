<?php

declare(strict_types=1);

namespace App\Domain\Competitions\DTOs;

final readonly class CompetitionData
{
    public function __construct(
        public string $externalId,
        public string $name,
    ) {}
}

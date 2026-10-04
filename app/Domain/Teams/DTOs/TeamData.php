<?php

declare(strict_types=1);

namespace App\Domain\Teams\DTOs;

final readonly class TeamData
{
    public function __construct(
        public string $externalId,
        public string $name,
    ) {}
}

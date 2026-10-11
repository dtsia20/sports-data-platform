<?php

declare(strict_types=1);

namespace App\Domain\Teams\DTOs;

final readonly class TeamData
{
    /**
     * @param  array<string, int|string|null>  $context
     */
    public function __construct(
        public string $externalId,
        public string $name,
        public array $context = [],
    ) {}
}

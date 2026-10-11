<?php

declare(strict_types=1);

namespace App\Domain\Competitions\DTOs;

final readonly class CompetitionData
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

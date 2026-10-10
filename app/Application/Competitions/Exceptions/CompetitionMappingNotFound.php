<?php

declare(strict_types=1);

namespace App\Application\Competitions\Exceptions;

use RuntimeException;

final class CompetitionMappingNotFound extends RuntimeException
{
    public static function forExternalId(string $externalId): self
    {
        return new self(
            sprintf(
                'Competition mapping not found for external ID %s.',
                $externalId
            )
        );
    }
}

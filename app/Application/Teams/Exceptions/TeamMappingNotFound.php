<?php

declare(strict_types=1);

namespace App\Application\Teams\Exceptions;

use RuntimeException;

final class TeamMappingNotFound extends RuntimeException
{
    public static function forExternalId(string $externalId): self
    {
        return new self(
            sprintf(
                'Team mapping not found for external ID %s.',
                $externalId
            )
        );
    }
}

<?php

declare(strict_types=1);

namespace App\Application\Providers\Services;

use App\Domain\Providers\Models\SportsDataProvider;
use App\Domain\Providers\Models\UnresolvedProviderEntity;

final readonly class RecordUnresolvedEntityService
{
    public function record(
        SportsDataProvider $provider,
        string $entityType,
        string $externalId,
        string $externalName,
        array $context = [],
    ): UnresolvedProviderEntity {
        $unresolved = UnresolvedProviderEntity::query()
            ->where('provider_id', $provider->id)
            ->where('entity_type', $entityType)
            ->where('external_id', $externalId)
            ->first();

        if ($unresolved === null) {
            return UnresolvedProviderEntity::query()->create([
                'provider_id' => $provider->id,
                'entity_type' => $entityType,
                'external_id' => $externalId,
                'external_name' => $externalName,
                'context' => $context,
                'status' => 'pending',
                'occurrences_count' => 1,
                'first_seen_at' => now(),
                'last_seen_at' => now(),
            ]);
        }

        $unresolved->update([
            'external_name' => $externalName,
            'context' => $context,
            'last_seen_at' => now(),
            'occurrences_count' => $unresolved->occurrences_count + 1,
        ]);

        return $unresolved->refresh();
    }
}

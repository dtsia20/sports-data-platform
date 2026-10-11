<?php

declare(strict_types=1);

namespace App\Application\Providers\Services;

use App\Application\Matches\Services\SyncMatchesService;
use App\Domain\Providers\Models\SportsDataProvider;
use InvalidArgumentException;

final class ProviderSyncServiceFactory
{
    public function forProvider(SportsDataProvider $provider): SyncMatchesService
    {
        $adapterClass = config('services.sports_data.adapters.'.$provider->slug);
        if (! is_string($adapterClass)) {
            throw new InvalidArgumentException('No adapter registered for provider: '.$provider->slug);
        }

        return app()->makeWith(SyncMatchesService::class, ['providerAdapter' => app($adapterClass)]);
    }
}

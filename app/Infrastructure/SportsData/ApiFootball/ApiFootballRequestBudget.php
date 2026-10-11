<?php

declare(strict_types=1);

namespace App\Infrastructure\SportsData\ApiFootball;

use Illuminate\Support\Facades\Cache;

final class ApiFootballRequestBudget
{
    public function reserve(): void
    {
        $key = $this->key();
        Cache::lock($key.':lock', 5)->block(3, function () use ($key): void {
            $count = (int) Cache::get($key, 0);
            $limit = max(0, min(90, (int) config('services.api_football.daily_limit', 90)));
            if ($count >= $limit) {
                throw new ApiFootballUnavailable('API-Football daily request budget exhausted.');
            }
            Cache::put($key, $count + 1, now('UTC')->addDay()->startOfDay());
        });
    }

    public function exhaust(): void
    {
        $key = $this->key();
        Cache::lock($key.':lock', 5)->block(3, function () use ($key): void {
            Cache::put($key, 90, now('UTC')->addDay()->startOfDay());
        });
    }

    private function key(): string
    {
        return 'api-football:requests:'.hash('sha256', (string) config('services.api_football.key')).':'.now('UTC')->toDateString();
    }
}

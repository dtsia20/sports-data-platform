<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Providers\Models\SportsDataProvider;
use Illuminate\Console\Command;

final class ConfigureApiFootballSchedule extends Command
{
    protected $signature = 'sports:api-football-schedule {state : on or off}';

    protected $description = 'Enable or disable API-Football dispatch on the 30-minute schedule';

    public function handle(): int
    {
        $state = $this->argument('state');
        if (! in_array($state, ['on', 'off'], true)) {
            $this->error('State must be on or off.');

            return self::FAILURE;
        }
        if ($state === 'on' && (! config('services.api_football.scheduled') || ! config('services.api_football.key'))) {
            $this->error('Configure API_FOOTBALL_KEY and API_FOOTBALL_SCHEDULED=true before enabling.');

            return self::FAILURE;
        }
        $provider = SportsDataProvider::query()->firstOrCreate(['slug' => 'api-football'], ['name' => 'API-Football', 'enabled' => false]);
        $provider->update(['enabled' => $state === 'on']);
        $this->info('API-Football scheduled dispatch is '.$state.'. Already queued jobs are not cancelled.');

        return self::SUCCESS;
    }
}

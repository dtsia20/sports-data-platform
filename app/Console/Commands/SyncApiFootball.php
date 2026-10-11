<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Application\Providers\Services\ProviderSyncServiceFactory;
use App\Domain\Providers\Models\SportsDataProvider;
use App\Jobs\SyncProviderMatchesJob;
use DateTimeImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Validator;

final class SyncApiFootball extends Command
{
    protected $signature = 'sports:sync-api-football {--date= : UTC fixture date (YYYY-MM-DD)}';

    protected $description = 'Synchronize API-Football manually using existing entity mappings';

    public function handle(ProviderSyncServiceFactory $factory): int
    {
        $date = $this->option('date') ?: now('UTC')->toDateString();
        if (Validator::make(['date' => $date], ['date' => 'required|date_format:Y-m-d'])->fails()) {
            $this->error('Date must be a valid YYYY-MM-DD date.');

            return self::FAILURE;
        }
        $provider = SportsDataProvider::query()->firstOrCreate(['slug' => 'api-football'], ['name' => 'API-Football', 'enabled' => false]);
        $job = new SyncProviderMatchesJob($provider->id, $date);
        $lock = Cache::lock($job->middleware()[0]->getLockKey($job), 90);
        if (! $lock->get()) {
            $this->error('A sync for this provider/date is already running.');

            return self::FAILURE;
        }
        try {
            $report = $factory->forProvider($provider)->sync($provider, new DateTimeImmutable($date));
            $this->info("Processed {$report->processed}; succeeded {$report->succeeded}; unresolved {$report->unresolved}.");
            if ($report->unresolved > 0) {
                $this->warn('Unresolved matches are missing competition or team mappings. Import mappings from a saved successful fixture response using sports:import-api-football-mappings <file>, then rerun this sync.');
            }
        } finally {
            $lock->release();
        }

        return self::SUCCESS;
    }
}

<?php

use App\Domain\Providers\Models\SportsDataProvider;
use App\Jobs\SyncProviderMatchesJob;
use Illuminate\Console\Command;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('sports:sync-matches', function (): int {
    $date = now('UTC')->toDateString();
    $dispatched = 0;

    foreach (SportsDataProvider::query()->where('enabled', true)->select('id')->lazyById() as $provider) {
        SyncProviderMatchesJob::dispatch($provider->id, $date);
        $dispatched++;
    }

    $this->info("Queued {$dispatched} provider sync jobs for {$date} (UTC).");

    return Command::SUCCESS;
})->purpose('Queue today’s UTC matches for each enabled sports-data provider');

Schedule::command('sports:sync-matches')
    ->everyFiveMinutes()
    ->timezone('UTC')
    ->withoutOverlapping(5)
    ->onOneServer();

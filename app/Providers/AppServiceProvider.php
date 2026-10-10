<?php

namespace App\Providers;

use App\Domain\Matches\Contracts\SportsDataProviderInterface;
use App\Infrastructure\SportsData\FakeProviderA\FakeProviderAAdapter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(
            SportsDataProviderInterface::class,
            FakeProviderAAdapter::class
        );
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}

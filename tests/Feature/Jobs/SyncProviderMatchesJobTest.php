<?php

declare(strict_types=1);

namespace Tests\Feature\Jobs;

use App\Application\Matches\Services\SyncMatchesService;
use App\Domain\Providers\Models\SportsDataProvider;
use App\Jobs\SyncProviderMatchesJob;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

final class SyncProviderMatchesJobTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_dispatches_a_sync_job(): void
    {
        Queue::fake();

        SyncProviderMatchesJob::dispatch(
            1,
            '2026-10-04'
        );

        Queue::assertPushed(
            SyncProviderMatchesJob::class,
            function (SyncProviderMatchesJob $job): bool {
                return $job->providerId === 1
                    && $job->date === '2026-10-04';
            }
        );
    }

    public function test_it_synchronizes_matches_when_executed(): void
    {
        $this->seed();

        $provider = SportsDataProvider::query()
            ->where('slug', 'fake-provider-a')
            ->firstOrFail();

        $job = new SyncProviderMatchesJob(
            $provider->id,
            '2026-10-04'
        );

        $job->handle(
            app(SyncMatchesService::class)
        );

        $this->assertDatabaseCount('matches', 1);

        $this->assertDatabaseHas('provider_match_references', [
            'provider_id' => $provider->id,
            'external_id' => '14567',
        ]);
    }

    public function test_it_uses_a_provider_and_date_specific_overlap_lock(): void
    {
        $job = new SyncProviderMatchesJob(
            providerId: 1,
            date: '2026-10-10',
        );

        $middleware = $job->middleware();

        self::assertCount(1, $middleware);
        self::assertInstanceOf(
            WithoutOverlapping::class,
            $middleware[0]
        );

        self::assertSame(
            'provider-sync:1:2026-10-10',
            $middleware[0]->key
        );

        self::assertSame(10, $middleware[0]->releaseAfter);
        self::assertSame(90, $middleware[0]->expiresAfter);
    }
}

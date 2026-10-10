<?php

declare(strict_types=1);

namespace Tests\Feature\Jobs;

use App\Domain\Matches\Contracts\SportsDataProviderInterface;
use App\Domain\Providers\Models\ProviderTeamReference;
use App\Domain\Providers\Models\SportsDataProvider;
use App\Infrastructure\SportsData\FakeProviderA\FakeProviderAAdapter;
use App\Jobs\SyncProviderMatchesJob;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Mockery;
use RuntimeException;
use Tests\TestCase;

final class SyncProviderMatchesQueueTest extends TestCase
{
    use DatabaseMigrations;

    private const string QUEUE = 'sync-reliability';

    protected function setUp(): void
    {
        parent::setUp();

        config(['queue.default' => 'database', 'cache.default' => 'database']);
        $this->freezeTime();
        $this->seed();
    }

    public function test_a_contended_job_waits_while_other_providers_and_dates_can_run(): void
    {
        $provider = $this->provider();
        $otherProvider = SportsDataProvider::query()->create([
            'name' => 'Other Provider',
            'slug' => 'other-provider',
            'enabled' => true,
        ]);
        $job = new SyncProviderMatchesJob($provider->id, '2026-10-04');
        $lock = Cache::lock($job->middleware()[0]->getLockKey($job), 90);
        self::assertTrue($lock->get());
        $adapter = Mockery::mock(SportsDataProviderInterface::class);
        $adapter->shouldReceive('getMatches')->times(3)->andReturn([]);
        $this->app->instance(SportsDataProviderInterface::class, $adapter);

        try {
            $this->dispatch($job);
            $this->dispatch(new SyncProviderMatchesJob($provider->id, '2026-10-05'));
            $this->dispatch(new SyncProviderMatchesJob($otherProvider->id, '2026-10-04'));
            $this->workOneJob();
            $released = DB::table('jobs')->where('queue', self::QUEUE)->where('attempts', 1)->sole();
            self::assertSame(now()->timestamp + 10, $released->available_at);
            self::assertNull($released->reserved_at);
            self::assertFalse(Cache::lock($job->middleware()[0]->getLockKey($job), 90)->get());
            $this->workOneJob();
            $this->workOneJob();
            $this->assertDatabaseCount('jobs', 1);
            $this->assertDatabaseCount('failed_jobs', 0);
        } finally {
            $lock->release();
        }

        $this->travel(10)->seconds();
        $this->workOneJob();
        $this->assertDatabaseCount('jobs', 0);
        $this->assertDatabaseCount('cache_locks', 0);
    }

    public function test_an_expired_lock_allows_a_waiting_job_to_recover(): void
    {
        $job = new SyncProviderMatchesJob($this->provider()->id, '2026-10-04');
        $lock = Cache::lock($job->middleware()[0]->getLockKey($job), 90);
        self::assertTrue($lock->get());

        try {
            $this->dispatch($job);
            $this->workOneJob();
            $this->assertDatabaseCount('jobs', 1);
            $this->travel(90)->seconds();
            $this->workOneJob();
            $this->assertDatabaseCount('jobs', 0);
            $this->assertDatabaseCount('failed_jobs', 0);
            $this->assertDatabaseCount('cache_locks', 0);
            $this->assertDatabaseCount('matches', 1);
        } finally {
            $lock->release();
        }
    }

    public function test_a_transient_failure_is_delayed_then_succeeds_without_duplicate_matches(): void
    {
        $adapter = Mockery::mock(SportsDataProviderInterface::class);
        $adapter->shouldReceive('getMatches')->once()->andThrow(new RuntimeException('Temporary provider failure'));
        $adapter->shouldReceive('getMatches')->once()->andReturn((new FakeProviderAAdapter)->getMatches(now()->toDateTimeImmutable()));
        $this->app->instance(SportsDataProviderInterface::class, $adapter);
        $this->dispatch(new SyncProviderMatchesJob($this->provider()->id, '2026-10-04'));

        $this->workOneJob();
        $released = DB::table('jobs')->sole();
        self::assertSame(1, $released->attempts);
        self::assertSame(now()->timestamp + 10, $released->available_at);
        $this->assertDatabaseCount('cache_locks', 0);

        $this->workOneJob();
        self::assertSame(1, DB::table('jobs')->sole()->attempts);
        $this->travel(10)->seconds();
        $this->workOneJob();
        $this->assertDatabaseCount('jobs', 0);
        $this->assertDatabaseCount('failed_jobs', 0);
        $this->assertDatabaseCount('matches', 1);
        $this->assertDatabaseCount('provider_match_references', 1);
    }

    public function test_a_persistent_failure_exhausts_ten_attempts_and_is_recorded(): void
    {
        $adapter = Mockery::mock(SportsDataProviderInterface::class);
        $adapter->shouldReceive('getMatches')->times(10)->andThrow(new RuntimeException('Persistent provider failure'));
        $this->app->instance(SportsDataProviderInterface::class, $adapter);
        $this->dispatch(new SyncProviderMatchesJob($this->provider()->id, '2026-10-04'));

        for ($attempt = 1; $attempt <= 10; $attempt++) {
            $this->workOneJob();
            $this->assertDatabaseCount('cache_locks', 0);
            if ($attempt < 10) {
                $delay = $attempt === 1 ? 10 : ($attempt === 2 ? 30 : 60);
                $released = DB::table('jobs')->sole();
                self::assertSame($attempt, $released->attempts);
                self::assertSame(now()->timestamp + $delay, $released->available_at);
                $this->assertDatabaseCount('failed_jobs', 0);
                $this->travel($delay)->seconds();
            }
        }

        $this->assertDatabaseCount('jobs', 0);
        $failure = DB::table('failed_jobs')->sole();
        self::assertSame('database', $failure->connection);
        self::assertSame(self::QUEUE, $failure->queue);
        self::assertStringContainsString('Persistent provider failure', $failure->exception);
        self::assertSame(10, json_decode($failure->payload, true, flags: JSON_THROW_ON_ERROR)['maxTries']);
    }

    public function test_missing_mappings_are_reported_without_retrying_the_job(): void
    {
        ProviderTeamReference::query()->where('external_id', '101')->delete();
        Log::spy();
        $provider = $this->provider();
        $this->dispatch(new SyncProviderMatchesJob($provider->id, '2026-10-04'));

        $this->workOneJob();
        $this->assertDatabaseCount('jobs', 0);
        $this->assertDatabaseCount('failed_jobs', 0);
        $this->assertDatabaseHas('unresolved_provider_entities', [
            'provider_id' => $provider->id,
            'external_id' => '101',
        ]);
        Log::shouldHaveReceived('info')->once()->with('Provider matches synchronized', [
            'providerId' => $provider->id,
            'date' => '2026-10-04',
            'processed' => 1,
            'succeeded' => 0,
            'unresolved' => 1,
        ]);
    }

    public function test_the_timeout_is_shorter_than_lock_expiry_and_queue_retry_after(): void
    {
        $job = new SyncProviderMatchesJob($this->provider()->id, '2026-10-04');

        self::assertGreaterThan($job->timeout, $job->middleware()[0]->expiresAfter);
        self::assertGreaterThan($job->timeout, config('queue.connections.database.retry_after'));
    }

    private function provider(): SportsDataProvider
    {
        return SportsDataProvider::query()->where('slug', 'fake-provider-a')->firstOrFail();
    }

    private function dispatch(SyncProviderMatchesJob $job): void
    {
        dispatch($job->onConnection('database')->onQueue(self::QUEUE));
    }

    private function workOneJob(): void
    {
        self::assertSame(0, Artisan::call('queue:work', [
            'connection' => 'database',
            '--queue' => self::QUEUE,
            '--once' => true,
            '--sleep' => 0,
            '--quiet' => true,
        ]));
    }
}

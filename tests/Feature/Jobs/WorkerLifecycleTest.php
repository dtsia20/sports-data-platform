<?php

declare(strict_types=1);

namespace Tests\Feature\Jobs;

use App\Domain\Providers\Models\SportsDataProvider;
use App\Jobs\SyncProviderMatchesJob;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\Process\Exception\ProcessSignaledException;
use Symfony\Component\Process\Process;
use Tests\TestCase;

final class WorkerLifecycleTest extends TestCase
{
    use DatabaseMigrations;

    private const string QUEUE = 'worker-lifecycle';

    protected function setUp(): void
    {
        parent::setUp();

        self::assertTrue(extension_loaded('pcntl'), 'Worker lifecycle tests require pcntl.');
        config([
            'queue.default' => 'database',
            'queue.connections.database.retry_after' => 3,
            'cache.default' => 'database',
            'cache.prefix' => 'worker-lifecycle-'.Str::uuid().':',
        ]);
        $this->seed();
    }

    public function test_timeout_terminates_the_worker_and_a_replacement_exhausts_the_job(): void
    {
        dispatch((new TimedOutJobFixture)->onConnection('database')->onQueue(self::QUEUE));

        $first = $this->worker(['--max-jobs=1', '--stop-when-empty']);
        $this->assertTimeoutTerminates($first);
        $reserved = DB::table('jobs')->sole();
        self::assertSame(1, $reserved->attempts);
        self::assertNotNull($reserved->reserved_at);
        $this->assertDatabaseCount('failed_jobs', 0);
        $this->assertDatabaseCount('cache_locks', 1);

        // Worker death leaves the reservation and lock until their real expiry.
        sleep(3);
        $replacement = $this->worker(['--max-jobs=1', '--stop-when-empty']);
        $this->assertTimeoutTerminates($replacement);
        $this->assertDatabaseCount('jobs', 0);
        $failed = DB::table('failed_jobs')->sole();
        self::assertStringContainsString('TimeoutExceededException', $failed->exception);
        self::assertSame(self::QUEUE, $failed->queue);

        $this->dispatchHealthyJob();
        $healthy = $this->worker(['--max-jobs=1', '--stop-when-empty']);
        $healthy->run();
        self::assertSame(0, $healthy->getExitCode(), $healthy->getOutput().$healthy->getErrorOutput());
        $this->assertDatabaseCount('jobs', 0);
        $this->assertDatabaseCount('matches', 1);
    }

    public function test_queue_restart_exits_a_daemon_and_a_replacement_can_process_work(): void
    {
        $this->dispatchHealthyJob();
        $worker = $this->worker();
        $worker->start();

        try {
            $deadline = microtime(true) + 5;
            while (DB::table('jobs')->exists() && $worker->isRunning() && microtime(true) < $deadline) {
                usleep(50_000);
            }
            $this->assertDatabaseCount('jobs', 0);
            self::assertTrue($worker->isRunning());

            // Restart markers are second-resolution timestamps; use a later second.
            sleep(1);
            self::assertSame(0, Artisan::call('queue:restart'));
            $worker->wait();
            self::assertSame(0, $worker->getExitCode(), $worker->getOutput().$worker->getErrorOutput());
        } finally {
            if ($worker->isRunning()) {
                $worker->stop(1);
            }
        }

        $this->dispatchHealthyJob();
        $replacement = $this->worker(['--max-jobs=1', '--stop-when-empty']);
        $replacement->run();
        self::assertSame(0, $replacement->getExitCode(), $replacement->getOutput().$replacement->getErrorOutput());
        $this->assertDatabaseCount('jobs', 0);
        $this->assertDatabaseCount('failed_jobs', 0);
        $this->assertDatabaseCount('matches', 1);
    }

    private function assertTimeoutTerminates(Process $worker): void
    {
        try {
            $worker->run();
            self::assertFalse(extension_loaded('posix'), 'POSIX workers must terminate with SIGKILL.');
            self::assertSame(1, $worker->getExitCode());
        } catch (ProcessSignaledException $exception) {
            self::assertSame(SIGKILL, $exception->getSignal());
        }
    }

    private function dispatchHealthyJob(): void
    {
        $provider = SportsDataProvider::query()->where('slug', 'fake-provider-a')->firstOrFail();
        SyncProviderMatchesJob::dispatch($provider->id, '2026-10-04')->onConnection('database')->onQueue(self::QUEUE);
    }

    /**
     * @param  array<int, string>  $options
     */
    private function worker(array $options = []): Process
    {
        $database = config('database.connections.pgsql');
        $process = new Process([
            PHP_BINARY, 'artisan', 'queue:work', 'database', '--queue='.self::QUEUE, '--sleep=1', ...$options,
        ], base_path(), [
            'APP_ENV' => 'testing',
            'APP_DEBUG' => 'false',
            'DB_CONNECTION' => 'pgsql',
            'DB_HOST' => (string) $database['host'],
            'DB_PORT' => (string) $database['port'],
            'DB_DATABASE' => (string) $database['database'],
            'DB_USERNAME' => (string) $database['username'],
            'DB_PASSWORD' => (string) $database['password'],
            'DB_QUEUE_RETRY_AFTER' => '3',
            'QUEUE_CONNECTION' => 'database',
            'CACHE_STORE' => 'database',
            'CACHE_PREFIX' => config('cache.prefix'),
        ]);
        $process->setTimeout(10);

        return $process;
    }
}

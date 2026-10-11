<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Providers\Models\SportsDataProvider;
use App\Jobs\SyncProviderMatchesJob;
use Carbon\CarbonImmutable;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

final class ScheduledMatchSyncTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_dispatches_one_job_per_enabled_provider(): void
    {
        Queue::fake();
        $this->travelTo(now()->setDate(2026, 10, 11)->setTime(12, 0));
        $first = $this->createProvider('first', true);
        $second = $this->createProvider('second', true);
        $disabled = $this->createProvider('disabled', false);

        $this->artisan('sports:sync-matches')
            ->expectsOutput('Queued 2 provider sync jobs for 2026-10-11 (UTC).')
            ->assertSuccessful();

        Queue::assertPushed(SyncProviderMatchesJob::class, 2);
        foreach ([$first, $second] as $provider) {
            Queue::assertPushed(SyncProviderMatchesJob::class, fn (SyncProviderMatchesJob $job): bool => $job->providerId === $provider->id && $job->date === '2026-10-11');
        }
        Queue::assertNotPushed(SyncProviderMatchesJob::class, fn (SyncProviderMatchesJob $job): bool => $job->providerId === $disabled->id);
    }

    public function test_it_does_not_dispatch_when_no_provider_is_enabled(): void
    {
        Queue::fake();
        $this->createProvider('disabled', false);

        $this->artisan('sports:sync-matches')->assertSuccessful();

        Queue::assertNothingPushed();
    }

    public function test_the_database_connection_persists_a_job_without_running_synchronization_inline(): void
    {
        config(['queue.default' => 'database']);
        $this->createProvider('first', true);

        $this->artisan('sports:sync-matches')->assertSuccessful();

        $queued = DB::table('jobs')->sole();
        $payload = json_decode($queued->payload, true, flags: JSON_THROW_ON_ERROR);
        self::assertSame(SyncProviderMatchesJob::class, $payload['displayName']);
        self::assertSame(0, $queued->attempts);
        self::assertNull($queued->reserved_at);
        $this->assertDatabaseCount('matches', 0);
    }

    public function test_the_target_date_is_utc_even_when_the_application_date_is_tomorrow(): void
    {
        config(['app.timezone' => 'Europe/Athens']);
        $this->travelTo(CarbonImmutable::parse('2026-10-12T01:05:00+03:00'));
        Queue::fake();
        $provider = $this->createProvider('first', true);

        $this->artisan('sports:sync-matches')->assertSuccessful();

        Queue::assertPushed(SyncProviderMatchesJob::class, fn (SyncProviderMatchesJob $job): bool => $job->providerId === $provider->id && $job->date === '2026-10-11');
    }

    public function test_repeated_dispatch_is_allowed_for_the_same_provider_and_date(): void
    {
        Queue::fake();
        $this->freezeTime();
        $provider = $this->createProvider('first', true);

        $this->artisan('sports:sync-matches')->assertSuccessful();
        $this->artisan('sports:sync-matches')->assertSuccessful();

        Queue::assertPushed(SyncProviderMatchesJob::class, 2);
        Queue::assertPushed(SyncProviderMatchesJob::class, fn (SyncProviderMatchesJob $job): bool => $job->providerId === $provider->id && $job->date === now('UTC')->toDateString());
    }

    public function test_the_schedule_is_due_every_thirty_minutes_in_utc_with_dispatch_locks(): void
    {
        $events = collect(app(Schedule::class)->events())
            ->filter(fn (Event $event): bool => str_contains($event->command ?? '', 'sports:sync-matches'));
        self::assertCount(1, $events);
        $event = $events->first();
        self::assertSame('*/30 * * * *', $event->expression);
        self::assertSame('UTC', $event->timezone);
        self::assertTrue($event->withoutOverlapping);
        self::assertSame(5, $event->expiresAt);
        self::assertTrue($event->onOneServer);

        $this->travelTo(CarbonImmutable::parse('2026-10-11T23:30:00Z'));
        self::assertTrue($event->isDue($this->app));
        $this->travel(1)->minutes();
        self::assertFalse($event->isDue($this->app));
        $this->travel(29)->minutes();
        self::assertTrue($event->isDue($this->app));
    }

    private function createProvider(string $slug, bool $enabled): SportsDataProvider
    {
        return SportsDataProvider::query()->create([
            'name' => ucfirst($slug).' Provider',
            'slug' => $slug,
            'enabled' => $enabled,
        ]);
    }
}

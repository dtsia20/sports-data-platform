<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Providers\Models\SportsDataProvider;
use App\Domain\Teams\Models\Team;
use App\Infrastructure\SportsData\ApiFootball\ApiFootballAdapter;
use App\Infrastructure\SportsData\ApiFootball\ApiFootballUnavailable;
use App\Jobs\SyncProviderMatchesJob;
use DateTimeImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

final class ApiFootballSyncTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.api_football.key' => 'integration-key', 'cache.default' => 'array']);
        Http::preventStrayRequests();
    }

    private ?string $mappingPath = null;

    protected function tearDown(): void
    {
        if ($this->mappingPath !== null) {
            unlink($this->mappingPath);
        }
        parent::tearDown();
    }

    private function mappingPath(): string
    {
        if ($this->mappingPath === null) {
            $sample = json_decode(file_get_contents($this->samplePath()), true, flags: JSON_THROW_ON_ERROR);
            $this->mappingPath = tempnam(sys_get_temp_dir(), 'api-football-test-');
            file_put_contents($this->mappingPath, json_encode(['errors' => [], 'response' => [$sample['response'][0]]], JSON_THROW_ON_ERROR));
        }

        return $this->mappingPath;
    }

    private function samplePath(): string
    {
        return base_path('tests/Fixtures/api-football/fixtures-2026-10-11.json');
    }

    private function fakeFirstFixture(): void
    {
        $sample = json_decode(file_get_contents($this->samplePath()), true, flags: JSON_THROW_ON_ERROR);
        Http::fake(['*' => Http::response(['errors' => [], 'response' => [$sample['response'][0]]])]);
    }

    public function test_sample_mappings_and_manual_sync_are_idempotent_and_visible_through_the_api(): void
    {
        $this->artisan('sports:import-api-football-mappings', ['file' => $this->mappingPath()])->assertSuccessful();
        $teamCount = Team::count();
        $this->artisan('sports:import-api-football-mappings', ['file' => $this->mappingPath()])->assertSuccessful();
        $this->assertDatabaseCount('teams', $teamCount);
        $this->assertFalse(SportsDataProvider::where('slug', 'api-football')->sole()->enabled);
        $this->fakeFirstFixture();
        foreach ([1, 2] as $attempt) {
            $this->artisan('sports:sync-api-football', ['--date' => '2026-10-11'])
                ->expectsOutput('Processed 1; succeeded 1; unresolved 0.')->assertSuccessful();
        }
        $this->assertDatabaseCount('matches', 1);
        $this->assertDatabaseCount('provider_match_references', 1);
        $this->getJson('/api/v1/matches?date=2026-10-11')->assertOk()->assertJsonPath('data.0.score.home', 2);
        Http::assertSentCount(2);
    }

    public function test_unmapped_live_entities_are_recorded_and_skipped(): void
    {
        $this->fakeFirstFixture();
        $this->artisan('sports:sync-api-football', ['--date' => '2026-10-11'])
            ->expectsOutput('Processed 1; succeeded 0; unresolved 1.')->assertSuccessful();
        $this->assertDatabaseCount('matches', 0);
        $this->assertDatabaseCount('unresolved_provider_entities', 1);
    }

    public function test_manual_sync_respects_the_queue_overlap_lock(): void
    {
        $provider = SportsDataProvider::create(['slug' => 'api-football', 'name' => 'API-Football', 'enabled' => false]);
        $job = new SyncProviderMatchesJob($provider->id, '2026-10-11');
        $lock = Cache::lock($job->middleware()[0]->getLockKey($job), 90);
        $lock->get();
        try {
            $this->artisan('sports:sync-api-football', ['--date' => '2026-10-11'])->assertFailed();
            Http::assertNothingSent();
        } finally {
            $lock->release();
        }
    }

    public function test_daily_budget_counts_retries_and_prevents_more_requests(): void
    {
        config(['services.api_football.daily_limit' => 2]);
        Http::fake(['*' => Http::sequence()->push([], 503)->push(['errors' => [], 'response' => []])->whenEmpty(Http::response(['errors' => [], 'response' => []]))]);
        $adapter = new ApiFootballAdapter;
        $this->assertSame([], $adapter->getMatches(new DateTimeImmutable('2026-10-11')));
        try {
            $adapter->getMatches(new DateTimeImmutable('2026-10-11'));
            $this->fail('Budget must reject the next request.');
        } catch (ApiFootballUnavailable $exception) {
            $this->assertStringContainsString('budget exhausted', $exception->getMessage());
        }
        Http::assertSentCount(2);
        $this->travel(1)->days();
        $this->assertSame([], $adapter->getMatches(new DateTimeImmutable('2026-10-12')));
    }

    public function test_a_rate_limit_response_blocks_further_requests_today(): void
    {
        Http::fake(['*' => Http::response([], 429)]);
        try {
            (new ApiFootballAdapter)->getMatches(new DateTimeImmutable('2026-10-11'));
        } catch (RequestException $exception) {
            $this->assertSame(429, $exception->response->status());
        }
        $this->expectException(ApiFootballUnavailable::class);
        (new ApiFootballAdapter)->getMatches(new DateTimeImmutable('2026-10-11'));
    }

    public function test_live_dispatch_requires_both_provider_enablement_and_schedule_opt_in(): void
    {
        Queue::fake();
        $provider = SportsDataProvider::create(['slug' => 'api-football', 'name' => 'API-Football', 'enabled' => true]);
        config(['services.api_football.scheduled' => false]);
        $this->artisan('sports:sync-matches')->assertSuccessful();
        Queue::assertNothingPushed();
        config(['services.api_football.scheduled' => true]);
        $this->artisan('sports:sync-matches')->assertSuccessful();
        Queue::assertPushed(SyncProviderMatchesJob::class, fn ($job): bool => $job->providerId === $provider->id);
    }

    public function test_invalid_date_makes_no_request(): void
    {
        $this->artisan('sports:sync-api-football', ['--date' => 'not-a-date'])->assertFailed();
        Http::assertNothingSent();
    }

    public function test_authentication_failure_is_failed_without_queue_retries(): void
    {
        config(['queue.default' => 'database']);
        $provider = SportsDataProvider::create(['slug' => 'api-football', 'name' => 'API-Football', 'enabled' => true]);
        Http::fake(['*' => Http::response([], 401)]);
        SyncProviderMatchesJob::dispatch($provider->id, '2026-10-11');
        $this->artisan('queue:work', ['connection' => 'database', '--once' => true])->assertSuccessful();
        $this->assertDatabaseCount('jobs', 0);
        $this->assertDatabaseCount('failed_jobs', 1);
        Http::assertSentCount(1);
    }

    public function test_schedule_configuration_requires_key_and_explicit_opt_in(): void
    {
        config(['services.api_football.scheduled' => false]);
        $this->artisan('sports:api-football-schedule', ['state' => 'on'])->assertFailed();
        config(['services.api_football.scheduled' => true]);
        $this->artisan('sports:api-football-schedule', ['state' => 'on'])->assertSuccessful();
        $this->assertTrue(SportsDataProvider::where('slug', 'api-football')->sole()->enabled);
        $this->artisan('sports:api-football-schedule', ['state' => 'off'])->assertSuccessful();
        $this->assertFalse(SportsDataProvider::where('slug', 'api-football')->sole()->enabled);
    }
}

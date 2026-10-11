<?php

declare(strict_types=1);

namespace Tests\Feature\Infrastructure;

use App\Infrastructure\SportsData\ApiFootball\ApiFootballAdapter;
use DateTimeImmutable;
use Illuminate\Http\Client\Request;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

final class ApiFootballAdapterTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['services.api_football.key' => 'test-key']);
        Http::preventStrayRequests();
    }

    private function sample(): array
    {
        return json_decode(file_get_contents(base_path('tests/Fixtures/api-football/fixtures-2026-10-11.json')), true, flags: JSON_THROW_ON_ERROR);
    }

    public function test_it_maps_the_saved_response_and_sends_authentication_and_date(): void
    {
        $payload = $this->sample();
        Http::fake(['*' => Http::response($payload)]);

        $matches = (new ApiFootballAdapter)->getMatches(new DateTimeImmutable('2026-10-11'));

        $this->assertNotEmpty($payload['response']);
        $this->assertSame(count($payload['response']), $payload['results']);
        $this->assertCount(count($payload['response']), $matches);
        $first = $matches[0];
        $this->assertSame('1492408', $first->externalId);
        $this->assertSame('71', $first->competition->externalId);
        $this->assertSame('Serie A', $first->competition->name);
        $this->assertSame(['country' => 'Brazil', 'season' => 2026], $first->competition->context);
        $this->assertSame('Brazil', $first->homeTeam->context['competition_country']);
        $this->assertSame('71', $first->awayTeam->context['competition_external_id']);
        $this->assertSame('126', $first->homeTeam->externalId);
        $this->assertSame('Sao Paulo', $first->homeTeam->name);
        $this->assertSame('136', $first->awayTeam->externalId);
        $this->assertSame('Vitoria', $first->awayTeam->name);
        $this->assertSame('2026-10-11T00:00:00+00:00', $first->startsAt->format(DATE_ATOM));
        $this->assertSame('finished', $first->status);
        $this->assertSame(2, $first->homeScore);
        $this->assertSame(1, $first->awayScore);
        Http::assertSent(fn (Request $request): bool => $request->method() === 'GET'
            && str_starts_with($request->url(), 'https://v3.football.api-sports.io/fixtures?')
            && $request->hasHeader('x-apisports-key', 'test-key')
            && $request['date'] === '2026-10-11' && $request['timezone'] === 'UTC');
        Http::assertSentCount(1);
    }

    public static function statuses(): array
    {
        return [
            ['NS', 'scheduled'], ['HT', 'live'], ['FT', 'finished'],
            ['AET', 'finished'], ['PEN', 'finished'], ['PST', 'postponed'],
            ['CANC', 'cancelled'], ['INT', 'suspended'], ['ABD', 'abandoned'], ['WO', 'awarded'],
        ];
    }

    #[DataProvider('statuses')]
    public function test_it_normalizes_status_and_preserves_null_scores(string $external, string $expected): void
    {
        $fixture = $this->sample()['response'][0];
        $fixture['fixture']['status']['short'] = $external;
        $fixture['goals'] = ['home' => null, 'away' => null];
        Http::fake(['*' => Http::response(['errors' => [], 'response' => [$fixture]])]);
        $match = (new ApiFootballAdapter)->getMatches(new DateTimeImmutable('2026-10-11'))[0];
        $this->assertSame($expected, $match->status);
        $this->assertNull($match->homeScore);
        $this->assertNull($match->awayScore);
    }

    public function test_empty_results_are_valid(): void
    {
        Http::fake(['*' => Http::response(['errors' => [], 'response' => []])]);
        $this->assertSame([], (new ApiFootballAdapter)->getMatches(new DateTimeImmutable('2026-10-11')));
    }

    public function test_missing_key_fails_before_a_request(): void
    {
        config(['services.api_football.key' => '']);
        try {
            (new ApiFootballAdapter)->getMatches(new DateTimeImmutable('2026-10-11'));
            $this->fail('Missing key must fail.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('not configured', $exception->getMessage());
        }
        Http::assertNothingSent();
    }

    public function test_body_errors_are_not_treated_as_empty_results(): void
    {
        Http::fake(['*' => Http::response(['errors' => ['requests' => 'Quota exhausted'], 'response' => []])]);
        $this->expectException(RuntimeException::class);
        (new ApiFootballAdapter)->getMatches(new DateTimeImmutable('2026-10-11'));
    }

    public function test_malformed_fixtures_fail_validation(): void
    {
        Http::fake(['*' => Http::response(['errors' => [], 'response' => [['fixture' => ['id' => 1]]]])]);
        $this->expectException(ValidationException::class);
        (new ApiFootballAdapter)->getMatches(new DateTimeImmutable('2026-10-11'));
    }

    public function test_server_failure_is_retried_once(): void
    {
        Http::fake(['*' => Http::sequence()->push([], 503)->push(['errors' => [], 'response' => []])]);
        $this->assertSame([], (new ApiFootballAdapter)->getMatches(new DateTimeImmutable('2026-10-11')));
        Http::assertSentCount(2);
    }

    public function test_connection_failure_is_retried_once(): void
    {
        Http::fake(['*' => Http::sequence()->pushFailedConnection()->push(['errors' => [], 'response' => []])]);
        $this->assertSame([], (new ApiFootballAdapter)->getMatches(new DateTimeImmutable('2026-10-11')));
        Http::assertSentCount(2);
    }

    public static function failures(): array
    {
        return [[401, 1], [429, 1], [503, 2]];
    }

    #[DataProvider('failures')]
    public function test_http_failures_have_bounded_request_counts(int $status, int $count): void
    {
        Http::fake(['*' => Http::response([], $status)]);
        try {
            (new ApiFootballAdapter)->getMatches(new DateTimeImmutable('2026-10-11'));
            $this->fail('HTTP failure must throw.');
        } catch (RequestException $exception) {
            $this->assertSame($status, $exception->response->status());
        }
        Http::assertSentCount($count);
    }
}

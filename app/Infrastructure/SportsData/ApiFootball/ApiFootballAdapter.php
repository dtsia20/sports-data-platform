<?php

declare(strict_types=1);

namespace App\Infrastructure\SportsData\ApiFootball;

use App\Domain\Competitions\DTOs\CompetitionData;
use App\Domain\Matches\Contracts\SportsDataProviderInterface;
use App\Domain\Matches\DTOs\MatchData;
use App\Domain\Teams\DTOs\TeamData;
use DateTimeImmutable;
use Exception;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Validator;
use RuntimeException;

final class ApiFootballAdapter implements SportsDataProviderInterface
{
    public function getMatches(DateTimeImmutable $date): array
    {
        $key = config('services.api_football.key');

        if (! is_string($key) || trim($key) === '') {
            throw new RuntimeException('API-Football API key is not configured.');
        }

        $response = Http::baseUrl('https://v3.football.api-sports.io')
            ->acceptJson()
            ->withHeaders(['x-apisports-key' => $key])
            ->connectTimeout(3)
            ->timeout(10)
            ->retry(2, 200, function (Exception $exception): bool {
                return $exception instanceof ConnectionException
                    || ($exception instanceof RequestException && $exception->response->serverError());
            })
            ->get('/fixtures', ['date' => $date->format('Y-m-d'), 'timezone' => 'UTC'])
            ->throw();

        $payload = $response->json();

        if (! is_array($payload) || ! array_key_exists('errors', $payload) || $payload['errors'] !== []) {
            throw new RuntimeException('API-Football returned an invalid response or an API error.');
        }

        Validator::make($payload, [
            'response' => ['present', 'array'],
            'response.*.fixture.id' => ['required', 'integer'],
            'response.*.fixture.date' => ['required', 'date'],
            'response.*.fixture.status.short' => ['required', 'string'],
            'response.*.league.id' => ['required', 'integer'],
            'response.*.league.name' => ['required', 'string'],
            'response.*.teams.home.id' => ['required', 'integer'],
            'response.*.teams.home.name' => ['required', 'string'],
            'response.*.teams.away.id' => ['required', 'integer'],
            'response.*.teams.away.name' => ['required', 'string'],
            'response.*.goals.home' => ['present', 'nullable', 'integer', 'min:0'],
            'response.*.goals.away' => ['present', 'nullable', 'integer', 'min:0'],
        ])->validate();

        $matches = [];

        foreach ($payload['response'] as $fixture) {
            $status = match ($fixture['fixture']['status']['short']) {
                'TBD', 'NS' => 'scheduled',
                '1H', 'HT', '2H', 'ET', 'BT', 'P', 'LIVE' => 'live',
                'FT', 'AET', 'PEN' => 'finished',
                'PST' => 'postponed',
                'CANC' => 'cancelled',
                'SUSP', 'INT' => 'suspended',
                'ABD' => 'abandoned',
                'AWD', 'WO' => 'awarded',
                default => throw new RuntimeException('API-Football returned an unsupported fixture status.'),
            };

            $matches[] = new MatchData(
                externalId: (string) $fixture['fixture']['id'],
                competition: new CompetitionData((string) $fixture['league']['id'], $fixture['league']['name']),
                homeTeam: new TeamData((string) $fixture['teams']['home']['id'], $fixture['teams']['home']['name']),
                awayTeam: new TeamData((string) $fixture['teams']['away']['id'], $fixture['teams']['away']['name']),
                startsAt: new DateTimeImmutable($fixture['fixture']['date']),
                status: $status,
                homeScore: $fixture['goals']['home'],
                awayScore: $fixture['goals']['away'],
            );
        }

        return $matches;
    }
}

<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Application\Providers\Services\ProviderSyncServiceFactory;
use App\Domain\Providers\Models\SportsDataProvider;
use App\Infrastructure\SportsData\ApiFootball\ApiFootballUnavailable;
use DateTimeImmutable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Http\Client\RequestException;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\Log;

final class SyncProviderMatchesJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 10;

    public int $timeout = 60;

    public function backoff(): array
    {
        return [10, 30, 60];
    }

    public function __construct(
        public int $providerId,
        public string $date,
    ) {}

    public function handle(ProviderSyncServiceFactory $factory): void
    {
        $provider = SportsDataProvider::query()
            ->findOrFail($this->providerId);

        try {
            $report = $factory->forProvider($provider)->sync($provider, new DateTimeImmutable($this->date));
        } catch (ApiFootballUnavailable $exception) {
            $this->fail($exception);

            return;
        } catch (RequestException $exception) {
            if ($provider->slug === 'api-football' && $exception->response->clientError()) {
                $this->fail($exception);

                return;
            }
            throw $exception;
        }

        Log::info('Provider matches synchronized', [
            'providerId' => $this->providerId,
            'date' => $this->date,
            'processed' => $report->processed,
            'succeeded' => $report->succeeded,
            'unresolved' => $report->unresolved,
        ]);
    }

    /**
     * @return array<int, object>
     */
    public function middleware(): array
    {
        return [
            (new WithoutOverlapping(
                "provider-sync:{$this->providerId}:{$this->date}"
            ))
                ->releaseAfter(10)
                ->expireAfter(90),
        ];
    }
}

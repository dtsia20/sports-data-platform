<?php

declare(strict_types=1);

namespace Tests\Feature\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;

final class TimedOutJobFixture implements ShouldQueue
{
    use Queueable;

    public int $timeout = 1;

    public int $tries = 2;

    public function handle(): void
    {
        sleep(20);
    }

    /**
     * @return array<int, WithoutOverlapping>
     */
    public function middleware(): array
    {
        return [(new WithoutOverlapping('worker-lifecycle-timeout'))->expireAfter(2)];
    }
}

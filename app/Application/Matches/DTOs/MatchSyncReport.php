<?php

declare(strict_types=1);

namespace App\Application\Matches\DTOs;

final class MatchSyncReport
{
    public int $processed = 0;

    public int $succeeded = 0;

    public int $unresolved = 0;

    public function recordSuccess(): void
    {
        $this->processed++;
        $this->succeeded++;
    }

    public function recordUnresolved(): void
    {
        $this->processed++;
        $this->unresolved++;
    }
}

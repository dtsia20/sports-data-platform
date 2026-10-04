<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Domain\Matches\Models\SportsMatch;
use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\MatchResource;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

final class MatchController extends Controller
{
    public function __invoke(Request $request): AnonymousResourceCollection
    {
        $validated = $request->validate([
            'date' => [
                'sometimes',
                'date_format:Y-m-d',
            ],
        ]);

        $query = SportsMatch::query()
            ->with([
                'competition',
                'homeTeam',
                'awayTeam',
            ]);

        if (isset($validated['date'])) {
            $start = CarbonImmutable::createFromFormat(
                'Y-m-d',
                $validated['date'],
                'UTC'
            )->startOfDay();

            $end = $start->endOfDay();

            $query->whereBetween(
                'starts_at',
                [$start, $end]
            );
        }

        $matches = $query
            ->orderBy('starts_at')
            ->paginate(20);

        return MatchResource::collection($matches);
    }
}

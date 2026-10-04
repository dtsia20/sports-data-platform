<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Domain\Matches\Models\SportsMatch;
use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\MatchCollection;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;

final class MatchController extends Controller
{
    public function __invoke(Request $request): MatchCollection
    {
        $validated = $request->validate([
            'date' => [
                'sometimes',
                'date_format:Y-m-d',
            ],
            'competition' => [
                'sometimes',
                'string',
                'max:255',
            ],
            'status' => [
                'sometimes',
                'string',
                'max:50',
            ],
            'team' => [
                'sometimes',
                'string',
                'max:255',
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

        if (isset($validated['competition'])) {
            $query->whereHas(
                'competition',
                fn ($competitionQuery) => $competitionQuery->where(
                    'slug',
                    $validated['competition']
                )
            );
        }

        if (isset($validated['status'])) {
            $query->where(
                'status',
                $validated['status']
            );
        }

        if (isset($validated['team'])) {
            $team = $validated['team'];

            $query->where(function ($query) use ($team): void {
                $query
                    ->whereHas(
                        'homeTeam',
                        fn ($teamQuery) => $teamQuery->where('slug', $team)
                    )
                    ->orWhereHas(
                        'awayTeam',
                        fn ($teamQuery) => $teamQuery->where('slug', $team)
                    );
            });
        }

        $matches = $query
            ->orderBy('starts_at')
            ->paginate(20);

        return new MatchCollection($matches);
    }
}

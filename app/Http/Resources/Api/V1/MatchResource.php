<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1;

use App\Domain\Matches\Models\SportsMatch;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin SportsMatch
 */
final class MatchResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'startsAt' => $this->starts_at?->toIso8601String(),
            'status' => $this->status,
            'score' => [
                'home' => $this->home_score,
                'away' => $this->away_score,
            ],
            'competition' => [
                'id' => $this->competition->id,
                'name' => $this->competition->name,
                'slug' => $this->competition->slug,
            ],
            'homeTeam' => [
                'id' => $this->homeTeam->id,
                'name' => $this->homeTeam->name,
                'slug' => $this->homeTeam->slug,
            ],
            'awayTeam' => [
                'id' => $this->awayTeam->id,
                'name' => $this->awayTeam->name,
                'slug' => $this->awayTeam->slug,
            ],
        ];
    }
}

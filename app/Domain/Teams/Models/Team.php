<?php

declare(strict_types=1);

namespace App\Domain\Teams\Models;

use App\Domain\Matches\Models\SportsMatch;
use App\Domain\Providers\Models\ProviderTeamReference;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class Team extends Model
{
    protected $fillable = [
        'name',
        'slug',
    ];

    public function providerReferences(): HasMany
    {
        return $this->hasMany(
            ProviderTeamReference::class,
            'team_id'
        );
    }

    public function homeMatches(): HasMany
    {
        return $this->hasMany(
            SportsMatch::class,
            'home_team_id'
        );
    }

    public function awayMatches(): HasMany
    {
        return $this->hasMany(
            SportsMatch::class,
            'away_team_id'
        );
    }
}

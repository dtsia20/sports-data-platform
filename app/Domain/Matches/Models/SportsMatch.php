<?php

declare(strict_types=1);

namespace App\Domain\Matches\Models;

use App\Domain\Competitions\Models\Competition;
use App\Domain\Providers\Models\ProviderMatchReference;
use App\Domain\Teams\Models\Team;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class SportsMatch extends Model
{
    protected $table = 'matches';

    protected $fillable = [
        'competition_id',
        'home_team_id',
        'away_team_id',
        'starts_at',
        'status',
        'home_score',
        'away_score',
    ];

    protected $casts = [
        'starts_at' => 'immutable_datetime',
        'home_score' => 'integer',
        'away_score' => 'integer',
    ];

    public function competition(): BelongsTo
    {
        return $this->belongsTo(Competition::class);
    }

    public function homeTeam(): BelongsTo
    {
        return $this->belongsTo(
            Team::class,
            'home_team_id'
        );
    }

    public function awayTeam(): BelongsTo
    {
        return $this->belongsTo(
            Team::class,
            'away_team_id'
        );
    }

    public function providerReferences(): HasMany
    {
        return $this->hasMany(
            ProviderMatchReference::class,
            'match_id'
        );
    }
}
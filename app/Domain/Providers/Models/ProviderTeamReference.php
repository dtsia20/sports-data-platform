<?php

declare(strict_types=1);

namespace App\Domain\Providers\Models;

use App\Domain\Teams\Models\Team;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class ProviderTeamReference extends Model
{
    protected $fillable = [
        'provider_id',
        'team_id',
        'external_id',
    ];

    public function provider(): BelongsTo
    {
        return $this->belongsTo(
            SportsDataProvider::class,
            'provider_id'
        );
    }

    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }
}

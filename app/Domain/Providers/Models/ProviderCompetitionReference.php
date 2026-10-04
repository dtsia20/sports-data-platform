<?php

declare(strict_types=1);

namespace App\Domain\Providers\Models;

use App\Domain\Competitions\Models\Competition;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class ProviderCompetitionReference extends Model
{
    protected $fillable = [
        'provider_id',
        'competition_id',
        'external_id',
    ];

    public function provider(): BelongsTo
    {
        return $this->belongsTo(
            SportsDataProvider::class,
            'provider_id'
        );
    }

    public function competition(): BelongsTo
    {
        return $this->belongsTo(Competition::class);
    }
}
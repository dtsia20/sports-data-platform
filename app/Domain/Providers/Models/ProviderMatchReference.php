<?php

declare(strict_types=1);

namespace App\Domain\Providers\Models;

use App\Domain\Matches\Models\SportsMatch;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class ProviderMatchReference extends Model
{
    protected $fillable = [
        'provider_id',
        'match_id',
        'external_id',
    ];

    public function provider(): BelongsTo
    {
        return $this->belongsTo(
            SportsDataProvider::class,
            'provider_id'
        );
    }

    public function match(): BelongsTo
    {
        return $this->belongsTo(
            SportsMatch::class,
            'match_id'
        );
    }
}

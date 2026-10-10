<?php

declare(strict_types=1);

namespace App\Domain\Providers\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class UnresolvedProviderEntity extends Model
{
    protected $fillable = [
        'provider_id',
        'entity_type',
        'external_id',
        'external_name',
        'context',
        'status',
        'occurrences_count',
        'first_seen_at',
        'last_seen_at',
        'resolved_at',
    ];

    protected $casts = [
        'context' => 'array',
        'occurrences_count' => 'integer',
        'first_seen_at' => 'immutable_datetime',
        'last_seen_at' => 'immutable_datetime',
        'resolved_at' => 'immutable_datetime',
    ];

    public function provider(): BelongsTo
    {
        return $this->belongsTo(
            SportsDataProvider::class,
            'provider_id'
        );
    }
}

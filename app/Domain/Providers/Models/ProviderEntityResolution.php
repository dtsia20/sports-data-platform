<?php

declare(strict_types=1);

namespace App\Domain\Providers\Models;

use Illuminate\Database\Eloquent\Model;

final class ProviderEntityResolution extends Model
{
    protected $fillable = [
        'unresolved_provider_entity_id', 'entity_type', 'canonical_id',
        'reviewer', 'reason', 'external_name', 'context',
    ];

    protected function casts(): array
    {
        return ['canonical_id' => 'integer', 'context' => 'array'];
    }
}

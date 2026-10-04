<?php

declare(strict_types=1);

namespace App\Domain\Providers\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class SportsDataProvider extends Model
{
    protected $fillable = [
        'name',
        'slug',
        'enabled',
    ];

    protected $casts = [
        'enabled' => 'boolean',
    ];

    public function teamReferences(): HasMany
    {
        return $this->hasMany(ProviderTeamReference::class, 'provider_id');
    }

    public function competitionReferences(): HasMany
    {
        return $this->hasMany(ProviderCompetitionReference::class, 'provider_id');
    }

    public function matchReferences(): HasMany
    {
        return $this->hasMany(ProviderMatchReference::class, 'provider_id');
    }
}
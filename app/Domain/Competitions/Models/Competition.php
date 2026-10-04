<?php

declare(strict_types=1);

namespace App\Domain\Competitions\Models;

use App\Domain\Matches\Models\MatchModel;
use App\Domain\Providers\Models\ProviderCompetitionReference;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class Competition extends Model
{
    protected $fillable = [
        'name',
        'slug',
    ];

    public function providerReferences(): HasMany
    {
        return $this->hasMany(
            ProviderCompetitionReference::class,
            'competition_id'
        );
    }

    public function matches(): HasMany
    {
        return $this->hasMany(
            MatchModel::class,
            'competition_id'
        );
    }
}
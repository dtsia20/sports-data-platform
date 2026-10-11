<?php

declare(strict_types=1);

namespace App\Domain\Providers\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

final class ProviderEntityAlias extends Model
{
    protected $fillable = [
        'provider_id', 'team_id', 'competition_id', 'name', 'normalized_name',
        'reviewer', 'reason', 'revoked_at', 'revoked_by', 'revocation_reason',
    ];

    protected function casts(): array
    {
        return ['revoked_at' => 'immutable_datetime'];
    }

    public static function normalizeName(string $name): string
    {
        return trim(preg_replace('/[^a-z0-9]+/', ' ', Str::lower(Str::ascii($name))));
    }
}

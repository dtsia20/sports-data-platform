<?php

declare(strict_types=1);

namespace App\Application\Providers\Services;

use App\Domain\Competitions\Models\Competition;
use App\Domain\Providers\Models\ProviderEntityAlias;
use App\Domain\Providers\Models\SportsDataProvider;
use App\Domain\Teams\Models\Team;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use InvalidArgumentException;

final class CurateProviderEntityAliases
{
    public function add(SportsDataProvider $provider, string $type, int $canonicalId, string $name, string $reviewer, string $reason): ProviderEntityAlias
    {
        $name = trim($name);
        $reviewer = trim($reviewer);
        $reason = trim($reason);
        $normalizedName = ProviderEntityAlias::normalizeName($name);
        Validator::make(compact('name', 'normalizedName', 'reviewer', 'reason'), [
            'name' => 'required|string|max:255', 'normalizedName' => 'required|string|max:255',
            'reviewer' => 'required|string|max:255', 'reason' => 'required|string|max:2000',
        ])->validate();
        [$class, $foreignKey] = match ($type) {
            'team' => [Team::class, 'team_id'],
            'competition' => [Competition::class, 'competition_id'],
            default => throw new InvalidArgumentException('Alias type must be team or competition.'),
        };

        return DB::transaction(function () use ($provider, $class, $foreignKey, $canonicalId, $name, $normalizedName, $reviewer, $reason): ProviderEntityAlias {
            $class::query()->lockForUpdate()->findOrFail($canonicalId);
            $alias = ProviderEntityAlias::query()->firstOrCreate([
                'provider_id' => $provider->id, $foreignKey => $canonicalId, 'normalized_name' => $normalizedName,
            ], ['name' => $name, 'reviewer' => $reviewer, 'reason' => $reason]);
            if ($alias->revoked_at !== null) {
                throw new InvalidArgumentException('This alias was revoked; it cannot be silently reactivated.');
            }

            return $alias;
        });
    }

    public function revoke(int $id, string $reviewer, string $reason): ProviderEntityAlias
    {
        $reviewer = trim($reviewer);
        $reason = trim($reason);
        Validator::make(compact('reviewer', 'reason'), ['reviewer' => 'required|string|max:255', 'reason' => 'required|string|max:2000'])->validate();

        return DB::transaction(function () use ($id, $reviewer, $reason): ProviderEntityAlias {
            $alias = ProviderEntityAlias::query()->lockForUpdate()->findOrFail($id);
            if ($alias->revoked_at === null) {
                $alias->update(['revoked_at' => now(), 'revoked_by' => $reviewer, 'revocation_reason' => $reason]);
            }

            return $alias;
        });
    }
}

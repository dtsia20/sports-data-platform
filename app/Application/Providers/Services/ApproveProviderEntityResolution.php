<?php

declare(strict_types=1);

namespace App\Application\Providers\Services;

use App\Domain\Competitions\Models\Competition;
use App\Domain\Providers\Models\ProviderCompetitionReference;
use App\Domain\Providers\Models\ProviderEntityResolution;
use App\Domain\Providers\Models\ProviderTeamReference;
use App\Domain\Providers\Models\UnresolvedProviderEntity;
use App\Domain\Teams\Models\Team;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use InvalidArgumentException;

final class ApproveProviderEntityResolution
{
    public function approve(int $unresolvedId, int $canonicalId, string $reviewer, string $reason): ProviderEntityResolution
    {
        $reviewer = trim($reviewer);
        $reason = trim($reason);
        Validator::make(compact('canonicalId', 'reviewer', 'reason'), [
            'canonicalId' => 'required|integer|min:1',
            'reviewer' => 'required|string|max:255',
            'reason' => 'required|string|max:2000',
        ])->validate();

        return DB::transaction(function () use ($unresolvedId, $canonicalId, $reviewer, $reason): ProviderEntityResolution {
            $entity = UnresolvedProviderEntity::query()->lockForUpdate()->findOrFail($unresolvedId);
            [$canonicalClass, $referenceClass, $foreignKey] = match ($entity->entity_type) {
                'team' => [Team::class, ProviderTeamReference::class, 'team_id'],
                'competition' => [Competition::class, ProviderCompetitionReference::class, 'competition_id'],
                default => throw new InvalidArgumentException('Only teams and competitions can be reviewed.'),
            };
            $canonicalClass::query()->sharedLock()->findOrFail($canonicalId);
            $existingDecision = ProviderEntityResolution::query()->where('unresolved_provider_entity_id', $entity->id)->first();
            if ($existingDecision !== null) {
                if ($existingDecision->canonical_id !== $canonicalId) {
                    throw new InvalidArgumentException('This entity was already approved for another canonical record.');
                }

                return $existingDecision;
            }
            if ($entity->status !== 'pending') {
                throw new InvalidArgumentException('Only pending entities can be approved.');
            }
            $reference = $referenceClass::query()->where('provider_id', $entity->provider_id)->where('external_id', $entity->external_id)->first();
            if ($reference !== null && (int) $reference->{$foreignKey} !== $canonicalId) {
                throw new InvalidArgumentException('An existing provider mapping points to another canonical record.');
            }
            if ($reference === null) {
                if ($referenceClass::query()->where('provider_id', $entity->provider_id)->where($foreignKey, $canonicalId)->exists()) {
                    throw new InvalidArgumentException('This provider already maps another external ID to that canonical record.');
                }
                $referenceClass::query()->create([
                    'provider_id' => $entity->provider_id,
                    'external_id' => $entity->external_id,
                    $foreignKey => $canonicalId,
                ]);
            }
            $decision = ProviderEntityResolution::query()->create([
                'unresolved_provider_entity_id' => $entity->id,
                'entity_type' => $entity->entity_type,
                'canonical_id' => $canonicalId,
                'reviewer' => $reviewer,
                'reason' => $reason,
                'external_name' => $entity->external_name,
                'context' => $entity->context,
            ]);
            $entity->update(['status' => 'resolved', 'resolved_at' => now()]);

            return $decision;
        });
    }
}

<?php

declare(strict_types=1);

namespace App\Application\Teams\Services;

use App\Application\Providers\Services\RecordUnresolvedEntityService;
use App\Application\Teams\Exceptions\TeamMappingNotFound;
use App\Domain\Providers\Models\ProviderTeamReference;
use App\Domain\Providers\Models\SportsDataProvider;
use App\Domain\Teams\DTOs\TeamData;
use App\Domain\Teams\Models\Team;

final readonly class ResolveTeamService
{
    public function __construct(
        private RecordUnresolvedEntityService $unresolvedRecorder,
    ) {}

    public function resolve(
        SportsDataProvider $provider,
        TeamData $teamData,
    ): Team {
        $reference = ProviderTeamReference::query()
            ->with('team')
            ->where('provider_id', $provider->id)
            ->where('external_id', $teamData->externalId)
            ->first();

        if ($reference === null) {
            $this->unresolvedRecorder->record(
                provider: $provider,
                entityType: 'team',
                externalId: $teamData->externalId,
                externalName: $teamData->name,
            );

            throw TeamMappingNotFound::forExternalId(
                $teamData->externalId
            );
        }

        return $reference->team;
    }
}

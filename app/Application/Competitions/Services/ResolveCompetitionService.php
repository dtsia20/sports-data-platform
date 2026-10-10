<?php

declare(strict_types=1);

namespace App\Application\Competitions\Services;

use App\Application\Competitions\Exceptions\CompetitionMappingNotFound;
use App\Application\Providers\Services\RecordUnresolvedEntityService;
use App\Domain\Competitions\DTOs\CompetitionData;
use App\Domain\Competitions\Models\Competition;
use App\Domain\Providers\Models\ProviderCompetitionReference;
use App\Domain\Providers\Models\SportsDataProvider;

final readonly class ResolveCompetitionService
{
    public function __construct(
        private RecordUnresolvedEntityService $unresolvedRecorder,
    ) {}

    public function resolve(
        SportsDataProvider $provider,
        CompetitionData $competitionData,
    ): Competition {
        $reference = ProviderCompetitionReference::query()
            ->with('competition')
            ->where('provider_id', $provider->id)
            ->where('external_id', $competitionData->externalId)
            ->first();

        if ($reference === null) {
            $this->unresolvedRecorder->record(
                provider: $provider,
                entityType: 'competition',
                externalId: $competitionData->externalId,
                externalName: $competitionData->name,
            );

            throw CompetitionMappingNotFound::forExternalId(
                $competitionData->externalId
            );
        }

        return $reference->competition;
    }
}

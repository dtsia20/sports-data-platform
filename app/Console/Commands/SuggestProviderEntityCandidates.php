<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Application\Providers\Services\SuggestProviderEntityCandidates as CandidateService;
use App\Domain\Providers\Models\UnresolvedProviderEntity;
use Illuminate\Console\Command;
use InvalidArgumentException;

final class SuggestProviderEntityCandidates extends Command
{
    protected $signature = 'sports:entity-candidates {unresolved : Pending entity ID} {--limit=10 : Maximum suggestions, up to 20}';

    protected $description = 'Rank canonical candidates with evidence; no mappings are changed';

    public function handle(CandidateService $service): int
    {
        $id = filter_var($this->argument('unresolved'), FILTER_VALIDATE_INT);
        $limit = filter_var($this->option('limit'), FILTER_VALIDATE_INT);
        if ($id === false || $id < 1 || $limit === false || $limit < 1 || $limit > 20) {
            $this->error('Use a positive entity ID and a limit between 1 and 20.');

            return self::FAILURE;
        }
        $entity = UnresolvedProviderEntity::query()->find($id);
        if ($entity === null || $entity->status !== 'pending') {
            $this->error('Pending entity not found.');

            return self::FAILURE;
        }
        try {
            $candidates = $service->suggest($entity, $limit);
        } catch (InvalidArgumentException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }
        $this->info("Candidates for {$entity->entity_type} {$entity->external_name}; explicit review required.");
        if ($candidates === []) {
            $this->info('No candidates met the name-evidence threshold.');
        } else {
            $this->table(['Canonical ID', 'Name', 'Score', 'Confidence', 'Evidence'], array_map(fn (array $candidate): array => [
                $candidate['id'], $candidate['name'], $candidate['score'], $candidate['confidence'], implode("\n", $candidate['evidence']),
            ], $candidates));
        }

        return self::SUCCESS;
    }
}

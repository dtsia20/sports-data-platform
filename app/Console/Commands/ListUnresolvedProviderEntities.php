<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Providers\Models\UnresolvedProviderEntity;
use Illuminate\Console\Command;

final class ListUnresolvedProviderEntities extends Command
{
    protected $signature = 'sports:unresolved {--provider= : Provider slug} {--limit=20 : Maximum pending rows, up to 100}';

    protected $description = 'Show pending provider entities and their latest context for manual review';

    public function handle(): int
    {
        $limit = filter_var($this->option('limit'), FILTER_VALIDATE_INT);
        if ($limit === false || $limit < 1 || $limit > 100) {
            $this->error('Limit must be between 1 and 100.');

            return self::FAILURE;
        }
        $query = UnresolvedProviderEntity::query()->with('provider')->where('status', 'pending')->orderBy('id');
        if ($this->option('provider')) {
            $query->whereHas('provider', fn ($provider) => $provider->where('slug', $this->option('provider')));
        }
        $this->table(['ID', 'Provider', 'Type', 'External ID', 'Name', 'Occurrences', 'Latest context'],
            $query->limit($limit)->get()->map(fn (UnresolvedProviderEntity $entity): array => [
                $entity->id, $entity->provider->slug, $entity->entity_type, $entity->external_id,
                $entity->external_name, $entity->occurrences_count, json_encode($entity->context, JSON_UNESCAPED_UNICODE),
            ])->all());

        return self::SUCCESS;
    }
}

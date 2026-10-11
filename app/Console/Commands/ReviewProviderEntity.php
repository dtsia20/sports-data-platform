<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Application\Providers\Services\ApproveProviderEntityResolution;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

final class ReviewProviderEntity extends Command
{
    protected $signature = 'sports:resolve-entity {unresolved : Pending entity ID} {canonical : Existing team or competition ID} {--reviewer= : Reviewer label} {--reason= : Evidence supporting the mapping}';

    protected $description = 'Approve an explicit provider mapping and record the review decision';

    public function handle(ApproveProviderEntityResolution $service): int
    {
        if (! ctype_digit((string) $this->argument('unresolved')) || ! ctype_digit((string) $this->argument('canonical'))) {
            $this->error('Entity IDs must be positive integers.');

            return self::FAILURE;
        }
        try {
            $decision = $service->approve((int) $this->argument('unresolved'), (int) $this->argument('canonical'), (string) $this->option('reviewer'), (string) $this->option('reason'));
        } catch (ValidationException|ModelNotFoundException|InvalidArgumentException $exception) {
            $this->error($exception instanceof ModelNotFoundException ? 'Pending entity or canonical record not found.' : $exception->getMessage());

            return self::FAILURE;
        } catch (QueryException) {
            $this->error('Mapping could not be approved because of a database conflict. No partial review was saved.');

            return self::FAILURE;
        }
        $this->info("Approved {$decision->entity_type} mapping; audit decision {$decision->id}. Rerun synchronization to import skipped matches.");

        return self::SUCCESS;
    }
}

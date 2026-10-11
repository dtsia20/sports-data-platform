<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Application\Providers\Services\CurateProviderEntityAliases;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Validation\ValidationException;

final class RevokeProviderEntityAlias extends Command
{
    protected $signature = 'sports:revoke-entity-alias {alias : Alias ID} {--reviewer=} {--reason=}';

    protected $description = 'Revoke an alternative-name suggestion while retaining its evidence';

    public function handle(CurateProviderEntityAliases $service): int
    {
        $id = filter_var($this->argument('alias'), FILTER_VALIDATE_INT);
        if ($id === false || $id < 1) {
            $this->error('Alias ID must be a positive integer.');

            return self::FAILURE;
        }
        try {
            $alias = $service->revoke($id, (string) $this->option('reviewer'), (string) $this->option('reason'));
        } catch (ModelNotFoundException|ValidationException $exception) {
            $this->error($exception instanceof ModelNotFoundException ? 'Alias not found.' : $exception->getMessage());

            return self::FAILURE;
        }
        $this->info("Revoked alias {$alias->id}; existing mappings are unchanged.");

        return self::SUCCESS;
    }
}

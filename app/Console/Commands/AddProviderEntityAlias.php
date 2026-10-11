<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Application\Providers\Services\CurateProviderEntityAliases;
use App\Domain\Providers\Models\SportsDataProvider;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

final class AddProviderEntityAlias extends Command
{
    protected $signature = 'sports:add-entity-alias {provider : Provider slug} {type : team or competition} {canonical : Canonical ID} {alias : Verified alternative name} {--reviewer=} {--reason=}';

    protected $description = 'Curate a provider-scoped alternative name for candidate suggestions';

    public function handle(CurateProviderEntityAliases $service): int
    {
        $id = filter_var($this->argument('canonical'), FILTER_VALIDATE_INT);
        if ($id === false || $id < 1) {
            $this->error('Canonical ID must be a positive integer.');

            return self::FAILURE;
        }
        try {
            $provider = SportsDataProvider::query()->where('slug', $this->argument('provider'))->firstOrFail();
            $alias = $service->add($provider, $this->argument('type'), $id, $this->argument('alias'), (string) $this->option('reviewer'), (string) $this->option('reason'));
        } catch (ModelNotFoundException|ValidationException|InvalidArgumentException $exception) {
            $this->error($exception instanceof ModelNotFoundException ? 'Provider or canonical entity not found.' : $exception->getMessage());

            return self::FAILURE;
        } catch (QueryException) {
            $this->error('Alias could not be saved because of a database conflict.');

            return self::FAILURE;
        }
        $this->info("Saved alias {$alias->id}. Provider references and canonical names are unchanged.");

        return self::SUCCESS;
    }
}

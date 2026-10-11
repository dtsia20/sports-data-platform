<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Competitions\Models\Competition;
use App\Domain\Providers\Models\ProviderCompetitionReference;
use App\Domain\Providers\Models\ProviderTeamReference;
use App\Domain\Providers\Models\SportsDataProvider;
use App\Domain\Providers\Models\UnresolvedProviderEntity;
use App\Domain\Teams\Models\Team;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

final class ImportApiFootballMappings extends Command
{
    protected $signature = 'sports:import-api-football-mappings {file : Saved fixture-response JSON path}';

    protected $description = 'Explicitly create provider-scoped lesson entities and mappings from a saved API-Football response';

    public function handle(): int
    {
        $path = $this->argument('file');
        if (! is_file($path) || ! is_readable($path)) {
            $this->error('The sample file must exist and be readable.');

            return self::FAILURE;
        }
        $payload = json_decode(file_get_contents($path), true);
        if (! is_array($payload) || ($payload['errors'] ?? null) !== []) {
            $this->error('Expected a successful API-Football fixture response.');

            return self::FAILURE;
        }
        $rules = ['response' => 'present|array'];
        foreach (['league', 'teams.home', 'teams.away'] as $entity) {
            $rules["response.*.{$entity}.id"] = 'required|integer|min:1';
            $rules["response.*.{$entity}.name"] = 'required|string|max:255';
        }
        if (Validator::make($payload, $rules)->fails()) {
            $this->error('The sample contains invalid entity fields.');

            return self::FAILURE;
        }
        DB::transaction(function () use ($payload): void {
            $provider = SportsDataProvider::query()->firstOrCreate(['slug' => 'api-football'], ['name' => 'API-Football', 'enabled' => false]);
            foreach ($payload['response'] as $fixture) {
                $league = $fixture['league'];
                if (! ProviderCompetitionReference::query()->where('provider_id', $provider->id)->where('external_id', (string) $league['id'])->exists()) {
                    $competition = Competition::query()->firstOrCreate(['slug' => 'api-football-league-'.$league['id']], ['name' => $league['name']]);
                    ProviderCompetitionReference::query()->create(['provider_id' => $provider->id, 'external_id' => (string) $league['id'], 'competition_id' => $competition->id]);
                }
                foreach ([$fixture['teams']['home'], $fixture['teams']['away']] as $entity) {
                    if (! ProviderTeamReference::query()->where('provider_id', $provider->id)->where('external_id', (string) $entity['id'])->exists()) {
                        $team = Team::query()->firstOrCreate(['slug' => 'api-football-team-'.$entity['id']], ['name' => $entity['name']]);
                        ProviderTeamReference::query()->create(['provider_id' => $provider->id, 'external_id' => (string) $entity['id'], 'team_id' => $team->id]);
                    }
                }
            }

            foreach (['competition' => ProviderCompetitionReference::class, 'team' => ProviderTeamReference::class] as $entityType => $referenceModel) {
                UnresolvedProviderEntity::query()
                    ->where('provider_id', $provider->id)
                    ->where('entity_type', $entityType)
                    ->where('status', 'pending')
                    ->whereIn('external_id', $referenceModel::query()->select('external_id')->where('provider_id', $provider->id))
                    ->update(['status' => 'resolved', 'resolved_at' => now()]);
            }
        });
        $this->info('Imported API-Football lesson mappings. Existing mappings were preserved; live scheduling remains opt-in.');

        return self::SUCCESS;
    }
}

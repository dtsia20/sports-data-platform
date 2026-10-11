<?php

declare(strict_types=1);

namespace App\Application\Providers\Services;

use App\Domain\Competitions\Models\Competition;
use App\Domain\Providers\Models\ProviderCompetitionReference;
use App\Domain\Providers\Models\ProviderEntityAlias;
use App\Domain\Providers\Models\UnresolvedProviderEntity;
use App\Domain\Teams\Models\Team;
use InvalidArgumentException;

final class SuggestProviderEntityCandidates
{
    /**
     * @return list<array{id: int, name: string, score: int, confidence: string, evidence: list<string>}>
     */
    public function suggest(UnresolvedProviderEntity $entity, int $limit = 10): array
    {
        if ($limit < 1 || $limit > 20) {
            throw new InvalidArgumentException('Limit must be between 1 and 20.');
        }
        $class = match ($entity->entity_type) {
            'team' => Team::class,
            'competition' => Competition::class,
            default => throw new InvalidArgumentException('Only teams and competitions have candidates.'),
        };
        if ($entity->status !== 'pending') {
            return [];
        }
        $name = ProviderEntityAlias::normalizeName($entity->external_name);
        if ($name === '') {
            return [];
        }
        $query = $class::query()->whereDoesntHave('providerReferences', fn ($references) => $references->where('provider_id', $entity->provider_id));
        $query->with(['providerAliases' => fn ($aliases) => $aliases->where('provider_id', $entity->provider_id)->whereNull('revoked_at')->where('normalized_name', $name)]);
        $competitionId = null;
        if ($entity->entity_type === 'team') {
            $externalCompetitionId = $entity->context['competition_external_id'] ?? null;
            if (is_string($externalCompetitionId) && $externalCompetitionId !== '') {
                $competitionId = ProviderCompetitionReference::query()->where('provider_id', $entity->provider_id)
                    ->where('external_id', $externalCompetitionId)->value('competition_id');
            }
            if ($competitionId !== null) {
                $query->withExists([
                    'homeMatches as observed_home' => fn ($matches) => $matches->where('competition_id', $competitionId),
                    'awayMatches as observed_away' => fn ($matches) => $matches->where('competition_id', $competitionId),
                ]);
            }
        }
        $candidates = [];
        $scoreCounts = [];
        foreach ($query->lazyById(250) as $canonical) {
            $canonicalName = ProviderEntityAlias::normalizeName($canonical->name);
            $exact = $name === $canonicalName;
            $alias = $canonical->providerAliases->first();
            $score = ($exact || $alias !== null) ? 50 : $this->tokenScore($name, $canonicalName);
            if ($score < 25) {
                continue;
            }
            $evidence = [$exact ? 'Exact normalized name (+50).' : ($alias !== null ? "Curated provider alias {$alias->id}: {$alias->name} (+50); reviewer {$alias->reviewer}; evidence: {$alias->reason}" : "Shared name tokens (+{$score}).")];
            if ($competitionId !== null && ($canonical->observed_home || $canonical->observed_away)) {
                $score += 40;
                $evidence[] = "Observed in matches for mapped competition {$competitionId} (+40); current season membership unverified.";
            } else {
                $evidence[] = 'No corroborating mapped-competition match history.';
            }
            $evidence[] = 'Canonical country/season are unavailable; no country or season points awarded.';
            $scoreCounts[$score] = ($scoreCounts[$score] ?? 0) + 1;
            $candidates[] = ['id' => $canonical->id, 'name' => $canonical->name, 'score' => $score,
                'confidence' => $score >= 90 ? 'strong' : ($score >= 65 ? 'tentative' : 'weak'), 'evidence' => $evidence];
            usort($candidates, fn (array $left, array $right): int => ($right['score'] <=> $left['score']) ?: ($left['id'] <=> $right['id']));
            $candidates = array_slice($candidates, 0, $limit);
        }
        foreach ($candidates as &$candidate) {
            if ($scoreCounts[$candidate['score']] > 1) {
                $candidate['confidence'] = 'ambiguous';
                $candidate['evidence'][] = 'Other candidates have the same score; the ID tie-break does not indicate identity.';
            }
            $candidate['evidence'][] = 'Explicit review required; score is a heuristic, not a probability.';
        }
        unset($candidate);

        return $candidates;
    }

    private function tokenScore(string $name, string $canonicalName): int
    {
        if ($canonicalName === '') {
            return 0;
        }
        $left = array_unique(explode(' ', $name));
        $right = array_unique(explode(' ', $canonicalName));
        $shared = count(array_intersect($left, $right));

        return (int) round(50 * $shared / count(array_unique([...$left, ...$right])));
    }
}

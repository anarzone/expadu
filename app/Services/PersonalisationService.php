<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Cosine-similarity ANN search over content embeddings, with an MMR
 * (Maximal Marginal Relevance) re-rank to avoid surfacing near-duplicates.
 *
 * Phase 2 entry point. Returns content IDs ranked first by closeness to
 * the user's preference_vector, then re-ranked to penalise items too
 * similar to already-picked items. The ContextFilter (in HomeFeedComposer)
 * then filters by opening_hours / distance / cooldown before display.
 *
 * Storage: pgvector `<=>` cosine-distance operator. ivfflat indexes are
 * created post-backfill — until then this falls back to seq scan, which
 * is fine for sub-10k rows.
 */
class PersonalisationService
{
    /**
     * MMR diversity weight. 1.0 = pure relevance (raw ANN order); 0.0 =
     * pure novelty (worst relevance with maximum spread). 0.65 leans
     * relevance-first while still rejecting "Cafe Nova" + "Café Nova"
     * style near-duplicates.
     */
    private const MMR_LAMBDA = 0.65;

    /**
     * Pool size multiplier — fetch this much × k candidates to give MMR
     * room to discard near-duplicates. Bigger = more diversity but more
     * embedding parsing CPU.
     */
    private const MMR_POOL_MULTIPLIER = 3;

    /** @return list<int> */
    public function recommendSpots(User $user, int $k = 10): array
    {
        return $this->recommend('spots', $user, $k);
    }

    /** @return list<int> */
    public function recommendEvents(User $user, int $k = 10, ?\DateTimeInterface $afterStartsAt = null): array
    {
        return $this->recommend('events', $user, $k, function ($q) use ($afterStartsAt) {
            $q->where('starts_at', '>', $afterStartsAt ?? now());
        });
    }

    /** @return list<int> */
    public function recommendCityNews(User $user, int $k = 5): array
    {
        return $this->recommend('city_news', $user, $k, function ($q) {
            $q->where(function ($qq) {
                $qq->whereNull('expires_at')->orWhere('expires_at', '>', now());
            });
        });
    }

    /**
     * @param  callable(Builder): void|null  $extraConstraints
     * @return list<int>
     */
    private function recommend(string $table, User $user, int $k, ?callable $extraConstraints = null): array
    {
        if (! $user->preference_vector) {
            return [];
        }

        $userVec = self::parseLiteral(self::ensureLiteral($user->preference_vector));
        if ($userVec === null) {
            return [];
        }

        $literal = self::ensureLiteral($user->preference_vector);
        $poolSize = max($k, $k * self::MMR_POOL_MULTIPLIER);

        $query = DB::table($table)
            ->whereNotNull('embedding')
            ->orderByRaw("embedding <=> '{$literal}'::vector")
            ->limit($poolSize)
            ->select(['id', 'embedding']);

        if ($extraConstraints) {
            $extraConstraints($query);
        }

        $rows = $query->get();
        if ($rows->isEmpty()) {
            return [];
        }

        $candidates = [];
        foreach ($rows as $row) {
            $vec = self::parseLiteral((string) $row->embedding);
            if ($vec === null) {
                continue;
            }
            $candidates[] = ['id' => (int) $row->id, 'vec' => $vec];
        }

        if (empty($candidates)) {
            return [];
        }

        return $this->applyMmr($candidates, $userVec, $k);
    }

    /**
     * Maximal Marginal Relevance re-rank.
     *
     * For each pick: argmax over remaining candidates of
     *   λ × sim(item, user) − (1−λ) × max_picked sim(item, picked)
     *
     * Embeddings from sentence-transformers/all-MiniLM-L6-v2 are L2-
     * normalised, so cosine similarity is just the dot product.
     *
     * @param  list<array{id: int, vec: list<float>}>  $candidates
     * @param  list<float>  $userVec
     * @return list<int>
     */
    private function applyMmr(array $candidates, array $userVec, int $k): array
    {
        // Precompute similarity to user vector once.
        foreach ($candidates as $i => $c) {
            $candidates[$i]['sim_user'] = self::dot($c['vec'], $userVec);
        }

        $picked = [];
        $pickedIds = [];

        while (count($picked) < $k && ! empty($candidates)) {
            $bestIdx = -1;
            $bestScore = -INF;

            foreach ($candidates as $i => $c) {
                $maxSimToPicked = 0.0;
                foreach ($picked as $p) {
                    $sim = self::dot($c['vec'], $p['vec']);
                    if ($sim > $maxSimToPicked) {
                        $maxSimToPicked = $sim;
                    }
                }

                $mmr = self::MMR_LAMBDA * $c['sim_user'] - (1.0 - self::MMR_LAMBDA) * $maxSimToPicked;

                if ($mmr > $bestScore) {
                    $bestScore = $mmr;
                    $bestIdx = $i;
                }
            }

            if ($bestIdx === -1) {
                break;
            }

            $picked[] = $candidates[$bestIdx];
            $pickedIds[] = $candidates[$bestIdx]['id'];
            unset($candidates[$bestIdx]);
            $candidates = array_values($candidates);
        }

        return $pickedIds;
    }

    /**
     * Cosine similarity for L2-normalised vectors == dot product.
     *
     * @param  list<float>  $a
     * @param  list<float>  $b
     */
    private static function dot(array $a, array $b): float
    {
        $sum = 0.0;
        $n = min(count($a), count($b));
        for ($i = 0; $i < $n; $i++) {
            $sum += $a[$i] * $b[$i];
        }

        return $sum;
    }

    /**
     * Parse a pgvector literal '[0.1,0.2,...]' into a list<float>.
     *
     * @return list<float>|null
     */
    private static function parseLiteral(string $literal): ?array
    {
        $literal = trim($literal, "[] \n\r\t");
        if ($literal === '') {
            return null;
        }
        $parts = explode(',', $literal);

        return array_map('floatval', $parts);
    }

    /**
     * Accept either a pgvector literal '[…]' string (selected from raw SQL)
     * or an already-formatted string.
     */
    private static function ensureLiteral(mixed $stored): string
    {
        if (is_array($stored)) {
            return EmbeddingService::toLiteral(array_map('floatval', $stored));
        }

        $s = (string) $stored;
        if ($s !== '' && $s[0] === '[') {
            return $s;
        }

        // Postgres returns vectors as strings like "[0.1,0.2,...]" via Laravel's PDO
        return $s;
    }
}

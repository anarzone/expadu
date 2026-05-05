<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Cosine-similarity ANN search over content embeddings.
 *
 * Phase 2 entry point. Returns content IDs ranked by closeness to the
 * user's preference_vector. The ContextFilter (in HomeFeedComposer) then
 * filters by opening_hours / distance / weather / cooldown before display.
 *
 * Storage: pgvector `<=>` cosine-distance operator. ivfflat indexes are
 * created post-backfill — until then, this falls back to seq scan, which
 * is fine for sub-10k rows.
 */
class PersonalisationService
{
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

        $vector = self::ensureLiteral($user->preference_vector);

        $query = DB::table($table)
            ->whereNotNull('embedding')
            ->orderByRaw("embedding <=> '{$vector}'::vector")
            ->limit($k)
            ->select(['id']);

        if ($extraConstraints) {
            $extraConstraints($query);
        }

        return $query->pluck('id')->map(fn ($v) => (int) $v)->all();
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

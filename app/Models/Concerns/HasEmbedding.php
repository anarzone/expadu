<?php

namespace App\Models\Concerns;

use App\Services\EmbeddingService;
use Illuminate\Support\Facades\DB;

/**
 * Embedding helpers for content models. Each model defines
 * `embeddingText(): string` returning the text to vectorize.
 *
 * Storage: writes via raw SQL because Laravel's grammar does not
 * understand pgvector's vector(N) literal binding.
 */
trait HasEmbedding
{
    /**
     * Source text for embedding. Override per model to combine title,
     * description, tags, etc. into a single coherent string.
     */
    abstract public function embeddingText(): string;

    public function refreshEmbedding(EmbeddingService $service): bool
    {
        $text = trim($this->embeddingText());
        if ($text === '') {
            return false;
        }

        $hash = EmbeddingService::hashFor($text);
        if (($this->embedding_hash ?? null) === $hash && ! is_null($this->embedding)) {
            return false; // already up to date
        }

        $vec = $service->embed($text);
        if ($vec === null) {
            return false;
        }

        DB::table($this->getTable())
            ->where($this->getKeyName(), $this->getKey())
            ->update([
                'embedding' => DB::raw("'".EmbeddingService::toLiteral($vec)."'::vector"),
                'embedding_hash' => $hash,
            ]);

        $this->embedding_hash = $hash;

        return true;
    }
}

<?php

namespace App\Services;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * HTTP wrapper around the local Python sentence-transformers sidecar.
 *
 * The sidecar (services/embedding/) exposes /embed and /embed/batch.
 * On any failure the service returns null — callers must handle that
 * case (skip insert / retry later) and never persist a stale vector.
 */
class EmbeddingService
{
    public function __construct(
        private readonly string $baseUrl,
        private readonly int $timeoutSec,
        private readonly int $dim,
    ) {}

    public function dim(): int
    {
        return $this->dim;
    }

    /** @return list<float>|null */
    public function embed(string $text): ?array
    {
        $text = trim($text);
        if ($text === '') {
            return null;
        }

        try {
            $response = Http::timeout($this->timeoutSec)
                ->acceptJson()
                ->asJson()
                ->post(rtrim($this->baseUrl, '/').'/embed', ['text' => $text]);
        } catch (ConnectionException $e) {
            Log::warning('EmbeddingService unreachable', ['error' => $e->getMessage()]);

            return null;
        }

        if (! $response->successful()) {
            Log::warning('EmbeddingService non-2xx', [
                'status' => $response->status(),
                'body' => substr($response->body(), 0, 200),
            ]);

            return null;
        }

        $vec = $response->json('vector');
        if (! is_array($vec) || count($vec) !== $this->dim) {
            return null;
        }

        return array_map('floatval', $vec);
    }

    /**
     * Batch embed many texts in one HTTP round-trip. Order preserved.
     * Empty/null entries return zero-vectors; consumers should filter.
     *
     * @param  list<string>  $texts
     * @return list<list<float>>|null
     */
    public function embedBatch(array $texts): ?array
    {
        if (empty($texts)) {
            return [];
        }

        try {
            $response = Http::timeout($this->timeoutSec * 4)
                ->acceptJson()
                ->asJson()
                ->post(rtrim($this->baseUrl, '/').'/embed/batch', ['texts' => array_values($texts)]);
        } catch (ConnectionException $e) {
            Log::warning('EmbeddingService batch unreachable', ['error' => $e->getMessage()]);

            return null;
        }

        if (! $response->successful()) {
            return null;
        }

        $vectors = $response->json('vectors');
        if (! is_array($vectors)) {
            return null;
        }

        return $vectors;
    }

    /**
     * Postgres pgvector accepts a literal in the form '[0.1,0.2,...]'.
     * Use this when binding via raw SQL since Laravel's grammar does not
     * yet know vector(N).
     *
     * @param  list<float>  $vec
     */
    public static function toLiteral(array $vec): string
    {
        return '['.implode(',', array_map(static fn ($v) => (float) $v, $vec)).']';
    }

    public static function hashFor(string $text): string
    {
        return hash('sha256', trim($text));
    }
}

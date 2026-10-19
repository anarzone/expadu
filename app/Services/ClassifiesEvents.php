<?php

namespace App\Services;

use App\Models\Event;

/**
 * The single AI touchpoint of the events pipeline — runs once at
 * ingest, never at request time. Fake this in tests.
 */
interface ClassifiesEvents
{
    /**
     * False when the classifier cannot run at all (e.g. no API key), so
     * callers skip it instead of failing every job the same way.
     */
    public function isConfigured(): bool;

    /**
     * @return array{
     *     relevance: float,
     *     confidence: float,
     *     category: string,
     *     language: string,
     *     chips: list<string>,
     *     title_en: string,
     *     description_en: ?string,
     *     summary_en: string,
     *     tip_en: ?string,
     * }
     *
     * @throws \RuntimeException when the model output is unusable
     */
    public function classify(Event $event): array;
}

<?php

namespace App\Console\Commands;

use App\Jobs\ProcessEventJob;
use App\Media\CaptureMediaCandidate;
use App\Media\MediaCandidate;
use App\Models\Event;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class ScrapeEvents extends Command
{
    protected $signature = 'events:scrape';

    protected $description = 'Scrape events from external sources and create Event records';

    public function handle(CaptureMediaCandidate $captureMediaCandidate): int
    {
        $systemUser = User::where('email', 'system@expadu.com')->first();
        if (! $systemUser) {
            $this->error('System user (system@expadu.com) not found. Run database seeder first.');

            return self::FAILURE;
        }

        $created = 0;

        // Recurring community events live in the manual source now
        // (events:import-manual) as single RRULE records — the old
        // per-week duplicate templates are gone.
        $koelnCount = $this->scrapeKoelnDe($systemUser->id, $captureMediaCandidate);
        $created += $koelnCount;
        $this->info("koeln.de events: {$koelnCount} created");

        $this->info("Total: {$created} events created/updated");

        return self::SUCCESS;
    }

    /** Stop after this many pages (50 events each) — a runaway guard, not a real cap. */
    private const KOELN_MAX_PAGES = 20;

    /**
     * Fetch events from koeln.de via their Tribe Events REST API, paging
     * through the full window (the first page of 50 only spans a few days).
     */
    protected function scrapeKoelnDe(int $organiserId, CaptureMediaCandidate $captureMediaCandidate): int
    {
        $created = 0;
        $page = 1;
        $totalPages = 1;

        // koeln.de returns events chronologically, so the first page of 50 only
        // covers the next couple of days — the weekend never arrived. Page
        // through the whole 30-day window (Tribe reports total_pages).
        try {
            do {
                $response = Http::timeout(15)
                    ->withHeaders(['User-Agent' => 'Expadu/1.0 (Event Aggregator for Expats)'])
                    ->get('https://www.koeln.de/wp-json/tribe/events/v1/events', [
                        'per_page' => 50,
                        'page' => $page,
                        'start_date' => now()->toDateString(),
                        'end_date' => now()->addDays(30)->toDateString(),
                    ]);

                if (! $response->successful()) {
                    // A 400 past the last page is how Tribe signals "no more" —
                    // expected on the final iteration, so only note page 1.
                    if ($page === 1) {
                        Log::info('events:scrape — koeln.de API returned '.$response->status());
                    }

                    break;
                }

                $events = $response->json('events', []);
                $totalPages = (int) $response->json('total_pages', 1);

                foreach ($events as $ev) {
                    $title = html_entity_decode(trim($ev['title'] ?? ''), ENT_QUOTES, 'UTF-8');
                    if (! $title) {
                        continue;
                    }

                    $startsAt = Carbon::parse($ev['start_date'] ?? now());
                    if ($startsAt->isPast()) {
                        continue;
                    }

                    // Dedupe on the source's own id when it has one; fall
                    // back to title+date for sources without stable ids.
                    $sourceUid = isset($ev['id']) ? (string) $ev['id'] : null;
                    $existingEvent = $sourceUid
                        ? Event::query()->where('source', 'koeln.de')->where('source_uid', $sourceUid)->first()
                        : null;
                    $event = $existingEvent ?? new Event([
                        'source' => 'koeln.de',
                        'source_uid' => $sourceUid,
                    ]);
                    $isNew = ! $event->exists;
                    $attributes = $this->koelnAttributes($ev, $title, $startsAt, $organiserId);
                    $copyChanged = ! $isNew
                        && ($event->title !== $attributes['title'] || $event->description !== $attributes['description']);
                    $classifierInputChanged = ! $isNew && ($copyChanged
                        || ! $event->starts_at?->equalTo($attributes['starts_at'])
                        || ! $event->ends_at?->equalTo($attributes['ends_at'])
                        || $event->location_name !== $attributes['location_name']
                        || $event->address !== $attributes['address']
                        || $event->price_text !== $attributes['price_text']
                        || $event->is_free !== $attributes['is_free']);
                    $venueChanged = ! $isNew
                        && ($event->location_name !== $attributes['location_name'] || $event->address !== $attributes['address']);

                    $event->fill($attributes);

                    if ($copyChanged) {
                        $event->fill([
                            'title_en' => null,
                            'description_en' => null,
                            'language' => null,
                        ]);
                    }

                    if ($classifierInputChanged) {
                        $event->fill([
                            'summary_en' => null,
                            'tip_en' => null,
                            'chips' => null,
                            'relevance' => null,
                            'classification_input_hash' => null,
                        ]);
                    }

                    if ($venueChanged) {
                        $event->venue_id = null;
                    }

                    $sourceChanged = $event->isDirty();
                    $event->save();

                    $this->captureKoelnImage($event, $ev, $captureMediaCandidate);

                    if ($isNew || $classifierInputChanged) {
                        ProcessEventJob::dispatch($event);
                    }

                    if ($isNew || $sourceChanged) {
                        $created++;
                    }
                }

                $page++;
            } while ($page <= min($totalPages, self::KOELN_MAX_PAGES));
        } catch (\Exception $e) {
            Log::warning('events:scrape — koeln.de API error: '.$e->getMessage());
        }

        return $created;
    }

    /**
     * Normalize one koeln.de record before deciding whether it changed.
     *
     * @param  array<string, mixed>  $sourceEvent
     * @return array<string, mixed>
     */
    private function koelnAttributes(array $sourceEvent, string $title, Carbon $startsAt, int $organiserId): array
    {
        // Preserve the complete German source. The classifier uses a bounded
        // excerpt for categorisation and translates the full text in chunks.
        $description = trim(strip_tags((string) ($sourceEvent['description'] ?? '')));
        $venue = $sourceEvent['venue']['venue'] ?? null;
        $address = $sourceEvent['venue']['address'] ?? null;
        $sourceUrl = $sourceEvent['url'] ?? null;
        $sourceCategories = array_map(
            fn ($category) => is_array($category) ? (string) ($category['name'] ?? '') : '',
            is_array($sourceEvent['categories'] ?? null) ? $sourceEvent['categories'] : [],
        );
        $category = $this->mapSourceCategory($sourceCategories)
            ?? $this->categoriseEvent($title, $description);
        [$isFree, $price, $priceText] = $this->parseCost(
            is_string($sourceEvent['cost'] ?? null) ? $sourceEvent['cost'] : null,
        );
        $locationName = is_string($venue) ? html_entity_decode($venue, ENT_QUOTES, 'UTF-8') : null;
        $locationAddress = is_string($address) ? html_entity_decode($address, ENT_QUOTES, 'UTF-8') : null;

        return [
            'title' => mb_substr($title, 0, 255),
            'emoji' => $this->categoryEmoji($category),
            'category' => $category,
            'description' => $description ?: null,
            'source_lang' => 'de',
            'starts_at' => $startsAt,
            'ends_at' => isset($sourceEvent['end_date']) ? Carbon::parse($sourceEvent['end_date']) : $startsAt->copy()->addHours(2),
            'location_name' => $locationName,
            'address' => $locationAddress,
            'is_free' => $isFree ?? false,
            'price' => $price,
            'price_text' => $priceText,
            'source_url' => is_string($sourceUrl) ? $sourceUrl : null,
            'tags' => array_values(array_filter($sourceCategories)) ?: null,
            'organiser_id' => $organiserId,
            'quality_score' => $this->computeInitialQuality([
                'venue' => $locationName,
                'address' => $locationAddress,
                'description' => $description,
                'starts_at' => $startsAt,
                'source_url' => is_string($sourceUrl) ? $sourceUrl : null,
                'price_known' => $isFree !== null,
            ]),
            'status' => 'active',
            'verified_at' => now(),
        ];
    }

    /** @param array<string, mixed> $sourceEvent */
    private function captureKoelnImage(
        Event $event,
        array $sourceEvent,
        CaptureMediaCandidate $captureMediaCandidate,
    ): void {
        $image = $sourceEvent['image'] ?? null;
        if (! is_array($image) || ! is_string($image['url'] ?? null)) {
            return;
        }

        $remoteUrl = preg_replace('/^http:\/\//i', 'https://', trim($image['url']));
        if ($remoteUrl === '') {
            return;
        }

        try {
            $captureMediaCandidate->execute($event, new MediaCandidate(
                provider: 'koeln-de',
                remoteUrl: $remoteUrl,
                providerAssetId: isset($image['id']) ? (string) $image['id'] : null,
                sourcePageUrl: $event->source_url,
                role: 'poster',
                priority: 20,
                isPrimary: true,
                width: filter_var($image['width'] ?? null, FILTER_VALIDATE_INT) ?: null,
                height: filter_var($image['height'] ?? null, FILTER_VALIDATE_INT) ?: null,
            ));
        } catch (\Throwable $exception) {
            Log::warning('events:scrape — koeln.de media candidate was skipped', [
                'event_id' => $event->id,
                'remote_url' => $remoteUrl,
                'error' => $exception->getMessage(),
            ]);
        }
    }

    /**
     * Parse a raw cost string into (is_free, price, price_text).
     *
     * Returns:
     *   - is_free: true = confirmed free, false = has cost, null = unknown
     *   - price: lowest numeric price found, or null
     *   - price_text: cleaned display string, or null when empty
     *
     * @return array{bool|null, float|null, string|null}
     */
    protected function parseCost(?string $raw): array
    {
        if ($raw === null || trim($raw) === '') {
            // Empty = unknown, NOT free
            return [null, null, null];
        }

        $cleaned = trim(html_entity_decode($raw, ENT_QUOTES, 'UTF-8'));

        // Explicitly free by keyword
        if (preg_match('/\b(frei|free|kostenlos|gratis|eintritt frei)\b/i', $cleaned)) {
            return [true, null, 'Free'];
        }

        // Extract all numeric prices (handles "8–12 €", "15,00€", "ab 9.50 €")
        preg_match_all('/(\d+)[,.]?(\d*)/', $cleaned, $matches);
        $prices = [];
        foreach ($matches[1] as $i => $whole) {
            $decimal = $matches[2][$i] ? '.'.$matches[2][$i] : '';
            $prices[] = (float) ($whole.$decimal);
        }

        $minPrice = ! empty($prices) ? min($prices) : null;

        // "0 €", "0" — numeric zero means free
        if ($minPrice !== null && $minPrice == 0 && mb_strlen(trim(preg_replace('/[\d\s,.]/', '', $cleaned))) <= 2) {
            return [true, null, 'Free'];
        }

        // Normalise display text: strip excess whitespace, cap length
        $displayText = mb_substr(preg_replace('/\s+/', ' ', $cleaned), 0, 60);

        return [false, $minPrice, $displayText];
    }

    /**
     * Compute an initial quality score 0.0–1.0 at scrape time.
     *
     * The enrichment command may later refine this.
     *
     * @param  array{venue: ?string, address: ?string, description: ?string, starts_at: Carbon, source_url: ?string, price_known: bool}  $fields
     */
    protected function computeInitialQuality(array $fields): float
    {
        $score = 0.0;

        if ($fields['venue'] && ! in_array(mb_strtolower($fields['venue']), ['cologne', 'köln', ''])) {
            $score += 0.25;
        }
        if ($fields['address'] && ! in_array(mb_strtolower($fields['address']), ['cologne', 'köln', ''])) {
            $score += 0.2;
        }
        if ($fields['description'] && mb_strlen($fields['description']) > 20) {
            $score += 0.2;
        }
        if ($fields['starts_at']->hour > 0) {
            $score += 0.15;
        }
        if ($fields['source_url']) {
            $score += 0.1;
        }
        if ($fields['price_known']) {
            $score += 0.1;
        }

        return min(1.0, $score);
    }

    /**
     * Map koeln.de source category names to our internal category system.
     * Returns null when no confident match — falls back to keyword guessing.
     *
     * @param  string[]  $sourceCategories
     */
    protected function mapSourceCategory(array $sourceCategories): ?string
    {
        $map = [
            'Konzerte' => 'music',
            'Partys & Clubbing' => 'music',
            'Festivals' => 'music',
            'Jazz' => 'music',
            'Theater' => 'culture',
            'Ausstellungen' => 'culture',
            'Kino' => 'culture',
            'Lesungen' => 'culture',
            'Kabarett & Comedy' => 'culture',
            'Führungen' => 'culture',
            'Vorträge' => 'culture',
            'Stadtführungen' => 'culture',
            'Märkte' => 'food',
            'Flohmärkte' => 'food',
            'Sport' => 'sports',
            'Yoga' => 'sports',
            'Laufen' => 'sports',
            'Sprachen' => 'language',
            'Networking' => 'social',
            'Stammtisch' => 'social',
        ];

        foreach ($sourceCategories as $name) {
            if (isset($map[$name])) {
                return $map[$name];
            }
        }

        return null;
    }

    protected function categoriseEvent(string $title, string $desc): string
    {
        $text = mb_strtolower($title.' '.$desc);

        if (preg_match('/sprach|language|deutsch|german|tandem/i', $text)) {
            return 'language';
        }
        if (preg_match('/musik|music|jazz|konzert|concert|live|dj/i', $text)) {
            return 'music';
        }
        if (preg_match('/sport|lauf|run|yoga|fitness|schwimm/i', $text)) {
            return 'sports';
        }
        if (preg_match('/food|essen|kulinar|street food|markt|market|brau|beer/i', $text)) {
            return 'food';
        }
        if (preg_match('/kunst|art|museum|galerie|gallery|theater|theatre|kino|film|ausstellung|exhibit/i', $text)) {
            return 'culture';
        }
        if (preg_match('/expat|international|community|meetup|stammtisch|networking/i', $text)) {
            return 'social';
        }

        return 'culture';
    }

    protected function categoryEmoji(string $category): string
    {
        return match ($category) {
            'language' => '🗣️',
            'music' => '🎵',
            'sports' => '🏃',
            'food' => '🍽️',
            'culture' => '🎭',
            'social' => '🌍',
            default => '📅',
        };
    }
}

<?php

namespace App\Console\Commands;

use App\Jobs\ProcessEventJob;
use App\Media\CaptureMediaCandidate;
use App\Media\MediaCandidate;
use App\Models\Event;
use App\Models\User;
use App\Support\EventCancellation;
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
        $fetched = [];
        $complete = false;

        // koeln.de returns events chronologically, so the first page of 50 only
        // covers the next couple of days — the weekend never arrived. Page
        // through the whole 30-day window (Tribe reports total_pages).
        try {
            do {
                // Pages come in first and are matched after, so one copy per
                // occurrence can be chosen across all of them. A page that
                // fails ends the paging; what came in is still used.
                try {
                    $response = Http::timeout(15)
                        ->withHeaders(['User-Agent' => 'Expadu/1.0 (Event Aggregator for Expats)'])
                        ->get('https://www.koeln.de/wp-json/tribe/events/v1/events', [
                            'per_page' => 50,
                            'page' => $page,
                            'start_date' => now()->toDateString(),
                            'end_date' => now()->addDays(30)->toDateString(),
                        ]);
                } catch (\Exception $e) {
                    Log::warning("events:scrape — koeln.de page {$page} failed: ".$e->getMessage());

                    break;
                }

                if (! $response->successful()) {
                    // A 400 past the last page is how Tribe signals "no more" —
                    // expected on the final iteration, so only note page 1.
                    if ($page === 1) {
                        Log::info('events:scrape — koeln.de API returned '.$response->status());
                    }

                    break;
                }

                $fetched = [...$fetched, ...$response->json('events', [])];
                $totalPages = (int) $response->json('total_pages', 1);
                $page++;
                // Every page came in; false after a failed page or when the
                // page cap cut the window short.
                $complete = $page > $totalPages;
            } while ($page <= min($totalPages, self::KOELN_MAX_PAGES));

            foreach ($this->oneCopyPerOccurrence($fetched) as $ev) {
                // One bad record is logged and skipped, not the rest of the run.
                try {
                    $title = html_entity_decode(trim($ev['title'] ?? ''), ENT_QUOTES, 'UTF-8');
                    if (! $title) {
                        continue;
                    }

                    // A listing without a start isn't given one.
                    if (! is_string($ev['start_date'] ?? null) || trim($ev['start_date']) === '') {
                        continue;
                    }

                    $startsAt = Carbon::parse($ev['start_date']);
                    if ($startsAt->isPast()) {
                        continue;
                    }

                    // koeln.de deletes and re-creates posts, so the same
                    // occurrence comes back under a new id (and sometimes a new
                    // page). (source, source_uid) is unique, so the id decides
                    // first, hidden copies included; otherwise the page, then
                    // title and venue, all at the same start, find the live copy.
                    $sourceUid = isset($ev['id']) ? (string) $ev['id'] : null;
                    $sourceUrl = is_string($ev['url'] ?? null) ? $ev['url'] : null;
                    $venueName = is_array($ev['venue'] ?? null) ? ($ev['venue']['venue'] ?? null) : null;
                    $byUid = $sourceUid ? Event::query()->where('source', 'koeln.de')->where('source_uid', $sourceUid)->first() : null;
                    $live = fn () => Event::query()->where('source', 'koeln.de')->where('status', '<>', 'hidden');
                    $liveCopy = ($byUid === null || $byUid->status === 'hidden')
                        ? (($sourceUrl ? $live()->where('source_url', $sourceUrl)->where('starts_at', $startsAt)->latest('id')->first() : null)
                            ?? $live()->whereRaw('lower(title) = ?', [mb_strtolower(mb_substr($title, 0, 255))])->where('starts_at', $startsAt)->latest('id')->get()
                                ->first(fn (Event $copy) => $this->sameVenue($copy->location_name, $venueName)))
                        : null;
                    // A hidden copy's listing updates the live copy it was hidden
                    // for, which keeps its own id. With no live copy left, the
                    // hidden one is the event again.
                    $event = match (true) {
                        $byUid !== null && $byUid->status !== 'hidden' => $byUid,
                        $liveCopy !== null => $liveCopy,
                        $byUid !== null => $byUid,
                        default => new Event(['source' => 'koeln.de']),
                    };
                    if ($byUid === null) {
                        $event->source_uid = $sourceUid;
                    }
                    $isNew = ! $event->exists;
                    $attributes = $this->koelnAttributes($ev, $title, $startsAt, $organiserId);
                    $copyChanged = ! $isNew
                        && ($event->title !== $attributes['title'] || $event->description !== $attributes['description']);
                    // The end alone doesn't change what the classifier writes,
                    // so correcting it costs no new classification.
                    $classifierInputChanged = ! $isNew && ($copyChanged
                        || ! $event->starts_at?->equalTo($attributes['starts_at'])
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

                    if (($isNew || $classifierInputChanged) && $event->status === 'active') {
                        ProcessEventJob::dispatch($event);
                    }

                    if ($isNew || $sourceChanged) {
                        $created++;
                    }
                } catch (\Exception $e) {
                    Log::warning('events:scrape — koeln.de event skipped', ['source_uid' => $ev['id'] ?? null, 'error' => $e->getMessage()]);
                }
            }

            if ($complete) {
                $this->retireUnlistedKoelnEvents();
            }
        } catch (\Exception $e) {
            Log::warning('events:scrape — koeln.de API error: '.$e->getMessage());
        }

        return $created;
    }

    /**
     * After a complete scrape, an upcoming koeln.de event that koeln.de hasn't
     * listed for two days has gone from the source: cancelled, or re-created
     * as another post. It is set aside as 'unlisted' (a status of its own, so
     * the hidden-copy rules never touch it) and comes back the moment koeln.de
     * lists it again.
     */
    private function retireUnlistedKoelnEvents(): void
    {
        $retired = Event::query()
            ->where('source', 'koeln.de')
            ->where('status', 'active')
            // The scraped window runs to now + 30 days; stop a day short of
            // its edge.
            ->whereBetween('starts_at', [now(), now()->addDays(29)->endOfDay()])
            ->where(fn ($query) => $query->whereNull('verified_at')->orWhere('verified_at', '<', now()->subDays(2)))
            ->update(['status' => 'unlisted', 'updated_at' => now()]);

        if ($retired > 0) {
            Log::info('events:scrape — koeln.de no longer lists events; set aside', ['count' => $retired]);
        }
    }

    /**
     * koeln.de can list one occurrence twice at once (a re-created post next
     * to the old one). Keep one copy per title, start and venue, the newest;
     * a copy without a venue ("Siehe Beschreibung") goes when a copy with one
     * exists. Same title and time at different venues stay apart.
     *
     * @param  list<array<string, mixed>>  $events
     * @return list<array<string, mixed>>
     */
    private function oneCopyPerOccurrence(array $events): array
    {
        $venue = fn (array $ev): ?string => $this->knownVenue(is_array($ev['venue'] ?? null) ? ($ev['venue']['venue'] ?? null) : null);

        return collect($events)
            ->groupBy(fn (array $ev) => mb_strtolower(html_entity_decode(trim((string) ($ev['title'] ?? '')), ENT_QUOTES, 'UTF-8')).'|'.($ev['start_date'] ?? ''))
            ->flatMap(function ($copies) use ($venue) {
                $withVenue = $copies->filter(fn (array $ev) => $venue($ev) !== null);
                $kept = $withVenue->isNotEmpty() ? $withVenue->groupBy(fn (array $ev) => mb_strtolower($venue($ev))) : collect([$copies]);

                // values(): keyed by venue, two events at one venue would
                // overwrite each other when the groups are flattened.
                return $kept->map(fn ($same) => $same->sortByDesc(fn (array $ev) => (int) ($ev['id'] ?? 0))->first())->values();
            })
            ->values()
            ->all();
    }

    private function knownVenue(mixed $venue): ?string
    {
        $venue = is_string($venue) ? trim(html_entity_decode($venue, ENT_QUOTES, 'UTF-8')) : '';

        return in_array(mb_strtolower($venue), ['', 'siehe beschreibung'], true) ? null : $venue;
    }

    private function sameVenue(mixed $stored, mixed $incoming): bool
    {
        $stored = $this->knownVenue($stored);
        $incoming = $this->knownVenue($incoming);

        return $stored === null || $incoming === null || mb_strtolower($stored) === mb_strtolower($incoming);
    }

    /**
     * koeln.de sends the whole rendered page block: an empty date line (the
     * stray "|"), the text, ticket links and a "Zum Kalender hinzufügen"
     * menu. Keep the text, one line per block.
     */
    private function koelnDescription(string $html): string
    {
        if (trim($html) === '') {
            return '';
        }

        $document = new \DOMDocument;
        $previous = libxml_use_internal_errors(true);
        $document->loadHTML('<?xml encoding="utf-8"?><div>'.$html.'</div>', LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        $xpath = new \DOMXPath($document);
        $chrome = $xpath->query('//*[contains(@class, "tribe-events-schedule") or contains(@class, "tribe-events-c-subscribe-dropdown")
            or contains(@class, "tribe-block__events-link") or contains(@class, "tribe-events-c-ical")]|//script|//style|//svg|//button');
        foreach (iterator_to_array($chrome ?: []) as $node) {
            $node->parentNode?->removeChild($node);
        }

        $markup = (string) $document->saveHTML();
        $markup = preg_replace('/<br\s*\/?>|<\/(p|div|li|h[1-6]|ul|ol|tr|section|blockquote)>/i', "\n", $markup) ?? $markup;
        $text = html_entity_decode(strip_tags($markup), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $lines = array_filter(array_map(fn (string $line) => trim(preg_replace('/[ \t\x{00A0}]+/u', ' ', $line) ?? ''), explode("\n", $text)));

        return implode("\n", $lines);
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
        $description = $this->koelnDescription((string) ($sourceEvent['description'] ?? ''));
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
            // koeln.de's own end, or none: never one made up.
            'ends_at' => filled($sourceEvent['end_date'] ?? null) ? Carbon::parse($sourceEvent['end_date']) : null,
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
            // Off the listings when the listing itself says it's off; back to
            // active when the source drops the wording again.
            'status' => EventCancellation::said($title, $description) ? 'cancelled' : 'active',
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

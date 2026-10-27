<?php

namespace App\Console\Commands\Events;

use App\Enums\EventCategory;
use App\Jobs\ProcessEventJob;
use App\Media\CaptureMediaCandidate;
use App\Media\MediaCandidate;
use App\Models\Event;
use App\Models\User;
use App\Services\CologneServiceArea;
use App\Support\EventCancellation;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * KölnTourismus's listings from DatenHub NRW (owner, 27 Oct 2026: "add datenhub and keep
 * koeln.de"). Open data: the texts are public domain and the photos CC BY-SA, so a listing's
 * photo shows with its credit. One event per time interval the listing gives (a weekly or daily
 * run becomes a series); a listing koeln.de or the city already has is not added twice, it only
 * lends that listing its photo.
 */
class ImportDatenHubEvents extends Command
{
    private const ENDPOINT = 'https://meta.et4.de/rest.ashx/search/';

    private const SOURCE = 'datenhub-nrw';

    /** The sources whose listings a DatenHub one may repeat. */
    private const OTHER_SOURCES = ['koeln.de', 'stadt-koeln.de'];

    private const WEEKDAYS = [
        'Monday' => 'MO', 'Tuesday' => 'TU', 'Wednesday' => 'WE', 'Thursday' => 'TH',
        'Friday' => 'FR', 'Saturday' => 'SA', 'Sunday' => 'SU',
    ];

    protected $signature = 'events:import-datenhub {--days=60 : Number of future days imported}';

    protected $description = 'Import KölnTourismus events from the DatenHub NRW open-data feed';

    /** @var Collection<string, list<int>> normalised title → other sources' upcoming event ids */
    private Collection $others;

    public function handle(CologneServiceArea $serviceArea, CaptureMediaCandidate $captureMediaCandidate): int
    {
        $organiserId = User::query()->where('email', 'system@expadu.com')->value('id');
        if ($organiserId === null) {
            $this->error('System user (system@expadu.com) not found. Run the database seeder first.');

            return self::FAILURE;
        }

        try {
            $response = Http::connectTimeout(5)
                ->timeout(30)
                ->retry([250, 750], throw: false)
                ->acceptJson()
                ->withUserAgent('Expadu/1.0 (Cologne event discovery; contact: support@expadu.com)')
                ->get(self::ENDPOINT, [
                    'experience' => 'open-data-nrw-tourismus',
                    'type' => 'Event',
                    'template' => 'ET2014A.json',
                    'q' => 'city:Köln',
                    'limit' => 500,
                ]);
        } catch (ConnectionException $exception) {
            $this->recordRun(['status' => 'failed', 'error' => 'connection']);
            Log::warning('DatenHub NRW event feed connection failed', ['error' => $exception->getMessage()]);
            $this->error('DatenHub NRW event feed could not be reached.');

            return self::FAILURE;
        }

        $payload = $response->json();
        if (! $response->successful() || ! is_array($payload) || ($payload['status'] ?? null) !== 'OK'
            || ! is_array($payload['items'] ?? null) || $payload['items'] === []) {
            Log::warning('DatenHub NRW event feed returned an invalid payload', ['status' => $response->status()]);
            $this->recordRun(['status' => 'failed', 'error' => 'invalid_payload']);
            $this->error('DatenHub NRW event feed returned an invalid payload. No records were changed.');

            return self::FAILURE;
        }

        $now = CarbonImmutable::now('Europe/Berlin');
        $horizon = $now->addDays(max(1, min(120, (int) $this->option('days'))))->endOfDay();
        $this->others = $this->otherListings($now);
        $metrics = ['created' => 0, 'updated' => 0, 'unchanged' => 0, 'listed_elsewhere' => 0, 'skipped' => 0, 'invalid_coordinates' => 0];
        $seen = [];
        $this->recordRun(['status' => 'running', 'fetched' => count($payload['items'])]);

        foreach ($payload['items'] as $item) {
            if (! is_array($item) || ! filled($item['global_id'] ?? null) || ! filled($item['title'] ?? null)) {
                $metrics['skipped']++;

                continue;
            }

            try {
                foreach ($this->runs($item, $now, $horizon) as $uid => $run) {
                    $seen[] = $uid;
                    $this->importRun($item, $uid, $run, (int) $organiserId, $serviceArea, $captureMediaCandidate, $metrics);
                }
            } catch (Throwable $exception) {
                $metrics['skipped']++;
                Log::warning('DatenHub NRW event record was skipped', ['global_id' => $item['global_id'], 'error' => $exception->getMessage()]);
            }
        }

        // A listing the feed no longer gives is hidden, never deleted (saves and links keep it).
        if ($metrics['skipped'] === 0) {
            Event::query()->where('source', self::SOURCE)->where('status', '!=', 'hidden')
                ->when($seen !== [], fn ($query) => $query->whereNotIn('source_uid', $seen))
                ->update(['status' => 'hidden']);
        }

        $this->recordRun([
            'status' => $metrics['skipped'] === 0 ? 'succeeded' : 'partial',
            'fetched' => count($payload['items']),
            'imported' => $metrics['created'] + $metrics['updated'] + $metrics['unchanged'],
            'skipped' => $metrics['skipped'],
        ]);
        $this->line((string) json_encode(['source' => self::SOURCE, ...$metrics, 'fetched' => count($payload['items'])], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

        return self::SUCCESS;
    }

    /**
     * The listing's time intervals still to come within the horizon, keyed by a stable uid: a
     * single date as it is, a weekly or daily run as a series (RRULE), and a monthly one ("every
     * 4th Saturday") as its dates, since the series reader repeats a month only on its day.
     *
     * @param  array<string, mixed>  $item
     * @return array<string, array{starts_at: CarbonImmutable, ends_at: ?CarbonImmutable, recurrence: ?string, until: ?CarbonImmutable}>
     */
    private function runs(array $item, CarbonImmutable $now, CarbonImmutable $horizon): array
    {
        $runs = [];
        foreach (array_values($item['timeIntervals'] ?? []) as $index => $interval) {
            $start = $this->instant($interval['start'] ?? null);
            if ($start === null || $start->greaterThan($horizon)) {
                continue;
            }
            $end = $this->instant($interval['end'] ?? null);
            $end = $end?->greaterThan($start) ? $end : null;
            // A whole day (midnight to midnight) is stored as the other feeds store a day with no
            // time: start to end of that day.
            if ($end !== null && $start->format('H:i') === '00:00' && $end->format('H:i') === '00:00') {
                $end = $end->subSecond();
            }
            $until = $this->instant($interval['repeatUntil'] ?? null);
            $uid = $item['global_id'].'#'.$index;

            $freq = $interval['freq'] ?? null;
            if ($freq === null) {
                if (($end ?? $start)->greaterThanOrEqualTo($now)) {
                    $runs[$uid] = ['starts_at' => $start, 'ends_at' => $end, 'recurrence' => null, 'until' => null];
                }

                continue;
            }
            if ($until === null || $until->lessThan($now)) {
                continue;
            }
            $every = max(1, (int) ($interval['interval'] ?? 1));

            if ($freq === 'Monthly') {
                foreach ($this->monthlyDates($interval, $start, $every, $until->min($horizon)) as $date) {
                    if ($date->greaterThanOrEqualTo($now->startOfDay())) {
                        $runs[$uid.'@'.$date->toDateString()] = [
                            'starts_at' => $date,
                            'ends_at' => $end ? $date->addSeconds((int) $start->diffInSeconds($end)) : null,
                            'recurrence' => null,
                            'until' => null,
                        ];
                    }
                }

                continue;
            }

            $days = array_values(array_filter(array_map(fn ($day) => self::WEEKDAYS[$day] ?? null, (array) ($interval['weekdays'] ?? []))));
            $rule = match ($freq) {
                'Daily' => 'FREQ=DAILY',
                'Weekly' => 'FREQ=WEEKLY'.($days !== [] ? ';BYDAY='.implode(',', $days) : ''),
                default => null,
            };
            if ($rule === null) {
                continue;
            }
            $runs[$uid] = [
                'starts_at' => $start,
                'ends_at' => $end,
                'recurrence' => $rule.($every > 1 ? ';INTERVAL='.$every : ''),
                'until' => $until,
            ];
        }

        return $runs;
    }

    /**
     * "Every nth weekday of the month": the dates from the interval's start to $last.
     *
     * @param  array<string, mixed>  $interval
     * @return list<CarbonImmutable>
     */
    private function monthlyDates(array $interval, CarbonImmutable $start, int $every, CarbonImmutable $last): array
    {
        $weekday = array_search(self::WEEKDAYS[$interval['weekday'] ?? ''] ?? null, array_values(self::WEEKDAYS), true);
        $ordinal = (int) ($interval['dayOrdinal'] ?? 0);
        if ($weekday === false || $ordinal < 1 || $ordinal > 5) {
            return [];
        }

        $dates = [];
        for ($month = $start->startOfMonth(); $month->lessThanOrEqualTo($last); $month = $month->addMonthsNoOverflow($every)) {
            $first = $month->addDays(($weekday + 1 - $month->isoWeekday() + 7) % 7);
            $date = $first->addWeeks($ordinal - 1)->setTimeFrom($start);
            if ($date->month === $month->month && $date->greaterThanOrEqualTo($start) && $date->lessThanOrEqualTo($last)) {
                $dates[] = $date;
            }
        }

        return $dates;
    }

    /**
     * @param  array<string, mixed>  $item
     * @param  array{starts_at: CarbonImmutable, ends_at: ?CarbonImmutable, recurrence: ?string, until: ?CarbonImmutable}  $run
     * @param  array<string, int>  $metrics
     */
    private function importRun(
        array $item,
        string $uid,
        array $run,
        int $organiserId,
        CologneServiceArea $serviceArea,
        CaptureMediaCandidate $captureMediaCandidate,
        array &$metrics,
    ): void {
        $title = $this->cleanText($item['title']);
        $photo = $this->photo($item);

        // koeln.de or the city lists it already: that listing stays, and gets the photo.
        $elsewhere = $this->listedElsewhere((string) $title, $run);
        if ($elsewhere !== []) {
            $metrics['listed_elsewhere']++;
            if ($photo !== null) {
                Event::query()->whereKey($elsewhere)->get()
                    ->each(fn (Event $other) => $this->capturePhoto($other, $photo, $captureMediaCandidate, primary: false));
            }

            return;
        }

        $description = $this->description($item);
        $locationName = $this->cleanText($item['name'] ?? null);
        $address = implode(', ', array_filter([
            $this->cleanText($item['street'] ?? null),
            trim(implode(' ', array_filter([$this->cleanText($item['zip'] ?? null), $this->cleanText($item['city'] ?? null)]))),
        ])) ?: null;
        $priceText = $this->priceText($item);
        $features = array_filter((array) ($item['features'] ?? []), 'is_string');
        $isFree = in_array('Eintritt frei', $features, true);
        $category = $this->category($item, (string) $title);

        $event = Event::query()->firstOrNew(['source' => self::SOURCE, 'source_uid' => $uid]);
        $isNew = ! $event->exists;
        $copyChanged = $event->exists && ($event->title !== $title || $event->description !== $description);
        $geoInputChanged = $event->exists && ($event->location_name !== $locationName || $event->address !== $address);

        $event->fill([
            'title' => mb_substr((string) $title, 0, 255),
            'description' => $description,
            'source_lang' => 'de',
            'source_url' => $this->link($item),
            'starts_at' => $run['starts_at'],
            'ends_at' => $run['ends_at'],
            'location_name' => $locationName,
            'address' => $address,
            'is_free' => $isFree,
            'price' => $isFree ? null : $this->adultPrice($item),
            'price_text' => $priceText,
            'category' => $category->value,
            'emoji' => $category->emoji(),
            'tags' => array_values(array_unique([...($event->tags ?? []), 'tourism-board'])),
            'organiser_id' => $organiserId,
            'quality_score' => min(1.0, ($locationName ? 0.25 : 0) + ($address ? 0.2 : 0)
                + ($description && mb_strlen($description) > 20 ? 0.2 : 0) + 0.15 + 0.1 + ($priceText ? 0.1 : 0)),
            'status' => EventCancellation::said((string) $title, $description) ? 'cancelled' : 'active',
            'verified_at' => now(),
            'recurrence' => $run['recurrence'],
            'recurrence_until' => $run['until'],
        ]);

        if ($copyChanged) {
            $event->fill(['title_en' => null, 'description_en' => null, 'language' => null]);
        }
        if ($copyChanged || ($event->exists && $event->isDirty(['starts_at', 'ends_at', 'location_name', 'address', 'price_text']))) {
            $event->fill(['summary_en' => null, 'tip_en' => null, 'chips' => null, 'relevance' => null, 'classification_input_hash' => null]);
        }

        $contentChanged = $isNew || $copyChanged || $event->isDirty([
            'starts_at', 'ends_at', 'location_name', 'address', 'price_text', 'source_url', 'recurrence', 'recurrence_until',
        ]);
        $event->save();

        if ($geoInputChanged) {
            DB::statement('UPDATE events SET location = NULL, venue_id = NULL, needs_review = TRUE WHERE id = ?', [$event->id]);
        }
        $this->storeCoordinates($event, $item, $serviceArea, $metrics);
        if ($photo !== null) {
            $this->capturePhoto($event, $photo, $captureMediaCandidate, primary: true);
        }

        if ($contentChanged && $event->status !== 'cancelled') {
            ProcessEventJob::dispatch($event);
        }

        $metrics[$isNew ? 'created' : ($contentChanged ? 'updated' : 'unchanged')]++;
    }

    /**
     * The other sources' upcoming listings by normalised title, read once per run.
     *
     * @return Collection<string, list<array{id: int, starts_at: CarbonImmutable, until: ?CarbonImmutable}>>
     */
    private function otherListings(CarbonImmutable $now): Collection
    {
        return Event::query()->visible()->whereIn('source', self::OTHER_SOURCES)
            ->where(fn ($query) => $query->where('starts_at', '>=', $now->startOfDay())
                ->orWhere(fn ($series) => $series->whereNotNull('recurrence')->where('recurrence_until', '>=', $now)))
            ->get(['id', 'title', 'starts_at', 'recurrence_until'])
            ->groupBy(fn (Event $event) => $this->normalised($event->title))
            ->map(fn (Collection $events) => $events->map(fn (Event $event) => [
                'id' => $event->id,
                'starts_at' => CarbonImmutable::parse($event->starts_at)->setTimezone('Europe/Berlin'),
                'until' => $event->recurrence_until ? CarbonImmutable::parse($event->recurrence_until) : null,
            ])->values()->all());
    }

    /**
     * The other sources' events with the same title on this run's days: on its date for a single
     * date, anywhere within its span for a series.
     *
     * @param  array{starts_at: CarbonImmutable, ends_at: ?CarbonImmutable, recurrence: ?string, until: ?CarbonImmutable}  $run
     * @return list<int>
     */
    private function listedElsewhere(string $title, array $run): array
    {
        $first = $run['starts_at']->setTimezone('Europe/Berlin')->startOfDay();
        $last = ($run['until'] ?? $run['starts_at'])->setTimezone('Europe/Berlin')->endOfDay();

        return collect($this->others->get($this->normalised($title), []))
            ->filter(fn (array $other) => $other['starts_at']->lessThanOrEqualTo($last)
                && ($other['until'] ?? $other['starts_at'])->greaterThanOrEqualTo($first))
            ->pluck('id')->all();
    }

    private function normalised(string $title): string
    {
        return (string) preg_replace('/[^\p{L}\p{N}]+/u', '', mb_strtolower(html_entity_decode($title, ENT_QUOTES | ENT_HTML5, 'UTF-8')));
    }

    /**
     * The listing's main photo (else its first gallery photo), only as an open-licensed one.
     *
     * @param  array<string, mixed>  $item
     * @return array{url: string, id: string, credit: ?string, author: ?string, width: ?int, height: ?int, alt: ?string}|null
     */
    private function photo(array $item): ?array
    {
        $images = collect($item['media_objects'] ?? [])
            ->filter(fn ($media) => is_array($media) && str_starts_with((string) ($media['type'] ?? ''), 'image/')
                && in_array($media['rel'] ?? null, ['default', 'imagegallery'], true)
                && ($media['license'] ?? null) === 'CC-BY-SA'
                && str_starts_with((string) ($media['url'] ?? ''), 'https://dam.destination.one/'))
            ->sortBy(fn (array $media) => [($media['rel'] === 'default' ? 0 : 1), (int) ($media['prio'] ?? 99)]);
        $image = $images->first();
        if ($image === null) {
            return null;
        }
        $credit = $this->cleanText($image['source'] ?? null);

        return [
            'url' => $image['url'],
            'id' => (string) ($image['id'] ?? $image['url']),
            'credit' => $credit,
            'author' => $credit !== null && preg_match('/Foto:\s*(.+)$/u', $credit, $match) ? trim($match[1]) : null,
            'width' => is_numeric($image['width'] ?? null) ? (int) $image['width'] : null,
            'height' => is_numeric($image['height'] ?? null) ? (int) $image['height'] : null,
            'alt' => $this->cleanText($image['alt'] ?? null),
        ];
    }

    /** @param  array{url: string, id: string, credit: ?string, author: ?string, width: ?int, height: ?int, alt: ?string}  $photo */
    private function capturePhoto(Event $event, array $photo, CaptureMediaCandidate $captureMediaCandidate, bool $primary): void
    {
        try {
            $captureMediaCandidate->execute($event, new MediaCandidate(
                provider: 'koeln-tourismus',
                remoteUrl: $photo['url'],
                providerAssetId: $photo['id'],
                sourcePageUrl: $event->source === self::SOURCE ? ($event->source_url ?? $photo['url']) : $photo['url'],
                role: 'poster',
                priority: $primary ? 10 : 5,
                isPrimary: $primary,
                // DatenHub NRW publishes the tourism board's photos as CC BY-SA, credit given.
                rightsStatus: 'approved',
                author: $photo['author'],
                attribution: trim(($photo['credit'] ?? 'KölnTourismus').' · CC BY-SA 4.0'),
                licenseCode: 'CC BY-SA 4.0',
                licenseUrl: 'https://creativecommons.org/licenses/by-sa/4.0/',
                width: $photo['width'],
                height: $photo['height'],
                metadata: array_filter(['alt' => $photo['alt'], 'feed' => self::SOURCE]),
            ));
        } catch (Throwable $exception) {
            Log::warning('DatenHub NRW event photo was skipped', ['event_id' => $event->id, 'error' => $exception->getMessage()]);
        }
    }

    /**
     * @param  array<string, mixed>  $item
     * @param  array<string, int>  $metrics
     */
    private function storeCoordinates(Event $event, array $item, CologneServiceArea $serviceArea, array &$metrics): void
    {
        $latitude = $item['geo']['main']['latitude'] ?? null;
        $longitude = $item['geo']['main']['longitude'] ?? null;
        $city = mb_strtolower(trim((string) ($item['city'] ?? '')));
        if (! is_numeric($latitude) || ! is_numeric($longitude) || ! in_array($city, ['köln', 'koeln'], true)) {
            DB::statement('UPDATE events SET location = NULL, venue_id = NULL, needs_review = TRUE WHERE id = ?', [$event->id]);

            return;
        }

        try {
            if (! $serviceArea->contains((float) $latitude, (float) $longitude)) {
                $metrics['invalid_coordinates']++;
                DB::statement('UPDATE events SET location = NULL, venue_id = NULL, needs_review = TRUE WHERE id = ?', [$event->id]);

                return;
            }
        } catch (Throwable $exception) {
            Log::warning('DatenHub NRW event coordinate validation failed closed', ['event_id' => $event->id, 'error' => $exception->getMessage()]);

            return;
        }

        if (($event->lat !== null && abs($event->lat - (float) $latitude) > 0.000001)
            || ($event->lng !== null && abs($event->lng - (float) $longitude) > 0.000001)) {
            DB::statement('UPDATE events SET venue_id = NULL WHERE id = ?', [$event->id]);
        }
        DB::statement(
            'UPDATE events SET location = ST_SetSRID(ST_MakePoint(?, ?), 4326)::geography WHERE id = ?',
            [(float) $longitude, (float) $latitude, $event->id],
        );
    }

    /**
     * The listing's own words: its details, else its teaser, else its search description.
     *
     * @param  array<string, mixed>  $item
     */
    private function description(array $item): ?string
    {
        $texts = collect($item['texts'] ?? [])->filter(fn ($text) => is_array($text) && filled($text['value'] ?? null));
        foreach (['details', 'open-data-nrw-detail', 'teaser', 'open-data-nrw-teaser', 'WEB_SEO_BESCHREIBUNG'] as $rel) {
            $text = $texts->first(fn (array $text) => $text['rel'] === $rel && $text['type'] === 'text/html')
                ?? $texts->first(fn (array $text) => $text['rel'] === $rel);
            if ($text !== null && ($clean = $this->cleanText($text['value'])) !== null) {
                return $clean;
            }
        }

        return null;
    }

    /**
     * A price, never the note's small print: the note's first line when it names an amount
     * ("ab 19,90 Euro"), else the adult price. Fees ("Zuzüglich 1,68 €") and terms are none.
     *
     * @param  array<string, mixed>  $item
     */
    private function priceText(array $item): ?string
    {
        $info = collect($item['texts'] ?? [])->first(fn ($text) => is_array($text) && ($text['rel'] ?? null) === 'PRICE_INFO');
        $line = trim(strtok((string) $this->cleanText($info['value'] ?? null), "\n") ?: '');
        if ($line !== '' && mb_strlen($line) <= 60 && ! preg_match('/^zuzüglich/iu', $line)
            && preg_match('/\d(?:[,.]\d+)?\s*(?:€|euro\b)|€\s*\d/iu', $line)) {
            return $line;
        }
        $adult = $this->adultPrice($item);

        return $adult !== null ? number_format($adult, 2, ',', '.').' €' : null;
    }

    /** @param  array<string, mixed>  $item */
    private function adultPrice(array $item): ?float
    {
        $number = collect($item['numbers'] ?? [])->first(fn ($number) => is_array($number) && ($number['type'] ?? null) === 'PRICE_ADULT');

        return is_numeric($number['value'] ?? null) && (float) $number['value'] > 0 ? (float) $number['value'] : null;
    }

    /**
     * The listing's own page, else its booking page (a link too long to keep whole is none).
     *
     * @param  array<string, mixed>  $item
     */
    private function link(array $item): ?string
    {
        $booking = collect($item['media_objects'] ?? [])->first(fn ($media) => is_array($media) && ($media['rel'] ?? null) === 'booking');
        foreach ([$item['web'] ?? null, $booking['url'] ?? null] as $url) {
            if (is_string($url) && filter_var($url = preg_replace('/^http:\/\//i', 'https://', trim($url)), FILTER_VALIDATE_URL)
                && parse_url($url, PHP_URL_SCHEME) === 'https' && strlen($url) <= 255) {
                return $url;
            }
        }

        return null;
    }

    /** @param  array<string, mixed>  $item */
    private function category(array $item, string $title): EventCategory
    {
        $categories = implode(' ', array_filter((array) ($item['categories'] ?? []), 'is_string'));

        return match (true) {
            (bool) preg_match('/sport|turnier|wettbewerb|radtour/iu', $categories) => EventCategory::Sports,
            (bool) preg_match('/party|nightlife|festival|open-air/iu', $categories) => EventCategory::Party,
            (bool) preg_match('/führung|bühne|ausstellung|comedy|schauspiel|vortrag|lesung|film|klassisch|musical|konzert|ballett|tanz|zirkus|jazz|oper|kabarett|pop|theater|brauchtum|kultur/iu', $categories) => EventCategory::Culture,
            (bool) preg_match('/sprach|language|tandem/iu', $title) => EventCategory::LanguageExchange,
            (bool) preg_match('/stammtisch/iu', $title) => EventCategory::Stammtisch,
            default => EventCategory::Other,
        };
    }

    private function instant(mixed $value): ?CarbonImmutable
    {
        if (! is_string($value) || $value === '') {
            return null;
        }
        try {
            return CarbonImmutable::parse($value)->setTimezone('Europe/Berlin');
        } catch (Throwable) {
            return null;
        }
    }

    private function cleanText(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }
        $decoded = html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $decoded = preg_replace('/<\/(?:p|div|li|h[1-6])>|<br\s*\/?>/iu', "\n\n", $decoded);
        $clean = preg_replace('/[^\S\r\n]+/u', ' ', strip_tags($decoded));
        $clean = trim(preg_replace('/(?:\r?\n\s*){3,}/u', "\n\n", $clean));

        return $clean !== '' ? $clean : null;
    }

    /** @param  array<string, mixed>  $run */
    private function recordRun(array $run): void
    {
        Cache::put('events:source-run:'.self::SOURCE, [...$run, 'completed_at' => now()->toIso8601String()], now()->addDays(7));
    }
}

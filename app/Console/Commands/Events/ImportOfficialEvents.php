<?php

namespace App\Console\Commands\Events;

use App\Enums\EventCategory;
use App\Jobs\ProcessEventJob;
use App\Media\CaptureMediaCandidate;
use App\Media\MediaCandidate;
use App\Models\Event;
use App\Models\User;
use App\Services\CologneServiceArea;
use Illuminate\Console\Command;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

class ImportOfficialEvents extends Command
{
    private const ENDPOINT = 'https://www.stadt-koeln.de/externe-dienste/open-data/events-od.php';

    private const SOURCE = 'stadt-koeln.de';

    protected $signature = 'events:import-official {--days=40 : Number of future days requested from the feed}';

    protected $description = 'Import the official Stadt Köln open-data event feed';

    public function handle(CologneServiceArea $serviceArea, CaptureMediaCandidate $captureMediaCandidate): int
    {
        $organiserId = User::query()->where('email', 'system@expadu.com')->value('id');
        if ($organiserId === null) {
            $this->error('System user (system@expadu.com) not found. Run the database seeder first.');

            return self::FAILURE;
        }

        try {
            $response = Http::connectTimeout(5)
                ->timeout(20)
                ->retry([250, 750], throw: false)
                ->acceptJson()
                ->withUserAgent('Expadu/1.0 (Cologne event discovery; contact: support@expadu.com)')
                ->get(self::ENDPOINT, [
                    'ndays' => max(1, min(90, (int) $this->option('days'))),
                    'nobrs' => 1,
                ]);
        } catch (ConnectionException $exception) {
            Cache::put('events:source-run:'.self::SOURCE, [
                'status' => 'failed', 'completed_at' => now()->toIso8601String(), 'error' => 'connection',
            ], now()->addDays(7));
            Log::warning('Official Cologne event feed connection failed', ['error' => $exception->getMessage()]);
            $this->error('Official Cologne event feed could not be reached.');

            return self::FAILURE;
        }

        $payload = $response->json();
        if (! $response->successful()
            || ! is_array($payload)
            || ($payload['success'] ?? false) !== true
            || ! isset($payload['items'])
            || ! is_array($payload['items'])
            || $payload['items'] === []) {
            Log::warning('Official Cologne event feed returned an invalid payload', [
                'status' => $response->status(),
                'content_type' => $response->header('Content-Type'),
            ]);
            Cache::put('events:source-run:'.self::SOURCE, [
                'status' => 'failed', 'completed_at' => now()->toIso8601String(), 'error' => 'invalid_payload',
            ], now()->addDays(7));
            $this->error('Official Cologne event feed returned an invalid payload. No records were changed.');

            return self::FAILURE;
        }

        $metrics = ['created' => 0, 'updated' => 0, 'unchanged' => 0, 'skipped' => 0, 'invalid_coordinates' => 0];

        Cache::put('events:source-run:'.self::SOURCE, [
            'status' => 'running', 'started_at' => now()->toIso8601String(), 'fetched' => count($payload['items']),
        ], now()->addDays(7));

        foreach ($payload['items'] as $record) {
            if (! is_array($record) || ! $this->validRecord($record)) {
                $metrics['skipped']++;

                continue;
            }

            try {
                $this->importRecord($record, (int) $organiserId, $serviceArea, $captureMediaCandidate, $metrics);
            } catch (Throwable $exception) {
                $metrics['skipped']++;
                Log::warning('Official Cologne event record was skipped', [
                    'source_uid' => $this->sourceUid($record),
                    'error' => $exception->getMessage(),
                ]);
            }
        }

        if ($metrics['skipped'] === 0) {
            $seen = collect($payload['items'])->map(fn (array $record): string => $this->sourceUid($record))->all();
            Event::query()->where('source', self::SOURCE)
                ->whereBetween('starts_at', [now()->startOfDay(), now()->addDays(max(1, min(90, (int) $this->option('days'))))->endOfDay()])
                ->when($seen !== [], fn ($query) => $query->whereNotIn('source_uid', $seen))
                ->update(['status' => 'hidden']);
        }

        Cache::put('events:source-run:'.self::SOURCE, [
            'status' => $metrics['skipped'] === 0 ? 'succeeded' : 'partial',
            'completed_at' => now()->toIso8601String(),
            'fetched' => count($payload['items']),
            'imported' => $metrics['created'] + $metrics['updated'] + $metrics['unchanged'],
            'skipped' => $metrics['skipped'],
        ], now()->addDays(7));

        $this->line((string) json_encode([
            'source' => self::SOURCE,
            ...$metrics,
            'fetched' => count($payload['items']),
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

        return self::SUCCESS;
    }

    /** @param array<string, mixed> $record */
    private function validRecord(array $record): bool
    {
        return filled($record['link'] ?? null)
            && filled($record['title'] ?? null)
            && $this->parseDate($record['beginndatum'] ?? null) !== null;
    }

    /**
     * @param  array<string, mixed>  $record
     * @param  array{created: int, updated: int, unchanged: int, skipped: int, invalid_coordinates: int}  $metrics
     */
    private function importRecord(
        array $record,
        int $organiserId,
        CologneServiceArea $serviceArea,
        CaptureMediaCandidate $captureMediaCandidate,
        array &$metrics,
    ): void {
        [$startsAt, $endsAt] = $this->parseSchedule($record);
        $isMultiDay = ! $startsAt->isSameDay($endsAt);
        $recurrenceUntil = $isMultiDay ? $endsAt->copy()->endOfDay() : null;
        if ($isMultiDay && filled($record['uhrzeit'] ?? null)) {
            $endsAt = $startsAt->copy()->setTime($endsAt->hour, $endsAt->minute);
        }
        $title = $this->cleanText($record['title'] ?? null);
        $description = $this->cleanText($record['description'] ?? null);
        $locationName = $this->cleanText($record['veranstaltungsort'] ?? null);
        $priceText = $this->cleanText($record['preis'] ?? null);
        [$isFree, $price] = $this->parsePrice($priceText);
        $address = $this->address($record);
        $sourceUid = $this->sourceUid($record);
        $category = $this->category($title.' '.$description);

        $event = Event::query()->firstOrNew([
            'source' => self::SOURCE,
            'source_uid' => $sourceUid,
        ]);
        $isNew = ! $event->exists;
        $copyChanged = $event->exists
            && ($event->title !== $title || $event->description !== $description);
        $classifierInputChanged = $event->exists && ($copyChanged
            || ! $event->starts_at?->equalTo($startsAt)
            || ! $event->ends_at?->equalTo($endsAt)
            || $event->location_name !== $locationName
            || $event->address !== $address
            || $event->price_text !== $priceText);
        $geoInputChanged = $event->exists
            && ($event->location_name !== $locationName || $event->address !== $address);
        $incomingLatitude = filter_var(trim((string) ($record['latitude'] ?? '')), FILTER_VALIDATE_FLOAT);
        $incomingLongitude = filter_var(trim((string) ($record['longitude'] ?? '')), FILTER_VALIDATE_FLOAT);
        $coordinateChanged = $event->exists && $incomingLatitude !== false && $incomingLongitude !== false
            && ($event->lat === null || $event->lng === null
                || abs($event->lat - (float) $incomingLatitude) > 0.000001
                || abs($event->lng - (float) $incomingLongitude) > 0.000001);

        $event->fill([
            'title' => mb_substr((string) $title, 0, 255),
            'description' => $description,
            'source_lang' => 'de',
            'source_url' => $this->httpsUrl((string) $record['link']),
            'starts_at' => $startsAt,
            'ends_at' => $endsAt,
            'location_name' => $locationName,
            'address' => $address,
            'is_free' => $isFree,
            'price' => $price,
            'price_text' => $priceText,
            'category' => $category->value,
            'emoji' => $category->emoji(),
            'tags' => $this->mergedTags($event->tags ?? [], $record, $isMultiDay, filled($record['uhrzeit'] ?? null)),
            'organiser_id' => $organiserId,
            'quality_score' => $this->qualityScore($description, $locationName, $address, $startsAt, $priceText),
            'status' => 'active',
            'verified_at' => now(),
            'recurrence' => $isMultiDay && filled($record['uhrzeit'] ?? null) ? 'FREQ=DAILY' : null,
            'recurrence_until' => $isMultiDay && filled($record['uhrzeit'] ?? null) ? $recurrenceUntil : null,
        ]);

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

        $contentChanged = $isNew || $copyChanged || $coordinateChanged || $event->isDirty([
            'starts_at', 'ends_at', 'location_name', 'address', 'price_text', 'source_url',
        ]);
        $event->save();

        if ($geoInputChanged) {
            DB::statement('UPDATE events SET location = NULL, venue_id = NULL, needs_review = TRUE WHERE id = ?', [$event->id]);
        }

        $this->storeTrustedCoordinates($event, $record, $serviceArea, $metrics);
        $this->captureTeaserImage($event, $record, $captureMediaCandidate);

        if ($contentChanged) {
            ProcessEventJob::dispatch($event);
        }

        $metrics[$isNew ? 'created' : ($contentChanged ? 'updated' : 'unchanged')]++;
    }

    /** @param array<string, mixed> $record */
    private function captureTeaserImage(
        Event $event,
        array $record,
        CaptureMediaCandidate $captureMediaCandidate,
    ): void {
        $teaserImage = trim((string) ($record['teaserbild'] ?? ''));
        if ($teaserImage === '') {
            return;
        }

        $remoteUrl = str_starts_with($teaserImage, '/')
            ? 'https://www.stadt-koeln.de'.$teaserImage
            : $this->httpsUrl($teaserImage);

        try {
            $captureMediaCandidate->execute($event, new MediaCandidate(
                provider: 'stadt-koeln',
                remoteUrl: $remoteUrl,
                providerAssetId: parse_url($remoteUrl, PHP_URL_PATH) ?: $teaserImage,
                sourcePageUrl: $event->source_url,
                role: 'poster',
                priority: 10,
                isPrimary: true,
            ));
        } catch (Throwable $exception) {
            Log::warning('Official Cologne event media candidate was skipped', [
                'event_id' => $event->id,
                'remote_url' => $remoteUrl,
                'error' => $exception->getMessage(),
            ]);
        }
    }

    /**
     * @param  array<string, mixed>  $record
     * @param  array{created: int, updated: int, unchanged: int, skipped: int, invalid_coordinates: int}  $metrics
     */
    private function storeTrustedCoordinates(Event $event, array $record, CologneServiceArea $serviceArea, array &$metrics): void
    {
        $latitude = filter_var(trim((string) ($record['latitude'] ?? '')), FILTER_VALIDATE_FLOAT);
        $longitude = filter_var(trim((string) ($record['longitude'] ?? '')), FILTER_VALIDATE_FLOAT);

        if ($latitude === false || $longitude === false) {
            DB::statement('UPDATE events SET location = NULL, venue_id = NULL, needs_review = TRUE WHERE id = ?', [$event->id]);

            return;
        }

        $city = mb_strtolower(trim((string) ($record['ort'] ?? '')));
        if (! in_array($city, ['köln', 'koeln'], true)) {
            $metrics['invalid_coordinates']++;
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
            Log::warning('Official event coordinate validation failed closed', [
                'event_id' => $event->id,
                'error' => $exception->getMessage(),
            ]);

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

    /** @param array<string, mixed> $record
     * @return array{Carbon, Carbon}
     */
    private function parseSchedule(array $record): array
    {
        $startDate = $this->parseDate($record['beginndatum'] ?? null) ?? now()->startOfDay();
        $endDate = $this->parseDate($record['endedatum'] ?? null) ?? $startDate->copy();
        $time = preg_replace('/\s+/u', ' ', trim((string) ($record['uhrzeit'] ?? '')));

        if (preg_match('/(?<sh>\d{1,2})(?::(?<sm>\d{2}))?\s*(?:bis|[-–])\s*(?<eh>\d{1,2})(?::(?<em>\d{2}))?\s*Uhr/iu', $time, $match)) {
            if (! $this->validClock($match['sh'], $match['sm'] ?? null)
                || ! $this->validClock($match['eh'], $match['em'] ?? null)) {
                throw new \InvalidArgumentException('Invalid event time.');
            }
            $startsAt = $startDate->copy()->setTime((int) $match['sh'], (int) (($match['sm'] ?? '') ?: 0));
            $endsAt = $endDate->copy()->setTime((int) $match['eh'], (int) (($match['em'] ?? '') ?: 0));
            if ($endsAt->lessThanOrEqualTo($startsAt)) {
                $endsAt->addDay();
            }

            return [$startsAt, $endsAt];
        }

        if (preg_match('/(?<h>\d{1,2})(?::(?<m>\d{2}))?\s*Uhr/iu', $time, $match)) {
            if (! $this->validClock($match['h'], $match['m'] ?? null)) {
                throw new \InvalidArgumentException('Invalid event time.');
            }
            $startsAt = $startDate->copy()->setTime((int) $match['h'], (int) (($match['m'] ?? '') ?: 0));

            return [$startsAt, $startsAt->copy()->addHours(2)];
        }

        return [$startDate->copy()->startOfDay(), $endDate->copy()->endOfDay()];
    }

    private function parseDate(mixed $value): ?Carbon
    {
        if (! is_string($value) || ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            return null;
        }

        try {
            $date = Carbon::createFromFormat('!Y-m-d', $value, 'Europe/Berlin');

            return $date->format('Y-m-d') === $value ? $date : null;
        } catch (Throwable) {
            return null;
        }
    }

    private function validClock(string $hour, ?string $minute): bool
    {
        return (int) $hour >= 0 && (int) $hour <= 23
            && ($minute === null || $minute === '' || ((int) $minute >= 0 && (int) $minute <= 59));
    }

    /** @param array<string, mixed> $record */
    private function sourceUid(array $record): string
    {
        $link = (string) ($record['link'] ?? '');
        if (preg_match('~/daten/(\d+)/~', $link, $match)) {
            return $match[1];
        }

        return hash('sha256', $link);
    }

    /** @param array<string, mixed> $record */
    private function address(array $record): ?string
    {
        $street = trim(implode(' ', array_filter([
            $this->cleanText($record['strasse'] ?? null),
            $this->cleanText($record['hausnummer'] ?? null),
        ])));
        $city = trim(implode(' ', array_filter([
            $this->cleanText($record['plz'] ?? null),
            $this->cleanText($record['ort'] ?? null),
        ])));

        return implode(', ', array_filter([$street, $city])) ?: null;
    }

    /** @return array{bool, ?float} */
    private function parsePrice(?string $priceText): array
    {
        if ($priceText === null) {
            return [false, null];
        }

        if (preg_match('/\b(kostenlos|eintritt\s+frei|frei|gratis)\b/iu', $priceText)) {
            return [true, null];
        }

        if (preg_match('/(\d+(?:[,.]\d{1,2})?)/', $priceText, $match)) {
            return [false, (float) str_replace(',', '.', $match[1])];
        }

        return [false, null];
    }

    private function category(string $text): EventCategory
    {
        return match (true) {
            (bool) preg_match('/sprach|language|tandem|deutschkurs/iu', $text) => EventCategory::LanguageExchange,
            (bool) preg_match('/stammtisch/iu', $text) => EventCategory::Stammtisch,
            (bool) preg_match('/international|interkulturell|begegnung|network/iu', $text) => EventCategory::IntlMeetup,
            (bool) preg_match('/sport|lauf|rad|yoga|fitness|schwimm|wander/iu', $text) => EventCategory::Sports,
            (bool) preg_match('/party|clubbing|dance|tanzparty/iu', $text) => EventCategory::Party,
            (bool) preg_match('/konzert|musik|museum|ausstellung|führung|theater|kino|film|lesung|literar|kunst/iu', $text) => EventCategory::Culture,
            default => EventCategory::Other,
        };
    }

    /**
     * @param  list<string>  $existing
     * @param  array<string, mixed>  $record
     * @return list<string>
     */
    private function mergedTags(array $existing, array $record, bool $multiDay, bool $hasDailyHours): array
    {
        $preserved = array_values(array_filter($existing, fn (string $tag): bool => $tag !== 'official-city'
            && $tag !== 'multi-day'
            && $tag !== 'multi-day-uncertain'
            && ! str_starts_with($tag, 'district:')
            && ! str_starts_with($tag, 'veedel:')));

        return array_values(array_unique([...$preserved, ...array_filter([
            'official-city',
            filled($record['stadtbezirk'] ?? null) ? 'district:'.trim((string) $record['stadtbezirk']) : null,
            filled($record['stadtteil'] ?? null) ? 'veedel:'.trim((string) $record['stadtteil']) : null,
            $multiDay ? 'multi-day' : null,
            $multiDay && ! $hasDailyHours ? 'multi-day-uncertain' : null,
        ])]));
    }

    private function qualityScore(?string $description, ?string $venue, ?string $address, Carbon $startsAt, ?string $priceText): float
    {
        return min(1.0,
            ($venue ? 0.25 : 0)
            + ($address ? 0.2 : 0)
            + ($description && mb_strlen($description) > 20 ? 0.2 : 0)
            + ($startsAt->hour > 0 ? 0.15 : 0)
            + 0.1
            + ($priceText ? 0.1 : 0),
        );
    }

    private function cleanText(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $decoded = html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $decoded = preg_replace('/<\/(?:p|div|li|h[1-6])>|<br\s*\/?>/iu', "\n\n", $decoded);
        $clean = strip_tags($decoded);
        $clean = preg_replace('/[^\S\r\n]+/u', ' ', $clean);
        $clean = trim(preg_replace('/(?:\r?\n\s*){3,}/u', "\n\n", $clean));

        return $clean !== '' ? $clean : null;
    }

    private function httpsUrl(string $url): string
    {
        return preg_replace('/^http:\/\//i', 'https://', trim($url));
    }
}

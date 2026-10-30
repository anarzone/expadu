<?php

namespace App\Services;

use App\Support\PerfLogger;
use App\Transit\TransitNotices;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Pool;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * VRS TRIAS API client for real-time departure boards and stop search.
 * Primary source for departure data — falls back to GTFS static when unavailable.
 */
class VrsTriasService
{
    /** Failures in a row that make an outage rather than a blip. */
    private const OUTAGE_FAILURES = 3;

    /**
     * Failures in a row before TRIAS is skipped at all. VRS's live server
     * answers in a quarter of a second but now and then one request hangs
     * (23 Oct 2026: 61 of 64 failures were a single 3 s timeout with 0
     * bytes); skipping everything for 30 s after one sent a 37-stop route's
     * boards to the timetable. One hang now falls back for that request alone.
     */
    private const BLIP_FAILURES = 2;

    /** Seconds TRIAS is skipped after a blip. */
    private const BLIP_SECONDS = 30;

    /** Seconds TRIAS is skipped in an outage. */
    private const OUTAGE_SECONDS = 300;

    /**
     * Get real-time departures from a stop using TRIAS StopEventRequest.
     *
     * @return array{stop_name: string, source: string, departures: array}|null
     */
    public function getDepartures(string $stopName, int $limit = 10): ?array
    {
        return PerfLogger::measure('ext:vrs.getDepartures', function () use ($stopName, $limit) {
            if (! config('services.vrs.enabled')) {
                return null;
            }

            try {
                $globalId = $this->resolveStopGlobalId($stopName);
                if (! $globalId) {
                    return null;
                }

                $cacheKey = "trias_departures_v3_{$globalId['id']}_{$limit}";
                $cached = Cache::get($cacheKey);
                if ($cached !== null) {
                    return $cached;
                }

                $departures = $this->fetchStopEvents($globalId['id'], $limit);
                if ($departures === null) {
                    return null;
                }

                $result = [
                    'stop_name' => $globalId['name'],
                    'source' => 'trias_rt',
                    ...$departures,
                ];

                Cache::put($cacheKey, $result, 60);

                return $result;
            } catch (\Throwable $e) {
                Log::error('TRIAS getDepartures exception', ['stop' => $stopName, 'error' => $e->getMessage()]);

                return null;
            }
        });
    }

    /**
     * Get real-time departures for the nearest stop to given coordinates.
     *
     * @return array{stop_name: string, source: string, departures: array}|null
     */
    public function getDeparturesNearby(float $lat, float $lng, int $limit = 10): ?array
    {
        if (! config('services.vrs.enabled')) {
            return null;
        }

        $cacheKey = 'trias_departures_nearby_v2_'.round($lat, 3).'_'.round($lng, 3)."_{$limit}"; // gitleaks:allow (cache key name, not a secret)

        // Return cached live data if available
        $cached = Cache::get($cacheKey);
        if ($cached !== null) {
            return $cached;
        }

        // Try TRIAS — only cache successful results
        $globalId = $this->resolveNearbyStop($lat, $lng);
        if (! $globalId) {
            return null;
        }

        $departures = $this->fetchStopEvents($globalId['id'], $limit);
        if ($departures === null) {
            return null;
        }

        $result = [
            'stop_name' => $globalId['name'],
            'source' => 'trias_rt',
            ...$departures,
        ];

        Cache::put($cacheKey, $result, 60);

        return $result;
    }

    /**
     * Resolve a stop name to a TRIAS GlobalID via LocationInformationRequest.
     *
     * @return array{id: string, name: string}|null
     */
    public function resolveStopGlobalId(string $stopName): ?array
    {
        // Strip "Köln " prefix — we re-add it to ensure Cologne results
        $search = preg_replace('/^Köln\s+/i', '', $stopName);
        $cacheKey = "trias_stop_id_v2_{$search}";

        $cached = Cache::get($cacheKey);
        if ($cached !== null) {
            return $cached;
        }

        // Prefix "Köln" to disambiguate stops that exist in multiple cities
        $xml = $this->buildLocationRequest("Köln {$search}");
        $response = $this->post($xml);

        if (! $response) {
            return null;
        }

        $parsed = $this->parseXml($response);
        if (! $parsed) {
            return null;
        }

        $refs = $parsed->xpath('//StopPlaceRef');
        $names = $parsed->xpath('//StopPlaceName/Text');

        $result = $this->findExactStop($search, $refs, $names)
            ?? $this->resolveStopWithoutPrefix($search);

        if ($result === null) {
            return null;
        }

        Cache::put($cacheKey, $result, 86400);

        return $result;
    }

    /**
     * Fallback stop resolution without city prefix.
     */
    private function resolveStopWithoutPrefix(string $search): ?array
    {
        $xml = $this->buildLocationRequest($search);
        $response = $this->post($xml);

        if (! $response) {
            return null;
        }

        $parsed = $this->parseXml($response);
        if (! $parsed) {
            return null;
        }

        $refs = $parsed->xpath('//StopPlaceRef');
        $names = $parsed->xpath('//StopPlaceName/Text');

        return $this->findExactStop($search, $refs, $names);
    }

    /**
     * @param  array<int, \SimpleXMLElement>  $refs
     * @param  array<int, \SimpleXMLElement>  $names
     * @return array{id: string, name: string}|null
     */
    private function findExactStop(string $search, array $refs, array $names): ?array
    {
        $expected = $this->normalizeStopName($search);

        foreach ($names as $index => $name) {
            if (! isset($refs[$index]) || $this->normalizeStopName((string) $name) !== $expected) {
                continue;
            }

            return ['id' => (string) $refs[$index], 'name' => (string) $name];
        }

        return null;
    }

    private function normalizeStopName(string $name): string
    {
        $withoutCity = preg_replace('/^Köln\s+/iu', '', trim($name)) ?? trim($name);

        return mb_strtolower(preg_replace('/[^\pL\pN]+/u', ' ', $withoutCity) ?? $withoutCity);
    }

    /**
     * Find the nearest stop to coordinates via TRIAS LocationInformationRequest.
     *
     * @return array{id: string, name: string}|null
     */
    public function resolveNearbyStop(float $lat, float $lng): ?array
    {
        $cacheKey = 'trias_nearby_stop_'.round($lat, 3).'_'.round($lng, 3);

        $cached = Cache::get($cacheKey);
        if ($cached !== null) {
            return $cached;
        }

        $xml = $this->buildGeoLocationRequest($lat, $lng);
        $response = $this->post($xml);

        if (! $response) {
            return null;
        }

        $parsed = $this->parseXml($response);
        if (! $parsed) {
            return null;
        }

        $refs = $parsed->xpath('//StopPlaceRef');
        $names = $parsed->xpath('//StopPlaceName/Text');

        if (empty($refs) || empty($names)) {
            return null;
        }

        $result = [
            'id' => (string) $refs[0],
            'name' => (string) $names[0],
        ];

        Cache::put($cacheKey, $result, 300);

        return $result;
    }

    /**
     * Fetch stop events (departures) from TRIAS and parse into app format.
     *
     * @return array{departures: array, observed_at: string}|null
     */
    private function fetchStopEvents(string $globalId, int $limit): ?array
    {
        $xml = $this->buildStopEventRequest($globalId, $limit);
        $response = $this->post($xml);

        if (! $response) {
            return null;
        }

        return $this->parseStopEvents($response, $globalId, $limit, now()->utc()->toIso8601String());
    }

    /**
     * Fetch live departures for several stops in one parallel round trip, under
     * one deadline, instead of one sequential call per stop. Results land in the
     * per-stop cache getDepartures() reads, so callers keep asking per stop and
     * a stop that misses here simply takes its usual path.
     *
     * @param  list<string>  $stopNames
     */
    public function prefetchDepartures(array $stopNames, int $limit = 10, float $budgetSeconds = 3.0): void
    {
        if (! config('services.vrs.enabled') || Cache::get('trias_down')) {
            return;
        }

        $deadline = microtime(true) + $budgetSeconds;

        // Stop ids are cached for a day; a miss costs one lookup here.
        $pending = [];
        foreach (array_unique($stopNames) as $stopName) {
            $globalId = $this->resolveStopGlobalId($stopName);
            if ($globalId === null) {
                continue;
            }

            $cacheKey = "trias_departures_v3_{$globalId['id']}_{$limit}";
            if (! isset($pending[$cacheKey]) && ! Cache::has($cacheKey)) {
                $pending[$cacheKey] = $globalId;
            }
        }

        $remaining = $deadline - microtime(true);
        if ($pending === [] || $remaining < 0.5) {
            return;
        }

        $bodies = $this->postMany(
            array_map(fn (array $globalId) => $this->buildStopEventRequest($globalId['id'], $limit), $pending),
            min(3.0, $remaining),
        );
        $observedAt = now()->utc()->toIso8601String();

        foreach ($bodies as $cacheKey => $body) {
            $departures = $body === null ? null : $this->parseStopEvents($body, $pending[$cacheKey]['id'], $limit, $observedAt);
            if ($departures === null) {
                continue;
            }

            Cache::put($cacheKey, [
                'stop_name' => $pending[$cacheKey]['name'],
                'source' => 'trias_rt',
                ...$departures,
            ], 60);
        }
    }

    /**
     * Parse a StopEventResponse into app-format departures.
     *
     * @return array{departures: array, observed_at: string}|null
     */
    private function parseStopEvents(string $response, string $globalId, int $limit, string $observedAt): ?array
    {
        $parsed = $this->parseXml($response);
        if (! $parsed) {
            return null;
        }

        $results = $parsed->xpath('//StopEventResult');
        if (empty($results)) {
            return null;
        }

        $now = Carbon::now();
        $disruptedLines = app(DisruptionService::class)->getDisruptedLines();
        $grouped = [];
        // Line refs to the names riders see, for the situations below.
        $lineNames = [];

        foreach ($results as $result) {
            $event = $result->StopEvent ?? null;
            if (! $event) {
                continue;
            }

            $service = $event->Service ?? null;
            $call = $event->ThisCall ?? null;
            if (! $service || ! $call) {
                continue;
            }

            $section = $service->ServiceSection ?? $service;
            $lineName = (string) ($section->PublishedLineName->Text ?? '');
            $dest = (string) ($service->DestinationText->Text ?? '');
            $ptMode = (string) ($section->Mode->PtMode ?? '');

            if (! $lineName && ! $dest) {
                continue;
            }

            // Get times — prefer EstimatedTime (live), fall back to TimetabledTime
            $stop = $call->CallAtStop;
            $departure = $stop->ServiceDeparture ?? null;
            $scheduled = (string) ($departure->TimetabledTime ?? '');
            $estimated = (string) ($departure->EstimatedTime ?? '');
            $timeStr = $estimated ?: $scheduled;

            if (! $timeStr) {
                continue;
            }

            $depTime = Carbon::parse($timeStr);
            $scheduledTime = $scheduled ? Carbon::parse($scheduled) : $depTime;
            $minsAway = (int) $now->diffInMinutes($depTime, false);

            if ($minsAway < 0) {
                continue;
            }

            // Calculate delay in minutes
            $delayMin = $estimated && $scheduled
                ? max(0, (int) round(($depTime->timestamp - $scheduledTime->timestamp) / 60))
                : 0;
            $cancelled = filter_var((string) ($service->Cancelled ?? ''), FILTER_VALIDATE_BOOLEAN)
                || filter_var((string) ($stop->NotServicedStop ?? ''), FILTER_VALIDATE_BOOLEAN)
                || filter_var((string) ($stop->NoBoardingAtStop ?? ''), FILTER_VALIDATE_BOOLEAN);
            $stopId = trim((string) ($stop->StopPointRef ?? ''));
            $stopName = trim((string) ($stop->StopPointName->Text ?? ''));
            $platform = trim((string) ($stop->EstimatedBay->Text ?? $stop->PlannedBay->Text ?? ''));
            $identity = implode('|', [
                (string) ($service->OperatingDayRef ?? ''),
                (string) ($service->JourneyRef ?? ''),
                (string) ($section->LineRef ?? ''),
                $lineName, $dest, $stopId ?: $globalId,
                $scheduledTime->toIso8601String(),
            ]);

            $type = $this->mapPtMode($ptMode, $lineName);
            if ((string) ($section->LineRef ?? '') !== '' && $lineName !== '') {
                $lineNames[(string) $section->LineRef] = $lineName;
            }

            $key = $lineName.'_'.$dest;
            if (! isset($grouped[$key])) {
                $isDisrupted = isset($disruptedLines[$lineName]);
                $grouped[$key] = [
                    'line' => $lineName,
                    'direction' => $dest,
                    'color' => '#1A4CD4',
                    'type' => $type,
                    'departures' => [],
                    'services' => [],
                    'delay' => 0,
                    'cancelled' => false,
                    'disrupted' => $isDisrupted,
                    'disruption_severity' => $isDisrupted ? $disruptedLines[$lineName] : null,
                ];
            }

            $id = 'trias:'.sha1($identity);
            $grouped[$key]['services'][$id] = [
                'id' => $id,
                'departure_at' => $depTime->toIso8601String(),
                'scheduled_at' => $scheduled !== '' ? $scheduledTime->toIso8601String() : null,
                'expected_at' => $estimated !== '' ? $depTime->toIso8601String() : null,
                'time_basis' => $estimated !== '' ? 'realtime' : 'scheduled',
                'delay' => $delayMin,
                'cancelled' => $cancelled,
                // VRS's own notices about this departure (TRIAS situations).
                'notices' => self::situationRefs($service, $stop),
                'boarding_point' => $stopId !== '' || $stopName !== '' ? [
                    'id' => $stopId !== '' ? $stopId : null,
                    'name' => $stopName !== '' ? $stopName : null,
                    'platform' => $platform !== '' ? $platform : null,
                ] : null,
            ];
        }

        $this->rememberSituations($parsed, $lineNames);

        foreach ($grouped as &$group) {
            $services = array_values($group['services']);
            usort($services, fn (array $a, array $b) => Carbon::parse($a['departure_at'])->timestamp <=> Carbon::parse($b['departure_at'])->timestamp);
            $group['services'] = array_slice($services, 0, 5);
            $group['departures'] = array_values(array_unique(array_map(
                fn (array $item) => (int) $now->diffInMinutes(Carbon::parse($item['departure_at']), false),
                $group['services'],
            )));
            $group['delay'] = $group['services'][0]['delay'] ?? 0;
            $group['cancelled'] = $group['services'][0]['cancelled'] ?? false;
        }
        unset($group);

        // Filter out empty groups and sort: trams first, buses last, then by next departure
        $typeOrder = ['tram' => 0, 'subway' => 1, 'rail' => 2, 'bus' => 3];
        $results = array_values(array_filter($grouped, fn ($g) => ! empty($g['departures'])));
        usort($results, function ($a, $b) use ($typeOrder) {
            $aType = $typeOrder[$a['type'] ?? 'bus'] ?? 3;
            $bType = $typeOrder[$b['type'] ?? 'bus'] ?? 3;
            if ($aType !== $bType) {
                return $aType <=> $bType;
            }

            return ($a['departures'][0] ?? 999) <=> ($b['departures'][0] ?? 999);
        });

        return ['departures' => array_slice($results, 0, $limit), 'observed_at' => $observedAt];
    }

    /** A TRIAS situation's id in the notices store. */
    public static function situationKey(string $participant, string $number): string
    {
        return 'trias:situation:'.($participant !== '' ? $participant.':' : '').$number;
    }

    /**
     * The situations a departure refers to (SituationFullRef on its service
     * or its call), as notices-store keys.
     *
     * @return list<string>
     */
    private static function situationRefs(\SimpleXMLElement $service, ?\SimpleXMLElement $call): array
    {
        $refs = [];
        foreach ([$service, $call] as $node) {
            foreach ($node?->SituationFullRef ?? [] as $ref) {
                $number = trim((string) ($ref->SituationNumber ?? ''));
                if ($number !== '') {
                    $refs[] = self::situationKey(trim((string) ($ref->ParticipantRef ?? '')), $number);
                }
            }
        }

        return array_values(array_unique($refs));
    }

    /**
     * VRS's official notices (SIRI situations), which come in the StopEvent
     * response's context: kept in the notices store with their lines, stops
     * and validity, by their own id, so each is briefed once.
     *
     * @param  array<string, string>  $lineNames  line refs to published names
     */
    private function rememberSituations(\SimpleXMLElement $parsed, array $lineNames): void
    {
        $text = fn (?\SimpleXMLElement $node): string => $node === null ? '' : trim((string) ($node->Text ?? $node));
        $notices = app(TransitNotices::class);

        foreach ($parsed->xpath('//StopEventResponseContext//PtSituation') ?: [] as $situation) {
            $number = trim((string) ($situation->SituationNumber ?? ''));
            if ($number === '') {
                continue;
            }

            $lines = [];
            foreach ($situation->xpath('.//AffectedLine') ?: [] as $line) {
                $name = $text($line->PublishedLineName ?? null);
                $lines[] = $name !== '' ? $name : ($lineNames[trim((string) ($line->LineRef ?? ''))] ?? '');
            }
            $stops = [];
            foreach ($situation->xpath('.//AffectedStopPoint') ?: [] as $point) {
                $name = $text($point->StopPointName ?? null);
                $stops[] = $name !== '' ? $name : trim((string) ($point->StopPointRef ?? ''));
            }
            $from = trim((string) ($situation->ValidityPeriod->StartTime ?? ''));
            $until = trim((string) ($situation->ValidityPeriod->EndTime ?? ''));

            $notices->remember(
                self::situationKey(trim((string) ($situation->ParticipantRef ?? '')), $number),
                'vrs-trias',
                $text($situation->Summary ?? null),
                implode("\n\n", array_filter([$text($situation->Description ?? null), $text($situation->Detail ?? null)])),
                array_values(array_filter($lines)),
                array_values(array_filter($stops)),
                [],
                $from !== '' ? CarbonImmutable::parse($from) : null,
                $until !== '' ? CarbonImmutable::parse($until) : null,
            );
        }
    }

    /**
     * Get real-time departures from a stop by its TRIAS GlobalID.
     * Used for showing connection alternatives at transfer points.
     *
     * @return array<int, array{line: string, direction: string, departure_time: string, type: string}>|null
     */
    public function getDeparturesAtStop(string $globalId, int $limit = 10, ?string $afterTime = null): ?array
    {
        if (! config('services.vrs.enabled')) {
            return null;
        }

        $cacheKey = "trias_stop_deps_v2_{$globalId}_{$limit}";

        $cached = Cache::get($cacheKey);
        if ($cached !== null) {
            return $cached;
        }

        $result = $this->fetchStopEvents($globalId, $limit)['departures'] ?? null;
        if ($result !== null) {
            Cache::put($cacheKey, $result, 60);
        }

        return $result;
    }

    /**
     * Plan a journey from a specific stop (by GlobalID) to a destination.
     * Used when user picks an alternative connection at a transfer point.
     *
     * @return array{source: string, trips: array}|null
     */
    public function planJourneyFromStop(string $fromStopId, float $toLat, float $toLng, string $departureTime, int $maxResults = 3): ?array
    {
        if (! config('services.vrs.enabled')) {
            return null;
        }

        $cacheKey = "trias_trip_stop_v2_{$fromStopId}_{$toLat}_{$toLng}_{$departureTime}";

        $cached = Cache::get($cacheKey);
        if ($cached !== null) {
            return $cached;
        }

        $xml = $this->buildTripRequestFromStop($fromStopId, $toLat, $toLng, $departureTime, $maxResults);
        $response = $this->post($xml);

        if (! $response) {
            return null;
        }

        $parsed = $this->parseXml($response);
        if (! $parsed) {
            return null;
        }

        $result = $this->parseTripResponse($parsed);
        if ($result !== null) {
            Cache::put($cacheKey, $result, 60);
        }

        return $result;
    }

    /**
     * Plan a transit journey from origin to destination using TRIAS TripRequest.
     * Returns multiple trip alternatives with walk/transit segments.
     *
     * @return array{source: string, trips: array}|null
     */
    public function planJourney(float $fromLat, float $fromLng, float $toLat, float $toLng, int $maxResults = 3): ?array
    {
        return PerfLogger::measure('ext:vrs.planJourney', function () use ($fromLat, $fromLng, $toLat, $toLng, $maxResults) {
            if (! config('services.vrs.enabled')) {
                return null;
            }

            $cacheKey = "trias_trip_v2_{$fromLat}_{$fromLng}_{$toLat}_{$toLng}_{$maxResults}";

            $cached = Cache::get($cacheKey);
            if ($cached !== null) {
                return $cached;
            }

            $xml = $this->buildTripRequest($fromLat, $fromLng, $toLat, $toLng, $maxResults);
            $response = $this->post($xml);

            if (! $response) {
                return null;
            }

            $parsed = $this->parseXml($response);
            if (! $parsed) {
                return null;
            }

            $result = $this->parseTripResponse($parsed);
            if ($result !== null) {
                Cache::put($cacheKey, $result, 60);
            }

            return $result;
        });
    }

    /**
     * Parse TRIAS TripResponse into structured trip alternatives.
     *
     * @return array{source: string, trips: array}|null
     */
    private function parseTripResponse(\SimpleXMLElement $xml): ?array
    {
        $tripResults = $xml->xpath('//TripResult');
        if (empty($tripResults)) {
            return null;
        }

        $trips = [];

        foreach ($tripResults as $tripResult) {
            $trip = $tripResult->Trip ?? null;
            if (! $trip) {
                continue;
            }

            $durationIso = (string) ($trip->Duration ?? '');
            $startTime = (string) ($trip->StartTime ?? '');
            $endTime = (string) ($trip->EndTime ?? '');
            $interchanges = (int) ($trip->Interchanges ?? 0);

            $segments = [];
            $steps = [];

            foreach ($trip->xpath('TripLeg') as $leg) {
                if ($leg->TimedLeg) {
                    $this->parseTimedLeg($leg->TimedLeg, $segments, $steps);
                } elseif ($leg->ContinuousLeg) {
                    $this->parseContinuousLeg($leg->ContinuousLeg, $segments, $steps);
                } elseif ($leg->InterchangeLeg) {
                    $this->parseInterchangeLeg($leg->InterchangeLeg, $segments, $steps);
                }
            }

            if (empty($segments)) {
                continue;
            }

            $trips[] = [
                'total_duration_min' => $this->isoDurationToMinutes($durationIso),
                'departure_at' => $startTime ?: null,
                'arrival_at' => $endTime ?: null,
                'departure_time' => $startTime ? Carbon::parse($startTime)->format('H:i') : '',
                'arrival_time' => $endTime ? Carbon::parse($endTime)->format('H:i') : '',
                'transfers' => $interchanges,
                'segments' => $segments,
                'steps' => $steps,
            ];
        }

        if (empty($trips)) {
            return null;
        }

        return [
            'source' => 'trias',
            'trips' => $trips,
        ];
    }

    private function parseTimedLeg(\SimpleXMLElement $tl, array &$segments, array &$steps): void
    {
        $section = $tl->Service->ServiceSection ?? null;
        $lineName = (string) ($section->PublishedLineName->Text ?? '');
        $ptMode = (string) ($section->Mode->PtMode ?? '');
        $type = $this->mapPtMode($ptMode, $lineName);

        $boardStop = (string) ($tl->LegBoard->StopPointName->Text ?? '');
        $alightStop = (string) ($tl->LegAlight->StopPointName->Text ?? '');
        $boardStopId = (string) ($tl->LegBoard->StopPointRef ?? '');
        $alightStopId = (string) ($tl->LegAlight->StopPointRef ?? '');

        // Strip city prefix for cleaner display (e.g. "Chorweiler (U) Gleis 3, Köln Chorweiler" → "Chorweiler")
        $boardShort = preg_replace('/\s*\(.*$|,.*$/', '', $boardStop);
        $alightShort = preg_replace('/\s*\(.*$|,.*$/', '', $alightStop);

        $depSched = (string) ($tl->LegBoard->ServiceDeparture->TimetabledTime ?? '');
        $depEst = (string) ($tl->LegBoard->ServiceDeparture->EstimatedTime ?? '');
        $arrSched = (string) ($tl->LegAlight->ServiceArrival->TimetabledTime ?? '');
        $arrEst = (string) ($tl->LegAlight->ServiceArrival->EstimatedTime ?? '');

        $depTime = $depEst ?: $depSched;
        $arrTime = $arrEst ?: $arrSched;

        $delayMin = 0;
        if ($depSched && $depEst) {
            $delayMin = max(0, (int) round((Carbon::parse($depEst)->timestamp - Carbon::parse($depSched)->timestamp) / 60));
        }

        // Extract projection coordinates for map drawing
        $coordinates = [];
        foreach ($tl->xpath('.//Position') as $pos) {
            $lng = (float) ($pos->Longitude ?? 0);
            $lat = (float) ($pos->Latitude ?? 0);
            if ($lng && $lat) {
                $coordinates[] = [$lng, $lat];
            }
        }

        // Extract platform info
        $platform = (string) ($tl->LegBoard->PlannedBay->Text ?? '');

        $segments[] = [
            'type' => 'transit',
            'line' => $lineName,
            'direction' => $alightShort,
            'color' => '#1A4CD4',
            'mode' => $type,
            'coordinates' => $coordinates,
            'boarding_stop' => $boardShort,
            'alighting_stop' => $alightShort,
            'boarding_stop_id' => $boardStopId,
            'alighting_stop_id' => $alightStopId,
            'departure_at' => $depTime ?: null,
            'arrival_at' => $arrTime ?: null,
            'departure_time' => $depTime ? Carbon::parse($depTime)->format('H:i') : '',
            'departure_time_scheduled' => $depSched ? Carbon::parse($depSched)->format('H:i') : '',
            'arrival_time' => $arrTime ? Carbon::parse($arrTime)->format('H:i') : '',
            'platform' => $platform,
            'delay_min' => $delayMin,
            'duration_min' => ($depTime && $arrTime) ? max(1, (int) round((Carbon::parse($arrTime)->timestamp - Carbon::parse($depTime)->timestamp) / 60)) : 0,
        ];

        // Build steps
        $depDisplay = $depTime ? Carbon::parse($depTime)->format('H:i') : '';
        $arrDisplay = $arrTime ? Carbon::parse($arrTime)->format('H:i') : '';

        $steps[] = [
            'instruction' => "Board Line {$lineName} → {$alightShort} at {$boardShort}",
            'distance_km' => 0,
            'time_sec' => 0,
            'type' => 'board',
            'emoji' => $type === 'rail' ? '🚂' : ($type === 'tram' ? '🚋' : '🚌'),
            'detail' => "Dep {$depDisplay}".($platform ? " · Platform {$platform}" : ''),
            'delay_min' => $delayMin,
        ];

        $steps[] = [
            'instruction' => "Ride to {$alightShort}",
            'distance_km' => 0,
            'time_sec' => ($depTime && $arrTime) ? Carbon::parse($arrTime)->timestamp - Carbon::parse($depTime)->timestamp : 0,
            'type' => 'ride',
            'emoji' => $type === 'rail' ? '🚂' : ($type === 'tram' ? '🚋' : '🚌'),
            'detail' => "Arr {$arrDisplay}",
        ];
    }

    private function parseContinuousLeg(\SimpleXMLElement $cl, array &$segments, array &$steps): void
    {
        $durationIso = (string) ($cl->Duration ?? '');
        $durationMin = $this->isoDurationToMinutes($durationIso);

        $startPos = $cl->LegStart->GeoPosition ?? null;
        $endPos = $cl->LegEnd->GeoPosition ?? null;

        $coordinates = [];
        if ($startPos) {
            $coordinates[] = [(float) $startPos->Longitude, (float) $startPos->Latitude];
        }

        // Include projection points if available
        foreach ($cl->xpath('.//Position') as $pos) {
            $lng = (float) ($pos->Longitude ?? 0);
            $lat = (float) ($pos->Latitude ?? 0);
            if ($lng && $lat) {
                $coordinates[] = [$lng, $lat];
            }
        }

        if ($endPos) {
            $coordinates[] = [(float) $endPos->Longitude, (float) $endPos->Latitude];
        }

        $segments[] = [
            'type' => 'walk',
            'coordinates' => $coordinates,
            'duration_min' => $durationMin,
        ];

        $steps[] = [
            'instruction' => "Walk {$durationMin} min",
            'distance_km' => round($durationMin * 0.08, 1), // ~80m/min
            'time_sec' => $durationMin * 60,
            'type' => 'walk',
            'emoji' => '🚶',
        ];
    }

    private function parseInterchangeLeg(\SimpleXMLElement $il, array &$segments, array &$steps): void
    {
        $durationIso = (string) ($il->WalkDuration ?? $il->Duration ?? '');
        $durationMin = $this->isoDurationToMinutes($durationIso);

        $startStopId = (string) ($il->LegStart->StopPointRef ?? '');
        $endStopId = (string) ($il->LegEnd->StopPointRef ?? '');
        $stopName = (string) ($il->LegEnd->LocationName->Text ?? '');
        $stopShort = preg_replace('/\s*\(.*$|,.*$/', '', $stopName);

        // Use the parent stop ID (strip platform suffix like :7:72 → keep de:05315:XXXXX)
        $transferStopId = $endStopId ?: $startStopId;
        if ($transferStopId && preg_match('/^(de:\d+:\d+)/', $transferStopId, $m)) {
            $transferStopId = $m[1];
        }

        if ($durationMin >= 0) {
            $steps[] = [
                'instruction' => "Transfer at {$stopShort}".($durationMin > 0 ? " ({$durationMin} min)" : ''),
                'distance_km' => 0,
                'time_sec' => $durationMin * 60,
                'type' => 'transfer',
                'emoji' => '🔄',
                'transfer_stop_id' => $transferStopId,
                'transfer_stop_name' => $stopShort,
            ];
        }
    }

    private function isoDurationToMinutes(string $iso): int
    {
        if (! $iso) {
            return 0;
        }

        try {
            $interval = new \DateInterval($iso);

            return ($interval->h * 60) + $interval->i + (int) round($interval->s / 60);
        } catch (\Throwable) {
            return 0;
        }
    }

    private function buildTripRequestFromStop(string $fromStopId, float $toLat, float $toLng, string $departureTime, int $maxResults): string
    {
        $timestamp = now()->toIso8601String();
        $ref = config('services.vrs.requestor_ref', 'expadu');
        $depTime = Carbon::parse($departureTime)->toIso8601String();

        return <<<XML
        <?xml version="1.0" encoding="UTF-8"?>
        <Trias version="1.1" xmlns="http://www.vdv.de/trias" xmlns:siri="http://www.siri.org.uk/siri">
            <ServiceRequest>
                <siri:RequestTimestamp>{$timestamp}</siri:RequestTimestamp>
                <siri:RequestorRef>{$ref}</siri:RequestorRef>
                <RequestPayload>
                    <TripRequest>
                        <Origin>
                            <LocationRef>
                                <StopPointRef>{$fromStopId}</StopPointRef>
                            </LocationRef>
                            <DepArrTime>{$depTime}</DepArrTime>
                        </Origin>
                        <Destination>
                            <LocationRef>
                                <GeoPosition>
                                    <Longitude>{$toLng}</Longitude>
                                    <Latitude>{$toLat}</Latitude>
                                </GeoPosition>
                            </LocationRef>
                        </Destination>
                        <Params>
                            <NumberOfResults>{$maxResults}</NumberOfResults>
                            <IncludeLegProjection>true</IncludeLegProjection>
                        </Params>
                    </TripRequest>
                </RequestPayload>
            </ServiceRequest>
        </Trias>
        XML;
    }

    private function buildTripRequest(float $fromLat, float $fromLng, float $toLat, float $toLng, int $maxResults): string
    {
        $timestamp = now()->toIso8601String();
        $ref = config('services.vrs.requestor_ref', 'expadu');

        return <<<XML
        <?xml version="1.0" encoding="UTF-8"?>
        <Trias version="1.1" xmlns="http://www.vdv.de/trias" xmlns:siri="http://www.siri.org.uk/siri">
            <ServiceRequest>
                <siri:RequestTimestamp>{$timestamp}</siri:RequestTimestamp>
                <siri:RequestorRef>{$ref}</siri:RequestorRef>
                <RequestPayload>
                    <TripRequest>
                        <Origin>
                            <LocationRef>
                                <GeoPosition>
                                    <Longitude>{$fromLng}</Longitude>
                                    <Latitude>{$fromLat}</Latitude>
                                </GeoPosition>
                            </LocationRef>
                            <DepArrTime>{$timestamp}</DepArrTime>
                        </Origin>
                        <Destination>
                            <LocationRef>
                                <GeoPosition>
                                    <Longitude>{$toLng}</Longitude>
                                    <Latitude>{$toLat}</Latitude>
                                </GeoPosition>
                            </LocationRef>
                        </Destination>
                        <Params>
                            <NumberOfResults>{$maxResults}</NumberOfResults>
                            <IncludeLegProjection>true</IncludeLegProjection>
                        </Params>
                    </TripRequest>
                </RequestPayload>
            </ServiceRequest>
        </Trias>
        XML;
    }

    private function buildStopEventRequest(string $globalId, int $limit): string
    {
        $timestamp = now()->toIso8601String();
        $ref = config('services.vrs.requestor_ref', 'expadu');

        return <<<XML
        <?xml version="1.0" encoding="UTF-8"?>
        <Trias version="1.1" xmlns="http://www.vdv.de/trias" xmlns:siri="http://www.siri.org.uk/siri">
            <ServiceRequest>
                <siri:RequestTimestamp>{$timestamp}</siri:RequestTimestamp>
                <siri:RequestorRef>{$ref}</siri:RequestorRef>
                <RequestPayload>
                    <StopEventRequest>
                        <Location>
                            <LocationRef>
                                <StopPointRef>{$globalId}</StopPointRef>
                            </LocationRef>
                        </Location>
                        <Params>
                            <NumberOfResults>{$limit}</NumberOfResults>
                            <StopEventType>departure</StopEventType>
                            <IncludeRealtimeData>true</IncludeRealtimeData>
                        </Params>
                    </StopEventRequest>
                </RequestPayload>
            </ServiceRequest>
        </Trias>
        XML;
    }

    private function buildLocationRequest(string $name): string
    {
        $timestamp = now()->toIso8601String();
        $ref = config('services.vrs.requestor_ref', 'expadu');
        $name = htmlspecialchars($name, ENT_XML1);

        return <<<XML
        <?xml version="1.0" encoding="UTF-8"?>
        <Trias version="1.1" xmlns="http://www.vdv.de/trias" xmlns:siri="http://www.siri.org.uk/siri">
            <ServiceRequest>
                <siri:RequestTimestamp>{$timestamp}</siri:RequestTimestamp>
                <siri:RequestorRef>{$ref}</siri:RequestorRef>
                <RequestPayload>
                    <LocationInformationRequest>
                        <InitialInput>
                            <LocationName>{$name}</LocationName>
                        </InitialInput>
                        <Restrictions>
                            <Type>stop</Type>
                            <NumberOfResults>1</NumberOfResults>
                        </Restrictions>
                    </LocationInformationRequest>
                </RequestPayload>
            </ServiceRequest>
        </Trias>
        XML;
    }

    private function buildGeoLocationRequest(float $lat, float $lng): string
    {
        $timestamp = now()->toIso8601String();
        $ref = config('services.vrs.requestor_ref', 'expadu');

        return <<<XML
        <?xml version="1.0" encoding="UTF-8"?>
        <Trias version="1.1" xmlns="http://www.vdv.de/trias" xmlns:siri="http://www.siri.org.uk/siri">
            <ServiceRequest>
                <siri:RequestTimestamp>{$timestamp}</siri:RequestTimestamp>
                <siri:RequestorRef>{$ref}</siri:RequestorRef>
                <RequestPayload>
                    <LocationInformationRequest>
                        <InitialInput>
                            <GeoPosition>
                                <Longitude>{$lng}</Longitude>
                                <Latitude>{$lat}</Latitude>
                            </GeoPosition>
                        </InitialInput>
                        <Restrictions>
                            <Type>stop</Type>
                            <NumberOfResults>1</NumberOfResults>
                        </Restrictions>
                    </LocationInformationRequest>
                </RequestPayload>
            </ServiceRequest>
        </Trias>
        XML;
    }

    private function post(string $xml): ?string
    {
        // If TRIAS was down recently, skip entirely — instant fallback
        if (Cache::get('trias_down')) {
            return null;
        }

        $start = microtime(true);

        try {
            $url = config('services.vrs.trias_url');

            $options = ['timeout' => 3, 'connect_timeout' => 1];
            $this->applyVrsSsl($options);

            // Single attempt: a departure board refreshes every ~30s anyway, so
            // a retry storm only stretches the cold build (2 tries × 3s each);
            // the trias_down breaker + the caller's fallbacks cover a miss.
            $response = Http::withOptions($options)
                ->withHeaders(['Content-Type' => 'text/xml; charset=UTF-8'])
                ->withBody($xml, 'text/xml')
                ->post($url);

            $durationMs = round((microtime(true) - $start) * 1000);

            if (! $response->successful()) {
                $this->markTriasDown($durationMs, 'HTTP '.$response->status());

                return null;
            }

            // Success — mark as healthy
            $this->markTriasUp();

            return $response->body();
        } catch (\Throwable $e) {
            $durationMs = round((microtime(true) - $start) * 1000);
            $this->markTriasDown($durationMs, $e->getMessage());

            return null;
        }
    }

    /**
     * POST several TRIAS requests at once. Any failure trips the same
     * breaker post() uses.
     *
     * @param  array<string, string>  $xmlByKey
     * @return array<string, string|null> response bodies by key; null for a failed request
     */
    private function postMany(array $xmlByKey, float $timeout): array
    {
        $out = array_fill_keys(array_keys($xmlByKey), null);

        $options = ['timeout' => $timeout, 'connect_timeout' => min(1.0, $timeout)];
        $this->applyVrsSsl($options);
        $url = config('services.vrs.trias_url');
        $start = microtime(true);

        $responses = Http::pool(function (Pool $pool) use ($xmlByKey, $options, $url) {
            foreach ($xmlByKey as $key => $xml) {
                $pool->as($key)
                    ->withOptions($options)
                    ->withHeaders(['Content-Type' => 'text/xml; charset=UTF-8'])
                    ->withBody($xml, 'text/xml')
                    ->post($url);
            }
        });

        $error = null;
        foreach ($xmlByKey as $key => $xml) {
            $response = $responses[$key] ?? null;
            if ($response instanceof Response && $response->successful()) {
                $out[$key] = $response->body();

                continue;
            }

            $error ??= $response instanceof \Throwable
                ? $response->getMessage()
                : 'HTTP '.($response instanceof Response ? $response->status() : 'no response');
        }

        if ($error !== null) {
            $this->markTriasDown(round((microtime(true) - $start) * 1000), $error);
        } else {
            $this->markTriasUp();
        }

        return $out;
    }

    /**
     * Skip TRIAS after a failure, so no board waits on its timeout: for 30 s
     * after one (a blip), for five minutes once three in a row say it's down.
     * Boards meanwhile answer from the timetable with GTFS-RT delays at once.
     */
    private function markTriasDown(float $durationMs, string $error): void
    {
        $failures = (int) Cache::get('trias_failures', 0) + 1;
        Cache::put('trias_failures', $failures, now()->addMinutes(30));
        $skip = $failures >= self::OUTAGE_FAILURES ? self::OUTAGE_SECONDS
            : ($failures >= self::BLIP_FAILURES ? self::BLIP_SECONDS : 0);
        if ($skip > 0) {
            Cache::put('trias_down', true, $skip);
        }

        Log::warning('VRS TRIAS down', ['error' => $error, 'ms' => $durationMs, 'failures' => $failures, 'skip_s' => $skip]);
    }

    /** A TRIAS answer: healthy again, and the failure count starts over. */
    private function markTriasUp(): void
    {
        Cache::put('trias_healthy', true, 120);
        Cache::forget('trias_failures');
    }

    /**
     * Parse TRIAS XML response, stripping namespaces for simpler XPath.
     */
    private function parseXml(string $body): ?\SimpleXMLElement
    {
        // Strip namespace prefixes for easier XPath
        $clean = preg_replace('/xmlns[^=]*="[^"]*"/', '', $body);
        $clean = preg_replace('/<([a-z]+):/i', '<', $clean);
        $clean = preg_replace('/<\/([a-z]+):/i', '</', $clean);

        $xml = @simplexml_load_string($clean);

        return $xml ?: null;
    }

    /**
     * Apply VRS client certificate to Guzzle options for mTLS authentication.
     */
    private function applyVrsSsl(array &$options): void
    {
        $clientCert = config('services.vrs.client_cert');
        $clientCertPassword = config('services.vrs.client_cert_password');

        if ($clientCert && file_exists($clientCert)) {
            $options['cert'] = $clientCertPassword
                ? [$clientCert, $clientCertPassword]
                : $clientCert;
        }
    }

    private function mapPtMode(string $ptMode, string $lineName): string
    {
        if (preg_match('/^(S|RE|RB|IC)/', $lineName)) {
            return 'rail';
        }

        return match ($ptMode) {
            'tram' => 'tram',
            'metro' => 'subway',
            'rail', 'suburbanRailway', 'regionalRail' => 'rail',
            'bus', 'localBus' => 'bus',
            default => is_numeric($lineName) && (int) $lineName <= 18 ? 'tram' : 'bus',
        };
    }
}

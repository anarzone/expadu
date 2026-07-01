<?php

namespace App\Services;

use App\Support\PerfLogger;
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

            $cacheKey = "trias_departures_{$stopName}_{$limit}";

            $cached = Cache::get($cacheKey);
            if ($cached !== null) {
                return $cached;
            }

            try {
                $globalId = $this->resolveStopGlobalId($stopName);
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
                    'departures' => $departures,
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

        $cacheKey = 'trias_departures_nearby_'.round($lat, 3).'_'.round($lng, 3)."_{$limit}";

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
            'departures' => $departures,
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
        $cacheKey = "trias_stop_id_{$search}";

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

        if (empty($refs) || empty($names)) {
            return $this->resolveStopWithoutPrefix($search);
        }

        $result = [
            'id' => (string) $refs[0],
            'name' => (string) $names[0],
        ];

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

        if (empty($refs) || empty($names)) {
            return null;
        }

        return [
            'id' => (string) $refs[0],
            'name' => (string) $names[0],
        ];
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
     * @return array<int, array>|null
     */
    private function fetchStopEvents(string $globalId, int $limit): ?array
    {
        $xml = $this->buildStopEventRequest($globalId, $limit);
        $response = $this->post($xml);

        if (! $response) {
            return null;
        }

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

            $section = $service->ServiceSection ?? null;
            $lineName = (string) ($section->PublishedLineName->Text ?? '');
            $dest = (string) ($service->DestinationText->Text ?? '');
            $ptMode = (string) ($section->Mode->PtMode ?? '');

            if (! $lineName && ! $dest) {
                continue;
            }

            // Get times — prefer EstimatedTime (live), fall back to TimetabledTime
            $departure = $call->CallAtStop->ServiceDeparture ?? null;
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

            $type = $this->mapPtMode($ptMode, $lineName);

            $key = $lineName.'_'.$dest;
            if (! isset($grouped[$key])) {
                $isDisrupted = isset($disruptedLines[$lineName]);
                $grouped[$key] = [
                    'line' => $lineName,
                    'direction' => $dest,
                    'color' => '#1A4CD4',
                    'type' => $type,
                    'departures' => [],
                    'delay' => 0,
                    'cancelled' => false,
                    'disrupted' => $isDisrupted,
                    'disruption_severity' => $isDisrupted ? $disruptedLines[$lineName] : null,
                ];
            }

            if (count($grouped[$key]['departures']) < 5 && ! in_array($minsAway, $grouped[$key]['departures'], true)) {
                $grouped[$key]['departures'][] = $minsAway;

                // Use first departure's delay as the group indicator
                if (count($grouped[$key]['departures']) === 1) {
                    $grouped[$key]['delay'] = $delayMin;
                }
            }
        }

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

        return array_slice($results, 0, $limit);
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

        $cacheKey = "trias_stop_deps_{$globalId}_{$limit}";

        $cached = Cache::get($cacheKey);
        if ($cached !== null) {
            return $cached;
        }

        $result = $this->fetchStopEvents($globalId, $limit);
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

        $cacheKey = "trias_trip_stop_{$fromStopId}_{$toLat}_{$toLng}_{$departureTime}";

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

            $cacheKey = "trias_trip_{$fromLat}_{$fromLng}_{$toLat}_{$toLng}_{$maxResults}";

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
            Cache::put('trias_healthy', true, 120);

            return $response->body();
        } catch (\Throwable $e) {
            $durationMs = round((microtime(true) - $start) * 1000);
            $this->markTriasDown($durationMs, $e->getMessage());

            return null;
        }
    }

    private function markTriasDown(float $durationMs, string $error): void
    {
        // Skip TRIAS for 30s after a failure — prevents timeout cascades
        Cache::put('trias_down', true, 30);

        Log::warning('VRS TRIAS down', ['error' => $error, 'ms' => $durationMs]);
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

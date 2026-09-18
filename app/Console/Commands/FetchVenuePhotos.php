<?php

namespace App\Console\Commands;

use App\Media\CaptureMediaCandidate;
use App\Media\CommonsPhotoResolver;
use App\Media\MediaAcquisitionScheduler;
use App\Media\ScheduledMediaAcquisition;
use App\Models\Venue;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

/**
 * Resolves openly-licensed Wikimedia Commons photos for event venues, so
 * every event inherits real art through the presenter's media cascade
 * (event poster → venue hero → place hero).
 *
 * Venues carry no OSM tags, so the entity link is found by NAME: a
 * Wikidata search whose candidates must ALSO sit at the venue's actual
 * coordinates (P625 within a tight radius) before their P18 image is
 * trusted — name similarity alone would happily return the Berlin
 * Philharmonie for "Philharmonie". Venues without a verifiable entity
 * fall back to the name-matched Commons geosearch.
 */
class FetchVenuePhotos extends Command
{
    protected $signature = 'venues:fetch-photos
        {--force : Refresh venues that already have a published photo}
        {--limit=200 : Max venues to process per run}';

    protected $description = 'Fetch venue photos from Wikimedia Commons via coordinate-verified Wikidata name search + geosearch';

    /**
     * A Wikidata candidate must sit this close to the venue's coordinates
     * to be accepted as the same building.
     */
    private const ENTITY_MAX_DISTANCE_M = 500;

    /**
     * Building-type words say what KIND of place a venue is, not WHICH one —
     * "Museum" in "Rautenstrauch-Joest-Museum" must not match the Museum
     * Schnütgen next door. District names (from the veedels table) are added
     * at runtime for the same reason: "Ehrenfeld" locates a venue, it doesn't
     * identify it.
     */
    private const TYPE_STOP_WORDS = [
        'museum', 'bibliothek', 'stadtbibliothek', 'stadtteilbibliothek',
        'theater', 'kirche', 'schule', 'halle', 'stadthalle', 'turnhalle',
        'sporthalle', 'zentrum', 'bürgerzentrum', 'bürgerhaus', 'arena',
        'stadion', 'bahnhof', 'hospital', 'krankenhaus', 'rathaus', 'kapelle',
    ];

    public function __construct(private readonly CommonsPhotoResolver $resolver)
    {
        parent::__construct();
    }

    public function handle(
        CaptureMediaCandidate $captureMediaCandidate,
        MediaAcquisitionScheduler $scheduler,
    ): int {
        $query = Venue::query()
            ->whereNotNull('lat')
            ->whereNotNull('lng')
            ->when(! $this->option('force'), fn ($query) => $query->whereDoesntHave(
                'mediaAttachments',
                fn ($attachment) => $attachment->publishable('wikimedia-commons'),
            ));
        $scheduled = $scheduler->select(
            $query,
            'wikimedia-commons',
            'venue_search',
            max(1, (int) $this->option('limit')),
            fn (Venue $venue): array => [
                'name' => $venue->name,
                'address' => $venue->address_text,
                'lat' => $venue->lat,
                'lng' => $venue->lng,
                'entity_max_distance_metres' => self::ENTITY_MAX_DISTANCE_M,
                'geosearch_radius_metres' => CommonsPhotoResolver::GEOSEARCH_RADIUS_M,
            ],
            (bool) $this->option('force'),
        );
        $venues = $scheduled->map(fn (ScheduledMediaAcquisition $item) => $item->target);
        $scheduledByVenue = $scheduled->keyBy(fn (ScheduledMediaAcquisition $item) => $item->target->getKey());

        $this->info("Resolving photos for {$venues->count()} venue(s)...");

        // venue id => Commons file name
        $providerErrors = [];
        $files = $this->filesFromWikidataSearch($venues, $providerErrors);
        $wikidataVenueIds = array_keys($files);
        $this->info('Wikidata (coordinate-verified): '.count($files).' file(s).');

        $stopWords = $this->venueStopWords();

        foreach ($venues as $venue) {
            if (isset($files[$venue->id])) {
                continue;
            }
            $file = $this->resolver->geoSearchFile(
                (float) $venue->lat,
                (float) $venue->lng,
                $this->identityName((string) $venue->name),
                function (string $error) use ($venue, &$providerErrors): void {
                    $this->retainProviderError($providerErrors, $venue->id, $error);
                    $this->warn("  geosearch failed for venue {$venue->id}: {$error}");
                },
                $stopWords,
            );
            if ($file !== null) {
                $files[$venue->id] = $file;
            }
        }

        $result = $this->save($venues, $files, $wikidataVenueIds, $captureMediaCandidate);
        foreach ($scheduledByVenue as $venueId => $item) {
            $assetId = $result['captured'][$venueId] ?? null;
            $hasCandidate = isset($files[$venueId]);
            $providerError = $providerErrors[$venueId] ?? ($hasCandidate ? $result['provider_error'] : null);
            $scheduler->record(
                $item,
                $assetId !== null
                    ? 'captured'
                    : ($providerError !== null
                        ? (str_contains($providerError, '429') ? 'rate_limited' : 'failed')
                        : ($hasCandidate ? 'failed' : 'no_result')),
                errorCode: $assetId === null && ($hasCandidate || $providerError !== null)
                    ? ($providerError === null ? 'metadata_or_candidate_unavailable' : 'provider_request_failed')
                    : null,
                candidateCount: $hasCandidate ? 1 : 0,
                selectedAssetIds: $assetId === null ? [] : [$assetId],
                retryAfterSeconds: $scheduler->retryAfterSeconds($providerError),
                metadata: $hasCandidate ? ['commons_file' => $files[$venueId]] : null,
            );
        }

        $this->info("Venue photos saved: {$result['count']}.");
        $this->line(json_encode(
            ['acquisition_summary' => $scheduler->summary($scheduled)],
            JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
        ));

        return self::SUCCESS;
    }

    /**
     * @param  Collection<int, Venue>  $venues
     * @param  array<int, string>  $files  venue id => Commons file name
     */
    private function save(Collection $venues, array $files, array $wikidataVenueIds, CaptureMediaCandidate $captureMediaCandidate): array
    {
        if ($files === []) {
            return ['count' => 0, 'captured' => [], 'provider_error' => null];
        }

        $providerError = null;
        $meta = $this->resolver->commonsMetadata(
            array_unique(array_values($files)),
            function (string $error) use (&$providerError): void {
                $providerError = $error;
                $this->warn("  {$error}");
            },
        );

        $saved = 0;
        $captured = [];
        foreach ($files as $venueId => $file) {
            $venue = $venues->firstWhere('id', $venueId);
            if (! $venue) {
                continue;
            }

            $metadata = $meta[str_replace('_', ' ', $file)] ?? null;
            if ($metadata === null) {
                continue;
            }

            $canonicalFile = str_replace(' ', '_', trim($file));
            $method = in_array($venueId, $wikidataVenueIds, true)
                ? 'venue_wikidata_coordinate_match'
                : 'venue_commons_geosearch';
            $attachment = $captureMediaCandidate->execute($venue, $this->resolver->candidate(
                $canonicalFile,
                $metadata,
                'pending',
                $method,
                [
                    'name' => $venue->name,
                    'lat' => $venue->lat,
                    'lng' => $venue->lng,
                    'commons_file' => $canonicalFile,
                ],
            ));

            $asset = $attachment?->mediaAsset;
            if ($attachment !== null) {
                $captured[$venueId] = $attachment->media_asset_id;
            }
            if ($asset?->rights_status === 'approved' && $asset->health_status === 'active') {
                $saved++;
            }
        }

        return ['count' => $saved, 'captured' => $captured, 'provider_error' => $providerError];
    }

    /**
     * Name-search Wikidata per venue, then batch-load every candidate's
     * claims once and keep the first candidate (search order = best label
     * match) whose P625 coordinates verify it IS this venue.
     *
     * @param  Collection<int, Venue>  $venues
     * @return array<int, string> venue id => Commons file name
     */
    private function filesFromWikidataSearch(Collection $venues, array &$providerErrors): array
    {
        // venue id => ordered candidate QIDs
        $candidates = [];
        $allQids = [];
        foreach ($venues as $venue) {
            $qids = $this->searchEntityIds(
                (string) $venue->name,
                function (string $error) use (&$providerErrors, $venue): void {
                    $this->retainProviderError($providerErrors, $venue->id, $error);
                },
            );
            if ($qids !== []) {
                $candidates[$venue->id] = $qids;
                $allQids = array_merge($allQids, $qids);
            }
        }

        $claims = $this->entityClaims(
            array_values(array_unique($allQids)),
            function (string $error, array $failedQids) use ($candidates, &$providerErrors): void {
                foreach ($candidates as $venueId => $venueQids) {
                    if (array_intersect($venueQids, $failedQids) !== []) {
                        $this->retainProviderError($providerErrors, $venueId, $error);
                    }
                }
            },
        );

        $files = [];
        foreach ($candidates as $venueId => $qids) {
            $venue = $venues->firstWhere('id', $venueId);
            foreach ($qids as $qid) {
                $entity = $claims[$qid] ?? null;
                if ($entity === null || $entity['file'] === null || $entity['lat'] === null) {
                    continue;
                }
                $distance = $this->distanceMeters(
                    (float) $venue->lat,
                    (float) $venue->lng,
                    $entity['lat'],
                    $entity['lng'],
                );
                if ($distance <= self::ENTITY_MAX_DISTANCE_M) {
                    $files[$venueId] = $entity['file'];
                    break;
                }
            }
        }

        return $files;
    }

    /**
     * The identity part of a venue name, for geosearch name-matching only.
     * German venue names often end in a locative phrase — "Studienhaus am
     * Neumarkt", "Bürgerhaus an der Severinstraße" — and the place word
     * ("Neumarkt") appears in MANY nearby Commons filenames, so it must not
     * count as evidence that a photo shows THIS building. Wikidata search
     * keeps the full name (labels contain the phrase); only the token gate
     * uses the stripped form.
     */
    private function identityName(string $name): string
    {
        // Parentheticals are clarifiers ("(Lesesaal Museum Ludwig)"), not the
        // venue's own name — a photo matching only them is the OTHER place.
        $stripped = preg_replace('/\s*\([^)]*\)/u', '', $name);
        $stripped = preg_replace(
            '/\s+(?:am|an der|an dem|auf dem|auf der|im|in der|beim|bei der|zum|zur)\s+\p{Lu}.*$/u',
            '',
            (string) $stripped,
        );

        return trim((string) $stripped) !== '' ? trim((string) $stripped) : $name;
    }

    /**
     * Generic words that locate or classify a venue without identifying it:
     * the fixed building-type list plus every Veedel name (split on the
     * compound separators, so "Bocklemünd/Mengenich" stops both parts).
     *
     * @return list<string>
     */
    private function venueStopWords(): array
    {
        $districts = DB::table('veedels')
            ->pluck('name')
            ->flatMap(fn ($name) => preg_split('/[\/\-\s]+/u', mb_strtolower((string) $name)) ?: [])
            ->filter()
            ->all();

        return array_values(array_unique(array_merge(self::TYPE_STOP_WORDS, $districts)));
    }

    /**
     * @return list<string> candidate QIDs in search-relevance order
     */
    private function searchEntityIds(string $name, ?callable $onError = null): array
    {
        if (trim($name) === '') {
            return [];
        }

        try {
            $response = Http::withUserAgent(CommonsPhotoResolver::USER_AGENT)->timeout(20)
                ->get('https://www.wikidata.org/w/api.php', [
                    'action' => 'wbsearchentities',
                    'search' => $name,
                    'language' => 'de',
                    'uselang' => 'de',
                    'type' => 'item',
                    'limit' => 5,
                    'format' => 'json',
                ]);
            if (! $response->successful()) {
                $error = CommonsPhotoResolver::httpError($response);
                $this->warn("  wikidata search failed for \"{$name}\": {$error}");
                if ($onError !== null) {
                    $onError($error);
                }

                return [];
            }
            $results = $response->json('search', []);
        } catch (\Exception $e) {
            $this->warn("  wikidata search failed for \"{$name}\": {$e->getMessage()}");
            if ($onError !== null) {
                $onError($e->getMessage());
            }

            return [];
        }

        return collect($results ?? [])
            ->pluck('id')
            ->filter(fn ($id) => is_string($id) && preg_match('/^Q\d+$/', $id))
            ->values()
            ->all();
    }

    /**
     * P18 file + P625 coordinates for each entity, 50 a call.
     *
     * @param  list<string>  $qids
     * @return array<string, array{file: ?string, lat: ?float, lng: ?float}>
     */
    private function entityClaims(array $qids, ?callable $onError = null): array
    {
        $claims = [];
        foreach (array_chunk($qids, CommonsPhotoResolver::BATCH) as $chunk) {
            if ($chunk === []) {
                continue;
            }
            try {
                $response = Http::withUserAgent(CommonsPhotoResolver::USER_AGENT)->timeout(30)
                    ->get('https://www.wikidata.org/w/api.php', [
                        'action' => 'wbgetentities',
                        'ids' => implode('|', $chunk),
                        'props' => 'claims',
                        'format' => 'json',
                    ]);
                if (! $response->successful()) {
                    $error = CommonsPhotoResolver::httpError($response);
                    $this->warn('  wikidata claims batch failed: '.$error);
                    if ($onError !== null) {
                        $onError($error, $chunk);
                    }

                    continue;
                }
                $entities = $response->json('entities', []);
            } catch (\Exception $e) {
                $this->warn("  wikidata claims batch failed: {$e->getMessage()}");
                if ($onError !== null) {
                    $onError($e->getMessage(), $chunk);
                }

                continue;
            }

            foreach ($entities as $qid => $entity) {
                $coordinate = $entity['claims']['P625'][0]['mainsnak']['datavalue']['value'] ?? null;
                $claims[$qid] = [
                    'file' => $entity['claims']['P18'][0]['mainsnak']['datavalue']['value'] ?? null,
                    'lat' => isset($coordinate['latitude']) ? (float) $coordinate['latitude'] : null,
                    'lng' => isset($coordinate['longitude']) ? (float) $coordinate['longitude'] : null,
                ];
            }
        }

        return $claims;
    }

    /** @param array<int, string> $errors */
    private function retainProviderError(array &$errors, int $venueId, string $error): void
    {
        if (! isset($errors[$venueId])
            || (! str_contains($errors[$venueId], '429') && str_contains($error, '429'))) {
            $errors[$venueId] = $error;
        }
    }

    private function distanceMeters(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $earthRadius = 6371000;
        $dLat = deg2rad($lat2 - $lat1);
        $dLng = deg2rad($lng2 - $lng1);
        $a = sin($dLat / 2) ** 2
            + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng / 2) ** 2;

        return $earthRadius * 2 * atan2(sqrt($a), sqrt(1 - $a));
    }
}

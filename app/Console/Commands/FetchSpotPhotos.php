<?php

namespace App\Console\Commands;

use App\Enums\SpotCategory;
use App\Media\CaptureMediaCandidate;
use App\Media\CommonsPhotoResolver;
use App\Media\MediaAcquisitionScheduler;
use App\Media\ScheduledMediaAcquisition;
use App\Models\Spot;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;

/**
 * Resolves openly-licensed photos for places from Wikimedia Commons.
 *
 * OSM tags carry the link: wikidata=Q… → the entity's P18 image claim;
 * wikipedia=de:… → the article's page image. Both point at a Commons
 * file, which we hotlink via the Special:FilePath thumbnail service and
 * credit via the file's extmetadata (artist + licence) — Commons
 * licences require visible attribution.
 */
class FetchSpotPhotos extends Command
{
    protected $signature = 'spots:fetch-photos
        {--force : Refresh spots that already have a photo}
        {--geo=400 : Max Commons geosearch lookups for unlinked large outdoor places (0 = skip)}';

    protected $description = 'Fetch place photos from Wikimedia Commons via wikidata/wikipedia OSM tags + geosearch';

    /**
     * Large outdoor features where the nearest geotagged Commons photo is
     * reliably OF the place. Small point features (playground, café, court)
     * are excluded — a nearby photo there is as likely the building next door,
     * and a wrong photo is worse than the category illustration fallback.
     */
    private const GEOSEARCH_CATEGORIES = ['park', 'viewpoint', 'lake'];

    public function __construct(private readonly CommonsPhotoResolver $resolver)
    {
        parent::__construct();
    }

    public function handle(
        CaptureMediaCandidate $captureMediaCandidate,
        MediaAcquisitionScheduler $scheduler,
    ): int {
        $linked = Spot::query()
            ->whereIn('category', SpotCategory::placesFines())
            ->whereNotNull('tags')
            ->when(! $this->option('force'), fn ($query) => $query->whereDoesntHave(
                'mediaAttachments',
                fn ($attachment) => $attachment->publishable('wikimedia-commons'),
            ))
            ->get()
            ->filter(fn (Spot $spot) => ! empty($spot->tags['wikidata'])
                || ! empty($spot->tags['wikipedia'])
                || ! empty($spot->tags['wikimedia_commons']));

        $this->info("Resolving photos for {$linked->count()} linked place(s)...");

        // spot id => Commons file name
        $commonsFiles = $this->filesFromCommonsTags($linked);
        $wikidataFiles = $this->filesFromWikidata($linked);
        $wikipediaFiles = $this->filesFromWikipedia(
            $linked,
            array_keys($commonsFiles + $wikidataFiles),
        );
        $files = $commonsFiles + $wikidataFiles + $wikipediaFiles;
        $matchSources = collect($files)->mapWithKeys(fn (string $_file, int $spotId): array => [
            $spotId => array_key_exists($spotId, $commonsFiles)
                ? 'osm_wikimedia_commons_tag'
                : (array_key_exists($spotId, $wikidataFiles)
                    ? 'osm_wikidata_p18'
                    : 'osm_wikipedia_pageimage'),
        ])->all();

        $saved = $this->save($linked, $files, $captureMediaCandidate, $matchSources)['count'];
        $this->info("Linked photos saved: {$saved}.");

        $saved += $this->geosearchPass(
            $captureMediaCandidate,
            $scheduler,
            $linked->pluck('id')->map(fn ($id): int => (int) $id)->all(),
        );

        $this->info("Photos saved (total): {$saved}.");

        return self::SUCCESS;
    }

    /**
     * Backfill large outdoor places that have no wikidata/wikipedia link by
     * asking Commons for the nearest geotagged photo.
     */
    private function geosearchPass(
        CaptureMediaCandidate $captureMediaCandidate,
        MediaAcquisitionScheduler $scheduler,
        array $linkedSpotIds,
    ): int {
        $limit = (int) $this->option('geo');
        if ($limit <= 0) {
            return 0;
        }

        $query = Spot::query()
            ->canonical()
            ->whereIn('category', self::GEOSEARCH_CATEGORIES)
            ->when($linkedSpotIds !== [], fn ($query) => $query->whereNotIn('id', $linkedSpotIds))
            ->when(! $this->option('force'), fn ($query) => $query->whereDoesntHave(
                'mediaAttachments',
                fn ($attachment) => $attachment->publishable('wikimedia-commons'),
            ))
            ->whereNotNull('lat')
            ->whereNotNull('lng');

        $scheduled = $scheduler->select(
            $query,
            'wikimedia-commons',
            'spot_geosearch',
            $limit,
            fn (Spot $spot): array => [
                'name' => $spot->name,
                'category' => $spot->category?->value ?? (string) $spot->category,
                'lat' => $spot->lat,
                'lng' => $spot->lng,
                'radius_metres' => CommonsPhotoResolver::GEOSEARCH_RADIUS_M,
            ],
            (bool) $this->option('force'),
        );
        $spots = $scheduled->map(fn (ScheduledMediaAcquisition $item) => $item->target);

        $this->info("Geosearching photos for {$spots->count()} unlinked outdoor place(s)...");

        $files = [];
        $scheduledBySpot = $scheduled->keyBy(fn (ScheduledMediaAcquisition $item) => $item->target->getKey());
        foreach ($scheduledBySpot as $spotId => $item) {
            $spot = $item->target;
            $error = null;
            $file = $this->resolver->geoSearchFile(
                (float) $spot->lat,
                (float) $spot->lng,
                (string) $spot->name,
                function (string $message) use ($spot, &$error): void {
                    $error = $message;
                    $this->warn("  geosearch failed for spot {$spot->id}: {$message}");
                },
            );
            if ($file !== null) {
                $files[$spot->id] = $file;
            } else {
                $scheduler->record(
                    $item,
                    $error === null ? 'no_result' : (str_contains($error, '429') ? 'rate_limited' : 'failed'),
                    errorCode: $error === null ? null : 'provider_request_failed',
                    retryAfterSeconds: $scheduler->retryAfterSeconds($error),
                    metadata: $error === null ? null : ['message' => mb_substr($error, 0, 500)],
                );
            }
        }

        $result = $this->save(
            $spots,
            $files,
            $captureMediaCandidate,
            array_fill_keys(array_keys($files), 'commons_geosearch'),
        );
        foreach ($files as $spotId => $file) {
            $item = $scheduledBySpot->get($spotId);
            if (! $item instanceof ScheduledMediaAcquisition) {
                continue;
            }
            $assetId = $result['captured'][$spotId] ?? null;
            $providerError = $result['provider_error'];
            $scheduler->record(
                $item,
                $assetId !== null ? 'captured' : ($providerError !== null && str_contains($providerError, '429') ? 'rate_limited' : 'failed'),
                errorCode: $assetId === null ? ($providerError === null ? 'metadata_or_candidate_unavailable' : 'provider_request_failed') : null,
                candidateCount: 1,
                selectedAssetIds: $assetId === null ? [] : [$assetId],
                retryAfterSeconds: $scheduler->retryAfterSeconds($providerError),
                metadata: ['commons_file' => $file],
            );
        }

        $this->line(json_encode(
            ['acquisition_summary' => $scheduler->summary($scheduled)],
            JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
        ));

        return $result['count'];
    }

    /**
     * Persist resolved files with their Commons attribution.
     *
     * @param  Collection<int, Spot>  $spots
     * @param  array<int, string>  $files  spot id => Commons file name
     */
    private function save(
        $spots,
        array $files,
        CaptureMediaCandidate $captureMediaCandidate,
        array $matchSources,
    ): array {
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
        foreach ($files as $spotId => $file) {
            $spot = $spots->firstWhere('id', $spotId);
            if (! $spot) {
                continue;
            }

            $metadata = $meta[str_replace('_', ' ', $file)] ?? null;
            if ($metadata === null) {
                continue;
            }

            $canonicalFile = str_replace(' ', '_', trim($file));
            $match = $this->matchEvidence(
                $spot,
                $canonicalFile,
                $matchSources[$spotId] ?? 'commons_geosearch',
            );
            $attachment = $captureMediaCandidate->execute($spot, $this->resolver->candidate(
                $canonicalFile,
                $metadata,
                $match['status'],
                $match['method'],
                $match['evidence'],
            ));

            $asset = $attachment?->mediaAsset;
            if ($attachment !== null) {
                $captured[$spotId] = $attachment->media_asset_id;
            }
            if ($attachment !== null && ($spot->photo_url !== null || $spot->photo_attribution !== null)) {
                $spot->update([
                    'photo_url' => null,
                    'photo_attribution' => null,
                ]);
            }

            if ($asset?->rights_status === 'approved' && $asset->health_status === 'active') {
                $saved++;
            }
        }

        return ['count' => $saved, 'captured' => $captured, 'provider_error' => $providerError];
    }

    /** @return array{status: string, method: string, evidence: array<string, mixed>} */
    private function matchEvidence(Spot $spot, string $canonicalFile, string $source): array
    {
        $commonsTag = trim((string) ($spot->tags['wikimedia_commons'] ?? ''));
        if ($source === 'osm_wikimedia_commons_tag'
            && preg_match('/^File:(?<filename>.+)$/iu', $commonsTag, $match) === 1
            && str_replace(' ', '_', trim($match['filename'])) === $canonicalFile) {
            return [
                'status' => 'accepted',
                'method' => 'osm_wikimedia_commons_tag',
                'evidence' => [
                    'source' => 'osm',
                    'source_id' => $spot->source_id,
                    'tag' => $commonsTag,
                    'commons_file' => $canonicalFile,
                ],
            ];
        }

        $wikidata = trim((string) ($spot->tags['wikidata'] ?? ''));
        if ($source === 'osm_wikidata_p18' && preg_match('/^Q\d+$/D', $wikidata) === 1) {
            return [
                'status' => 'accepted',
                'method' => 'osm_wikidata_p18',
                'evidence' => [
                    'source' => 'osm',
                    'source_id' => $spot->source_id,
                    'wikidata' => $wikidata,
                    'claim' => 'P18',
                    'commons_file' => $canonicalFile,
                ],
            ];
        }

        $wikipedia = trim((string) ($spot->tags['wikipedia'] ?? ''));
        if ($source === 'osm_wikipedia_pageimage' && preg_match('/^[a-z]{2,3}:.+$/u', $wikipedia) === 1) {
            return [
                'status' => 'accepted',
                'method' => 'osm_wikipedia_pageimage',
                'evidence' => [
                    'source' => 'osm',
                    'source_id' => $spot->source_id,
                    'wikipedia' => $wikipedia,
                    'commons_file' => $canonicalFile,
                ],
            ];
        }

        return [
            'status' => 'pending',
            'method' => 'commons_geosearch',
            'evidence' => [
                'name' => $spot->name,
                'lat' => $spot->lat,
                'lng' => $spot->lng,
                'radius_metres' => CommonsPhotoResolver::GEOSEARCH_RADIUS_M,
                'commons_file' => $canonicalFile,
            ],
        ];
    }

    /**
     * Exact mapper-provided Commons files are the strongest place match and
     * take precedence over Wikidata, Wikipedia, and coordinate guesses.
     *
     * @param  Collection<int, Spot>  $spots
     * @return array<int, string>
     */
    private function filesFromCommonsTags($spots): array
    {
        $files = [];
        foreach ($spots as $spot) {
            $reference = trim((string) ($spot->tags['wikimedia_commons'] ?? ''));
            if (preg_match('/^File:(?<filename>.+)$/iu', $reference, $match) === 1) {
                $files[$spot->id] = trim($match['filename']);
            }
        }

        return $files;
    }

    /**
     * P18 image claims for all wikidata-tagged spots, 50 entities a call.
     *
     * @param  Collection<int, Spot>  $spots
     * @return array<int, string>
     */
    private function filesFromWikidata($spots): array
    {
        // Several spots may share one QID (e.g. duplicate OSM rows) —
        // group, don't overwrite.
        $byQid = $spots
            ->filter(fn (Spot $spot) => preg_match('/^Q\d+$/', $spot->tags['wikidata'] ?? ''))
            ->groupBy(fn (Spot $spot) => $spot->tags['wikidata'])
            ->map(fn ($group) => $group->pluck('id')->all());

        $files = [];
        foreach (array_chunk($byQid->keys()->all(), CommonsPhotoResolver::BATCH) as $chunk) {
            try {
                $response = Http::withUserAgent(CommonsPhotoResolver::USER_AGENT)->timeout(30)
                    ->get('https://www.wikidata.org/w/api.php', [
                        'action' => 'wbgetentities',
                        'ids' => implode('|', $chunk),
                        'props' => 'claims',
                        'format' => 'json',
                    ]);
                if (! $response->successful()) {
                    $this->warn('  wikidata batch failed: '.CommonsPhotoResolver::httpError($response));

                    continue;
                }
                $entities = $response->json('entities', []);
            } catch (\Exception $e) {
                $this->warn("  wikidata batch failed: {$e->getMessage()}");

                continue;
            }

            foreach ($entities as $qid => $entity) {
                $file = $entity['claims']['P18'][0]['mainsnak']['datavalue']['value'] ?? null;
                if ($file && isset($byQid[$qid])) {
                    foreach ($byQid[$qid] as $spotId) {
                        $files[$spotId] = (string) $file;
                    }
                }
            }
        }

        return $files;
    }

    /**
     * Page images for unresolved spots carrying a wikipedia=lang:Title tag.
     *
     * @param  Collection<int, Spot>  $spots
     * @param  list<int>  $resolvedSpotIds
     * @return array<int, string>
     */
    private function filesFromWikipedia($spots, array $resolvedSpotIds): array
    {
        // lang => [title => spot id]
        $byLang = [];
        $resolved = array_fill_keys($resolvedSpotIds, true);
        foreach ($spots as $spot) {
            if (isset($resolved[$spot->id])) {
                continue;
            }
            if (preg_match('/^([a-z]{2,3}):(.+)$/u', $spot->tags['wikipedia'] ?? '', $m)) {
                $byLang[$m[1]][$m[2]] = $spot->id;
            }
        }

        $files = [];
        foreach ($byLang as $lang => $titles) {
            foreach (array_chunk(array_keys($titles), CommonsPhotoResolver::BATCH) as $chunk) {
                try {
                    $response = Http::withUserAgent(CommonsPhotoResolver::USER_AGENT)->timeout(30)
                        ->get("https://{$lang}.wikipedia.org/w/api.php", [
                            'action' => 'query',
                            'titles' => implode('|', $chunk),
                            'prop' => 'pageimages',
                            'piprop' => 'name',
                            'redirects' => 1,
                            'format' => 'json',
                        ]);
                    if (! $response->successful()) {
                        $this->warn("  {$lang}.wikipedia batch failed: ".CommonsPhotoResolver::httpError($response));

                        continue;
                    }
                    $query = $response->json('query', []);
                } catch (\Exception $e) {
                    $this->warn("  {$lang}.wikipedia batch failed: {$e->getMessage()}");

                    continue;
                }

                // The API returns canonical titles — map them back to the
                // titles we asked for, through normalization + redirects,
                // or every redirect-reached page silently loses its photo.
                $requestedFor = [];
                foreach (array_merge($query['normalized'] ?? [], $query['redirects'] ?? []) as $mapping) {
                    $original = $requestedFor[$mapping['from']] ?? $mapping['from'];
                    $requestedFor[$mapping['to']] = $original;
                }

                foreach ($query['pages'] ?? [] as $page) {
                    $title = $page['title'] ?? '';
                    $requested = $requestedFor[$title] ?? $title;
                    $file = $page['pageimage'] ?? null;
                    if ($file && isset($titles[$requested])) {
                        $files[$titles[$requested]] = (string) $file;
                    }
                }
            }
        }

        return $files;
    }
}

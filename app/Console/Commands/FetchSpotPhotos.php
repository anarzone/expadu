<?php

namespace App\Console\Commands;

use App\Enums\SpotCategory;
use App\Media\CaptureMediaCandidate;
use App\Media\CommonsPhotoResolver;
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

    public function handle(CaptureMediaCandidate $captureMediaCandidate): int
    {
        $linked = Spot::query()
            ->whereIn('category', SpotCategory::placesFines())
            ->whereNotNull('tags')
            ->when(! $this->option('force'), fn ($query) => $query->whereDoesntHave(
                'mediaAttachments.mediaAsset',
                fn ($asset) => $asset->where('provider', 'wikimedia-commons')->published(),
            ))
            ->get()
            ->filter(fn (Spot $spot) => ! empty($spot->tags['wikidata'])
                || ! empty($spot->tags['wikipedia'])
                || ! empty($spot->tags['wikimedia_commons']));

        $this->info("Resolving photos for {$linked->count()} linked place(s)...");

        // spot id => Commons file name
        $files = $this->filesFromCommonsTags($linked)
            + $this->filesFromWikidata($linked)
            + $this->filesFromWikipedia($linked);

        $saved = $this->save($linked, $files, $captureMediaCandidate);
        $this->info("Linked photos saved: {$saved}.");

        $saved += $this->geosearchPass($captureMediaCandidate);

        $this->info("Photos saved (total): {$saved}.");

        return self::SUCCESS;
    }

    /**
     * Backfill large outdoor places that have no wikidata/wikipedia link by
     * asking Commons for the nearest geotagged photo.
     */
    private function geosearchPass(CaptureMediaCandidate $captureMediaCandidate): int
    {
        $limit = (int) $this->option('geo');
        if ($limit <= 0) {
            return 0;
        }

        $spots = Spot::query()
            ->whereIn('category', self::GEOSEARCH_CATEGORIES)
            ->when(! $this->option('force'), fn ($q) => $q->whereNull('photo_url'))
            ->when(! $this->option('force'), fn ($query) => $query->whereDoesntHave(
                'mediaAttachments.mediaAsset',
                fn ($asset) => $asset->where('provider', 'wikimedia-commons')->published(),
            ))
            ->whereNotNull('lat')
            ->whereNotNull('lng')
            ->orderBy('id')
            ->limit($limit)
            ->get();

        $this->info("Geosearching photos for {$spots->count()} unlinked outdoor place(s)...");

        $files = [];
        foreach ($spots as $spot) {
            $file = $this->resolver->geoSearchFile(
                (float) $spot->lat,
                (float) $spot->lng,
                (string) $spot->name,
                fn (string $error) => $this->warn("  geosearch failed for spot {$spot->id}: {$error}"),
            );
            if ($file !== null) {
                $files[$spot->id] = $file;
            }
        }

        return $this->save($spots, $files, $captureMediaCandidate);
    }

    /**
     * Persist resolved files with their Commons attribution.
     *
     * @param  Collection<int, Spot>  $spots
     * @param  array<int, string>  $files  spot id => Commons file name
     */
    private function save($spots, array $files, CaptureMediaCandidate $captureMediaCandidate): int
    {
        if ($files === []) {
            return 0;
        }

        $meta = $this->resolver->commonsMetadata(
            array_unique(array_values($files)),
            fn (string $error) => $this->warn("  {$error}"),
        );

        $saved = 0;
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
            $attachment = $captureMediaCandidate->execute($spot, $this->resolver->candidate($canonicalFile, $metadata));

            $asset = $attachment?->mediaAsset;
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

        return $saved;
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
                $entities = Http::withUserAgent(CommonsPhotoResolver::USER_AGENT)->timeout(30)
                    ->get('https://www.wikidata.org/w/api.php', [
                        'action' => 'wbgetentities',
                        'ids' => implode('|', $chunk),
                        'props' => 'claims',
                        'format' => 'json',
                    ])
                    ->json('entities', []);
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
     * Page images for spots that only carry a wikipedia=lang:Title tag.
     *
     * @param  Collection<int, Spot>  $spots
     * @return array<int, string>
     */
    private function filesFromWikipedia($spots): array
    {
        // lang => [title => spot id]
        $byLang = [];
        foreach ($spots as $spot) {
            if (! empty($spot->tags['wikidata'])) {
                continue; // wikidata path already covers it
            }
            if (preg_match('/^([a-z]{2,3}):(.+)$/u', $spot->tags['wikipedia'] ?? '', $m)) {
                $byLang[$m[1]][$m[2]] = $spot->id;
            }
        }

        $files = [];
        foreach ($byLang as $lang => $titles) {
            foreach (array_chunk(array_keys($titles), CommonsPhotoResolver::BATCH) as $chunk) {
                try {
                    $query = Http::withUserAgent(CommonsPhotoResolver::USER_AGENT)->timeout(30)
                        ->get("https://{$lang}.wikipedia.org/w/api.php", [
                            'action' => 'query',
                            'titles' => implode('|', $chunk),
                            'prop' => 'pageimages',
                            'piprop' => 'name',
                            'redirects' => 1,
                            'format' => 'json',
                        ])
                        ->json('query', []);
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

<?php

namespace App\Console\Commands;

use App\Enums\SpotCategory;
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

    private const BATCH = 50; // both MediaWiki APIs cap batched ids/titles at 50

    /** Wikimedia's API policy 403s anonymous/default user agents. */
    private const USER_AGENT = 'Expadu/1.0 (https://expadu.com; contact@expadu.com)';

    /**
     * Large outdoor features where the nearest geotagged Commons photo is
     * reliably OF the place. Small point features (playground, café, court)
     * are excluded — a nearby photo there is as likely the building next door,
     * and a wrong photo is worse than the category illustration fallback.
     */
    private const GEOSEARCH_CATEGORIES = ['park', 'viewpoint', 'lake'];

    /** Tight radius so a hit is the place itself, not a neighbour. */
    private const GEOSEARCH_RADIUS_M = 150;

    public function handle(): int
    {
        $linked = Spot::query()
            ->whereIn('category', SpotCategory::placesFines())
            ->whereNotNull('tags')
            ->when(! $this->option('force'), fn ($q) => $q->whereNull('photo_url'))
            ->get()
            ->filter(fn (Spot $spot) => ! empty($spot->tags['wikidata']) || ! empty($spot->tags['wikipedia']));

        $this->info("Resolving photos for {$linked->count()} linked place(s)...");

        // spot id => Commons file name
        $files = $this->filesFromWikidata($linked)
            + $this->filesFromWikipedia($linked);

        $saved = $this->save($linked, $files);
        $this->info("Linked photos saved: {$saved}.");

        $saved += $this->geosearchPass();

        $this->info("Photos saved (total): {$saved}.");

        return self::SUCCESS;
    }

    /**
     * Backfill large outdoor places that have no wikidata/wikipedia link by
     * asking Commons for the nearest geotagged photo.
     */
    private function geosearchPass(): int
    {
        $limit = (int) $this->option('geo');
        if ($limit <= 0) {
            return 0;
        }

        $spots = Spot::query()
            ->whereIn('category', self::GEOSEARCH_CATEGORIES)
            ->when(! $this->option('force'), fn ($q) => $q->whereNull('photo_url'))
            ->whereNotNull('lat')
            ->whereNotNull('lng')
            ->orderBy('id')
            ->limit($limit)
            ->get();

        $this->info("Geosearching photos for {$spots->count()} unlinked outdoor place(s)...");

        $files = $this->filesFromGeoSearch($spots);

        return $this->save($spots, $files);
    }

    /**
     * Persist resolved files with their Commons attribution.
     *
     * @param  Collection<int, Spot>  $spots
     * @param  array<int, string>  $files  spot id => Commons file name
     */
    private function save($spots, array $files): int
    {
        if ($files === []) {
            return 0;
        }

        $meta = $this->commonsMetadata(array_unique(array_values($files)));

        $saved = 0;
        foreach ($files as $spotId => $file) {
            $spot = $spots->firstWhere('id', $spotId);
            if (! $spot) {
                continue;
            }

            $spot->update([
                'photo_url' => 'https://commons.wikimedia.org/wiki/Special:FilePath/'
                    .rawurlencode($file).'?width=800',
                'photo_attribution' => $meta[str_replace('_', ' ', $file)] ?? 'Wikimedia Commons',
            ]);
            $saved++;
        }

        return $saved;
    }

    /**
     * Nearest geotagged Commons photo per spot (one geosearch call each — the
     * API takes a single coordinate). Skips maps, diagrams, logos and vector
     * files so a real photograph is chosen.
     *
     * @param  Collection<int, Spot>  $spots
     * @return array<int, string> spot id => Commons file name
     */
    private function filesFromGeoSearch($spots): array
    {
        $files = [];
        foreach ($spots as $spot) {
            try {
                $pages = Http::withUserAgent(self::USER_AGENT)->timeout(20)
                    ->get('https://commons.wikimedia.org/w/api.php', [
                        'action' => 'query',
                        'generator' => 'geosearch',
                        'ggsnamespace' => 6, // File:
                        'ggscoord' => "{$spot->lat}|{$spot->lng}",
                        'ggsradius' => self::GEOSEARCH_RADIUS_M,
                        'ggslimit' => 12,
                        'prop' => 'imageinfo',
                        'iiprop' => 'mediatype',
                        'format' => 'json',
                    ])
                    ->json('query.pages', []);
            } catch (\Exception $e) {
                $this->warn("  geosearch failed for spot {$spot->id}: {$e->getMessage()}");

                continue;
            }

            $file = $this->pickGeoFile($pages ?? [], (string) $spot->name);
            if ($file !== null) {
                $files[$spot->id] = $file;
            }
        }

        return $files;
    }

    /**
     * The nearest real photograph whose file name actually references the
     * place. Proximity alone is unreliable — the closest geotagged file is
     * often a neighbouring building, memorial or camera test — and a wrong
     * photo is worse than the category illustration. So we require a name
     * match (a distinctive word from the place name), trading recall for
     * precision. Geosearch orders pages by distance via `index`.
     *
     * @param  array<int|string, array<string, mixed>>  $pages
     */
    private function pickGeoFile(array $pages, string $spotName): ?string
    {
        $tokens = $this->nameTokens($spotName);
        if ($tokens === []) {
            return null; // nothing distinctive to verify against → don't guess
        }

        $best = null;
        $bestIndex = PHP_INT_MAX;

        foreach ($pages as $page) {
            $index = (int) ($page['index'] ?? PHP_INT_MAX);
            if ($index >= $bestIndex) {
                continue;
            }
            if (($page['imageinfo'][0]['mediatype'] ?? '') !== 'BITMAP') {
                continue; // skip SVG maps, PDFs, audio
            }
            $title = mb_strtolower($page['title'] ?? '');
            if (preg_match('/\b(map|karte|logo|icon|plan|diagram|seal|wappen|coat of arms|flag)\b/u', $title)) {
                continue;
            }

            $matchesName = false;
            foreach ($tokens as $token) {
                if (str_contains($title, $token)) {
                    $matchesName = true;
                    break;
                }
            }
            if (! $matchesName) {
                continue;
            }

            $best = preg_replace('/^File:/', '', $page['title'] ?? '');
            $bestIndex = $index;
        }

        return $best;
    }

    /**
     * Distinctive words from a place name to verify a candidate photo against
     * — long enough not to be generic, with bare type words dropped.
     *
     * @return list<string>
     */
    private function nameTokens(string $name): array
    {
        $stop = ['park', 'köln', 'koeln', 'cologne', 'platz', 'garten', 'der', 'die', 'das', 'und'];
        $tokens = preg_split('/[^\p{L}]+/u', mb_strtolower($name), -1, PREG_SPLIT_NO_EMPTY) ?: [];

        return array_values(array_filter(
            $tokens,
            fn (string $token) => mb_strlen($token) >= 5 && ! in_array($token, $stop, true),
        ));
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
        foreach (array_chunk($byQid->keys()->all(), self::BATCH) as $chunk) {
            try {
                $entities = Http::withUserAgent(self::USER_AGENT)->timeout(30)
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
            foreach (array_chunk(array_keys($titles), self::BATCH) as $chunk) {
                try {
                    $query = Http::withUserAgent(self::USER_AGENT)->timeout(30)
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

    /**
     * Artist + licence per Commons file → one attribution string each.
     *
     * @param  list<string>  $files
     * @return array<string, string>
     */
    private function commonsMetadata(array $files): array
    {
        $meta = [];
        foreach (array_chunk($files, self::BATCH) as $chunk) {
            try {
                $pages = Http::withUserAgent(self::USER_AGENT)->timeout(30)
                    ->get('https://commons.wikimedia.org/w/api.php', [
                        'action' => 'query',
                        'titles' => implode('|', array_map(fn (string $f) => "File:{$f}", $chunk)),
                        'prop' => 'imageinfo',
                        'iiprop' => 'extmetadata',
                        'format' => 'json',
                    ])
                    ->json('query.pages', []);
            } catch (\Exception $e) {
                $this->warn("  commons metadata batch failed: {$e->getMessage()}");

                continue;
            }

            foreach ($pages as $page) {
                // Commons answers with spaces; pageimage names arrive
                // underscored — normalize so the attribution lookup hits.
                $file = str_replace('_', ' ', preg_replace('/^File:/', '', $page['title'] ?? ''));
                $ext = $page['imageinfo'][0]['extmetadata'] ?? [];

                $artist = trim(strip_tags($ext['Artist']['value'] ?? ''));
                $licence = trim($ext['LicenseShortName']['value'] ?? '');

                $parts = array_filter([
                    $artist !== '' ? mb_substr($artist, 0, 120) : null,
                    $licence !== '' ? $licence : null,
                ]);

                $meta[$file] = $parts === []
                    ? 'Wikimedia Commons'
                    : implode(' · ', $parts).' · Wikimedia Commons';
            }
        }

        return $meta;
    }
}

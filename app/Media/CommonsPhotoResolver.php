<?php

namespace App\Media;

use Illuminate\Support\Facades\Http;

/**
 * Shared Wikimedia Commons plumbing for the photo-fetch commands
 * (spots:fetch-photos, venues:fetch-photos): geosearch with the
 * name-match precision gate, per-file publication metadata (attribution,
 * licence, health), and the open-licence check. Commons licences require
 * visible attribution, so metadata always carries it.
 */
class CommonsPhotoResolver
{
    /** Both MediaWiki APIs cap batched ids/titles at 50. */
    public const BATCH = 50;

    /** Wikimedia's API policy 403s anonymous/default user agents. */
    public const USER_AGENT = 'Expadu/1.0 (https://expadu.com; contact@expadu.com)';

    /** Tight radius so a geosearch hit is the place itself, not a neighbour. */
    public const GEOSEARCH_RADIUS_M = 150;

    /**
     * Nearest geotagged Commons files around a coordinate, name-verified.
     * Returns null when nothing both near AND matching the name exists.
     *
     * @param  list<string>  $extraStopWords  caller-specific generic words that
     *                                        must not count as name evidence
     */
    public function geoSearchFile(float $lat, float $lng, string $name, ?callable $onError = null, array $extraStopWords = []): ?string
    {
        try {
            $pages = Http::withUserAgent(self::USER_AGENT)->timeout(20)
                ->get('https://commons.wikimedia.org/w/api.php', [
                    'action' => 'query',
                    'generator' => 'geosearch',
                    'ggsnamespace' => 6, // File:
                    'ggscoord' => "{$lat}|{$lng}",
                    'ggsradius' => self::GEOSEARCH_RADIUS_M,
                    'ggslimit' => 12,
                    'prop' => 'imageinfo',
                    'iiprop' => 'mediatype',
                    'format' => 'json',
                ])
                ->json('query.pages', []);
        } catch (\Exception $e) {
            if ($onError !== null) {
                $onError($e->getMessage());
            }

            return null;
        }

        return $this->pickGeoFile($pages ?? [], $name, $extraStopWords);
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
     * @param  list<string>  $extraStopWords
     */
    public function pickGeoFile(array $pages, string $placeName, array $extraStopWords = []): ?string
    {
        $tokens = $this->nameTokens($placeName, $extraStopWords);
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
     * @param  list<string>  $extraStopWords
     * @return list<string>
     */
    public function nameTokens(string $name, array $extraStopWords = []): array
    {
        $stop = array_merge(
            ['park', 'köln', 'koeln', 'kölner', 'koelner', 'cologne', 'platz', 'garten', 'der', 'die', 'das', 'und'],
            array_map(mb_strtolower(...), $extraStopWords),
        );
        $tokens = preg_split('/[^\p{L}]+/u', mb_strtolower($name), -1, PREG_SPLIT_NO_EMPTY) ?: [];

        return array_values(array_filter(
            $tokens,
            fn (string $token) => mb_strlen($token) >= 5 && ! in_array($token, $stop, true),
        ));
    }

    /**
     * Resolve publication evidence for each Commons file. A healthy image and
     * a recognized open license are independent gates.
     *
     * @param  list<string>  $files
     * @param  callable(string): void|null  $onError
     * @return array<string, array{
     *     remote_url: string,
     *     source_page_url: string,
     *     author: ?string,
     *     attribution: string,
     *     license_code: ?string,
     *     license_url: ?string,
     *     mime_type: ?string,
     *     width: ?int,
     *     height: ?int,
     *     checksum: ?string,
     *     rights_status: string,
     *     health_status: string
     * }>
     */
    public function commonsMetadata(array $files, ?callable $onError = null): array
    {
        $meta = [];
        foreach (array_chunk($files, self::BATCH) as $chunk) {
            try {
                $pages = Http::withUserAgent(self::USER_AGENT)->timeout(30)
                    ->get('https://commons.wikimedia.org/w/api.php', [
                        'action' => 'query',
                        'titles' => implode('|', array_map(fn (string $f) => "File:{$f}", $chunk)),
                        'prop' => 'imageinfo',
                        'iiprop' => 'url|mime|size|sha1|extmetadata',
                        'iiurlwidth' => 1200,
                        'format' => 'json',
                    ])
                    ->json('query.pages', []);
            } catch (\Exception $e) {
                if ($onError !== null) {
                    $onError("commons metadata batch failed: {$e->getMessage()}");
                }

                continue;
            }

            foreach ($pages as $page) {
                // Commons answers with spaces; pageimage names arrive
                // underscored — normalize so the attribution lookup hits.
                $file = str_replace('_', ' ', preg_replace('/^File:/', '', $page['title'] ?? ''));
                $imageInfo = $page['imageinfo'][0] ?? [];
                $ext = $imageInfo['extmetadata'] ?? [];

                $artist = trim(strip_tags($ext['Artist']['value'] ?? ''));
                $licence = trim($ext['LicenseShortName']['value'] ?? '');
                $licenceUrl = trim((string) ($ext['LicenseUrl']['value'] ?? '')) ?: null;
                $sourcePageUrl = trim((string) ($imageInfo['descriptionurl'] ?? ''));
                if ($sourcePageUrl === '') {
                    $sourcePageUrl = 'https://commons.wikimedia.org/wiki/File:'
                        .str_replace('%2F', '/', rawurlencode(str_replace(' ', '_', $file)));
                }

                $remoteUrl = trim((string) ($imageInfo['thumburl'] ?? $imageInfo['url'] ?? ''));
                if ($remoteUrl === '') {
                    $remoteUrl = 'https://commons.wikimedia.org/wiki/Special:FilePath/'
                        .str_replace('%2F', '/', rawurlencode(str_replace(' ', '_', $file))).'?width=1200';
                }

                $mimeType = is_string($imageInfo['mime'] ?? null) ? $imageInfo['mime'] : null;
                $width = filter_var($imageInfo['thumbwidth'] ?? $imageInfo['width'] ?? null, FILTER_VALIDATE_INT) ?: null;
                $height = filter_var($imageInfo['thumbheight'] ?? $imageInfo['height'] ?? null, FILTER_VALIDATE_INT) ?: null;
                $healthStatus = MediaAssetValidator::supportsMimeType($mimeType)
                    && MediaAssetValidator::isAllowedProviderUrl('wikimedia-commons', $remoteUrl)
                    && $width !== null && $width >= (int) config('media.validation.min_width')
                    && $height !== null && $height >= (int) config('media.validation.min_height')
                    ? 'active'
                    : 'pending';

                $isPublicDomain = in_array(mb_strtoupper($licence), ['PUBLIC DOMAIN', 'PD'], true);
                $rightsStatus = $this->isOpenLicense($licence)
                    && $sourcePageUrl !== ''
                    && ($isPublicDomain || $licenceUrl !== null)
                    && (! str_starts_with(mb_strtoupper($licence), 'CC BY') || $artist !== '')
                    ? 'approved'
                    : 'pending';

                $parts = array_filter([
                    $artist !== '' ? mb_substr($artist, 0, 120) : null,
                    $licence !== '' ? $licence : null,
                ]);

                $attribution = $parts === []
                    ? 'Wikimedia Commons'
                    : implode(' · ', $parts).' · Wikimedia Commons';

                $meta[$file] = [
                    'remote_url' => $remoteUrl,
                    'source_page_url' => $sourcePageUrl,
                    'author' => $artist !== '' ? mb_substr($artist, 0, 120) : null,
                    'attribution' => $attribution,
                    'license_code' => $licence !== '' ? $licence : null,
                    'license_url' => $licenceUrl,
                    'mime_type' => $mimeType,
                    'width' => $width,
                    'height' => $height,
                    'checksum' => is_string($imageInfo['sha1'] ?? null) ? $imageInfo['sha1'] : null,
                    'rights_status' => $rightsStatus,
                    'health_status' => $healthStatus,
                ];
            }
        }

        return $meta;
    }

    public function isOpenLicense(string $licence): bool
    {
        $normalized = mb_strtoupper(trim($licence));

        return collect(config('media.open_licenses', []))
            ->contains(function (string $allowed) use ($normalized): bool {
                $allowed = mb_strtoupper(trim($allowed));

                return preg_match('/^'.preg_quote($allowed, '/').'(?:\s+\d+(?:\.\d+)*)?$/u', $normalized) === 1;
            });
    }

    /**
     * Build the standard Commons media candidate from resolved metadata.
     *
     * @param  array{remote_url: string, source_page_url: string, author: ?string, attribution: string, license_code: ?string, license_url: ?string, mime_type: ?string, width: ?int, height: ?int, checksum: ?string, rights_status: string, health_status: string}  $metadata
     */
    public function candidate(string $canonicalFile, array $metadata): MediaCandidate
    {
        return new MediaCandidate(
            provider: 'wikimedia-commons',
            remoteUrl: $metadata['remote_url'],
            providerAssetId: 'File:'.$canonicalFile,
            sourcePageUrl: $metadata['source_page_url'],
            role: 'hero',
            priority: 20,
            isPrimary: true,
            rightsStatus: $metadata['rights_status'],
            healthStatus: $metadata['health_status'],
            author: $metadata['author'],
            attribution: $metadata['attribution'],
            licenseCode: $metadata['license_code'],
            licenseUrl: $metadata['license_url'],
            mimeType: $metadata['mime_type'],
            width: $metadata['width'],
            height: $metadata['height'],
            checksum: $metadata['checksum'],
            metadata: ['commons_file' => $canonicalFile],
            shouldValidate: $metadata['health_status'] !== 'active',
            authoritativeEvidence: true,
        );
    }
}

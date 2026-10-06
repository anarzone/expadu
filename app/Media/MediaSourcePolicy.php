<?php

namespace App\Media;

use App\Models\MediaAsset;

class MediaSourcePolicy
{
    public function excludesAsset(MediaAsset $asset): bool
    {
        return $this->excludes($asset->provider, $asset->author, $asset->source_page_url, $asset->remote_url, $asset->metadata ?? []);
    }

    /** @param array<string, mixed> $metadata */
    public function excludes(string $provider, ?string $author, ?string $sourceUrl, ?string $remoteUrl, array $metadata): bool
    {
        if (in_array($provider, config('media.excluded_place_origins.providers', []), true)) {
            return true;
        }

        $provenance = is_array($metadata['source_provenance'] ?? null) ? $metadata['source_provenance'] : [];
        $credits = array_filter([$author, $provenance['artist'] ?? null, $provenance['credit'] ?? null, $provenance['permission'] ?? null], is_string(...));
        $names = config('media.excluded_place_origins.names', []);
        foreach ($credits as $credit) {
            $plain = mb_strtolower(preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags($credit), ENT_QUOTES | ENT_HTML5, 'UTF-8')));
            foreach ($names as $name) {
                if (str_contains($plain, mb_strtolower($name))) {
                    return true;
                }
            }
        }

        // Inspect provenance links, never filenames or descriptions of the venue.
        $urls = array_filter([$sourceUrl, $remoteUrl]);
        foreach ($credits as $credit) {
            preg_match_all('~https?://[^\s<>"\']+~i', html_entity_decode($credit, ENT_QUOTES | ENT_HTML5, 'UTF-8'), $matches);
            array_push($urls, ...$matches[0]);
        }
        foreach ($urls as $url) {
            $host = strtolower((string) parse_url($url, PHP_URL_HOST));
            foreach (config('media.excluded_place_origins.hosts', []) as $excluded) {
                if ($host === $excluded || str_ends_with($host, '.'.$excluded)) {
                    return true;
                }
            }
        }

        return false;
    }
}

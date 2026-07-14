<?php

namespace App\Media;

use App\Models\MediaAsset;
use Illuminate\Support\Facades\Http;

class MediaAssetValidator
{
    /** @var list<string> */
    private const ALLOWED_MIME_TYPES = [
        'image/jpeg',
        'image/png',
        'image/webp',
        'image/avif',
    ];

    public function validate(MediaAsset $asset): void
    {
        if (! self::isAllowedProviderUrl($asset->provider, $asset->remote_url)) {
            $this->recordFailure($asset, 'provider_or_url_not_allowed');

            return;
        }

        $maxBytes = (int) config('media.validation.max_bytes', 10 * 1024 * 1024);

        try {
            $response = Http::withUserAgent((string) config('media.user_agent'))
                ->connectTimeout((int) config('media.validation.connect_timeout_seconds', 5))
                ->timeout((int) config('media.validation.timeout_seconds', 15))
                ->retry([250, 750], throw: false)
                ->withoutRedirecting()
                ->withOptions([
                    'progress' => function (
                        float $downloadTotal,
                        float $downloadedBytes,
                    ) use ($maxBytes): void {
                        if ($downloadTotal > $maxBytes || $downloadedBytes > $maxBytes) {
                            throw new \OverflowException('image_too_large');
                        }
                    },
                ])
                ->withHeaders([
                    'Accept' => 'image/avif,image/webp,image/png,image/jpeg',
                    'Range' => 'bytes=0-'.($maxBytes - 1),
                ])
                ->get($asset->remote_url);
        } catch (\OverflowException) {
            $this->recordFailure($asset, 'image_too_large');

            return;
        } catch (\Throwable $exception) {
            $this->recordFailure($asset, 'connection: '.$exception->getMessage());

            throw $exception;
        }

        if (! $response->successful()) {
            $this->recordFailure($asset, 'http_status_'.$response->status());

            return;
        }

        $mimeType = mb_strtolower(trim(explode(';', (string) $response->header('Content-Type'))[0]));
        if (! self::supportsMimeType($mimeType)) {
            $this->recordFailure($asset, 'unsupported_mime_type');

            return;
        }

        $declaredBytes = filter_var($response->header('Content-Length'), FILTER_VALIDATE_INT);
        $originalBytes = null;
        if (preg_match('/\/(?<total>\d+)$/', (string) $response->header('Content-Range'), $match) === 1) {
            $originalBytes = (int) $match['total'];
        }
        $body = $response->body();
        if (($declaredBytes !== false && $declaredBytes > $maxBytes)
            || ($originalBytes !== null && $originalBytes > $maxBytes)
            || mb_strlen($body, '8bit') > $maxBytes) {
            $this->recordFailure($asset, 'image_too_large');

            return;
        }

        $dimensions = @getimagesizefromstring($body);
        if ($dimensions === false
            || $dimensions[0] < (int) config('media.validation.min_width', 400)
            || $dimensions[1] < (int) config('media.validation.min_height', 225)) {
            $this->recordFailure($asset, 'invalid_or_too_small_image');

            return;
        }

        $asset->update([
            'health_status' => 'active',
            'mime_type' => $mimeType,
            'width' => $dimensions[0],
            'height' => $dimensions[1],
            'checksum' => hash('sha256', $body),
            'failure_count' => 0,
            'last_error' => null,
            'last_verified_at' => now(),
        ]);
    }

    public function recordFailure(MediaAsset $asset, string $error): void
    {
        $failures = $asset->failure_count + 1;
        $asset->update([
            'health_status' => $failures >= (int) config('media.validation.broken_after_failures', 3)
                ? 'broken'
                : 'pending',
            'failure_count' => $failures,
            'last_error' => mb_substr($error, 0, 500),
            'last_verified_at' => now(),
        ]);
    }

    public static function supportsMimeType(?string $mimeType): bool
    {
        return $mimeType !== null && in_array(mb_strtolower($mimeType), self::ALLOWED_MIME_TYPES, true);
    }

    public static function isAllowedProviderUrl(string $provider, string $url): bool
    {
        $hosts = config("media.providers.{$provider}.hosts");
        $scheme = parse_url($url, PHP_URL_SCHEME);
        $host = mb_strtolower((string) parse_url($url, PHP_URL_HOST));

        return is_array($hosts)
            && $scheme === 'https'
            && $host !== ''
            && ! filter_var($host, FILTER_VALIDATE_IP)
            && in_array($host, $hosts, true);
    }
}

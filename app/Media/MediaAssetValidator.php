<?php

namespace App\Media;

use App\Models\MediaAsset;
use App\Models\MediaValidationAttempt;
use Illuminate\Support\Facades\DB;
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

    /** @param array<string, mixed>|null $expectedInputSnapshot */
    public function validate(
        MediaAsset $asset,
        ?string $expectedInputFingerprint = null,
        ?array $expectedInputSnapshot = null,
    ): string {
        $asset->refresh();
        $startedAt = now()->utc();
        $input = $expectedInputSnapshot ?? self::inputSnapshot($asset);
        $expectedInputFingerprint ??= self::fingerprintForSnapshot($input);

        if (! hash_equals($expectedInputFingerprint, self::inputFingerprint($asset))) {
            return $this->recordStale($asset, $expectedInputFingerprint, $input, $startedAt);
        }

        if (! self::isAllowedProviderUrl($asset->provider, $asset->remote_url)) {
            return $this->recordFailure($asset, 'provider_or_url_not_allowed', $expectedInputFingerprint, $input, $startedAt);
        }

        $maxBytes = (int) config('media.validation.max_bytes', 10 * 1024 * 1024);

        try {
            $response = Http::withUserAgent((string) config('media.user_agent'))
                ->connectTimeout((int) config('media.validation.connect_timeout_seconds', 5))
                ->timeout((int) config('media.validation.timeout_seconds', 15))
                ->withoutRedirecting()
                ->withOptions([
                    'progress' => function (float $downloadTotal, float $downloadedBytes) use ($maxBytes): void {
                        if ($downloadTotal > $maxBytes || $downloadedBytes > $maxBytes) {
                            throw new \OverflowException('image_too_large');
                        }
                    },
                ])
                ->withHeaders([
                    'Accept' => 'image/avif,image/webp,image/png,image/jpeg',
                    'Range' => 'bytes=0-'.($maxBytes - 1),
                ])
                ->get($input['remote_url']);
        } catch (\OverflowException) {
            return $this->recordFailure($asset, 'image_too_large', $expectedInputFingerprint, $input, $startedAt);
        } catch (\Throwable $exception) {
            return $this->recordFailure(
                $asset,
                'connection: '.$exception->getMessage(),
                $expectedInputFingerprint,
                $input,
                $startedAt,
            );
        }

        $responseMetadata = ['http_status' => $response->status()];
        if (! $response->successful()) {
            return $this->recordFailure(
                $asset,
                'http_status_'.$response->status(),
                $expectedInputFingerprint,
                $input,
                $startedAt,
                $responseMetadata,
            );
        }

        $mimeType = mb_strtolower(trim(explode(';', (string) $response->header('Content-Type'))[0]));
        $responseMetadata['mime_type'] = $mimeType;
        if (! self::supportsMimeType($mimeType)) {
            return $this->recordFailure(
                $asset,
                'unsupported_mime_type',
                $expectedInputFingerprint,
                $input,
                $startedAt,
                $responseMetadata,
            );
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
            return $this->recordFailure(
                $asset,
                'image_too_large',
                $expectedInputFingerprint,
                $input,
                $startedAt,
                $responseMetadata,
            );
        }

        $dimensions = @getimagesizefromstring($body);
        if ($dimensions === false
            || $dimensions[0] < (int) config('media.validation.min_width', 400)
            || $dimensions[1] < (int) config('media.validation.min_height', 225)) {
            return $this->recordFailure(
                $asset,
                'invalid_or_too_small_image',
                $expectedInputFingerprint,
                $input,
                $startedAt,
                $responseMetadata,
            );
        }

        $responseMetadata['width'] = $dimensions[0];
        $responseMetadata['height'] = $dimensions[1];

        return DB::transaction(function () use (
            $asset,
            $expectedInputFingerprint,
            $input,
            $startedAt,
            $mimeType,
            $dimensions,
            $body,
            $responseMetadata,
        ): string {
            $current = MediaAsset::query()->whereKey($asset->id)->lockForUpdate()->firstOrFail();
            if (! hash_equals($expectedInputFingerprint, self::inputFingerprint($current))) {
                return $this->recordStaleLocked($current, $expectedInputFingerprint, $input, $startedAt);
            }

            $finishedAt = now()->utc();
            $current->update([
                'health_status' => 'active',
                'mime_type' => $mimeType,
                'width' => $dimensions[0],
                'height' => $dimensions[1],
                'checksum' => hash('sha256', $body),
                'failure_count' => 0,
                'last_error' => null,
                'last_verified_at' => $finishedAt,
                'next_validation_at' => $finishedAt->copy()->addSeconds(max(
                    1,
                    (int) config('media.validation.active_interval_seconds', 604800),
                )),
                'validation_queued_at' => null,
                'validation_queued_fingerprint' => null,
                'last_validation_outcome' => 'active',
                'last_validation_error_code' => null,
            ]);
            $this->recordAttempt(
                $current,
                $expectedInputFingerprint,
                $input,
                'active',
                null,
                $startedAt,
                $finishedAt,
                $responseMetadata,
            );

            return 'active';
        });
    }

    /**
     * @param  array<string, mixed>|null  $input
     * @param  array<string, mixed>|null  $metadata
     */
    public function recordFailure(
        MediaAsset $asset,
        string $error,
        ?string $expectedInputFingerprint = null,
        ?array $input = null,
        mixed $startedAt = null,
        ?array $metadata = null,
    ): string {
        $asset->refresh();
        $input ??= self::inputSnapshot($asset);
        $expectedInputFingerprint ??= self::fingerprintForSnapshot($input);
        $startedAt ??= now()->utc();

        return DB::transaction(function () use (
            $asset,
            $error,
            $expectedInputFingerprint,
            $input,
            $startedAt,
            $metadata,
        ): string {
            $current = MediaAsset::query()->whereKey($asset->id)->lockForUpdate()->firstOrFail();
            if (! hash_equals($expectedInputFingerprint, self::inputFingerprint($current))) {
                return $this->recordStaleLocked($current, $expectedInputFingerprint, $input, $startedAt);
            }

            $failures = $current->failure_count + 1;
            $finishedAt = now()->utc();
            $errorCode = $this->errorCode($error);
            $current->update([
                'health_status' => $failures >= (int) config('media.validation.broken_after_failures', 3)
                    ? 'broken'
                    : 'pending',
                'failure_count' => $failures,
                'last_error' => mb_substr($error, 0, 500),
                'next_validation_at' => $finishedAt->copy()->addSeconds($this->failureDelay($failures)),
                'validation_queued_at' => null,
                'validation_queued_fingerprint' => null,
                'last_validation_outcome' => 'failed',
                'last_validation_error_code' => $errorCode,
            ]);
            $this->recordAttempt(
                $current,
                $expectedInputFingerprint,
                $input,
                'failed',
                $errorCode,
                $startedAt,
                $finishedAt,
                $metadata,
            );

            return 'failed';
        });
    }

    /** @param array<string, mixed>|null $input */
    public function recordInfrastructureFailure(
        MediaAsset $asset,
        string $error,
        ?string $expectedInputFingerprint = null,
        ?array $input = null,
    ): string {
        $asset->refresh();
        $input ??= self::inputSnapshot($asset);
        $expectedInputFingerprint ??= self::fingerprintForSnapshot($input);
        $startedAt = now()->utc();

        return DB::transaction(function () use (
            $asset,
            $error,
            $expectedInputFingerprint,
            $input,
            $startedAt,
        ): string {
            $current = MediaAsset::query()->whereKey($asset->id)->lockForUpdate()->firstOrFail();
            if (! hash_equals($expectedInputFingerprint, self::inputFingerprint($current))) {
                return $this->recordStaleLocked($current, $expectedInputFingerprint, $input, $startedAt);
            }

            $finishedAt = now()->utc();
            $current->update([
                'next_validation_at' => $finishedAt,
                'validation_queued_at' => null,
                'validation_queued_fingerprint' => null,
                'last_validation_outcome' => 'job_failed',
                'last_validation_error_code' => 'job_failed',
            ]);
            $this->recordAttempt(
                $current,
                $expectedInputFingerprint,
                $input,
                'job_failed',
                'job_failed',
                $startedAt,
                $finishedAt,
                ['message' => mb_substr(trim($error), 0, 500)],
            );

            return 'job_failed';
        });
    }

    /** @return array{provider: string, source_key: string, remote_url: string, checksum: ?string, updated_at: ?string} */
    public static function inputSnapshot(MediaAsset $asset): array
    {
        return [
            'provider' => $asset->provider,
            'source_key' => $asset->source_key,
            'remote_url' => $asset->remote_url,
            'checksum' => $asset->checksum,
            'updated_at' => $asset->updated_at?->toAtomString(),
        ];
    }

    public static function inputFingerprint(MediaAsset $asset): string
    {
        return self::fingerprintForSnapshot(self::inputSnapshot($asset));
    }

    /** @param array<string, mixed> $snapshot */
    private static function fingerprintForSnapshot(array $snapshot): string
    {
        return hash('sha256', json_encode(
            $snapshot,
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
        ));
    }

    /** @param array<string, mixed> $input */
    private function recordStale(MediaAsset $asset, string $expectedInputFingerprint, array $input, mixed $startedAt): string
    {
        return DB::transaction(function () use ($asset, $expectedInputFingerprint, $input, $startedAt): string {
            $current = MediaAsset::query()->whereKey($asset->id)->lockForUpdate()->firstOrFail();

            return $this->recordStaleLocked($current, $expectedInputFingerprint, $input, $startedAt);
        });
    }

    /** @param array<string, mixed> $input */
    private function recordStaleLocked(MediaAsset $asset, string $expectedInputFingerprint, array $input, mixed $startedAt): string
    {
        $finishedAt = now()->utc();
        if ($asset->validation_queued_fingerprint === $expectedInputFingerprint) {
            DB::table($asset->getTable())->where('id', $asset->id)->update([
                'validation_queued_at' => null,
                'validation_queued_fingerprint' => null,
            ]);
        }
        $this->recordAttempt(
            $asset,
            $expectedInputFingerprint,
            $input,
            'stale_input',
            'input_changed',
            $startedAt,
            $finishedAt,
            ['current_input_fingerprint' => self::inputFingerprint($asset)],
        );

        return 'stale_input';
    }

    /**
     * @param  array<string, mixed>  $input
     * @param  array<string, mixed>|null  $metadata
     */
    private function recordAttempt(
        MediaAsset $asset,
        string $inputFingerprint,
        array $input,
        string $outcome,
        ?string $errorCode,
        mixed $startedAt,
        mixed $finishedAt,
        ?array $metadata,
    ): void {
        MediaValidationAttempt::query()->create([
            'media_asset_id' => $asset->id,
            'input_fingerprint' => $inputFingerprint,
            'remote_url' => $input['remote_url'],
            'checksum' => $input['checksum'],
            'asset_updated_at' => $input['updated_at'],
            'outcome' => $outcome,
            'error_code' => $errorCode,
            'started_at' => $startedAt,
            'finished_at' => $finishedAt,
            'metadata' => $metadata,
        ]);
    }

    private function failureDelay(int $failures): int
    {
        $delays = array_values(array_filter(
            array_map('intval', (array) config('media.validation.failure_retry_seconds', [3600, 21600, 86400, 604800])),
            fn (int $delay): bool => $delay > 0,
        ));
        if ($delays === []) {
            return 3600;
        }

        return $delays[min(max(1, $failures) - 1, count($delays) - 1)];
    }

    private function errorCode(string $error): string
    {
        return str_starts_with($error, 'connection:')
            ? 'connection'
            : mb_substr(trim($error), 0, 120);
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
            && collect($hosts)->contains(fn ($allowedHost): bool => is_string($allowedHost)
                && $host === mb_strtolower(trim($allowedHost)));
    }
}

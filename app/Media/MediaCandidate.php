<?php

namespace App\Media;

final readonly class MediaCandidate
{
    /** @param array<string, mixed>|null $metadata */
    public function __construct(
        public string $provider,
        public string $remoteUrl,
        public ?string $providerAssetId = null,
        public ?string $sourcePageUrl = null,
        public string $type = 'image',
        public string $role = 'hero',
        public int $priority = 100,
        public bool $isPrimary = false,
        public string $rightsStatus = 'pending',
        public string $healthStatus = 'pending',
        public ?string $author = null,
        public ?string $attribution = null,
        public ?string $licenseCode = null,
        public ?string $licenseUrl = null,
        public ?string $mimeType = null,
        public ?int $width = null,
        public ?int $height = null,
        public ?string $checksum = null,
        public ?array $metadata = null,
        public bool $shouldValidate = true,
        public bool $authoritativeEvidence = false,
    ) {}
}

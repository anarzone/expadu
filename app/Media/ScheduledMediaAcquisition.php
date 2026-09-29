<?php

namespace App\Media;

use Illuminate\Database\Eloquent\Model;

final readonly class ScheduledMediaAcquisition
{
    /** @param array<string, mixed> $inputSnapshot */
    public function __construct(
        public Model $target,
        public string $provider,
        public string $strategy,
        public string $inputFingerprint,
        public array $inputSnapshot,
        public int $attemptId,
        public string $activeKey,
    ) {}
}

<?php

namespace App\Composer\Contracts;

use App\Composer\Constraints;
use App\Profile\Profile;
use Carbon\CarbonImmutable;

interface ParsesConstraints
{
    /**
     * Free text → Constraints. Implementations must never choose venues —
     * parsing only. On failure, return profile-default constraints so the
     * composer never hard-fails on the LLM.
     */
    public function parse(string $text, Profile $profile, CarbonImmutable $now): Constraints;
}

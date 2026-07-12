<?php

namespace App\Composer\Contracts;

use App\Composer\ParsedPrompt;
use App\Profile\Profile;
use Carbon\CarbonImmutable;

/**
 * The prompt-understanding seam. An implementation classifies the prompt's
 * intent and parses its payload; candidate judgment belongs to the separate
 * grounded ranker. Implementations must never throw: on any failure they
 * degrade (heuristic, then profile defaults), so the box always responds.
 */
interface ParsesPrompt
{
    public function parse(string $text, Profile $profile, CarbonImmutable $now): ParsedPrompt;
}

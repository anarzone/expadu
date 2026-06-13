<?php

namespace App\Composer\Contracts;

use App\Composer\ParsedPrompt;
use App\Profile\Profile;
use Carbon\CarbonImmutable;

/**
 * The one and only LLM-capable seam in the composer. An implementation
 * classifies the prompt's intent and parses its payload — it NEVER picks
 * venues, generates facts, or answers questions from its own knowledge.
 * Plans come from the deterministic pipeline, answers from retrieval over
 * verified content. Implementations must never throw: on any failure they
 * degrade (heuristic, then profile defaults), so the box always responds.
 */
interface ParsesPrompt
{
    public function parse(string $text, Profile $profile, CarbonImmutable $now): ParsedPrompt;
}

<?php

namespace App\Composer;

use App\Composer\Contracts\ParsesPrompt;
use App\Profile\Profile;
use Carbon\CarbonImmutable;

/**
 * Test double: returns a scripted ParsedPrompt regardless of input, so a
 * feature test can force any intent without depending on the heuristic's
 * wording. Bind it over ParsesPrompt in tests that exercise the routing.
 */
class FakePromptParser implements ParsesPrompt
{
    public function __construct(private ParsedPrompt $result) {}

    public function parse(string $text, Profile $profile, CarbonImmutable $now): ParsedPrompt
    {
        return $this->result;
    }
}

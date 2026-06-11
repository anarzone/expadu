<?php

namespace App\Composer;

use App\Composer\Contracts\ParsesConstraints;
use App\Profile\Profile;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * The ONLY LLM call in the composer. It converts free text into a
 * constraint object — it never chooses venues; determinism and
 * testability stay with the pure pipeline. Structured output is forced
 * via a single tool definition, so the response is guaranteed-parseable.
 * Any failure falls back to profile defaults: the composer never
 * hard-fails on the LLM, the editable chips absorb mis-parses.
 */
class AnthropicConstraintParser implements ParsesConstraints
{
    private const TOOL = [
        'name' => 'set_constraints',
        'description' => 'Record the parsed day-planning constraints.',
        'input_schema' => [
            'type' => 'object',
            'properties' => [
                'window_start' => ['type' => 'string', 'description' => 'ISO 8601 local datetime when the free time starts'],
                'window_end' => ['type' => 'string', 'description' => 'ISO 8601 local datetime when the free time ends'],
                'areas' => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => 'Cologne Veedel names mentioned, empty if none'],
                'categories' => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => 'activity categories: park, cafe, library, restaurant, bar, playground, pitch, basketball, lake, swimming, culture, event'],
                'companions' => ['type' => ['string', 'null'], 'enum' => ['alone', 'partner', 'friends', 'kids', null]],
                'budget' => ['type' => ['string', 'null'], 'enum' => ['free', 'low', 'normal', null]],
            ],
            'required' => ['window_start', 'window_end'],
        ],
    ];

    public function parse(string $text, Profile $profile, CarbonImmutable $now): Constraints
    {
        try {
            $response = Http::baseUrl('https://api.anthropic.com')
                ->timeout(10)
                ->connectTimeout(3)
                ->withHeaders([
                    'x-api-key' => (string) config('services.anthropic.key'),
                    'anthropic-version' => '2023-06-01',
                ])
                ->post('/v1/messages', [
                    'model' => (string) config('services.anthropic.model'),
                    'max_tokens' => 400,
                    'tool_choice' => ['type' => 'tool', 'name' => 'set_constraints'],
                    'tools' => [self::TOOL],
                    'system' => 'You convert free text about leisure plans into structured constraints. '
                        .'You never choose venues — parsing only. Resolve relative dates against the '
                        .'current local time. The window must not exceed 72 hours from now and a single '
                        .'day window is preferred when the text names one day.',
                    'messages' => [[
                        'role' => 'user',
                        'content' => "Current local time: {$now->toIso8601String()} (Europe/Berlin, "
                            .$now->format('l').").\nUser's home Veedel: ".($profile->veedel ?? 'unknown')
                            .".\n\nText to parse: \"{$text}\"",
                    ]],
                ])
                ->throw();

            $input = collect($response->json('content', []))
                ->firstWhere('type', 'tool_use')['input'] ?? null;

            if (! is_array($input) || ! isset($input['window_start'], $input['window_end'])) {
                throw new \RuntimeException('no tool_use block in response');
            }

            return $this->clamp(new Constraints(
                windowStart: CarbonImmutable::parse($input['window_start'], 'Europe/Berlin'),
                windowEnd: CarbonImmutable::parse($input['window_end'], 'Europe/Berlin'),
                areas: array_values((array) ($input['areas'] ?? [])),
                categories: array_values((array) ($input['categories'] ?? [])),
                companions: $input['companions'] ?? null,
                budget: $input['budget'] ?? null,
            ), $profile, $now);
        } catch (\Throwable $e) {
            Log::warning('constraint parse failed, using profile defaults', ['error' => $e->getMessage()]);

            return $this->defaults($profile, $now);
        }
    }

    /**
     * Scope guard: clamp to the 72h horizon and never let a window
     * start in the past or end before it starts.
     */
    private function clamp(Constraints $constraints, Profile $profile, CarbonImmutable $now): Constraints
    {
        $horizon = $now->addHours(72);
        $start = $constraints->windowStart->max($now);
        $end = $constraints->windowEnd->min($horizon);

        if ($end->lessThanOrEqualTo($start)) {
            return $this->defaults($profile, $now);
        }

        return new Constraints(
            windowStart: $start,
            windowEnd: $end,
            areas: $constraints->areas !== [] ? $constraints->areas : $profile->defaultAreas,
            categories: $constraints->categories,
            companions: $constraints->companions,
            budget: $constraints->budget,
        );
    }

    private function defaults(Profile $profile, CarbonImmutable $now): Constraints
    {
        return new Constraints(
            windowStart: $now,
            windowEnd: $now->endOfDay()->min($now->addHours(8)),
            areas: $profile->defaultAreas,
        );
    }
}

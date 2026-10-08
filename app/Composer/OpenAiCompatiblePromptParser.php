<?php

namespace App\Composer;

use App\Composer\Concerns\NormalisesConstraints;
use App\Composer\Contracts\ParsesPrompt;
use App\Enums\SpotCategory;
use App\Places\PlaceCapabilities;
use App\Privacy\ExternalProcessingGate;
use App\Privacy\ProcessingConsentStore;
use App\Privacy\ProcessingPermit;
use App\Privacy\ProcessingPurpose;
use App\Profile\Profile;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * One driver for every OpenAI-compatible chat API — DeepSeek AND OpenAI
 * (GPT) speak the same chat-completions + function-calling dialect, so the
 * provider is just a base URL + model + key in config. It classifies the
 * intent and parses constraints in a single forced tool call; it never
 * picks venues or answers questions. Any failure degrades to the
 * deterministic heuristic, so the box always responds.
 *
 * Dormant until config('services.llm.driver') === 'openai' and a key is
 * set; the heuristic carries the product until then.
 */
class OpenAiCompatiblePromptParser implements ParsesPrompt
{
    use NormalisesConstraints;

    private const FUNCTION = [
        'type' => 'function',
        'function' => [
            'name' => 'route_prompt',
            'description' => 'Classify the user prompt and extract its payload. Never choose venues.',
            'parameters' => [
                'type' => 'object',
                'properties' => [
                    'intent' => [
                        'type' => 'string',
                        'enum' => ['plan_day', 'bureaucracy_q', 'find', 'take_me_there', 'unknown'],
                        'description' => 'plan_day = compose free time; bureaucracy_q = a German-paperwork question; find = search a place/event; take_me_there = navigation request',
                    ],
                    'window_start' => ['type' => 'string', 'description' => 'plan_day only: ISO 8601 local datetime the free time starts'],
                    'window_end' => ['type' => 'string', 'description' => 'plan_day only: ISO 8601 local datetime the free time ends'],
                    'areas' => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => 'Cologne Veedel names mentioned, empty if none'],
                    'categories' => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => 'Supported place types or category families; choose exact types for specific requests and never invent types'],
                    'activities' => ['type' => 'array', 'items' => ['type' => 'string', 'enum' => PlaceCapabilities::ACTIVITIES], 'description' => 'Explicit sports only; football means soccer. Never infer capability from pitch category.'],
                    'radius_km' => ['type' => ['number', 'null'], 'minimum' => 0.1, 'maximum' => 50, 'description' => 'Explicit maximum distance from the chosen starting point in kilometres.'],
                    'companions' => ['type' => ['string', 'null'], 'enum' => ['alone', 'partner', 'friends', 'kids', null]],
                    'budget' => ['type' => ['string', 'null'], 'enum' => ['free', 'low', 'normal', null]],
                    'query' => ['type' => ['string', 'null'], 'description' => 'normalized search text for find / bureaucracy_q / take_me_there'],
                ],
                'required' => ['intent'],
            ],
        ],
    ];

    public function __construct(private readonly HeuristicPromptParser $fallback) {}

    private function functionSchema(): array
    {
        $schema = self::FUNCTION;
        $schema['function']['parameters']['properties']['categories']['items']['enum'] = array_values(array_unique([
            ...array_map(fn (SpotCategory $category): string => $category->value, SpotCategory::cases()),
            ...SpotCategory::placesCoarse(),
            'event',
        ]));

        return $schema;
    }

    public static function processingContext(string $text, Profile $profile): array
    {
        return ['text' => $text, 'veedel' => $profile->veedel];
    }

    public function parse(string $text, Profile $profile, CarbonImmutable $now, ?ProcessingPermit $permit = null): ParsedPrompt
    {
        if ($permit === null) {
            return $this->fallback->parse($text, $profile, $now);
        }
        $reference = app(ProcessingConsentStore::class)->referenceTime($permit);
        if ($reference === null) {
            return $this->fallback->parse($text, $profile, $now);
        }
        $now = $reference->setTimezone($now->getTimezone());

        try {
            $body = app(ExternalProcessingGate::class)->send($permit, ProcessingPurpose::ComposerParse, self::processingContext($text, $profile), fn () => Http::baseUrl((string) config('services.llm.base_url'))
                ->withOptions(['allow_redirects' => false])
                ->withToken((string) config('services.llm.key'))
                ->timeout(10)
                ->connectTimeout(3)
                ->post('/chat/completions', [
                    'model' => (string) config('services.llm.model'),
                    'temperature' => 0,
                    'tools' => [$this->functionSchema()],
                    'tool_choice' => ['type' => 'function', 'function' => ['name' => 'route_prompt']],
                    'messages' => [
                        [
                            'role' => 'system',
                            'content' => 'You route a newcomer-to-Cologne prompt. You only classify and parse — '
                                .'you never choose venues, never answer paperwork questions, never invent areas. '
                                .'Resolve relative dates against the given local time; the window must stay within 72 hours.',
                        ],
                        [
                            'role' => 'user',
                            'content' => "Current local time: {$now->toIso8601String()} ({$now->format('l')}, Europe/Berlin).\n"
                                .'Home Veedel: '.($profile->veedel ?? 'unknown').".\n\nPrompt: \"{$text}\"",
                        ],
                    ],
                ]));

            if ($body === null) {
                return $this->fallback->parse($text, $profile, $now);
            }

            $args = $this->arguments($body);
            $explicit = $this->fallback->explicitPlaceRequirements($text);
            $intent = PromptIntent::tryFrom((string) ($args['intent'] ?? '')) ?? PromptIntent::Find;

            if ($intent === PromptIntent::PlanDay && isset($args['window_start'], $args['window_end'])) {
                return new ParsedPrompt(
                    $intent,
                    plan: $this->clampConstraints(new Constraints(
                        windowStart: CarbonImmutable::parse($args['window_start'], 'Europe/Berlin'),
                        windowEnd: CarbonImmutable::parse($args['window_end'], 'Europe/Berlin'),
                        areas: $this->normaliseAreas($args['areas'] ?? []),
                        categories: $this->normaliseCategories($args['categories'] ?? []),
                        companions: $this->allowedString($args['companions'] ?? null, ['alone', 'partner', 'friends', 'kids']),
                        budget: $explicit['budget'] ?? $this->allowedString($args['budget'] ?? null, ['free', 'low', 'normal']),
                        activities: $explicit['activities'] !== [] ? $explicit['activities'] : collect(is_array($args['activities'] ?? null) ? $args['activities'] : [])
                            ->filter(fn ($value): bool => is_string($value) && in_array($value, PlaceCapabilities::ACTIVITIES, true))->unique()->values()->all(),
                        radiusKm: $explicit['radius_km'] ?? (is_numeric($args['radius_km'] ?? null) && $args['radius_km'] >= 0.1 && $args['radius_km'] <= 50 ? (float) $args['radius_km'] : null),
                    ), $profile, $now),
                    source: 'llm',
                );
            }

            if ($explicit['activities'] !== [] && ($explicit['budget'] !== null || $explicit['radius_km'] !== null) && $intent === PromptIntent::Find) {
                return $this->fallback->parse($text, $profile, $now);
            }

            return new ParsedPrompt($intent, query: $args['query'] ?? $text, source: 'llm');
        } catch (\Throwable $e) {
            Log::warning('llm prompt parse failed, using heuristic', ['error_type' => $e::class]);

            return $this->fallback->parse($text, $profile, $now);
        }
    }

    /**
     * Pull the forced tool call's JSON arguments out of the response.
     *
     * @param  array<string, mixed>|null  $body
     * @return array<string, mixed>
     */
    private function arguments(?array $body): array
    {
        $raw = data_get($body, 'choices.0.message.tool_calls.0.function.arguments');
        if (! is_string($raw)) {
            throw new \RuntimeException('no tool call in LLM response');
        }

        $decoded = json_decode($raw, true);
        if (! is_array($decoded)) {
            throw new \RuntimeException('tool call arguments were not valid JSON');
        }

        return $decoded;
    }

    /**
     * @return list<string>
     */
    private function normaliseAreas(mixed $areas): array
    {
        if (! is_array($areas)) {
            return [];
        }

        $known = collect(config('veedels', []))
            ->flatten()
            ->filter(fn (mixed $area): bool => is_string($area))
            ->mapWithKeys(fn (string $area): array => [mb_strtolower($area) => $area]);

        return collect($areas)
            ->filter(fn (mixed $area): bool => is_string($area))
            ->map(fn (string $area): ?string => $known->get(mb_strtolower(trim($area))))
            ->filter()
            ->unique()
            ->values()
            ->all();
    }

    /**
     * @return list<string>
     */
    private function normaliseCategories(mixed $categories): array
    {
        if (! is_array($categories)) {
            return [];
        }

        $allowed = [
            ...array_map(fn (SpotCategory $category): string => $category->value, SpotCategory::cases()),
            ...SpotCategory::placesCoarse(),
            'event',
        ];

        return collect($categories)
            ->filter(fn (mixed $category): bool => is_string($category))
            ->map(fn (string $category): string => mb_strtolower(trim($category)))
            ->filter(fn (string $category): bool => in_array($category, $allowed, true))
            ->unique()
            ->values()
            ->all();
    }

    /**
     * @param  list<string>  $allowed
     */
    private function allowedString(mixed $value, array $allowed): ?string
    {
        return is_string($value) && in_array($value, $allowed, true) ? $value : null;
    }
}

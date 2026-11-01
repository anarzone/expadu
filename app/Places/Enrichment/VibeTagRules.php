<?php

namespace App\Places\Enrichment;

use App\Enums\VibeTag;
use App\Services\OpeningHoursParser;

/**
 * Which evidence can carry which vibe tag.
 *
 * OSM evidence is decided here, in code: a tag is set when the place carries
 * one of the listed key=value pairs ('*' = any value but "no"), and the pair
 * is stored as its evidence. The model never adds an OSM-backed tag this
 * table doesn't produce. Text evidence is the model's part: an exact quote
 * from the place's source text that contains one of the tag's cue words
 * (VibeTag::textCue). Step-free and late night never take text evidence.
 */
final class VibeTagRules
{
    /** @var array<string, array<string, list<string>>> */
    private const OSM = [
        'quiet' => ['amenity' => ['library'], 'leisure' => ['nature_reserve']],
        'lively' => ['amenity' => ['bar', 'pub', 'biergarten', 'nightclub'], 'live_music' => ['yes']],
        'family_friendly' => [
            'changing_table' => ['yes'], 'highchair' => ['yes'], 'kids_area' => ['yes'], 'kids_area:indoor' => ['yes'],
            'baby_feeding' => ['yes'], 'leisure' => ['playground'], 'tourism' => ['zoo', 'theme_park'], 'zoo' => ['*'],
        ],
        'kid_play' => ['leisure' => ['playground'], 'playground' => ['*'], 'kids_area' => ['yes'], 'kids_area:indoor' => ['yes']],
        'outdoor_seating' => ['outdoor_seating' => ['yes'], 'amenity' => ['biergarten']],
        'indoors' => [
            'indoor' => ['yes'], 'indoor_seating' => ['yes'], 'tourism' => ['museum', 'gallery', 'aquarium'],
            'amenity' => ['library', 'cinema', 'theatre', 'arts_centre', 'cafe', 'restaurant', 'bar', 'pub'],
            'leisure' => ['bowling_alley', 'escape_game', 'amusement_arcade', 'trampoline_park', 'sports_hall'],
        ],
        'garden_or_park' => ['leisure' => ['park', 'garden']],
        'waterside' => ['natural' => ['water', 'beach'], 'water' => ['*'], 'waterway' => ['*'], 'leisure' => ['marina', 'swimming_area', 'beach_resort']],
        'scenic_view' => ['tourism' => ['viewpoint'], 'tower:type' => ['observation']],
        'historic' => ['historic' => ['*'], 'heritage' => ['*']],
        'work_friendly' => ['amenity' => ['coworking_space', 'library'], 'office' => ['coworking']],
        'vegetarian_friendly' => ['diet:vegetarian' => ['yes', 'only']],
        'vegan_options' => ['diet:vegan' => ['yes', 'only']],
        'dog_friendly' => ['dog' => ['yes', 'leashed', 'unleashed'], 'leisure' => ['dog_park']],
        'local_food' => ['microbrewery' => ['yes'], 'craft' => ['brewery']],
        'step_free' => ['wheelchair' => ['yes']],
    ];

    /** Cuisine values (one of a `;` list) that carry a tag. */
    private const CUISINE = [
        'vegetarian_friendly' => ['vegetarian', 'vegan'],
        'vegan_options' => ['vegan'],
        'local_food' => ['german', 'regional', 'rhenish', 'rhineland', 'brauhaus', 'kölsch'],
    ];

    /** Only these internet_access values make a café work-friendly. */
    private const WIFI = ['wlan', 'yes', 'wifi'];

    /** Every OSM key a rule reads, so the input hash covers them. */
    public static function keys(): array
    {
        $keys = ['cuisine', 'internet_access', 'opening_hours'];
        foreach (self::OSM as $rules) {
            array_push($keys, ...array_keys($rules));
        }

        return array_values(array_unique($keys));
    }

    /**
     * The tags OSM alone supports, each with the pair that supports it.
     *
     * @param  array<string, string>  $tags
     * @return list<array{tag: string, by: string, evidence: array{source: string, ref: string}}>
     */
    public function fromOsm(array $tags): array
    {
        $found = [];
        $add = function (string $tag, string $key) use (&$found, $tags): void {
            $found[$tag] ??= self::entry($tag, 'rule', 'osm', $key.'='.$tags[$key]);
        };

        foreach (self::OSM as $tag => $rules) {
            foreach ($rules as $key => $values) {
                $value = $this->value($tags, $key);
                if ($value === null || $value === 'no') {
                    continue;
                }
                // A café or restaurant mapped with indoor_seating=no is not an indoor place.
                if ($tag === 'indoors' && $key === 'amenity' && $this->value($tags, 'indoor_seating') === 'no') {
                    continue;
                }
                if ($values === ['*'] || array_intersect($this->parts($value), $values) !== []) {
                    $add($tag, $key);
                }
            }
        }

        $cuisines = $this->parts($this->value($tags, 'cuisine') ?? '');
        foreach (self::CUISINE as $tag => $values) {
            if (array_intersect($cuisines, $values) !== []) {
                $add($tag, 'cuisine');
            }
        }

        if ($this->value($tags, 'amenity') === 'cafe' && in_array($this->value($tags, 'internet_access'), self::WIFI, true)) {
            $add('work_friendly', 'internet_access');
        }

        // Venues only: a park or pitch mapped "24/7" is open ground, not a late-night place.
        if (($this->value($tags, 'amenity') !== null || $this->value($tags, 'shop') !== null) && $this->opensPastMidnight($tags['opening_hours'] ?? null)) {
            $add('late_night', 'opening_hours');
        }

        // Stable order: the enum's.
        return array_values(array_filter(array_map(
            fn (VibeTag $tag): ?array => $found[$tag->value] ?? null,
            VibeTag::cases(),
        )));
    }

    /**
     * Keeps only the model's tags whose evidence checks out:
     * - text: the quote is in the source text (case and spacing aside) and
     *   contains one of the tag's cue words;
     * - osm: the rules above produce the same tag from the same pair (those
     *   tags are already set, so nothing new comes from this path).
     * Unknown tags, code-only tags and anything else are dropped.
     *
     * @param  list<mixed>  $proposed
     * @param  list<array{tag: string, by: string, evidence: array{source: string, ref: string}}>  $ruleTags
     * @return list<array{tag: string, by: string, evidence: array{source: string, ref: string}}>
     */
    public function verify(array $proposed, ?string $sourceText, array $ruleTags): array
    {
        $ruled = collect($ruleTags)->keyBy('tag');
        $accepted = [];

        foreach ($proposed as $item) {
            $tag = is_array($item) ? VibeTag::tryFrom((string) ($item['tag'] ?? '')) : null;
            $source = is_array($item) ? ($item['source'] ?? null) : null;
            $ref = is_array($item) && is_string($item['ref'] ?? null) ? trim(html_entity_decode($item['ref'], ENT_QUOTES | ENT_HTML5, 'UTF-8')) : '';
            if ($tag === null || $tag->codeOnly() || $ruled->has($tag->value) || isset($accepted[$tag->value]) || $ref === '') {
                continue;
            }

            if ($source === 'text' && $sourceText !== null && $this->quoted($ref, $sourceText) && preg_match((string) $tag->textCue(), $ref) === 1) {
                $accepted[$tag->value] = self::entry($tag->value, 'model', 'text', $ref);
            }
        }

        return array_values($accepted);
    }

    /** @return array{tag: string, by: string, evidence: array{source: string, ref: string}} */
    private static function entry(string $tag, string $by, string $source, string $ref): array
    {
        return ['tag' => $tag, 'by' => $by, 'evidence' => ['source' => $source, 'ref' => mb_substr($ref, 0, 300)]];
    }

    private function quoted(string $ref, string $text): bool
    {
        $normalise = fn (string $value): string => mb_strtolower(trim((string) preg_replace('/\s+/u', ' ', $value)));
        $ref = $normalise($ref);

        return mb_strlen($ref) >= 3 && str_contains($normalise($text), $ref);
    }

    /**
     * Open until midnight or later on at least one day: an interval ending at
     * 24:00, or one that runs past midnight (18:00-01:00).
     */
    private function opensPastMidnight(?string $raw): bool
    {
        foreach (OpeningHoursParser::parse($raw) ?? [] as $intervals) {
            foreach ($intervals as [$open, $close]) {
                if ($close === '24:00' || ($close !== $open && $close <= $open)) {
                    return true;
                }
            }
        }

        return false;
    }

    /** @param array<string, mixed> $tags */
    private function value(array $tags, string $key): ?string
    {
        $value = $tags[$key] ?? null;

        return is_string($value) && trim($value) !== '' ? mb_strtolower(trim($value)) : null;
    }

    /** @return list<string> */
    private function parts(string $value): array
    {
        return array_values(array_filter(array_map('trim', explode(';', mb_strtolower($value)))));
    }
}

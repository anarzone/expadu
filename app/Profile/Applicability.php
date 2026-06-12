<?php

namespace App\Profile;

/**
 * Tri-state task applicability. Unknown means the verdict hinges on an
 * attribute the user hasn't answered yet — those tasks render as teaser
 * cards ("answer 1 question"), never silently hidden.
 */
enum Applicability
{
    case Yes;
    case No;
    case Unknown;

    /**
     * Evaluate a task's applies_if against a flat attribute bag.
     *
     * applies_if is a list of AND-groups (any group matching → applicable).
     * Each group maps attribute => scalar (equality) or list (in). Within a
     * group: any condition that definitively fails → group fails; any
     * condition on a NULL attribute (and nothing failed) → group unknown.
     * Across groups: any Yes wins; else any Unknown → Unknown; else No.
     *
     * @param  list<array<string, mixed>>|null  $appliesIf
     * @param  array<string, mixed>  $attributes
     */
    public static function evaluate(?array $appliesIf, array $attributes): self
    {
        if ($appliesIf === null || $appliesIf === []) {
            return self::Yes;
        }

        $sawUnknown = false;

        foreach ($appliesIf as $group) {
            $verdict = self::evaluateGroup($group, $attributes);
            if ($verdict === self::Yes) {
                return self::Yes;
            }
            if ($verdict === self::Unknown) {
                $sawUnknown = true;
            }
        }

        return $sawUnknown ? self::Unknown : self::No;
    }

    /**
     * The attributes a verdict is waiting on — drives which teaser question
     * to ask. Only meaningful when evaluate() returned Unknown.
     *
     * @param  list<array<string, mixed>>|null  $appliesIf
     * @param  array<string, mixed>  $attributes
     * @return list<string>
     */
    public static function unknownAttributes(?array $appliesIf, array $attributes): array
    {
        $unknown = [];

        foreach ($appliesIf ?? [] as $group) {
            if (self::evaluateGroup($group, $attributes) !== self::Unknown) {
                continue;
            }
            foreach ($group as $attribute => $expected) {
                if (($attributes[$attribute] ?? null) === null) {
                    $unknown[] = $attribute;
                }
            }
        }

        return array_values(array_unique($unknown));
    }

    /**
     * @param  array<string, mixed>  $group
     * @param  array<string, mixed>  $attributes
     */
    private static function evaluateGroup(array $group, array $attributes): self
    {
        $sawUnknown = false;

        foreach ($group as $attribute => $expected) {
            $actual = $attributes[$attribute] ?? null;

            if ($actual === null) {
                $sawUnknown = true;

                continue;
            }

            $matches = is_array($expected)
                ? in_array($actual, $expected, true)
                : $actual === $expected;

            if (! $matches) {
                return self::No;
            }
        }

        return $sawUnknown ? self::Unknown : self::Yes;
    }
}

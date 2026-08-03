<?php

namespace App\Profile;

use Carbon\CarbonImmutable;
use DomainException;
use Throwable;

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
     * Each group maps attribute => scalar (equality), list (membership), or a
     * single explicit operator. Within a group: any condition that
     * definitively fails → group fails; any unresolved condition (and
     * nothing failed) → group unknown.
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
                if (self::evaluateCondition($expected, $attributes[$attribute] ?? null) === self::Unknown) {
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
            $verdict = self::evaluateCondition($expected, $actual);

            if ($verdict === self::Unknown) {
                $sawUnknown = true;

                continue;
            }

            if ($verdict === self::No) {
                return self::No;
            }
        }

        return $sawUnknown ? self::Unknown : self::Yes;
    }

    private static function evaluateCondition(mixed $expected, mixed $actual): self
    {
        if ($expected === null) {
            throw new DomainException('Applicability conditions cannot use an explicit null operand.');
        }

        if (! is_array($expected)) {
            if ($actual === null) {
                return self::Unknown;
            }

            return $actual === $expected ? self::Yes : self::No;
        }

        if (array_is_list($expected)) {
            if ($actual === null) {
                return self::Unknown;
            }

            return in_array($actual, $expected, true) ? self::Yes : self::No;
        }

        if (count($expected) !== 1) {
            throw new DomainException('Applicability operator objects must contain exactly one operator.');
        }

        $operator = array_key_first($expected);
        $operand = $expected[$operator];

        if (! is_string($operator) || ! in_array($operator, ['gte', 'lte', 'in', 'present', 'at_least_months_ago', 'months_ago_between'], true)) {
            throw new DomainException('Applicability condition uses an unsupported operator.');
        }

        if ($operator === 'present') {
            if (! is_bool($operand)) {
                throw new DomainException('The present applicability operator requires a boolean operand.');
            }

            return ($actual !== null) === $operand ? self::Yes : self::No;
        }

        if ($operator === 'in') {
            if (! is_array($operand) || ! array_is_list($operand) || $operand === []) {
                throw new DomainException('The in applicability operator requires a non-empty list operand.');
            }

            if ($actual === null) {
                return self::Unknown;
            }

            return in_array($actual, $operand, true) ? self::Yes : self::No;
        }

        if ($operator === 'at_least_months_ago') {
            if (! is_int($operand) || $operand < 0) {
                throw new DomainException('The at_least_months_ago applicability operator requires a non-negative integer operand.');
            }

            return self::evaluateDateAge($actual, $operand);
        }

        if ($operator === 'months_ago_between') {
            if (! is_array($operand)
                || ! array_is_list($operand)
                || count($operand) !== 2
                || ! is_int($operand[0])
                || ! is_int($operand[1])
                || $operand[0] < 0
                || $operand[0] > $operand[1]) {
                throw new DomainException('The months_ago_between applicability operator requires an ascending pair of non-negative integers.');
            }

            if ($actual === null) {
                return self::Unknown;
            }

            $date = self::dateValue($actual);

            if ($date === null) {
                return self::No;
            }

            $today = CarbonImmutable::today(config('app.timezone'));
            $matches = $date->lessThanOrEqualTo($today->subMonthsNoOverflow($operand[0]))
                && $date->greaterThan($today->subMonthsNoOverflow($operand[1] + 1));

            return $matches ? self::Yes : self::No;
        }

        if (! is_int($operand) && ! is_float($operand)) {
            throw new DomainException("The {$operator} applicability operator requires a numeric operand.");
        }

        if ($actual === null) {
            return self::Unknown;
        }

        if (! is_int($actual) && ! is_float($actual)) {
            return self::No;
        }

        $matches = $operator === 'gte'
            ? $actual >= $operand
            : $actual <= $operand;

        return $matches ? self::Yes : self::No;
    }

    private static function evaluateDateAge(mixed $actual, int $months): self
    {
        if ($actual === null) {
            return self::Unknown;
        }

        $date = self::dateValue($actual);

        if ($date === null) {
            return self::No;
        }

        return $date->lessThanOrEqualTo(CarbonImmutable::today(config('app.timezone'))->subMonthsNoOverflow($months))
            ? self::Yes
            : self::No;
    }

    private static function dateValue(mixed $value): ?CarbonImmutable
    {
        if (! is_string($value) || preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) !== 1) {
            return null;
        }

        try {
            $date = CarbonImmutable::createFromFormat('!Y-m-d', $value);
        } catch (Throwable) {
            return null;
        }

        return $date->format('Y-m-d') === $value ? $date : null;
    }
}

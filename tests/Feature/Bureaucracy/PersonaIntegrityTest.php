<?php

use App\Bureaucracy\BureaucracyPersonas;
use App\Bureaucracy\Facts\FactRegistry;
use Illuminate\Support\Carbon;

/**
 * QA personas are the fixtures every review of "does the engine handle this
 * case?" is judged against. A persona that describes someone who cannot exist
 * makes the answer worthless — and worse, it reproduces the exact defect the
 * owner reported on the real page: a plan asserting two things that contradict
 * each other.
 */

/** Facts that place an event in the past, relative to the persona's arrival. */
const HISTORY_FACTS = [
    'family_residence_permit_held_since',
];

it('never gives a persona a history from before it arrived', function () {
    foreach (BureaucracyPersonas::demo() as $persona) {
        $arrival = BureaucracyPersonas::arrivalFor($persona);

        if ($arrival === null) {
            continue; // Pre-arrival personas have no history to contradict.
        }

        foreach (HISTORY_FACTS as $fact) {
            $since = $persona['facts'][$fact] ?? null;

            if ($since === null) {
                continue;
            }

            expect(Carbon::parse($arrival)->lessThanOrEqualTo(Carbon::parse($since)))
                ->toBeTrue(
                    "{$persona['key']}: arrived {$arrival} but claims {$fact} = {$since} — "
                    .'a permit issued before the person entered the country.'
                );
        }
    }
});

it('keeps a persona holding a Blue Card for at least as long as it claims', function () {
    foreach (BureaucracyPersonas::demo() as $persona) {
        $months = $persona['facts']['blue_card_qualifying_months'] ?? null;
        $arrival = BureaucracyPersonas::arrivalFor($persona);

        if ($months === null || $arrival === null) {
            continue;
        }

        expect(Carbon::parse($arrival)->diffInMonths(now()))
            ->toBeGreaterThanOrEqual(
                (int) $months,
                "{$persona['key']}: claims {$months} qualifying Blue Card months but arrived {$arrival}."
            );
    }
});

/**
 * The thresholds these personas exist to exercise are relative, so they must
 * still mean what their label says next month. Hardcoded dates drifted: the
 * "almost four years" persona was nine days from silently becoming four.
 */
it('holds the labelled thresholds steady as the calendar moves', function () {
    $held = function (string $key): float {
        $persona = collect(BureaucracyPersonas::demo())->firstWhere('key', $key);

        return Carbon::parse($persona['facts']['family_residence_permit_held_since'])
            ->diffInMonths(now());
    };

    // §9(3a) needs three years — the persona must sit just past it, never short.
    expect($held('case-spouse-18c-three-years'))->toBeGreaterThanOrEqual(36.0)
        ->and($held('case-spouse-18c-three-years'))->toBeLessThan(48.0);

    // "Almost four years" must stay almost, not become four.
    expect($held('case-family-renewal-four-years'))->toBeGreaterThanOrEqual(36.0)
        ->and($held('case-family-renewal-four-years'))->toBeLessThan(48.0);
});

it('exercises every residence title onboarding can record', function () {
    $offered = collect(app(FactRegistry::class)->definition('current_residence_title')->options)
        ->map(fn ($option) => is_array($option) ? ($option['value'] ?? null) : $option)
        ->filter()
        // "other" is the unsupported-title escape hatch, covered by its own persona.
        ->all();

    $covered = collect(BureaucracyPersonas::demo())
        ->pluck('facts.current_residence_title')
        ->filter()
        ->unique()
        ->all();

    // array_values: array_diff keeps the original keys, which would fail an
    // identity check against [] even when nothing is actually missing.
    $missing = array_values(array_diff($offered, $covered));

    // A title the wizard offers but no persona holds is a case nobody reviews —
    // which is how the §9 / §18c split shipped with neither exercised.
    expect($missing)->toBe(
        [],
        'No QA persona holds: '.implode(', ', $missing)
    );
});

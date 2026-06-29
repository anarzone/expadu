<?php

use App\Composer\HeuristicPromptParser;
use App\Composer\ParsedPrompt;
use App\Composer\PromptIntent;
use App\Enums\GermanLevel;
use App\Enums\Situation;
use App\Profile\Profile;
use App\Profile\TicketAdvice;
use Carbon\CarbonImmutable;

/**
 * The key-free parser that runs the product until a provider lands. Pure
 * string heuristics — no DB, no network — so these read like unit tests
 * but live in Feature for config('veedels') access.
 */
function heuristicProfile(): Profile
{
    return new Profile(
        situation: Situation::Student,
        isEu: true,
        arrivalDate: null,
        veedel: 'Ehrenfeld',
        bureaucracyBranch: 'student',
        ticketAdvice: TicketAdvice::SemesterTicket,
        defaultAreas: ['Ehrenfeld', 'Neuehrenfeld'],
        germanLevel: GermanLevel::B1,
    );
}

// 2026-06-10 is a Wednesday; 2026-06-13 is the Saturday the pipeline fixtures
// use. Evening anchor keeps "Saturday afternoon" exactly inside the 72h horizon.
function heuristicNow(): CarbonImmutable
{
    return CarbonImmutable::parse('2026-06-10 18:00', 'Europe/Berlin');
}

function parsePrompt(string $text): ParsedPrompt
{
    return (new HeuristicPromptParser)->parse($text, heuristicProfile(), heuristicNow());
}

test('a paperwork question routes to bureaucracy, never a plan', function () {
    $result = parsePrompt('Do I need an appointment for Anmeldung?');

    expect($result->intent)->toBe(PromptIntent::BureaucracyQ)
        ->and($result->plan)->toBeNull()
        ->and($result->query)->toBe('Do I need an appointment for Anmeldung?')
        ->and($result->source)->toBe('heuristic');
});

test('explicit navigation wins even over a bureaucracy keyword', function () {
    $result = parsePrompt('take me to the Ausländerbehörde');

    expect($result->intent)->toBe(PromptIntent::TakeMeThere);
});

test('a place search with no time word is a find', function () {
    $result = parsePrompt('basketball court near Ehrenfeld');

    expect($result->intent)->toBe(PromptIntent::Find)
        ->and($result->plan)->toBeNull();
});

test('a leisure prompt extracts window, area, companions and budget', function () {
    $result = parsePrompt('free Saturday afternoon in Ehrenfeld with friends, cheap');

    expect($result->intent)->toBe(PromptIntent::PlanDay)
        ->and($result->plan)->not->toBeNull();

    $plan = $result->plan;
    expect($plan->windowStart->format('l'))->toBe('Saturday')
        ->and($plan->windowStart->format('H:i'))->toBe('12:00')
        ->and($plan->windowEnd->format('H:i'))->toBe('18:00')
        ->and($plan->areas)->toContain('Ehrenfeld')
        ->and($plan->companions)->toBe('friends')
        ->and($plan->budget)->toBe('low');
});

test('"free" as in free time is not mistaken for a free budget', function () {
    $result = parsePrompt('free Saturday afternoon');

    expect($result->intent)->toBe(PromptIntent::PlanDay)
        ->and($result->plan->budget)->toBeNull();
});

test('"meet people tonight" is an evening plan with friends', function () {
    $result = parsePrompt('meet people tonight');

    expect($result->intent)->toBe(PromptIntent::PlanDay)
        ->and($result->plan->companions)->toBe('friends')
        ->and($result->plan->windowStart->format('Y-m-d H:i'))->toBe('2026-06-10 18:00');
});

test('"something with the kids tomorrow" plans tomorrow with kids', function () {
    $result = parsePrompt('something with the kids tomorrow');

    expect($result->intent)->toBe(PromptIntent::PlanDay)
        ->and($result->plan->companions)->toBe('kids')
        ->and($result->plan->windowStart->format('Y-m-d'))->toBe('2026-06-11');
});

test('plural and umbrella category words are extracted', function () {
    // "tomorrow" keeps the window in the future (now is an evening in the fixture).
    // Plurals of singular synonyms (-s and -y→-ies) still resolve.
    $culture = parsePrompt('see some museums and galleries tomorrow afternoon');
    expect($culture->intent)->toBe(PromptIntent::PlanDay)
        ->and($culture->plan->categories)->toContain('culture');

    // "sports" is an umbrella that fans out to the active sport categories.
    $sports = parsePrompt('a day of sports tomorrow');
    expect($sports->plan->categories)->toContain('pitch')
        ->and($sports->plan->categories)->toContain('basketball');

    // "outdoors" is an umbrella too — the open-air categories.
    $outdoors = parsePrompt('something outdoors tomorrow afternoon');
    expect($outdoors->plan->categories)->toContain('park')
        ->and($outdoors->plan->categories)->toContain('lake')
        ->and($outdoors->plan->categories)->toContain('playground');
});

test('a prompt with no named area leaves areas empty (compose fills home areas)', function () {
    $result = parsePrompt('tomorrow afternoon');

    // The whole home Bezirk is no longer spelled out as chips; compose
    // applies the profile's areas for scoring internally instead.
    expect($result->intent)->toBe(PromptIntent::PlanDay)
        ->and($result->plan->areas)->toBe([]);
});

test('the window is clamped to the 72h horizon', function () {
    $result = parsePrompt('plan my Saturday');

    expect($result->plan->windowStart->diffInHours($result->plan->windowEnd))
        ->toBeLessThanOrEqual(72);
});

test('a daypart clamped late in the day is widened to a usable window', function () {
    // "afternoon" (12:00–18:00) parsed at 16:45 would clamp to a ~75-min sliver
    // that the composer can't fill; the min-window guard stretches the end so a
    // plan still has room (the "free afternoon → nothing fits" bug).
    $now = CarbonImmutable::parse('2026-06-10 16:45', 'Europe/Berlin');
    $result = (new HeuristicPromptParser)->parse('free afternoon', heuristicProfile(), $now);

    expect($result->plan)->not->toBeNull()
        ->and($result->plan->windowMinutes())->toBeGreaterThanOrEqual(180);
});

test('a full daypart window is left untouched', function () {
    $now = CarbonImmutable::parse('2026-06-10 09:00', 'Europe/Berlin');
    $result = (new HeuristicPromptParser)->parse('free afternoon', heuristicProfile(), $now);

    expect($result->plan->windowStart->format('H:i'))->toBe('12:00')
        ->and($result->plan->windowEnd->format('H:i'))->toBe('18:00');
});

test('a "today" plan asked once the day is spent rolls forward and KEEPS the activity', function () {
    // "pitch today" at 22:00: the default day window (10:00–22:00) is already
    // past, so the window rolls to tomorrow — but the chosen activity must
    // survive. Resetting it here is the bug that made the sentence read "for
    // anything" and the plan come back empty.
    $now = CarbonImmutable::parse('2026-06-29 22:05', 'Europe/Berlin');
    $result = (new HeuristicPromptParser)->parse('pitch today', heuristicProfile(), $now);

    expect($result->intent)->toBe(PromptIntent::PlanDay)
        ->and($result->plan->categories)->toBe(['pitch'])
        ->and($result->plan->windowStart->isAfter($now))->toBeTrue()
        ->and($result->plan->windowMinutes())->toBeGreaterThanOrEqual(180);
});

test('an explicit evening still composes tonight when asked late, keeping the activity', function () {
    // "pitch tonight" at 22:00 keeps the evening (stretched past midnight for
    // room) rather than jumping to tomorrow — and still carries the category.
    $now = CarbonImmutable::parse('2026-06-29 22:00', 'Europe/Berlin');
    $result = (new HeuristicPromptParser)->parse('pitch tonight', heuristicProfile(), $now);

    expect($result->plan->categories)->toBe(['pitch'])
        ->and($result->plan->windowStart->isSameDay($now))->toBeTrue()
        ->and($result->plan->windowMinutes())->toBeGreaterThanOrEqual(180);
});

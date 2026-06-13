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

test('a vague leisure prompt falls back to profile default areas', function () {
    $result = parsePrompt('what should I do this afternoon');

    expect($result->intent)->toBe(PromptIntent::PlanDay)
        ->and($result->plan->areas)->toBe(['Ehrenfeld', 'Neuehrenfeld']);
});

test('the window is clamped to the 72h horizon', function () {
    $result = parsePrompt('plan my Saturday');

    expect($result->plan->windowStart->diffInHours($result->plan->windowEnd))
        ->toBeLessThanOrEqual(72);
});

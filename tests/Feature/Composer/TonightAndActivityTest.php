<?php

use App\Composer\CategoryHours;
use App\Composer\HeuristicPromptParser;
use App\Enums\GermanLevel;
use App\Enums\Situation;
use App\Models\Spot;
use App\Places\PlaceFacts;
use App\Profile\Profile;
use App\Profile\TicketAdvice;
use Carbon\CarbonImmutable;

function tonightProfile(): Profile
{
    return new Profile(situation: Situation::Other, isEu: true, arrivalDate: null, veedel: 'Merkenich', bureaucracyBranch: 'other',
        ticketAdvice: TicketAdvice::SemesterTicket, defaultAreas: ['Merkenich'], germanLevel: GermanLevel::B1);
}

it('never plans "tonight" across midnight, however late it is asked', function (string $at) {
    $now = CarbonImmutable::parse("2026-10-21 {$at}", 'Europe/Berlin');
    $plan = app(HeuristicPromptParser::class)->parse('what up tonight?', tonightProfile(), $now)->plan;

    // Still tonight while some of it is left (ending by 23:59); once it has
    // passed, tomorrow evening — but never a window that runs into the next day.
    expect($plan)->not->toBeNull()
        ->and($plan->windowEnd->toDateString())->toBe($plan->windowStart->toDateString())
        ->and($plan->windowEnd->format('H:i') <= '23:59')->toBeTrue();
})->with(['22:02', '22:55', '23:40']);

it('reads "what\'s on tonight" as a question about events', function (string $text) {
    $plan = app(HeuristicPromptParser::class)->parse($text, tonightProfile(), CarbonImmutable::parse('2026-10-21 19:00', 'Europe/Berlin'))->plan;

    expect($plan?->categories)->toContain('event');
})->with(['What’s on tonight?', "what's happening tonight", 'what up tonight?', 'Was ist los heute Abend? tonight']);

it('keeps viewpoints to daylight, like parks', function () {
    [$opens, $closes] = CategoryHours::defaults('viewpoint', CarbonImmutable::parse('2026-10-21', 'Europe/Berlin'));

    expect($opens)->not->toBeNull()->and($closes->hour)->toBeLessThan(22);
});

it('lets OSM evidence, not a manual review, qualify a pitch for an activity', function () {
    $evidence = ['category' => 'pitch', 'is_active' => true, 'is_recommendable' => false, 'lat' => 51.04, 'lng' => 6.93];
    $public = Spot::factory()->create([...$evidence, 'name' => 'Bolzplatz', 'tags' => ['sport' => 'soccer', 'leisure' => 'pitch']]);
    $private = Spot::factory()->create([...$evidence, 'name' => 'Bolzplatz', 'tags' => ['sport' => 'soccer', 'access' => 'private']]);
    $members = Spot::factory()->create([...$evidence, 'name' => 'Vereinsplatz', 'tags' => ['sport' => 'soccer', 'membership' => 'required']]);
    $noSport = Spot::factory()->create([...$evidence, 'name' => 'Bolzplatz', 'tags' => ['leisure' => 'pitch']]);
    Spot::query()->whereKey([$public->id, $private->id, $members->id, $noSport->id])->update(['public_access_allowed' => true]);

    $ids = Spot::query()->where(fn ($q) => app(PlaceFacts::class)->activityQualified($q))->pluck('id')->all();

    expect($ids)->toContain($public->id)
        ->not->toContain($private->id)
        ->not->toContain($members->id)
        ->not->toContain($noSport->id);
});

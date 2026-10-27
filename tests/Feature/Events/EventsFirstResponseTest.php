<?php

use App\Models\Event;
use App\Models\User;
use Carbon\CarbonImmutable;

// Pages load whole (owner, 26 Oct 2026): what Events shows comes in its first response.
// (The shell's shared `widgets` group is app-wide, not the Events page's own.)

/** The page's own deferred props, without the shell's shared ones. */
function eventsDeferred(array $page): array
{
    return collect($page['deferredProps'] ?? [])->except('widgets')->flatten()->all();
}

test('the first response carries the weather and nothing deferred', function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-26 12:00', 'Europe/Berlin'));
    $this->actingAs(User::factory()->create());

    $page = $this->get(route('events'))->assertOk()->viewData('page');

    expect($page['props'])->toHaveKey('weather')
        ->and($page['props']['weather'])->toHaveKey('available')
        ->and(eventsDeferred($page))->toBe([]);
});

test('a past day says the next day with listings in the first response', function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-26 12:00', 'Europe/Berlin'));
    $this->actingAs(User::factory()->create());
    Event::factory()->create(['starts_at' => CarbonImmutable::parse('2026-10-29 19:00', 'Europe/Berlin')->utc(), 'ends_at' => null]);

    $page = $this->get(route('events', ['date' => '2026-10-20']))->assertOk()->viewData('page');

    expect($page['props']['next_listed'])->toBe('2026-10-29')
        ->and(eventsDeferred($page))->toBe([]);
});

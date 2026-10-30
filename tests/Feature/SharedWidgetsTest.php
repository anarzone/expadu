<?php

use App\Day\DayProjection;
use App\Http\Middleware\HandleInertiaRequests;
use App\Models\User;
use Illuminate\Support\Facades\DB;

const WIDGET_PROPS = ['weather', 'forecast'];

function widgetUser(): User
{
    return User::factory()->create([
        'onboarded_at' => now(),
        'situation' => 'student',
        'veedel' => 'Ehrenfeld',
    ]);
}

/** Headers of an Inertia client visit (SPA navigation), plus any extras. */
function inertiaVisit(array $extra = []): array
{
    return [
        'X-Inertia' => 'true',
        'X-Inertia-Version' => (string) app(HandleInertiaRequests::class)->version(request()),
        ...$extra,
    ];
}

it('renders app pages with the shared right-panel widgets, no error', function () {
    $user = widgetUser();

    // share() (with the city-widget props) runs on every Inertia request:
    // these must not 500.
    $this->actingAs($user)->get('/composer')->assertOk();
    $this->actingAs($user)->get('/dashboard')->assertOk();
});

it('sends the widgets in the first response, never deferred', function () {
    $user = widgetUser();

    $page = $this->actingAs($user)->withHeaders(inertiaVisit())->get('/composer')->assertOk();

    // Cache reads, so a page needs no second round trip for them (pages load whole).
    expect($page->json('props'))->toHaveKeys(WIDGET_PROPS)
        ->and($page->json('deferredProps.widgets'))->toBeNull()
        ->and($page->json('onceProps'))->toBeNull();
});

it('shares no widgets with guests', function () {
    $page = $this->withHeaders(inertiaVisit())->get('/login')->assertOk();

    expect($page->json('props'))->not->toHaveKeys(WIDGET_PROPS)
        ->and($page->json('deferredProps.widgets'))->toBeNull();
});

it('a partial reload builds only the props it returns', function () {
    $user = widgetUser();
    $this->mock(DayProjection::class, fn ($mock) => $mock->shouldReceive('for')->never());

    // A partial reload of the shared widgets alone never works out the day.
    $this->actingAs($user)
        ->withHeaders(inertiaVisit([
            'X-Inertia-Partial-Component' => 'dashboard',
            'X-Inertia-Partial-Data' => 'weather',
        ]))
        ->get('/dashboard')
        ->assertOk()
        ->assertJsonPath('component', 'dashboard');

    // Departures' 30 s board poll (usePoll, only: ['boards']).
    DB::enableQueryLog();
    $this->actingAs($user)
        ->withHeaders(inertiaVisit([
            'X-Inertia-Partial-Component' => 'timetable',
            'X-Inertia-Partial-Data' => 'boards',
        ]))
        ->get('/timetable')
        ->assertOk();

    $recents = collect(DB::getQueryLog())->pluck('query')->filter(fn (string $sql) => str_contains($sql, 'journey_recents'));
    expect($recents)->toBeEmpty();
});

<?php

use App\Day\DayProjection;
use App\Http\Middleware\HandleInertiaRequests;
use App\Models\User;
use Illuminate\Support\Facades\DB;

const WIDGET_PROPS = ['weather', 'forecast', 'rhineLevel', 'featuredEvent', 'activeDisruptions'];

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

    // share() (which now defers the city-widget props) runs on every Inertia
    // request — these must not 500.
    $this->actingAs($user)->get('/composer')->assertOk();
    $this->actingAs($user)->get('/dashboard')->assertOk();
});

it('defers the widgets once, then skips them while the client still holds them', function () {
    $user = widgetUser();

    $first = $this->actingAs($user)->withHeaders(inertiaVisit())->get('/composer')->assertOk();

    expect($first->json('deferredProps.widgets'))->toEqualCanonicalizing(WIDGET_PROPS)
        ->and(array_keys($first->json('onceProps')))->toEqualCanonicalizing(WIDGET_PROPS);

    // The next navigation: the client reports what it already has, so no
    // second request goes out to re-fetch them.
    $next = $this->actingAs($user)
        ->withHeaders(inertiaVisit(['X-Inertia-Except-Once-Props' => implode(',', WIDGET_PROPS)]))
        ->get('/composer')
        ->assertOk();

    expect($next->json('deferredProps.widgets'))->toBeNull();
});

it('shares no widgets with guests, so nulls are not kept past login', function () {
    $page = $this->withHeaders(inertiaVisit())->get('/login')->assertOk();

    expect($page->json('deferredProps.widgets'))->toBeNull()
        ->and($page->json('onceProps'))->toBeNull();
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

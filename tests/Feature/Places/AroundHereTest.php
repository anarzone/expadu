<?php

use App\Models\ComposerWorkspace;
use App\Models\Spot;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

// Wiener Platz, Mülheim; Wednesday 30 September 2026, in Cologne.
const AROUND_LAT = 50.96103;
const AROUND_LNG = 7.0048;

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-09-30 13:10', 'Europe/Berlin'));
});

function aroundSpot(string $name, string $category, float $dLat, ?string $hours = 'Mo-Su 08:00-19:00'): Spot
{
    return Spot::factory()->create([
        'name' => $name,
        'category' => $category,
        'lat' => AROUND_LAT + $dLat,
        'lng' => AROUND_LNG,
        'veedel' => 'Mülheim',
        'source' => 'osm',
        'source_id' => 'node/'.fake()->unique()->numberBetween(1, 9_999_999),
        'tags' => $hours === null ? [] : ['opening_hours' => $hours],
        'public_access_allowed' => true,
    ]);
}

/** A plan on the person's own day today, at a place they put on the map. */
function aroundPlan(User $user, string $id): void
{
    ComposerWorkspace::query()->create(['user_id' => $user->id, 'revision' => 1, 'state' => ['plans' => ['2026-09-30' => ['slots' => [[
        'id' => $id,
        'type' => 'appointment',
        'name' => 'Blue Card appointment',
        'start_at' => '2026-09-30T14:00:00+02:00',
        'end_at' => '2026-09-30T14:30:00+02:00',
        'location' => 'Mülheim',
        'lat' => AROUND_LAT,
        'lng' => AROUND_LNG,
    ]]]]]]);
}

test('Around here lists one kind near a public point, nearest first, at the listed hours of the time asked', function () {
    aroundSpot('Near pharmacy', 'pharmacy', 0.0005, 'Mo-Fr 08:30-18:30');
    aroundSpot('Later pharmacy', 'pharmacy', 0.001, 'Mo-Fr 15:00-19:00');
    aroundSpot('A café', 'cafe', 0.0002);

    $this->actingAs(User::factory()->onboarded()->create())
        ->get('/explore?kind=pharmacy&near=50.96103,7.0048&name=Wiener%20Platz&time=14:30')
        ->assertInertia(fn ($page) => $page->component('around-here')
            ->where('point', ['name' => 'Wiener Platz', 'lat' => AROUND_LAT, 'lng' => AROUND_LNG])
            ->where('kind', 'pharmacy')
            ->where('at', ['date' => '2026-09-30', 'time' => '14:30'])
            ->where('places.0.name', 'Near pharmacy')
            ->where('places.0.hours', ['state' => 'open', 'until' => '18:30'])
            ->where('places.0.icon', 'pharmacy')
            ->where('places.1.hours', ['state' => 'closed', 'opens' => '15:00'])
            ->count('places', 2));
});

test('a plan names its point by its id; another person’s plan names none', function () {
    aroundSpot('Near bakery', 'bakery', 0.0005);
    $me = User::factory()->onboarded()->create();
    $other = User::factory()->onboarded()->create();
    aroundPlan($other, 'their-appointment');
    aroundPlan($me, 'my-appointment');

    $this->actingAs($me)->get('/explore?kind=bakery&near=plan:my-appointment')
        ->assertInertia(fn ($page) => $page->where('point', ['name' => 'Mülheim', 'lat' => AROUND_LAT, 'lng' => AROUND_LNG])
            ->where('places.0.name', 'Near bakery'));

    $this->actingAs($me)->get('/explore?kind=bakery&near=plan:their-appointment')
        ->assertInertia(fn ($page) => $page->where('point', null)->where('places', []));
});

test('"Open at" keeps only the places listed as open then', function () {
    aroundSpot('Open', 'pharmacy', 0.0005);
    aroundSpot('Opens later', 'pharmacy', 0.0006, 'Mo-Fr 15:00-19:00');

    $this->actingAs(User::factory()->onboarded()->create())
        ->get('/explore?kind=pharmacy&near=50.96103,7.0048&time=14:30&open=1')
        ->assertInertia(fn ($page) => $page->where('open', true)->count('places', 1)->where('places.0.name', 'Open'));
});

test('a thin area adds up to three further away; a full one stays within 2 km', function () {
    aroundSpot('Close', 'library', 0.001);
    foreach ([0.03, 0.04, 0.05, 0.06] as $i => $far) {
        aroundSpot("Far {$i}", 'library', $far);
    }

    $this->actingAs(User::factory()->onboarded()->create())
        ->get('/explore?kind=library&near=50.96103,7.0048')
        ->assertInertia(fn ($page) => $page->count('places', 3)
            ->where('places.0.name', 'Close')
            ->where('places.1.name', 'Far 0')
            ->where('places.2.name', 'Far 1'));
});

test('🔒 the point and the time are only read: nothing is written, cached or logged, and the session keeps neither', function (string $near) {
    aroundSpot('Near pharmacy', 'pharmacy', 0.0005);
    $me = User::factory()->onboarded()->create();
    aroundPlan($me, 'my-appointment');
    $this->actingAs($me);
    Cache::spy();
    Log::spy();
    $writes = [];
    DB::listen(function ($query) use (&$writes) {
        if (preg_match('/^\s*(insert|update|delete|upsert|merge)\b/i', $query->sql)) {
            $writes[] = $query->sql;
        }
    });

    $response = $this->get('/explore?kind=pharmacy&time=14:30&near='.urlencode($near).'&name=Wiener%20Platz');

    $response->assertOk();
    expect($writes)->toBe([])
        ->and(json_encode(session()->all()))->not->toContain('50.96')
        ->and(json_encode(session()->all()))->not->toContain('14:30')
        ->and(json_encode(session()->all()))->not->toContain($near);
    Cache::shouldNotHaveReceived('put');
    Cache::shouldNotHaveReceived('remember');
    Cache::shouldNotHaveReceived('forever');
    Log::shouldNotHaveReceived('info');
    Log::shouldNotHaveReceived('debug');
    Log::shouldNotHaveReceived('warning');
})->with(['a public point' => '50.96103,7.0048', 'a plan on my day' => 'plan:my-appointment']);

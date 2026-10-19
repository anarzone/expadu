<?php

use App\Models\FavouriteStop;
use App\Models\User;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    // KVB's stop list: Neumarkt's points and one other station.
    $point = fn (int $id, string $name, string $area, string $lines, float $lat, float $lng) => [
        'type' => 'Feature',
        'properties' => ['ASS' => $id, 'Name' => $name, 'Kurzname' => '', 'Betriebsbereich' => $area, 'Linien' => $lines],
        'geometry' => ['type' => 'Point', 'coordinates' => [$lng, $lat]],
    ];
    Http::fake([
        'data.webservice-kvb.koeln/*' => Http::response(['features' => [
            $point(211, 'Neumarkt', 'STRAB', '1 7 9', 50.93576, 6.94811),
            $point(214, 'Neumarkt', 'STRAB', '3 4 16 18', 50.93464, 6.94947),
            $point(57011, 'Mülheim Wiener Platz', 'STRAB', '4 13 18', 50.9610, 7.0048),
        ]]),
    ]);
});

test('favourite stops are for signed-in people only', function () {
    $this->getJson('/api/travel/favourite-stops')->assertUnauthorized();
    $this->postJson('/api/travel/favourite-stops', ['name' => 'Neumarkt'])->assertUnauthorized();
    $this->deleteJson('/api/travel/favourite-stops', ['name' => 'Neumarkt'])->assertUnauthorized();
});

test('one account never sees or removes another account\'s favourites', function () {
    $a = User::factory()->onboarded()->create();
    $b = User::factory()->onboarded()->create();

    $this->actingAs($a)->postJson('/api/travel/favourite-stops', ['name' => 'Neumarkt'])
        ->assertCreated()
        ->assertJsonPath('favourites.0.name', 'Neumarkt')
        ->assertJsonPath('favourites.0.lines', ['1', '3', '4', '7', '9', '16', '18']);

    $this->actingAs($b)->getJson('/api/travel/favourite-stops')->assertOk()->assertJsonPath('favourites', []);
    // B removing "Neumarkt" removes nothing of A's.
    $this->actingAs($b)->deleteJson('/api/travel/favourite-stops', ['name' => 'Neumarkt'])->assertOk()->assertJsonPath('favourites', []);
    expect(FavouriteStop::query()->where('user_id', $a->id)->pluck('name')->all())->toBe(['Neumarkt']);

    $this->actingAs($a)->deleteJson('/api/travel/favourite-stops', ['name' => 'Neumarkt'])->assertOk()->assertJsonPath('favourites', []);
    expect(FavouriteStop::query()->count())->toBe(0);
});

test('only a real KVB station can be starred, once, up to the limit', function () {
    $user = User::factory()->onboarded()->create();
    $this->actingAs($user);

    $this->postJson('/api/travel/favourite-stops', ['name' => 'Nowhere Platz'])->assertUnprocessable();
    $this->postJson('/api/travel/favourite-stops', ['name' => str_repeat('x', 121)])->assertUnprocessable();
    $this->postJson('/api/travel/favourite-stops', ['name' => 'Mülheim Wiener Platz'])->assertCreated();
    $this->postJson('/api/travel/favourite-stops', ['name' => 'Mülheim Wiener Platz'])->assertOk()->assertJsonCount(1, 'favourites');

    foreach (range(1, FavouriteStop::LIMIT - 1) as $i) {
        FavouriteStop::query()->create(['user_id' => $user->id, 'name' => "Old stop {$i}"]);
    }
    $this->postJson('/api/travel/favourite-stops', ['name' => 'Neumarkt'])->assertUnprocessable();
    expect(FavouriteStop::query()->where('user_id', $user->id)->count())->toBe(FavouriteStop::LIMIT);
});

test('the Travel page lists only the signed-in account\'s favourites', function () {
    $a = User::factory()->onboarded()->create();
    $b = User::factory()->onboarded()->create();
    FavouriteStop::query()->create(['user_id' => $a->id, 'name' => 'Neumarkt']);

    $this->actingAs($b)->get('/timetable')
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('timetable')->where('favouriteStops', []));
    $this->actingAs($a)->get('/timetable')
        ->assertInertia(fn ($page) => $page->where('favouriteStops.0.name', 'Neumarkt'));
});

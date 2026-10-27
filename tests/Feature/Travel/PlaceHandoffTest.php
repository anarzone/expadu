<?php

use App\Models\Spot;
use App\Models\User;

// A place's "Get there" hands it to Plan a trip by its id, never by
// coordinates: the server finds the place, and an unknown, closed or
// access-restricted one gives the page nothing.

function placeHandoffUrl(int|string $id): string
{
    return '/travel?'.http_build_query(['view' => 'journey', 'place' => $id]);
}

test('Plan a trip finds an open, public place by its id, and nothing else', function () {
    $user = User::factory()->onboarded()->create();
    $place = Spot::factory()->create(['name' => 'Café Riese', 'veedel' => 'Ehrenfeld', 'lat' => 50.9497, 'lng' => 6.9187, 'is_active' => true]);

    $this->actingAs($user)->get(placeHandoffUrl($place->id))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('handoffPlace.id', $place->id)
            ->where('handoffPlace.name', 'Café Riese')
            ->where('handoffPlace.area', 'Ehrenfeld')
            ->where('handoffPlace.lat', 50.9497)
            ->where('handoffPlace.lng', 6.9187));

    // An alias opens as its place.
    $alias = Spot::factory()->create(['name' => 'Cafe Riese', 'lat' => 50.9497, 'lng' => 6.9187, 'is_active' => true]);
    $alias->forceFill(['canonical_spot_id' => $place->id])->save();
    $this->actingAs($user)->get(placeHandoffUrl($alias->id))
        ->assertInertia(fn ($page) => $page->where('handoffPlace.id', $place->id));

    // A closed place, a private one, an unknown id or anything but an id: nothing.
    $closed = Spot::factory()->create(['lat' => 50.94, 'lng' => 6.93, 'is_active' => false]);
    $private = Spot::factory()->create(['lat' => 50.94, 'lng' => 6.93, 'is_active' => true, 'tags' => ['access' => 'private']]);
    foreach ([$closed->id, $private->id, 999999, '1 OR 1=1', '50.94,6.93'] as $id) {
        $this->actingAs($user)->get(placeHandoffUrl($id))
            ->assertInertia(fn ($page) => $page->where('handoffPlace', null));
    }
});

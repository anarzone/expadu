<?php

use App\Models\Spot;

test('spots:sanitize drops dense attraction clusters but keeps isolated landmarks', function () {
    // A tight knot of six attractions within ~150m — a zoo / wildpark site.
    foreach (range(1, 6) as $i) {
        Spot::factory()->create([
            'name' => "Animal {$i}",
            'category' => 'attraction',
            'lat' => 50.9600 + $i * 0.0002,
            'lng' => 6.9700,
        ]);
    }
    // An isolated real landmark ~2km away, and a non-attraction in the knot.
    Spot::factory()->create(['name' => 'Kölner Dom', 'category' => 'attraction', 'lat' => 50.9413, 'lng' => 6.9583]);
    Spot::factory()->create(['name' => 'Stadtpark', 'category' => 'park', 'lat' => 50.9601, 'lng' => 6.9700]);

    $this->artisan('spots:sanitize')->assertSuccessful();

    $attractions = Spot::where('category', 'attraction')->pluck('name');
    expect($attractions)->toContain('Kölner Dom')           // the lone landmark survives
        ->not->toContain('Animal 1');                       // the clustered sub-features are gone
    expect(Spot::where('category', 'attraction')->count())->toBe(1);
    expect(Spot::where('name', 'Stadtpark')->exists())->toBeTrue(); // non-attractions untouched
});

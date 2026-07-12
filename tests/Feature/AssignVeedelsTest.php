<?php

use App\Models\Spot;
use Illuminate\Support\Facades\DB;

// The legacy centroid fallback remains available only while no polygon dataset
// has been imported at all.
function seedTwoVeedels(): void
{
    DB::table('veedels')->insert([
        ['name' => 'Merkenich', 'bezirk' => 'Chorweiler', 'centroid_lat' => 51.0396893, 'centroid_lng' => 6.9301582],
        ['name' => 'Ehrenfeld', 'bezirk' => 'Ehrenfeld', 'centroid_lat' => 50.9503304, 'centroid_lng' => 6.9112611],
    ]);
}

it('assigns each spot to its nearest Veedel centroid', function () {
    seedTwoVeedels();
    $north = Spot::factory()->create(['category' => 'playground', 'lat' => 51.039, 'lng' => 6.930, 'veedel' => null]);
    $south = Spot::factory()->create(['category' => 'pitch', 'lat' => 50.950, 'lng' => 6.911, 'veedel' => null]);

    $this->artisan('spots:assign-veedel')->assertExitCode(0);

    expect($north->fresh()->veedel)->toBe('Merkenich');
    expect($south->fresh()->veedel)->toBe('Ehrenfeld');
});

it('only touches null-veedel spots unless --force is given', function () {
    seedTwoVeedels();
    // Sits in Merkenich but already carries a hand-set Veedel.
    $spot = Spot::factory()->create(['category' => 'park', 'lat' => 51.039, 'lng' => 6.930, 'veedel' => 'KeepMe']);

    $this->artisan('spots:assign-veedel')->assertExitCode(0);
    expect($spot->fresh()->veedel)->toBe('KeepMe');

    $this->artisan('spots:assign-veedel --force')->assertExitCode(0);
    expect($spot->fresh()->veedel)->toBe('Merkenich');
});

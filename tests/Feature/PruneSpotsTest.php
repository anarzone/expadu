<?php

use App\Models\Spot;

it('dry-runs by default without deleting', function () {
    Spot::factory()->count(3)->create(['category' => 'restaurant', 'lat' => 50.9, 'lng' => 6.9]);
    Spot::factory()->count(2)->create(['category' => 'park', 'lat' => 50.9, 'lng' => 6.9]);

    $this->artisan('spots:prune', ['--category' => ['restaurant']])
        ->expectsOutputToContain('would delete 3')
        ->assertExitCode(0);

    expect(Spot::where('category', 'restaurant')->count())->toBe(3);
});

it('deletes the given categories with --force and leaves others', function () {
    Spot::factory()->count(3)->create(['category' => 'restaurant', 'lat' => 50.9, 'lng' => 6.9]);
    Spot::factory()->count(2)->create(['category' => 'bar', 'lat' => 50.9, 'lng' => 6.9]);
    Spot::factory()->count(2)->create(['category' => 'park', 'lat' => 50.9, 'lng' => 6.9]);

    $this->artisan('spots:prune', ['--category' => ['restaurant', 'bar'], '--force' => true])
        ->assertExitCode(0);

    expect(Spot::whereIn('category', ['restaurant', 'bar'])->count())->toBe(0);
    expect(Spot::where('category', 'park')->count())->toBe(2);
});

it('refuses to run without a category', function () {
    $this->artisan('spots:prune')->assertExitCode(1);
});

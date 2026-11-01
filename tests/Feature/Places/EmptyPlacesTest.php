<?php

use App\Models\UserPlace;

test('only untouched empty places are removed, and a dry run removes nothing', function () {
    $empty = UserPlace::factory()->create(['category' => 'home', 'address' => null, 'lat' => null, 'lng' => null]);
    $typed = UserPlace::factory()->create(['category' => 'home', 'address' => 'Venloer Str. 12', 'lat' => null, 'lng' => null]);
    $pinned = UserPlace::factory()->create(['category' => 'work', 'address' => null, 'lat' => 50.9, 'lng' => 6.9]);
    $edited = UserPlace::factory()->create(['category' => 'work', 'address' => null, 'lat' => null, 'lng' => null]);
    $edited->forceFill(['updated_at' => $edited->created_at->addMinute()])->save();

    $this->artisan('places:remove-empty', ['--dry-run' => true])->expectsOutputToContain('Would delete 1')->assertSuccessful();
    expect(UserPlace::count())->toBe(4);

    $this->artisan('places:remove-empty')->assertSuccessful();
    expect(UserPlace::pluck('id')->sort()->values()->all())->toBe(collect([$typed->id, $pinned->id, $edited->id])->sort()->values()->all())
        ->and(UserPlace::find($empty->id))->toBeNull();
});

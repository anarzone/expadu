<?php

use App\Enums\LocationSource;
use App\Models\FavouriteStop;
use App\Models\User;
use App\Models\UserPlace;
use App\Personalisation\PersonalisationWording;
use App\Personalisation\SignalItemType;
use App\Personalisation\SignalRecorder;
use App\Services\LocationContext;
use App\Transit\NearbyStation;
use Illuminate\Support\Facades\DB;

/*
 * Today's Check departures without a picked stop: where the person is, else
 * their first favourite stop, else near Home, else near their Veedel. With
 * none of these there is no station and no deferred read for one.
 */

function todayStationFor(User $user): mixed
{
    $station = null;
    test()->actingAs($user)->get(route('dashboard'))
        ->assertOk()
        ->assertInertia(function ($page) use (&$station) {
            $page->loadDeferredProps('station', function ($reload) use (&$station) {
                $station = $reload->toArray()['props']['station'] ?? null;
            });
        });

    return $station;
}

test('with nothing to anchor it, Today sends no station and defers none', function () {
    $user = User::factory()->onboarded()->create(['veedel' => null]);
    $this->mock(NearbyStation::class, fn ($mock) => $mock->shouldNotReceive('closestTo', 'named'));

    $page = $this->actingAs($user)->get(route('dashboard'))->assertOk()->viewData('page');

    expect($page['props'])->toHaveKey('station')
        ->and($page['props']['station'])->toBeNull()
        ->and($page['deferredProps'] ?? [])->not->toHaveKey('station');
});

test('a favourite stop comes before Home', function () {
    $user = User::factory()->onboarded()->create(['veedel' => null]);
    FavouriteStop::create(['user_id' => $user->id, 'name' => 'Neumarkt']);
    UserPlace::factory()->for($user)->create(['category' => 'home']);
    $this->mock(NearbyStation::class, fn ($mock) => $mock->shouldReceive('named')->once()->with('Neumarkt')->andReturn(['name' => 'Neumarkt']));

    expect(todayStationFor($user))->toBe(['name' => 'Neumarkt']);
});

test('without a favourite, the station is near Home, with no walk from it', function () {
    $user = User::factory()->onboarded()->create(['veedel' => 'Nippes']);
    UserPlace::factory()->for($user)->create(['category' => 'home', 'lat' => 50.96, 'lng' => 6.95]);
    $this->mock(NearbyStation::class, fn ($mock) => $mock->shouldReceive('closestTo')->once()
        ->withArgs(fn (LocationContext $at) => $at->source === LocationSource::Place && $at->label === 'Home'
            && $at->lat === 50.96 && $at->lng === 6.95 && ! $at->isFresh())
        ->andReturn(['name' => 'Florastr.']));

    expect(todayStationFor($user))->toBe(['name' => 'Florastr.']);
});

test('last, the station is near the Veedel', function () {
    DB::table('veedels')->insert(['name' => 'Nippes', 'bezirk' => 'Nippes', 'centroid_lat' => 50.965, 'centroid_lng' => 6.955]);
    $user = User::factory()->onboarded()->create(['veedel' => 'Nippes']);
    $this->mock(NearbyStation::class, fn ($mock) => $mock->shouldReceive('closestTo')->once()
        ->withArgs(fn (LocationContext $at) => $at->source === LocationSource::Area && $at->label === 'Nippes')
        ->andReturn(['name' => 'Nippes Bf']));

    expect(todayStationFor($user))->toBe(['name' => 'Nippes Bf']);
});

test('the stops they pick most are quick picks only under the current personalisation wording', function () {
    $user = User::factory()->onboarded()->create(['veedel' => null]);
    $learn = function () use ($user): void {
        foreach (['Neumarkt', 'Neumarkt', 'Ebertplatz', 'Neumarkt', 'Ebertplatz'] as $stop) {
            app(SignalRecorder::class)->transit($user, SignalItemType::Stop, $stop);
        }
    };
    $picks = fn () => $this->actingAs($user)->get(route('dashboard'))->assertOk()->viewData('page')['props']['learnedStops'];

    $learn();
    expect($picks())->toBe([]);

    // On under the first wording (places and events only): still none.
    $user->userSetting()->updateOrCreate(['user_id' => $user->id], ['settings' => ['personalisation' => true, PersonalisationWording::SETTING => 1]]);
    $learn();
    expect($picks())->toBe([]);

    $user->userSetting()->update(['settings' => ['personalisation' => true, PersonalisationWording::SETTING => PersonalisationWording::CURRENT]]);
    $user->load('userSetting');
    $learn();
    expect($picks())->toBe(['Neumarkt', 'Ebertplatz']);
});

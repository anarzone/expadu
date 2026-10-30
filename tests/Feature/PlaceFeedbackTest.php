<?php

use App\Composer\IntentWeights;
use App\Enums\SpotFeedbackState;
use App\Models\Spot;
use App\Models\SpotFeedback;
use App\Models\User;
use App\Models\UserEvent;
use App\Places\OpenNearby;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

// Start each test from an empty cache, so freshly seeded places show.
beforeEach(fn () => Cache::flush());

function feedbackUser(): User
{
    return User::factory()->onboarded()->create([
        'veedel' => 'Ehrenfeld',
        'situation' => 'student',
        'is_eu' => true,
    ]);
}

/** The parks OpenNearby lists as open there on Wednesday 30 September at 14:30. */
function openParkNames(float $lat, float $lng): Collection
{
    return app(OpenNearby::class)
        ->around($lat, $lng, CarbonImmutable::parse('2026-09-30 14:30', 'Europe/Berlin'), ['park'], limit: 20)
        ->pluck('name');
}

test('more like this records a signal and keeps the place in discovery', function () {
    $user = feedbackUser();
    $spot = Spot::factory()->create([
        'name' => 'Stadtgarten', 'category' => 'park', 'veedel' => 'Ehrenfeld', 'lat' => 50.95, 'lng' => 6.92,
        'source' => 'osm', 'source_id' => 'way/4201', 'tags' => ['opening_hours' => 'Mo-Su 06:00-22:00'], 'public_access_allowed' => true,
    ]);

    $this->actingAs($user)
        ->postJson("/api/places/{$spot->id}/feedback", ['action' => 'more_like_this'])
        ->assertOk()
        ->assertJson(['state' => 'more_like_this']);

    expect(SpotFeedback::where('user_id', $user->id)->where('spot_id', $spot->id)->value('state'))->toBe(SpotFeedbackState::MoreLikeThis);
    $this->assertDatabaseHas('user_events', ['user_id' => $user->id, 'event_type' => 'spot_more_like_this']);

    // Forward-looking interest — the place still surfaces in what's open nearby.
    expect(openParkNames(50.95, 6.92))->toContain('Stadtgarten');
});

test('not interested hides the place from the places list', function () {
    $user = feedbackUser();
    $spot = Spot::factory()->create(['name' => 'Boring Lot', 'category' => 'park', 'veedel' => 'Ehrenfeld', 'lat' => 50.95, 'lng' => 6.92]);

    $this->actingAs($user)
        ->postJson("/api/places/{$spot->id}/feedback", ['action' => 'not_interested'])
        ->assertOk();

    $this->assertDatabaseHas('user_events', ['user_id' => $user->id, 'event_type' => 'spot_not_interested']);

    $names = collect($this->actingAs($user)->getJson('/api/places')->json('data'))->pluck('name');
    expect($names)->not->toContain('Boring Lot');
});

test('been with a rating records taste; been alone is silent', function () {
    $user = feedbackUser();
    $liked = Spot::factory()->create(['name' => 'Loved Park', 'category' => 'park', 'veedel' => 'Ehrenfeld', 'lat' => 50.95, 'lng' => 6.92]);
    $known = Spot::factory()->create(['name' => 'Known Park', 'category' => 'park', 'veedel' => 'Ehrenfeld', 'lat' => 50.95, 'lng' => 6.93]);

    $this->actingAs($user)->postJson("/api/places/{$liked->id}/feedback", ['action' => 'been', 'rating' => 'up'])
        ->assertOk()->assertJson(['state' => null, 'been' => true, 'been_rating' => 'up']);
    $this->actingAs($user)->postJson("/api/places/{$known->id}/feedback", ['action' => 'been'])
        ->assertOk()->assertJson(['state' => null, 'been' => true, 'been_rating' => null]);

    // Rated visit carries a taste signal; the unrated one records none.
    expect(UserEvent::where('user_id', $user->id)->where('event_type', 'spot_been_liked')->count())->toBe(1);
    expect(UserEvent::where('user_id', $user->id)->where('event_type', 'spot_been_disliked')->exists())->toBeFalse();
});

test('clear removes the standing feedback', function () {
    $user = feedbackUser();
    $spot = Spot::factory()->create(['category' => 'park', 'veedel' => 'Ehrenfeld', 'lat' => 50.95, 'lng' => 6.92]);
    SpotFeedback::factory()->create(['user_id' => $user->id, 'spot_id' => $spot->id]);

    $this->actingAs($user)
        ->postJson("/api/places/{$spot->id}/feedback", ['action' => 'clear'])
        ->assertOk()->assertJson(['state' => null]);

    expect(SpotFeedback::where('user_id', $user->id)->where('spot_id', $spot->id)->exists())->toBeFalse();
});

test('changing feedback updates the same row instead of duplicating', function () {
    $user = feedbackUser();
    $spot = Spot::factory()->create(['category' => 'park', 'veedel' => 'Ehrenfeld', 'lat' => 50.95, 'lng' => 6.92]);

    $this->actingAs($user)->postJson("/api/places/{$spot->id}/feedback", ['action' => 'saved'])->assertOk();
    $this->actingAs($user)->postJson("/api/places/{$spot->id}/feedback", ['action' => 'more_like_this'])->assertOk();

    expect(SpotFeedback::where('user_id', $user->id)->where('spot_id', $spot->id)->count())->toBe(1)
        ->and(SpotFeedback::where('user_id', $user->id)->where('spot_id', $spot->id)->value('state'))->toBe(SpotFeedbackState::MoreLikeThis);
});

test('saved and been there are independent toggles', function () {
    $user = feedbackUser();
    $spot = Spot::factory()->create(['category' => 'cafe', 'veedel' => 'Ehrenfeld', 'lat' => 50.95, 'lng' => 6.92]);
    $row = fn () => SpotFeedback::where('user_id', $user->id)->where('spot_id', $spot->id)->first();

    $this->actingAs($user)->postJson("/api/places/{$spot->id}/feedback", ['action' => 'saved'])->assertOk();
    $this->actingAs($user)->putJson("/api/places/{$spot->id}/been", ['rating' => 'up'])
        ->assertOk()->assertExactJson(['state' => 'saved', 'rating' => null, 'been' => true, 'been_rating' => 'up']);
    expect($row()->state)->toBe(SpotFeedbackState::Saved)->and($row()->been())->toBeTrue();

    // Un-saving keeps Been there.
    $this->actingAs($user)->postJson("/api/places/{$spot->id}/feedback", ['action' => 'clear'])
        ->assertOk()->assertJson(['state' => null, 'been' => true, 'been_rating' => 'up']);
    // Saving again keeps it too; clearing Been keeps the save.
    $this->actingAs($user)->postJson("/api/places/{$spot->id}/feedback", ['action' => 'saved'])->assertOk()->assertJson(['been' => true]);
    $this->actingAs($user)->deleteJson("/api/places/{$spot->id}/been")
        ->assertOk()->assertJson(['state' => 'saved', 'been' => false, 'been_rating' => null]);
    expect($row()->state)->toBe(SpotFeedbackState::Saved)->and($row()->been())->toBeFalse();

    // With neither left, the row goes.
    $this->actingAs($user)->postJson("/api/places/{$spot->id}/feedback", ['action' => 'clear'])->assertOk();
    expect($row())->toBeNull();
});

test('been there keeps its first date when the rating changes', function () {
    $user = feedbackUser();
    $spot = Spot::factory()->create(['category' => 'cafe', 'veedel' => 'Ehrenfeld', 'lat' => 50.95, 'lng' => 6.92]);

    $this->travelTo(now()->subDays(3));
    $this->actingAs($user)->putJson("/api/places/{$spot->id}/been")->assertOk();
    $this->travelBack();
    $first = SpotFeedback::where('user_id', $user->id)->value('been_at');
    $this->actingAs($user)->putJson("/api/places/{$spot->id}/been", ['rating' => 'down'])->assertOk()->assertJson(['been_rating' => 'down']);

    expect(SpotFeedback::where('user_id', $user->id)->value('been_at')->equalTo($first))->toBeTrue();
});

test('deleting the account erases saved and been there', function () {
    $user = feedbackUser();
    $spot = Spot::factory()->create(['category' => 'cafe', 'veedel' => 'Ehrenfeld', 'lat' => 50.95, 'lng' => 6.92]);
    $this->actingAs($user)->postJson("/api/places/{$spot->id}/feedback", ['action' => 'saved'])->assertOk();
    $this->actingAs($user)->putJson("/api/places/{$spot->id}/been")->assertOk();

    $user->delete();

    expect(SpotFeedback::where('user_id', $user->id)->exists())->toBeFalse();
});

test('the migration moves existing been rows to been_at', function () {
    $user = feedbackUser();
    $spot = Spot::factory()->create(['category' => 'cafe', 'veedel' => 'Ehrenfeld', 'lat' => 50.95, 'lng' => 6.92]);
    $migration = require database_path('migrations/2026_10_23_210000_separate_been_from_spot_feedback_state.php');
    $migration->down();
    DB::table('spot_feedback')->insert(['user_id' => $user->id, 'spot_id' => $spot->id, 'state' => 'been', 'rating' => 'up', 'created_at' => now(), 'updated_at' => '2026-09-01 10:00:00+00']);

    $migration->up();

    $row = SpotFeedback::where('user_id', $user->id)->first();
    expect($row->state)->toBeNull()->and($row->rating)->toBeNull()->and($row->been_rating)->toBe('up')
        ->and($row->been_at->toDateString())->toBe('2026-09-01');
});

test('the feedback action is validated', function () {
    $user = feedbackUser();
    $spot = Spot::factory()->create(['category' => 'park', 'veedel' => 'Ehrenfeld', 'lat' => 50.95, 'lng' => 6.92]);

    $this->actingAs($user)
        ->postJson("/api/places/{$spot->id}/feedback", ['action' => 'love_it'])
        ->assertStatus(422);
});

test('more-like-this feeds IntentWeights for that category and veedel, with the opt-in', function () {
    $user = feedbackUser();
    $user->userSetting()->updateOrCreate([], ['settings' => ['personalisation' => true], 'sixteen_or_older' => true]);
    $user = $user->fresh();
    $spot = Spot::factory()->create(['category' => 'museum', 'veedel' => 'Ehrenfeld', 'lat' => 50.95, 'lng' => 6.92]);

    $this->actingAs($user)->postJson("/api/places/{$spot->id}/feedback", ['action' => 'more_like_this'])->assertOk();

    $weights = app(IntentWeights::class)->for($user);
    expect($weights)->toHaveKey('museum|Ehrenfeld')
        ->and($weights['museum|Ehrenfeld'])->toBeGreaterThan(0.0);
});

test('without the personalisation opt-in, feedback leaves the ranking weights alone', function () {
    $user = feedbackUser();
    $spot = Spot::factory()->create(['category' => 'museum', 'veedel' => 'Ehrenfeld', 'lat' => 50.95, 'lng' => 6.92]);

    $this->actingAs($user)->postJson("/api/places/{$spot->id}/feedback", ['action' => 'more_like_this'])->assertOk();

    expect(app(IntentWeights::class)->for($user->fresh()))->toBe([])
        ->and(app(IntentWeights::class)->preferenceVector($user->fresh()))->toBe(['category_intent' => [], 'category_dislikes' => []]);
});

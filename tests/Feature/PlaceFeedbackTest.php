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
        ->assertOk()->assertJson(['state' => 'been', 'rating' => 'up']);
    $this->actingAs($user)->postJson("/api/places/{$known->id}/feedback", ['action' => 'been'])
        ->assertOk()->assertJson(['state' => 'been', 'rating' => null]);

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
    $this->actingAs($user)->postJson("/api/places/{$spot->id}/feedback", ['action' => 'been', 'rating' => 'up'])->assertOk();

    expect(SpotFeedback::where('user_id', $user->id)->where('spot_id', $spot->id)->count())->toBe(1)
        ->and(SpotFeedback::where('user_id', $user->id)->where('spot_id', $spot->id)->value('state'))->toBe(SpotFeedbackState::Been);
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
    $user->userSetting()->updateOrCreate([], ['settings' => ['personalisation' => true]]);
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

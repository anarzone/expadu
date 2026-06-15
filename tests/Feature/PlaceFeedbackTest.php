<?php

use App\Composer\IntentWeights;
use App\Enums\SpotFeedbackState;
use App\Home\HomeFeed;
use App\Models\Spot;
use App\Models\SpotFeedback;
use App\Models\User;
use App\Models\UserEvent;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

// The discovery scan is cached globally; flush so freshly-seeded spots show.
beforeEach(fn () => Cache::flush());

function feedbackUser(): User
{
    return User::factory()->onboarded()->create([
        'veedel' => 'Ehrenfeld',
        'situation' => 'student',
        'is_eu' => true,
    ]);
}

function railSpotNames(User $user): Collection
{
    return collect(app(HomeFeed::class)->rails($user))
        ->flatMap(fn (array $rail) => collect($rail['cards'])->pluck('name'));
}

test('more like this records a signal and keeps the place in discovery', function () {
    $user = feedbackUser();
    $spot = Spot::factory()->create(['name' => 'Stadtgarten', 'category' => 'park', 'veedel' => 'Ehrenfeld', 'lat' => 50.95, 'lng' => 6.92]);

    $this->actingAs($user)
        ->postJson("/api/places/{$spot->id}/feedback", ['action' => 'more_like_this'])
        ->assertOk()
        ->assertJson(['state' => 'more_like_this']);

    expect(SpotFeedback::where('user_id', $user->id)->where('spot_id', $spot->id)->value('state'))->toBe(SpotFeedbackState::MoreLikeThis);
    $this->assertDatabaseHas('user_events', ['user_id' => $user->id, 'event_type' => 'spot_more_like_this']);

    // Forward-looking interest — the place still surfaces in discovery.
    expect(railSpotNames($user))->toContain('Stadtgarten');
});

test('not interested hides the place from discovery and the places list', function () {
    $user = feedbackUser();
    $spot = Spot::factory()->create(['name' => 'Boring Lot', 'category' => 'park', 'veedel' => 'Ehrenfeld', 'lat' => 50.95, 'lng' => 6.92]);

    $this->actingAs($user)
        ->postJson("/api/places/{$spot->id}/feedback", ['action' => 'not_interested'])
        ->assertOk();

    $this->assertDatabaseHas('user_events', ['user_id' => $user->id, 'event_type' => 'spot_not_interested']);
    expect(railSpotNames($user))->not->toContain('Boring Lot');

    $names = collect($this->actingAs($user)->getJson('/api/places')->json('data'))->pluck('name');
    expect($names)->not->toContain('Boring Lot');
});

test('been with a rating records taste and leaves discovery; been alone is silent but still leaves', function () {
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

    // Both "been" places drop out of discovery — you already know them.
    expect(railSpotNames($user))->not->toContain('Loved Park')->not->toContain('Known Park');
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

test('more-like-this feeds IntentWeights for that category and veedel', function () {
    $user = feedbackUser();
    $spot = Spot::factory()->create(['category' => 'museum', 'veedel' => 'Ehrenfeld', 'lat' => 50.95, 'lng' => 6.92]);

    $this->actingAs($user)->postJson("/api/places/{$spot->id}/feedback", ['action' => 'more_like_this'])->assertOk();

    $weights = app(IntentWeights::class)->for($user);
    expect($weights)->toHaveKey('museum|Ehrenfeld')
        ->and($weights['museum|Ehrenfeld'])->toBeGreaterThan(0.0);
});

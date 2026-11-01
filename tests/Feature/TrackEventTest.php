<?php

use App\Composer\IntentWeights;
use App\Http\Controllers\Api\TrackEventController;
use App\Models\User;
use App\Models\UserEvent;
use Illuminate\Support\Facades\File;

test('tracking endpoint records a user event', function () {
    $user = User::factory()->onboarded()->create();
    $this->actingAs($user);

    $response = $this->postJson('/api/track', [
        'event_type' => 'page_viewed',
        'payload' => ['page' => '/today'],
    ]);

    $response->assertNoContent();

    $this->assertDatabaseHas('user_events', [
        'user_id' => $user->id,
        'event_type' => 'page_viewed',
    ]);

    $event = UserEvent::where('user_id', $user->id)->first();
    expect($event->payload)->toBe(['page' => '/today']);
});

test('tracking endpoint works without payload', function () {
    $user = User::factory()->onboarded()->create();
    $this->actingAs($user);

    $response = $this->postJson('/api/track', [
        'event_type' => 'page_viewed',
    ]);

    $response->assertNoContent();

    $this->assertDatabaseHas('user_events', [
        'user_id' => $user->id,
        'event_type' => 'page_viewed',
    ]);
});

test('tracking endpoint validates event_type is required', function () {
    $user = User::factory()->onboarded()->create();
    $this->actingAs($user);

    $response = $this->postJson('/api/track', []);

    $response->assertUnprocessable();
    $response->assertJsonValidationErrors('event_type');
});

test('tracking endpoint validates event_type max length', function () {
    $user = User::factory()->onboarded()->create();
    $this->actingAs($user);

    $response = $this->postJson('/api/track', [
        'event_type' => str_repeat('a', 101),
    ]);

    $response->assertUnprocessable();
    $response->assertJsonValidationErrors('event_type');
});

test('tracking endpoint validates payload must be array', function () {
    $user = User::factory()->onboarded()->create();
    $this->actingAs($user);

    $response = $this->postJson('/api/track', [
        'event_type' => 'page_viewed',
        'payload' => 'not_an_array',
    ]);

    $response->assertUnprocessable();
    $response->assertJsonValidationErrors('payload');
});

test('tracking endpoint requires authentication', function () {
    $response = $this->postJson('/api/track', [
        'event_type' => 'page_viewed',
    ]);

    $response->assertUnauthorized();
});

test('clients cannot write taste signals or unknown event types', function (string $eventType) {
    $user = User::factory()->onboarded()->create();

    $this->actingAs($user)->postJson('/api/track', [
        'event_type' => $eventType,
        'payload' => ['category' => 'cafe', 'veedel' => 'Ehrenfeld'],
    ])->assertUnprocessable()->assertJsonValidationErrors('event_type');

    expect(UserEvent::query()->where('user_id', $user->id)->exists())->toBeFalse()
        ->and(app(IntentWeights::class)->for($user->fresh()))->toBe([]);
})->with(fn () => [...array_keys(intentSignalWeights()), 'post_trip_thumbs_up', 'test_event']);

test('every taste signal has a server-side emitter and none is client-writable', function () {
    $sources = collect(File::allFiles(app_path()))
        ->reject(fn ($file) => $file->getRealPath() === (new ReflectionClass(IntentWeights::class))->getFileName())
        ->map(fn ($file) => $file->getContents())
        ->implode("\n");

    foreach (array_keys(intentSignalWeights()) as $signal) {
        expect(str_contains($sources, "'{$signal}'"))->toBeTrue("No emitter writes the '{$signal}' signal")
            ->and(TrackEventController::CLIENT_EVENT_TYPES)->not->toContain($signal);
    }
});

/** @return array<string, float> */
function intentSignalWeights(): array
{
    return (new ReflectionClassConstant(IntentWeights::class, 'SIGNAL_WEIGHTS'))->getValue();
}

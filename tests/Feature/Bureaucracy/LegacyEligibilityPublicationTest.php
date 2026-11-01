<?php

use App\ContextEngine\ActionBus;
use App\ContextEngine\ScoredAction;
use App\Models\Alert;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Redis;
use Inertia\Testing\AssertableInertia as Assert;

test('previously stored legacy eligibility alerts are hidden from page and shared counts', function () {
    $user = User::factory()->onboarded()->create();
    Alert::factory()->create(['user_id' => $user->id, 'subtype' => 'permanent_residency', 'read_at' => null, 'dismissed_at' => null]);
    $ordinary = Alert::factory()->create(['user_id' => $user->id, 'subtype' => 'transit_delay', 'read_at' => null, 'dismissed_at' => null]);

    $this->actingAs($user)->get('/alerts')->assertOk()
        ->assertInertia(fn (Assert $page) => $page->has('alerts', 1)->where('alerts.0.id', $ordinary->id)
            ->where('unreadCount', 1)->where('unreadAlertCount', 1));

    expect(Alert::where('user_id', $user->id)->count())->toBe(2);
});

test('previously cached legacy eligibility actions cannot reappear in Today', function () {
    $user = User::factory()->onboarded()->create();
    $action = new ScoredAction(
        type: 'permanent_residency_eligible', actionKey: 'legacy:'.$user->id,
        score: 90, severity: 'success', validUntil: CarbonImmutable::now()->addDay(),
        deliverChannels: [ScoredAction::CHANNEL_DASHBOARD],
        payload: ['threshold_months' => 36], createdAt: CarbonImmutable::now(),
    );
    app(ActionBus::class)->clear($user->id);
    Redis::zadd('pending_actions:'.$user->id, 90, json_encode($action, JSON_THROW_ON_ERROR));

    expect(app(ActionBus::class)->topK($user->id, 20))->toBeEmpty();
});

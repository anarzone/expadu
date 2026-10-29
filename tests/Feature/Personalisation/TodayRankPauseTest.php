<?php

use App\Jobs\RankToday;
use App\Models\User;
use App\Personalisation\TodayRanker;
use App\Personalisation\TodayRankPause;
use App\Personalisation\TodayTaste;
use App\Privacy\ProcessingOptIns;
use App\Privacy\ProcessingPurpose;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia;

/*
 * Today's AI order after its notice changes (owner, 29 Oct 2026, the consent canvas's boards
 * 9–11): each standing yes is bound to its own notice, so another feature's change leaves it on;
 * a change to its own pauses it, Settings and Today say what changed, and it's back only with a
 * yes to the notice they were shown. Not now keeps it off Today for a week.
 */
beforeEach(function () {
    config()->set('services.llm', [
        'driver' => 'anthropic', 'base_url' => 'https://processor.example.test', 'model' => 'claude-haiku-5-5', 'key' => 'synthetic',
        'processor_name' => 'Anthropic PBC', 'processor_privacy_url' => 'https://processor.example.test/privacy', 'prompt_version' => 'test.1',
    ]);
    Http::fake();
    Queue::fake();
    $this->user = User::factory()->onboarded()->create();
    $this->actingAs($this->user)->putJson('/user-settings', ['settings' => ['personalisation' => true], 'sixteen_or_older' => true])->assertOk();
});

function rankOn(): bool
{
    return app(ProcessingOptIns::class)->active(test()->user->fresh(), ProcessingPurpose::TodayRank);
}

function waiting(): ?array
{
    return app(TodayRankPause::class)->for(test()->user->fresh());
}

test('🔒 a change to Composer’s notice leaves Today’s AI order on', function () {
    config()->set('bureaucracy_privacy.notice_version', 'composer-changed.test');

    expect(rankOn())->toBeTrue()
        ->and(waiting())->toBeNull()
        ->and(ProcessingPurpose::ComposerAssist->noticeVersion())->toBe('composer-changed.test');
});

test('🔒 a change to its own notice pauses it, says what changed, and only a yes to that notice brings it back', function () {
    $before = ProcessingPurpose::TodayRank->notice();
    // Today's order, made this morning under their yes.
    Cache::put(TodayTaste::orderKey($this->user->id), ['events' => ['e2@1', 'e1@1'], 'places' => [], 'why' => [], 'at' => now()->toIso8601String()], now()->endOfDay());
    expect(app(TodayTaste::class)->order($this->user->fresh()))->not->toBeNull();
    config()->set('bureaucracy_privacy.audit_days', 20);

    $paused = waiting();
    expect(rankOn())->toBeFalse()
        ->and($paused['model_changed'])->toBeFalse()
        ->and($paused['changes'])->toHaveCount(1)
        ->and($paused['changes'][0])->toContain('20 days')
        ->and($paused['changes'])->not->toContain($before[0])
        // Today is in the usual order while it waits, even with this morning's order kept.
        ->and(app(TodayTaste::class)->order($this->user->fresh()))->toBeNull()
        // Nothing goes to the AI while it waits.
        ->and(app(TodayRanker::class)->rankFrom($this->user->fresh(), ['events' => [['id' => 'e1@1', 'name' => 'A', 'kind' => 'Music', 'area' => null, 'start' => '19:00'],
            ['id' => 'e2@1', 'name' => 'B', 'kind' => 'Music', 'area' => null, 'start' => '20:00']], 'places' => []], [], CarbonImmutable::now('Europe/Berlin')))->toBeNull();
    Http::assertNothingSent();

    // Each request reads the account afresh; the test reuses one instance.
    $this->actingAs($this->user->fresh())->get('/settings/privacy')->assertInertia(fn (AssertableInertia $page) => $page->where('todayRankPaused.changes.0', $paused['changes'][0]));
    $this->get('/today')->assertInertia(fn (AssertableInertia $page) => $page->where('aiOrderPaused.notice_version', $paused['notice_version']));

    // The notice before this one isn't agreed to by sending it back.
    $this->putJson('/today-rank', ['notice_version' => ProcessingPurpose::TodayRank->noticeVersion().'x', 'provider_version' => $paused['provider_version']])->assertUnprocessable();
    expect(rankOn())->toBeFalse();

    $this->putJson('/today-rank', ['notice_version' => $paused['notice_version'], 'provider_version' => $paused['provider_version']])->assertOk();
    expect(rankOn())->toBeTrue()->and(waiting())->toBeNull();
    // And Today is ordered again now, not tomorrow morning.
    Queue::assertPushed(RankToday::class, fn (RankToday $job) => $job->userId === $this->user->id);
});

test('a new model or instructions say so, with no line changed', function () {
    config()->set('services.llm.prompt_version', 'test.2');

    expect(waiting())->toMatchArray(['changes' => [], 'model_changed' => true]);
});

test('Not now keeps it off Today for a week; Settings still says so', function () {
    config()->set('bureaucracy_privacy.audit_days', 20);
    $this->actingAs($this->user->fresh())->postJson('/today-rank/later')->assertOk();

    expect(app(TodayRankPause::class)->onToday($this->user->fresh()))->toBeNull()
        ->and(waiting())->not->toBeNull();

    $this->travel(TodayRankPause::LATER_DAYS + 1)->days();
    expect(app(TodayRankPause::class)->onToday($this->user->fresh()))->not->toBeNull();
});

test('nothing waits with personalisation off, and there is nothing to agree to', function () {
    $this->putJson('/user-settings', ['settings' => ['personalisation' => false]])->assertOk();
    config()->set('bureaucracy_privacy.audit_days', 20);
    $disclosure = ProcessingPurpose::TodayRank->disclosure();

    expect(waiting())->toBeNull();
    $this->putJson('/today-rank', ['notice_version' => $disclosure['notice_version'], 'provider_version' => $disclosure['provider_version']])->assertStatus(409);
    expect(rankOn())->toBeFalse();
});

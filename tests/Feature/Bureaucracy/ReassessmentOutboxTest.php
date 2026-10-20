<?php

use App\Bureaucracy\Facts\RecordFactChange;
use App\Bureaucracy\People\EnsureAccountHolder;
use App\Bureaucracy\People\ManageDelegation;
use App\Bureaucracy\People\ManageDependents;
use App\Bureaucracy\People\PersonDataLifecycle;
use App\Bureaucracy\People\RecordRelationship;
use App\Bureaucracy\ReadModel\ProcessReassessmentOutbox;
use App\Bureaucracy\ReadModel\ReassessmentTargets;
use App\Bureaucracy\ReadModel\ReassessPerson;
use App\ContextEngine\ActionBus;
use App\ContextEngine\ScoredAction;
use App\Models\BureaucracyAccessGrant;
use App\Models\BureaucracyOutboxEvent;
use App\Models\BureaucracyProcess;
use App\Models\BureaucracyRelationship;
use App\Models\User;
use App\Models\UserTask;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Str;

beforeEach(function () {
    $this->travelTo('2026-09-08 10:00:00');
    config()->set('context_engine.push_via_bus', false);
    Notification::fake();
    $this->actor = User::factory()->onboarded()->create();
    $this->case = app(EnsureAccountHolder::class)->dossier($this->actor);
    app(RecordFactChange::class)->execute($this->actor, $this->case->person, 'current_residence_title', 'blue_card', null, 1);
    $this->event = BureaucracyOutboxEvent::query()->where('event_type', 'facts.changed')->sole();
});

test('fact changes durably reassess the person and linked applicants without creating work', function () {
    $applicant = User::factory()->onboarded()->create();
    $otherCase = app(EnsureAccountHolder::class)->dossier($applicant);
    $invite = app(ManageDelegation::class)->invite($applicant, $otherCase->person->workspace, $this->actor->email, ['view_facts']);
    app(ManageDelegation::class)->accept($this->actor, $invite['token'], ['view_facts']);
    app(RecordRelationship::class)->execute($applicant, $otherCase->person, $this->case->person, 'sponsor', null, $otherCase->person->fresh()->record_version);
    expect(app(ReassessmentTargets::class)->for($this->event))->toBe([$this->case->person_id, $otherCase->person_id]);
    $worker = app(ProcessReassessmentOutbox::class);
    expect($worker->process($this->event))->toBeTrue()->and($worker->process($this->event))->toBeFalse()
        ->and($this->event->fresh()->delivered_at)->not->toBeNull()
        ->and(BureaucracyProcess::query()->count())->toBe(0)->and(UserTask::query()->count())->toBe(0);
    expect($otherCase->facts()->count())->toBe(0);
    Notification::assertNothingSent();
});

test('failed background refreshes retry without retaining private error messages', function () {
    $effects = Mockery::mock(ReassessPerson::class);
    $effects->shouldReceive('execute')->once()->with($this->case->person_id)->andThrow(new RuntimeException('private rejected answer'));
    $worker = new ProcessReassessmentOutbox(app(ReassessmentTargets::class), $effects);
    expect($worker->process($this->event))->toBeFalse()->and($this->event->fresh()->delivered_at)->toBeNull()
        ->and($this->event->fresh()->last_error_type)->toBe(RuntimeException::class)
        ->and(json_encode($this->event->fresh()->getAttributes()))->not->toContain('private rejected answer');
    $this->travelTo($this->event->fresh()->available_at);
    expect(app(ProcessReassessmentOutbox::class)->process($this->event))->toBeTrue()
        ->and($this->event->fresh()->attempts)->toBe(2);
});

test('a leased event is not processed twice but an abandoned lease can be recovered', function () {
    $this->event->update(['claim_token' => (string) Str::uuid(), 'claimed_until' => now()->utc()->addMinutes(5)]);
    expect(app(ProcessReassessmentOutbox::class)->process($this->event))->toBeFalse();
    $this->travel(6)->minutes();
    expect(app(ProcessReassessmentOutbox::class)->process($this->event))->toBeTrue();
});

test('refresh removes obsolete bureaucracy actions but preserves other app features', function () {
    $bus = app(ActionBus::class);
    $weather = new ScoredAction('weather_alert', 'weather:test', 1, 'minor', CarbonImmutable::now()->addHour(), ['dashboard'], [], CarbonImmutable::now());
    $bus->insert($this->actor, $weather);
    Redis::zadd('pending_actions:'.$this->actor->id, 50, json_encode(['type' => 'bureaucracy_task', 'action_key' => 'legacy:private', 'payload' => ['private' => 'data']]));
    app(ReassessPerson::class)->execute($this->case->person_id);
    expect(array_map(fn ($action) => $action->actionKey, $bus->topK($this->actor->id)))->toBe(['weather:test']);
});

test('malformed action cache entries cannot crash a fresh plan or hide a valid action', function () {
    $bus = app(ActionBus::class);
    $bus->insert($this->actor, new ScoredAction('weather_alert', 'weather:test', 1, 'minor', CarbonImmutable::now()->addHour(), ['dashboard'], [], CarbonImmutable::now()));
    Redis::zadd('pending_actions:'.$this->actor->id, 50, json_encode(['type' => 'bureaucracy_task']));
    Redis::zadd('pending_actions:'.$this->actor->id, 49, json_encode(['type' => 'weather_alert', 'action_key' => [], 'score' => 'not a score']));
    expect(array_map(fn ($action) => $action->actionKey, $bus->topK($this->actor->id)))->toBe(['weather:test']);
});

test('rolled back changes produce no additional background reassessment', function () {
    $count = BureaucracyOutboxEvent::query()->count();
    try {
        DB::transaction(function () {
            app(RecordFactChange::class)->execute($this->actor, $this->case->person, 'german_level', 'b1', null, 2);
            throw new RuntimeException('synthetic rollback');
        });
    } catch (RuntimeException) {
    }
    expect(BureaucracyOutboxEvent::query()->count())->toBe($count);
});

test('the refresh command never consumes erasure or unsupported event types', function () {
    $other = BureaucracyOutboxEvent::query()->create(['event_type' => 'person.erased', 'aggregate_type' => 'person', 'aggregate_id' => 9999,
        'aggregate_version' => 1, 'dedupe_key' => 'erased:fixture', 'payload' => [], 'available_at' => now()->utc()]);
    $this->artisan('bureaucracy:process-reassessments')->assertSuccessful();
    expect($this->event->fresh()->delivered_at)->not->toBeNull()->and($other->fresh()->delivered_at)->toBeNull();
});

test('grant acceptance and revocation enqueue affected plans exactly once per change', function () {
    $applicant = User::factory()->onboarded()->create();
    $otherCase = app(EnsureAccountHolder::class)->dossier($applicant);
    $invite = app(ManageDelegation::class)->invite($applicant, $otherCase->person->workspace, $this->actor->email, ['view_facts']);
    app(ManageDelegation::class)->accept($this->actor, $invite['token'], ['view_facts']);
    $grant = BureaucracyAccessGrant::query()->where('person_id', $this->case->person_id)->sole();
    expect(BureaucracyOutboxEvent::query()->where('event_type', 'access.changed')->count())->toBe(1);
    app(RecordRelationship::class)->execute($applicant, $otherCase->person, $this->case->person, 'sponsor', null, $otherCase->person->fresh()->record_version);
    app(ManageDelegation::class)->revoke($this->actor, $grant);
    app(ManageDelegation::class)->revoke($this->actor, $grant);
    $events = BureaucracyOutboxEvent::query()->where('event_type', 'access.changed')->orderBy('id')->get();
    expect($events)->toHaveCount(2)->and(app(ReassessmentTargets::class)->for($events->last()))
        ->toBe([$this->case->person_id, $otherCase->person_id])->and($events->last()->payload)->toBe([]);
});

test('reviewed guardian access changes enqueue a refresh without granting notification rights', function () {
    config(['bureaucracy_family.guardian_policy_version' => 'synthetic-test-review']);
    $reviewer = User::factory()->onboarded()->create(['is_admin' => true]);
    $authority = app(ManageDependents::class)->request($this->actor, 'Synthetic child');
    app(ManageDependents::class)->review($reviewer, $authority, 'synthetic-test-review', 'synthetic-evidence', now()->addMonth());
    app(ManageDependents::class)->revoke($this->actor, $authority);
    app(ManageDependents::class)->revoke($this->actor, $authority);
    $events = BureaucracyOutboxEvent::query()->where('event_type', 'access.changed')->get();
    expect($events)->toHaveCount(2);
    foreach ($events as $event) {
        expect(app(ProcessReassessmentOutbox::class)->process($event))->toBeTrue();
    }
    Notification::assertNothingSent();
});

test('erasing a sponsor queues the applicant before removing dependency links', function (bool $deleteAccount) {
    $applicant = User::factory()->onboarded()->create();
    $otherCase = app(EnsureAccountHolder::class)->dossier($applicant);
    $invite = app(ManageDelegation::class)->invite($applicant, $otherCase->person->workspace, $this->actor->email, ['view_facts']);
    app(ManageDelegation::class)->accept($this->actor, $invite['token'], ['view_facts']);
    app(RecordRelationship::class)->execute($applicant, $otherCase->person, $this->case->person, 'sponsor', null, $otherCase->person->fresh()->record_version);
    if ($deleteAccount) {
        $this->actor->delete();
    } else {
        app(PersonDataLifecycle::class)->erase($this->actor, $this->case->person);
    }
    // The applicant's own relationship row is ended, not deleted from their history.
    expect(BureaucracyRelationship::query()->whereNull('revoked_at')->count())->toBe(0)
        ->and(BureaucracyRelationship::query()->where('person_id', $otherCase->person_id)->count())->toBe(1);
    $event = BureaucracyOutboxEvent::query()->where('event_type', 'person.reassessment_requested')->sole();
    expect($event->aggregate_id)->toBe($otherCase->person_id)->and($event->payload)->toBe([])
        ->and(app(ProcessReassessmentOutbox::class)->process($event))->toBeTrue()
        ->and($otherCase->fresh()->status)->toBe('active');
})->with([false, true]);

test('a fresh assessment schedules its next time boundary without waiting for another answer', function () {
    $this->actor->update(['city' => 'Köln']);
    $this->case->facts()->where('key', 'current_residence_title')->sole()->update(['reconfirm_at' => now()->addMinutes(5)]);
    $worker = app(ProcessReassessmentOutbox::class);
    expect($worker->process($this->event))->toBeTrue();
    $timer = BureaucracyOutboxEvent::query()->where('event_type', 'person.reassessment_requested')->sole();
    expect($timer->available_at->equalTo(now()->addMinutes(5)))->toBeTrue()->and($worker->process($timer))->toBeFalse();
    app(ReassessPerson::class)->execute($this->case->person_id);
    expect(BureaucracyOutboxEvent::query()->where('event_type', 'person.reassessment_requested')->count())->toBe(1);
    $this->travel(6)->minutes();
    expect($worker->process($timer))->toBeTrue();
    $next = BureaucracyOutboxEvent::query()->where('event_type', 'person.reassessment_requested')->whereNull('delivered_at')->sole();
    expect($next->available_at->equalTo(now()->addDay()->startOfDay()))->toBeTrue();
});

<?php

use App\Bureaucracy\Catalogue\CatalogueCompiler;
use App\Bureaucracy\Catalogue\CatalogueReleaseStore;
use App\Bureaucracy\People\EnsureAccountHolder;
use App\Bureaucracy\People\PersonDataLifecycle;
use App\Bureaucracy\Privacy\PurgePersonCopies;
use App\Bureaucracy\Processes\ReconcileProcesses;
use App\Bureaucracy\Processes\RecordProcessEvent;
use App\Composer\ActivePlanStore;
use App\Composer\AppointmentRepository;
use App\Composer\Constraints;
use App\Composer\PlanSlot;
use App\Models\Task;
use App\Models\User;
use App\Models\UserTask;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

beforeEach(function () {
    $this->travelTo('2026-09-08 10:00:00');
    $this->actor = User::factory()->onboarded()->create(['city' => 'Köln']);
    $this->case = app(EnsureAccountHolder::class)->dossier($this->actor);
    $this->task = Task::factory()->approvedFixture()->create(['key' => 'fixture.appointment', 'type' => 'task', 'applies_if' => [],
        'depends_on' => [], 'deadline_type' => 'none', 'links' => [], 'how_to_steps' => [], 'documents_required' => []])->fresh();
    $store = app(CatalogueReleaseStore::class);
    $release = $store->stage(app(CatalogueCompiler::class)->compile([$this->task], [$this->task->key => [
        'process_id' => 'fixture.appointment', 'topic' => 'residence', 'kind' => 'preparation', 'coverage' => 'partial',
    ]]));
    $store->activate($release->id, null);
    $this->process = app(ReconcileProcesses::class)->execute($this->actor, $this->case->person, 'de-nrw-cologne')[0];
    $this->constraints = Constraints::fromArray(['window_start' => now()->toIso8601String(), 'window_end' => now()->addHours(5)->toIso8601String()]);
    $this->appointment = ['appointment_id' => (string) Str::uuid(), 'starts_at' => now()->addHour()->toIso8601String(),
        'timezone' => 'Europe/Berlin', 'duration_minutes' => 25];
    $this->location = ['label' => 'My recorded meeting place', 'lat' => 50.95, 'lng' => 6.91];
});

test('legacy task appointments never become a second scheduling source', function () {
    UserTask::factory()->for($this->actor)->for($this->task)->create(['appointment_at' => now()->addHour()]);
    expect(app(AppointmentRepository::class)->within($this->actor, $this->constraints))->toBeEmpty();
});

test('a recorded appointment without a location asks for it instead of guessing an office', function () {
    app(RecordProcessEvent::class)->execute($this->actor, $this->process, 'appointment_recorded', $this->appointment, 1, (string) Str::uuid());
    expect(fn () => app(AppointmentRepository::class)->within($this->actor, $this->constraints))->toThrow(ValidationException::class);
});

test('the planner uses the recorded instant location and duration without a made-up cost', function () {
    app(RecordProcessEvent::class)->execute($this->actor, $this->process, 'appointment_recorded', [...$this->appointment, 'location' => $this->location], 1, (string) Str::uuid());
    $repository = app(AppointmentRepository::class);
    $one = $repository->within($this->actor, $this->constraints)[0];
    expect($one->lat)->toBe(50.95)->and($one->lng)->toBe(6.91)->and($one->typicalDurationMin)->toBe(25)
        ->and($one->costTier)->toBe('unknown')->and($one->fixedStart->toIso8601String())->toBe($this->appointment['starts_at'])
        ->and($one->swappable)->toBeFalse();
    $this->task->update(['is_published' => false]);
    expect($repository->within($this->actor, $this->constraints))->toHaveCount(1);
    expect($repository->within(User::factory()->onboarded()->create(), $this->constraints))->toBeEmpty();
});

test('rescheduling or cancellation changes the appointment fingerprint and removes obsolete timing', function () {
    $events = app(RecordProcessEvent::class);
    $events->execute($this->actor, $this->process, 'appointment_recorded', [...$this->appointment, 'location' => $this->location], 1, (string) Str::uuid());
    $repository = app(AppointmentRepository::class);
    $before = $repository->revision($this->actor, $this->constraints);
    $events->execute($this->actor, $this->process, 'appointment_recorded', [...$this->appointment, 'starts_at' => now()->addHours(2)->toIso8601String(), 'location' => $this->location], 2, (string) Str::uuid());
    expect($repository->revision($this->actor, $this->constraints))->not->toBe($before)
        ->and($repository->within($this->actor, $this->constraints))->toHaveCount(1)
        ->and($repository->within($this->actor, $this->constraints)[0]->fixedStart->hour)->toBe(12);
    $events->execute($this->actor, $this->process, 'appointment_cancelled', ['appointment_id' => $this->appointment['appointment_id']], 3, (string) Str::uuid());
    expect($repository->within($this->actor, $this->constraints))->toBeEmpty();
});

test('a partly overlapping appointment cannot be ignored when planning the same time', function () {
    app(RecordProcessEvent::class)->execute($this->actor, $this->process, 'appointment_recorded', [
        ...$this->appointment, 'starts_at' => now()->subMinutes(10)->toIso8601String(), 'location' => $this->location,
    ], 1, (string) Str::uuid());
    expect(fn () => app(AppointmentRepository::class)->within($this->actor, $this->constraints))->toThrow(ValidationException::class);
});

test('appointment location input rejects partial or invalid coordinate pairs', function (array $location) {
    expect(fn () => app(RecordProcessEvent::class)->execute($this->actor, $this->process, 'appointment_recorded', [
        ...$this->appointment, 'location' => $location,
    ], 1, (string) Str::uuid()))->toThrow(ValidationException::class);
})->with([[['lat' => 50]], [['lat' => 91, 'lng' => 7]], [['lat' => 50, 'lng' => '7']], [['lat' => 50, 'lng' => 7, 'unreviewed_claim' => true]]]);

function recordedComposerPlan($fixture): array
{
    app(RecordProcessEvent::class)->execute($fixture->actor, $fixture->process, 'appointment_recorded', [
        ...$fixture->appointment, 'location' => $fixture->location,
    ], 1, (string) Str::uuid());
    $snapshot = app(AppointmentRepository::class)->snapshot($fixture->actor, $fixture->constraints);
    $candidate = $snapshot['candidates'][0];

    return ['constraints' => $fixture->constraints->toArray(), 'appointment_revision' => $snapshot['revision'],
        'slots' => [(new PlanSlot($candidate, $candidate->fixedStart, $candidate->fixedStart->addMinutes(25), 0))->toArray()]];
}

test('active day plans encrypt personal data and bind it to their owner', function () {
    $plan = recordedComposerPlan($this);
    $active = app(ActivePlanStore::class);
    $active->save($this->actor, $plan);
    $activeRaw = Cache::get('composer:plan:'.$this->actor->id);
    expect($activeRaw)->toBeString()->not->toContain('My recorded meeting place');
    $stranger = User::factory()->onboarded()->create();
    Cache::put('composer:plan:'.$stranger->id, $activeRaw, 100);
    expect($active->get($stranger))->toBeNull();
});

test('changed appointments invalidate active plans', function () {
    $plan = recordedComposerPlan($this);
    $active = app(ActivePlanStore::class);
    $active->save($this->actor, $plan);
    app(RecordProcessEvent::class)->execute($this->actor, $this->process, 'appointment_cancelled', [
        'appointment_id' => $this->appointment['appointment_id'],
    ], 2, (string) Str::uuid());
    expect(fn () => $active->get($this->actor))->toThrow(ValidationException::class);
    expect(fn () => $active->save($this->actor, $plan))->toThrow(ValidationException::class);
});

test('legacy unbound caches and unverified accounts cannot expose a saved appointment', function () {
    $plan = recordedComposerPlan($this);
    Cache::put('composer:plan:'.$this->actor->id, $plan, 100);
    expect(app(ActivePlanStore::class)->get($this->actor))->toBeNull();
    app(ActivePlanStore::class)->save($this->actor, $plan);
    $this->actor->forceFill(['email_verified_at' => null])->save();
    expect(app(ActivePlanStore::class)->get($this->actor))->toBeNull();
});

test('composing keeps recorded appointments even when a client excludes them', function () {
    Http::fake();
    $plan = recordedComposerPlan($this);
    $id = $plan['slots'][0]['id'];
    $this->actingAs($this->actor)->postJson('/composer/compose', [
        'constraints' => $this->constraints->toArray(), 'excluded' => [$id],
    ])->assertOk()->assertJsonPath('plan.slots.0.id', $id);
    expect(Cache::get('composer:plan:'.$this->actor->id))->toBeString();
});

test('erasure cleans encrypted appointment copies without affecting another account', function () {
    $plan = recordedComposerPlan($this);
    app(ActivePlanStore::class)->save($this->actor, $plan);
    // A copy left from the retired Today pin is erased too.
    Cache::put('composer:today:'.$this->actor->id, 'leftover encrypted copy', 100);
    Cache::put('composer:plan:999999', 'another users encrypted snapshot', 100);
    app(PurgePersonCopies::class)->purge(['subject_user_id' => $this->actor->id]);
    expect(Cache::get('composer:plan:'.$this->actor->id))->toBeNull()
        ->and(Cache::get('composer:today:'.$this->actor->id))->toBeNull()
        ->and(Cache::get('composer:plan:999999'))->toBe('another users encrypted snapshot');
});

test('an existing appointment remains a commitment after changing to an unsupported city', function () {
    recordedComposerPlan($this);
    $repository = app(AppointmentRepository::class);
    $before = $repository->revision($this->actor, $this->constraints);
    $this->actor->update(['city' => 'Berlin']);
    expect($repository->within($this->actor, $this->constraints))->toHaveCount(1)
        ->and($repository->revision($this->actor, $this->constraints))->toBe($before);
});

test('Composer keeps the warning when two recorded appointments cannot fit together', function () {
    Http::fake();
    recordedComposerPlan($this);
    app(RecordProcessEvent::class)->execute($this->actor, $this->process, 'appointment_recorded', [
        ...$this->appointment, 'appointment_id' => (string) Str::uuid(), 'location' => $this->location,
    ], 2, (string) Str::uuid());
    $response = $this->actingAs($this->actor)->postJson('/composer/compose', ['constraints' => $this->constraints->toArray()])
        ->assertOk()->assertJsonPath('plan.schedule_feasible', false)
        ->assertJsonPath('notices.0.type', 'warn')
        ->assertJsonCount(2, 'plan.slots');
    expect($response->json('notices.0.text'))->toContain('cannot be reached in time');
});

test('a save cannot recreate an appointment copy after erasure wins the race', function () {
    $plan = recordedComposerPlan($this);
    $cache = Cache::store();
    Cache::swap(Mockery::mock(Cache::getFacadeRoot())->makePartial());
    Cache::shouldReceive('put')->once()->andReturnUsing(function ($key, $value, $ttl) use ($cache) {
        app(PersonDataLifecycle::class)->erase($this->actor, $this->case->person);
        app(PurgePersonCopies::class)->purge(['subject_user_id' => $this->actor->id]);

        return $cache->put($key, $value, $ttl);
    });
    expect(fn () => app(ActivePlanStore::class)->save($this->actor, $plan))->toThrow(ValidationException::class);
    expect($cache->get('composer:plan:'.$this->actor->id))->toBeNull();
});

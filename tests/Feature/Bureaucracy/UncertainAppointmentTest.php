<?php

use App\Bureaucracy\Catalogue\CatalogueCompiler;
use App\Bureaucracy\Catalogue\CatalogueReleaseStore;
use App\Bureaucracy\People\EnsureAccountHolder;
use App\Bureaucracy\Processes\ReconcileProcesses;
use App\Bureaucracy\Processes\RecordProcessEvent;
use App\Bureaucracy\ReadModel\PlanReadModel;
use App\Composer\AppointmentRepository;
use App\Composer\Constraints;
use App\Composer\TodayPlanStore;
use App\Models\Task;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

beforeEach(function () {
    $this->travelTo('2026-09-08 10:00:00');
    $this->actor = User::factory()->onboarded()->create(['city' => 'Köln']);
    $this->case = app(EnsureAccountHolder::class)->dossier($this->actor);
    $task = Task::factory()->approvedFixture()->create(['key' => 'fixture.uncertain_appointment', 'type' => 'task', 'applies_if' => [],
        'depends_on' => [], 'deadline_type' => 'none', 'links' => [], 'how_to_steps' => [], 'documents_required' => []])->fresh();
    $store = app(CatalogueReleaseStore::class);
    $release = $store->stage(app(CatalogueCompiler::class)->compile([$task], [$task->key => [
        'process_id' => 'fixture.uncertain_appointment', 'topic' => 'residence', 'kind' => 'preparation', 'coverage' => 'partial',
    ]]));
    $store->activate($release->id, null);
    $this->process = app(ReconcileProcesses::class)->execute($this->actor, $this->case->person, 'de-nrw-cologne')[0];
    $this->constraints = Constraints::fromArray(['window_start' => now()->toIso8601String(), 'window_end' => now()->addHours(5)->toIso8601String()]);
    $this->appointment = ['appointment_id' => (string) Str::uuid(), 'starts_at' => now()->addHour()->toIso8601String(),
        'timezone' => 'Europe/Berlin', 'duration_minutes' => null, 'location' => ['label' => 'Office', 'lat' => 50.95, 'lng' => 6.91]];
    $this->record = fn (array $payload, int $version = 1) => app(RecordProcessEvent::class)
        ->execute($this->actor, $this->process, 'appointment_recorded', $payload, $version, (string) Str::uuid());
    $this->timeline = fn () => collect(app(PlanReadModel::class)->for($this->actor, $this->case->person, 'de-nrw-cologne')['timeline'])->firstWhere('kind', 'appointment');
});

test('an unknown appointment length is an explicit null and is never defaulted', function () {
    $without = $this->appointment;
    unset($without['duration_minutes']);
    expect(fn () => ($this->record)($without))->toThrow(ValidationException::class);
    expect(fn () => ($this->record)([...$this->appointment, 'duration_minutes' => 0]))->toThrow(ValidationException::class);
    ($this->record)($this->appointment);
    $row = ($this->timeline)();
    expect($row)->toHaveKey('duration_minutes')->and($row['duration_minutes'])->toBeNull()
        ->and($row['appointment_id'])->toBe($this->appointment['appointment_id'])->and($row['event_id'])->toBeInt()
        ->and($row['routable'])->toBeTrue()->and($row['legal_effect'])->toBe('not_assessed');
});

test('a text-only place is accepted as unroutable and never gains coordinates', function () {
    ($this->record)([...$this->appointment, 'duration_minutes' => 30, 'location' => ['label' => 'Ausländeramt, Room 3']]);
    $row = ($this->timeline)();
    expect($row['routable'])->toBeFalse()->and($row['location'])->toBe(['label' => 'Ausländeramt, Room 3']);
    expect(fn () => ($this->record)([...$this->appointment, 'location' => []], 2))->toThrow(ValidationException::class);
    expect(fn () => ($this->record)([...$this->appointment, 'location' => ['label' => ' ']], 2))->toThrow(ValidationException::class);
});

test('Composer keeps an unknown-length appointment without inventing an end and flags the next stop as a possible overlap', function () {
    ($this->record)($this->appointment);
    $candidate = app(AppointmentRepository::class)->within($this->actor, $this->constraints)[0];
    expect($candidate->durationKnown)->toBeFalse()->and($candidate->typicalDurationMin)->toBe(0)->and($candidate->routable)->toBeTrue();
    Http::fake();
    $response = $this->actingAs($this->actor)->postJson('/composer/compose', ['constraints' => $this->constraints->toArray()])->assertOk();
    $slots = $response->json('plan.slots');
    $index = collect($slots)->search(fn ($slot) => $slot['is_appointment']);
    expect($slots[$index]['duration_known'])->toBeFalse()->and($slots[$index]['end_time'])->toBeNull()
        ->and($slots[$index]['duration_label'])->toBeNull()->and($response->json('plan.schedule_feasible'))->toBeTrue();
    if (isset($slots[$index + 1])) {
        expect($slots[$index + 1]['may_overlap_previous'])->toBeTrue();
    }
    expect(collect($response->json('notices'))->pluck('code'))->toContain('appointment_end_unknown');
});

test('Composer and Today never compute a journey to a text-only appointment place', function () {
    ($this->record)([...$this->appointment, 'duration_minutes' => 30, 'location' => ['label' => 'Ausländeramt']]);
    $candidate = app(AppointmentRepository::class)->within($this->actor, $this->constraints)[0];
    expect($candidate->routable)->toBeFalse()->and(is_nan($candidate->lat))->toBeTrue();
    Http::fake();
    $response = $this->actingAs($this->actor)->postJson('/composer/compose', ['constraints' => $this->constraints->toArray()])->assertOk();
    $slots = $response->json('plan.slots');
    $index = collect($slots)->search(fn ($slot) => $slot['is_appointment']);
    expect($slots[$index])->toMatchArray(['routable' => false, 'lat' => null, 'lng' => null, 'leave_by' => null]);
    foreach ([$index, $index + 1] as $neighbour) {
        if ($neighbour > 0 && isset($slots[$neighbour])) {
            expect($slots[$neighbour]['travel_known'])->toBeFalse()->and($slots[$neighbour]['travel_min_from_previous'])->toBeNull();
        }
    }
    expect(collect($response->json('notices'))->pluck('code'))->toContain('appointment_location_unroutable');
    Http::assertNothingSent();
    $this->postJson('/composer/save', [])->assertOk();
    expect(collect(app(TodayPlanStore::class)->get($this->actor)['notices'])->pluck('code'))->toContain('appointment_location_unroutable');
});

test('an unknown-length appointment that may still be running at the plan start is not silently ignored', function () {
    ($this->record)([...$this->appointment, 'starts_at' => now()->subHours(2)->toIso8601String()]);
    expect(fn () => app(AppointmentRepository::class)->within($this->actor, $this->constraints))
        ->toThrow(ValidationException::class, 'unknown length');
});

test('a reviewed preparation step is finished by completing it, not by reporting a submission', function () {
    $plan = app(PlanReadModel::class)->for($this->actor, $this->case->person, 'de-nrw-cologne');
    expect($plan['processes'][0]['guidance'][0]['completion_event'])->toBe('step_completed');
});

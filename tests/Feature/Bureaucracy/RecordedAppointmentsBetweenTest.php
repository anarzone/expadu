<?php

use App\Bureaucracy\Catalogue\CatalogueCompiler;
use App\Bureaucracy\Catalogue\CatalogueReleaseStore;
use App\Bureaucracy\People\EnsureAccountHolder;
use App\Bureaucracy\People\ManageDelegation;
use App\Bureaucracy\Processes\CorrectProcessEvent;
use App\Bureaucracy\Processes\ReconcileProcesses;
use App\Bureaucracy\Processes\RecordProcessEvent;
use App\Bureaucracy\ReadModel\AccountHolderPlan;
use App\Bureaucracy\ReadModel\RecordedAppointmentsBetween;
use App\Models\BureaucracyProcess;
use App\Models\Task;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** One reviewed fixture card that applies to everyone, so each account gets one process. */
function appointmentsCatalogue(): void
{
    $task = Task::factory()->approvedFixture()->create(['key' => 'fixture.recorded_appointments', 'title' => 'EU Blue Card',
        'applies_if' => [], 'deadline_type' => 'none', 'depends_on' => [], 'type' => 'task',
        'documents_required' => [], 'how_to_steps' => [], 'links' => []])->fresh();
    $store = app(CatalogueReleaseStore::class);
    $store->activate($store->stage(app(CatalogueCompiler::class)->compile([$task], [$task->key => [
        'process_id' => 'fixture.recorded_appointments', 'topic' => 'residence', 'kind' => 'preparation', 'coverage' => 'partial',
    ]]))->id, null);
}

function appointmentsProcess(User $user): BureaucracyProcess
{
    $case = app(EnsureAccountHolder::class)->dossier($user);

    return app(ReconcileProcesses::class)->execute($user, $case->person, 'de-nrw-cologne')[0];
}

/** Records an appointment at a Berlin wall-clock time; returns the report's event id and appointment id. */
function recordAppointmentAt(User $user, BureaucracyProcess $process, string $at, string $label = 'Mülheim'): array
{
    $id = (string) Str::uuid();
    app(RecordProcessEvent::class)->execute($user, $process->fresh(), 'appointment_recorded', [
        'appointment_id' => $id, 'starts_at' => CarbonImmutable::parse($at, 'Europe/Berlin')->toIso8601String(),
        'timezone' => 'Europe/Berlin', 'duration_minutes' => 30, 'location' => ['label' => $label, 'lat' => 50.96103, 'lng' => 7.0048],
    ], $process->fresh()->version, (string) Str::uuid());

    return [$process->fresh()->events()->latest('id')->value('id'), $id];
}

function appointmentsOn(User $user, string $date): array
{
    $day = CarbonImmutable::parse($date, 'Europe/Berlin');

    return app(RecordedAppointmentsBetween::class)->for($user, $day->startOfDay(), $day->addDay()->startOfDay());
}

beforeEach(fn () => appointmentsCatalogue());

test('the account holder\'s recorded appointments in the window, oldest first, with the process name', function () {
    $user = User::factory()->onboarded()->create();
    $process = appointmentsProcess($user);
    recordAppointmentAt($user, $process, '2026-10-19 14:00');
    recordAppointmentAt($user, $process, '2026-10-19 09:30', 'Kalk');
    recordAppointmentAt($user, $process, '2026-10-20 09:00');

    $rows = appointmentsOn($user, '2026-10-19');

    expect($rows)->toHaveCount(2)
        ->and(array_column($rows, 'location'))->sequence(
            fn ($location) => $location->label->toBe('Kalk'),
            fn ($location) => $location->label->toBe('Mülheim'),
        )
        ->and($rows[0])->process_id->toBe($process->id)->process_title->toBe('EU Blue Card')
        ->duration_minutes->toBe(30)->timezone->toBe('Europe/Berlin');
});

test('🔒 another account\'s appointments are never returned', function () {
    $owner = User::factory()->onboarded()->create();
    recordAppointmentAt($owner, appointmentsProcess($owner), '2026-10-19 14:00');
    $other = User::factory()->onboarded()->create();
    appointmentsProcess($other);

    expect(appointmentsOn($other, '2026-10-19'))->toBe([])
        ->and(appointmentsOn($owner, '2026-10-19'))->toHaveCount(1);
});

test('🔒 a family member\'s appointments are never returned, even one this account may view', function () {
    $owner = User::factory()->onboarded()->create();
    $spouse = User::factory()->onboarded()->create();
    appointmentsProcess($owner);
    $spouseProcess = appointmentsProcess($spouse);
    $invite = app(ManageDelegation::class)->invite($owner, app(EnsureAccountHolder::class)->person($owner)->workspace, $spouse->email, ['view_plan']);
    app(ManageDelegation::class)->accept($spouse, $invite['token'], ['view_plan']);
    recordAppointmentAt($spouse, $spouseProcess, '2026-10-19 14:00');

    expect(appointmentsOn($owner, '2026-10-19'))->toBe([])
        ->and(appointmentsOn($spouse, '2026-10-19'))->toHaveCount(1);
});

test('🔒 a cancelled appointment is gone and a corrected one is read as corrected', function () {
    $user = User::factory()->onboarded()->create();
    $process = appointmentsProcess($user);
    [, $cancelled] = recordAppointmentAt($user, $process, '2026-10-19 10:00');
    [$reportId, $moved] = recordAppointmentAt($user, $process, '2026-10-19 14:00');
    app(RecordProcessEvent::class)->execute($user, $process->fresh(), 'appointment_cancelled', ['appointment_id' => $cancelled],
        $process->fresh()->version, (string) Str::uuid());
    app(CorrectProcessEvent::class)->execute($user, $process->fresh(), $reportId, ['appointment_id' => $moved,
        'starts_at' => CarbonImmutable::parse('2026-10-19 15:30', 'Europe/Berlin')->toIso8601String(), 'timezone' => 'Europe/Berlin',
        'duration_minutes' => 45, 'location' => ['label' => 'Mülheim']], $process->fresh()->version, (string) Str::uuid());

    $rows = appointmentsOn($user, '2026-10-19');

    expect($rows)->toHaveCount(1)
        ->and($rows[0])->appointment_id->toBe($moved)->duration_minutes->toBe(45)
        ->and(CarbonImmutable::parse($rows[0]['starts_at'])->setTimezone('Europe/Berlin')->format('H:i'))->toBe('15:30');

    // The same appointments the Paperwork plan's timeline shows: nothing more, nothing less.
    $plan = collect(app(AccountHolderPlan::class)->for($user)['timeline'])->where('kind', 'appointment');
    expect($plan->pluck('appointment_id')->all())->toBe([$moved])
        ->and($plan->first()['starts_at'])->toBe($rows[0]['starts_at']);
});

test('🔒 the gates hold: an unverified email, a closed dossier or a removed person reads nothing', function (string $gate) {
    $user = User::factory()->onboarded()->create();
    recordAppointmentAt($user, $process = appointmentsProcess($user), '2026-10-19 14:00');
    expect(appointmentsOn($user, '2026-10-19'))->toHaveCount(1);

    match ($gate) {
        'unverified' => $user->forceFill(['email_verified_at' => null])->save(),
        'dossier' => $process->dossier()->update(['status' => 'closed']),
        'person' => app(EnsureAccountHolder::class)->person($user)->update(['record_status' => 'erased']),
    };

    expect(appointmentsOn($user, '2026-10-19'))->toBe([]);
})->with(['unverified', 'dossier', 'person']);

test('it reads the appointments in a handful of queries, without compiling the plan', function () {
    $user = User::factory()->onboarded()->create();
    recordAppointmentAt($user, appointmentsProcess($user), '2026-10-19 14:00');
    appointmentsOn($user, '2026-10-19'); // The release's names are cached after the first read.

    DB::flushQueryLog();
    DB::enableQueryLog();
    $rows = appointmentsOn($user, '2026-10-19');
    $queries = count(DB::getQueryLog());
    DB::disableQueryLog();

    expect($rows)->toHaveCount(1)->and($queries)->toBeLessThanOrEqual(8);
});

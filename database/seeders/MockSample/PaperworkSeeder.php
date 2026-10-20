<?php

namespace Database\Seeders\MockSample;

use App\Bureaucracy\Evidence\ConfirmRequirementUse;
use App\Bureaucracy\Evidence\RecordEvidence;
use App\Bureaucracy\Facts\RecordFactChange;
use App\Bureaucracy\People\EnsureAccountHolder;
use App\Bureaucracy\Processes\RecordProcessEvent;
use App\Bureaucracy\Processes\StartProcess;
use App\Bureaucracy\ReadModel\PlanReadModel;
use App\Bureaucracy\RuleSourcePolicy;
use App\Bureaucracy\Verification\ClaimCheck;
use App\Bureaucracy\Verification\SourceCheckRecorder;
use App\Models\BureaucracyPerson;
use App\Models\BureaucracyProcess;
use App\Models\Task;
use App\Models\User;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Local only: the Paperwork mock's sample record in the E2E account, so tools/mock-compare
 * shows the app and the mock with the same words. The record is the one
 * prototype/dev/design/bureaucracy-next shows on its sample day (connected/sample-day.js,
 * Wednesday 30 September 2026, 13:10):
 *
 *  - the person's answers (model.js sampleFacts) and their D visa expiring on 1 October;
 *  - EU Blue Card: preparing, one document ready, the appointment today at 14:00 in Mülheim;
 *  - Address registration: blocked, "A document is not ready", one document reported;
 *  - Health insurance: submitted on 24 September, waiting for a response;
 *  - Bank account: suggested, not started.
 *
 * Everything goes through the v2 commands, at the mock's moment, so the plan is the engine's own.
 * It replaces the E2E account's Paperwork record. Pair it with the `mock_clock` cookie
 * (App\Support\MockClock):
 *
 *   php artisan db:seed --class='Database\Seeders\MockSample\PaperworkSeeder'
 *
 * IMPORTANT: never registered in DatabaseSeeder; it refuses to run outside local.
 */
class PaperworkSeeder extends Seeder
{
    private const MOMENT = '2026-09-30 13:10:00';

    private const JURISDICTION = 'de-nrw-cologne';

    private const FACTS = [
        'arrival_planned' => false,
        'purpose' => 'employment',
        'citizenship_group' => 'non_eu',
        'current_residence_title' => 'national_d_visa',
        'entry_mode' => 'd_visa',
        'case_goal' => 'blue_card',
        'registration_status' => 'not_registered',
        'health_coverage_confirmed' => false,
        'bank_account_help_needed' => true,
        'tax_id_available' => true,
        'visa_expires_at' => '2026-10-01',
    ];

    public function run(): void
    {
        if (! app()->environment('local')) {
            $this->command?->error('Refusing: the Paperwork sample is for local mock comparisons only.');

            return;
        }

        $user = User::query()->where('email', (string) env('E2E_EMAIL', 'e2e@expadu.test'))->first();

        if ($user === null) {
            $this->command?->error('No E2E account. Run the E2ETestUserSeeder first.');

            return;
        }

        $moment = CarbonImmutable::parse(self::MOMENT, 'Europe/Berlin');
        Carbon::setTestNow($moment);
        CarbonImmutable::setTestNow($moment);

        try {
            $this->publish();
            $this->seed($user);
        } finally {
            Carbon::setTestNow();
            CarbonImmutable::setTestNow();
        }

        $this->command?->info("The Paperwork sample is in {$user->email}'s record.");
    }

    /** The browser has no network to the official pages: a checked card counts when its quotes pass offline. */
    private function publish(): void
    {
        $checks = app(ClaimCheck::class);
        $recorder = app(SourceCheckRecorder::class);
        Task::query()->where('source_verification', RuleSourcePolicy::QuoteChecked)->where('is_published', true)
            ->each(function (Task $task) use ($checks, $recorder): void {
                if ($checks->offlineErrors($task->attributesToArray()) === []) {
                    $recorder->record($task, ['outcome' => 'passed', 'failures' => [], 'unreachable' => []]);
                    $recorder->apply($task);
                }
            });
        Artisan::call('bureaucracy:compile-catalogue', ['--deploy' => true]);
    }

    private function seed(User $user): void
    {
        // A fresh record: the person, their dossier, answers, processes and documents.
        BureaucracyPerson::query()->where('account_user_id', $user->id)->delete();

        $case = app(EnsureAccountHolder::class)->dossier($user);
        $person = $case->person;

        foreach (self::FACTS as $key => $value) {
            app(RecordFactChange::class)->execute($user, $person->fresh(), $key, $value, null, $case->fresh()->fact_version);
        }

        $blueCard = $this->start($user, $person, 'residence.blue_card.first');
        $address = $this->start($user, $person, 'address.registration');
        $health = $this->start($user, $person, 'health.coverage');

        $this->event($user, $blueCard, 'preparation_started', []);
        $this->event($user, $blueCard, 'appointment_recorded', [
            'appointment_id' => (string) Str::uuid(),
            'starts_at' => '2026-09-30T14:00:00+02:00',
            'timezone' => 'Europe/Berlin',
            'duration_minutes' => 30,
            'location' => ['label' => 'Mülheim', 'lat' => 50.96103, 'lng' => 7.0048],
        ]);

        $this->event($user, $address, 'preparation_started', []);
        $this->event($user, $address, 'blocked_reported', ['note' => 'A document is not ready']);

        $this->event($user, $health, 'preparation_started', []);
        $this->event($user, $health, 'submission_recorded', ['occurred_on' => '2026-09-24']);
        $this->event($user, $health, 'waiting_reported', ['note' => 'Response pending']);

        // EU Blue Card: one of its documents ready. Address registration: one reported, not yet ready.
        $this->document($user, $person, $blueCard, ready: true);
        $this->document($user, $person, $address, ready: false);
    }

    /** @return array<string, mixed> */
    private function plan(User $user, BureaucracyPerson $person): array
    {
        return app(PlanReadModel::class)->for($user, $person->fresh(), self::JURISDICTION);
    }

    private function start(User $user, BureaucracyPerson $person, string $definition): BureaucracyProcess
    {
        $proposal = collect($this->plan($user, $person)['processes'] ?? [])
            ->first(fn (array $process) => $process['id'] === null && $process['definition_id'] === $definition);

        if ($proposal === null) {
            throw new RuntimeException("The plan suggests no {$definition} process for the sample answers.");
        }

        $receipt = app(StartProcess::class)->execute($user, $person->fresh(), self::JURISDICTION,
            $proposal['occurrence_key'], $proposal['review_token'], (string) Str::uuid());

        return BureaucracyProcess::query()->findOrFail($receipt['process_id']);
    }

    /** @param  array<string, mixed>  $payload */
    private function event(User $user, BureaucracyProcess $process, string $type, array $payload): void
    {
        $process = $process->fresh();
        $current = collect($this->plan($user, $process->dossier->person)['processes'] ?? [])->firstWhere('id', $process->id);

        app(RecordProcessEvent::class)->execute($user, $process, $type, $payload, (int) $process->version,
            (string) Str::uuid(), $current['review_token'] ?? null);
    }

    private function document(User $user, BureaucracyPerson $person, BureaucracyProcess $process, bool $ready): void
    {
        $requirement = collect($this->plan($user, $person)['paperwork']['requirements'] ?? [])
            ->first(fn (array $requirement) => $requirement['process_id'] === $process->id && $requirement['applicability'] === 'required');

        if ($requirement === null) {
            throw new RuntimeException("Process {$process->id} lists no required document.");
        }

        $evidenceId = (string) Str::uuid();
        $receipt = app(RecordEvidence::class)->execute($user, $person->fresh(), $evidenceId, [
            'label' => $requirement['label'],
            'kind' => 'document',
            'reported_available' => true,
            'requirement_refs' => [$requirement['id']],
        ], 0, (string) Str::uuid());

        if ($ready) {
            app(ConfirmRequirementUse::class)->execute($user, $process->fresh(), $requirement['id'], $evidenceId,
                (int) $process->fresh()->version, $receipt->version, $requirement['semantic_hash'], (string) Str::uuid());
        }
    }
}

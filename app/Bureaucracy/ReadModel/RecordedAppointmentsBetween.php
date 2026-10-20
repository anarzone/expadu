<?php

namespace App\Bureaucracy\ReadModel;

use App\Bureaucracy\Catalogue\CatalogueReleaseStore;
use App\Bureaucracy\People\AccessScope;
use App\Bureaucracy\People\PersonAccess;
use App\Bureaucracy\Processes\ProcessHistory;
use App\Bureaucracy\Timeline\BuildTimeline;
use App\Models\BureaucracyProcess;
use App\Models\BureaucracyProcessEvent;
use App\Models\User;
use Carbon\CarbonImmutable;

/**
 * The account holder's own recorded appointments in a time window, without compiling the
 * plan (Your day reads these on every request). The rule: an appointment the Paperwork
 * page wouldn't show, the day mustn't either. So it reads them as the plan does:
 *
 *  - the same gates as AccountHolderPlan (verified email, the account holder's own active
 *    person with an active dossier) and view-plan access; a family member's record, even
 *    one this account may view, is never read;
 *  - the same processes PlanReadModel's timeline covers: this jurisdiction's, except an
 *    undone (untracked) start, and every process elsewhere;
 *  - the same folding: corrections (ProcessHistory), then BuildTimeline::appointments(),
 *    where an appointment's latest report wins, so a cancelled one is gone.
 */
final class RecordedAppointmentsBetween
{
    public function __construct(private AccountHolderPlan $plans, private PersonAccess $access, private CatalogueReleaseStore $catalogue) {}

    /**
     * Appointments starting in [$from, $to), oldest first.
     *
     * @return list<array{appointment_id: string, process_id: int, process_title: ?string, starts_at: string, timezone: string, duration_minutes: ?int, location: ?array<string, mixed>}>
     */
    public function for(User $user, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $person = $this->plans->person($user);
        if ($person === null || ! $this->access->allows($user, $person, AccessScope::ViewPlan)) {
            return [];
        }
        $case = $person->dossier()->where('status', 'active')->first();
        if ($case === null) {
            return [];
        }
        $jurisdiction = $this->plans->jurisdiction($user);
        $processes = BureaucracyProcess::query()->where('case_id', $case->id)->orderBy('id')->get(['id', 'definition_id', 'jurisdiction', 'state'])
            ->reject(fn (BureaucracyProcess $process): bool => $process->jurisdiction === $jurisdiction && ($process->state['workflow'] ?? null) === 'untracked')
            ->keyBy('id');
        if ($processes->isEmpty()) {
            return [];
        }

        $timeline = new BuildTimeline;
        $titles = null;
        $rows = [];
        $events = BureaucracyProcessEvent::query()->whereIn('process_id', $processes->keys())->orderBy('id')
            ->get(['id', 'process_id', 'type', 'payload', 'corrects_event_id'])->groupBy('process_id');
        foreach ($events as $processId => $reports) {
            $active = (new ProcessHistory)->active($reports->map(fn (BureaucracyProcessEvent $event): array => ['id' => $event->id,
                'type' => $event->type, 'payload' => $event->payload, 'corrects_event_id' => $event->corrects_event_id])->all());
            foreach ($timeline->appointments($active) as $appointment) {
                $start = CarbonImmutable::parse($appointment['starts_at']);
                if ($start->lessThan($from) || ! $start->lessThan($to)) {
                    continue;
                }
                $titles ??= $this->catalogue->processTitles();
                $rows[] = ['appointment_id' => $appointment['appointment_id'], 'process_id' => $processId,
                    'process_title' => $titles[$processes[$processId]->definition_id] ?? null,
                    'starts_at' => $appointment['starts_at'], 'timezone' => $appointment['timezone'],
                    'duration_minutes' => $appointment['duration_minutes'], 'location' => $appointment['location']];
            }
        }
        usort($rows, fn (array $one, array $two): int => CarbonImmutable::parse($one['starts_at'])->getTimestamp() <=> CarbonImmutable::parse($two['starts_at'])->getTimestamp());

        return $rows;
    }
}

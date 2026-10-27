<?php

namespace App\Composer\Assistant;

use App\Bureaucracy\ReadModel\AccountPlanEntry;
use App\Bureaucracy\ReadModel\RecordedAppointmentsBetween;
use App\Composer\Answers\TripAnswers;
use App\Composer\PaperworkGlance;
use App\Day\DayProjection;
use App\Models\ActiveTrip;
use App\Models\User;
use Carbon\CarbonImmutable;

/**
 * The person's own records as cards the assistant may show but never reads (owner, 22 Oct
 * 2026: Paperwork details and a trip under way stay out of the AI's input): their Paperwork
 * checklist and next date, their trip, their next plan or appointment. Each card's words
 * are Composer's own, from their records as they are; the AI only learns that a card is
 * there. Nothing here is a legal claim.
 */
final class OwnCards
{
    /** Paperwork's own words for each document state, as Composer says them. */
    private const READINESS = [
        'missing' => 'Still to get',
        'reported_available' => 'You have it · not checked yet',
        'confirmed_for_use' => 'Checked and ready',
        'needs_reconfirmation' => 'Check again',
    ];

    public function __construct(
        private AccountPlanEntry $plans,
        private PaperworkGlance $glance,
        private RecordedAppointmentsBetween $appointments,
        private TripAnswers $trips,
        private DayProjection $day,
    ) {}

    /**
     * Their Paperwork: the checklist for their next appointment's task (else their first
     * task with documents to bring), and their next date, from their own record.
     *
     * @return array<string, mixed> the card
     */
    public function paperwork(User $user, CarbonImmutable $now): array
    {
        $plan = $this->plans->for($user)['plan'] ?? null;
        if (! is_array($plan)) {
            return self::note('Nothing is recorded in Paperwork yet. It sorts out what to do first, from your own situation.', [['label' => 'Open Paperwork', 'href' => '/bureaucracy']]);
        }
        $requirements = collect($plan['paperwork']['requirements'] ?? []);
        $processes = collect($plan['processes'] ?? [])->filter(fn (array $process): bool => $process['id'] !== null && ! ($process['is_closed'] ?? false));
        $next = collect($this->appointments->for($user, $now, $now->addDays(90)))->first();
        $process = ($next !== null ? $processes->firstWhere('id', $next['process_id']) : null)
            ?? $processes->first(fn (array $process): bool => $requirements->contains(fn (array $r): bool => $r['process_id'] === $process['id'] && $r['applicability'] === 'required'));
        $required = $process === null ? collect() : $requirements->where('process_id', $process['id'])->where('applicability', 'required')->values();
        $deadline = $this->glance->for($user)['deadline'];
        $facts = $deadline === null || $deadline['days_remaining'] < 0 ? [] : [['label' => 'Next date', 'known' => true,
            'value' => ($deadline['kind'] === 'document_expiry' ? ($deadline['document'] === 'visa' ? 'Visa expires' : 'Document expires') : ($deadline['process'] ?? 'A date to aim for'))
                .' · '.CarbonImmutable::parse($deadline['date'], 'Europe/Berlin')->format('D j M')]];

        if ($required->isEmpty()) {
            return [...self::note('That’s from your own Paperwork record. The steps and their official sources are in Paperwork.', [['label' => 'Open Paperwork', 'href' => '/bureaucracy']]),
                ...($facts !== [] ? ['facts' => $facts] : [])];
        }
        $prepared = $required->where('readiness', 'confirmed_for_use')->count();

        return array_filter([
            'type' => 'checklist',
            'text' => 'That’s from your own checklist. I can’t give verified guidance on what the office requires; the checklist and its official sources are in Paperwork.',
            'title' => "{$prepared} of {$required->count()} documents marked ready.",
            'record' => [
                'title' => (string) ($process['title'] ?? 'Paperwork'),
                'prepared' => $prepared,
                'total' => $required->count(),
                'href' => '/bureaucracy?view=paperwork&process='.rawurlencode((string) $process['occurrence_key']),
                'documents' => $required->map(fn (array $r): array => ['label' => (string) $r['label'],
                    'ready' => $r['readiness'] === 'confirmed_for_use', 'state' => self::READINESS[$r['readiness']] ?? self::READINESS['missing']])->all(),
            ],
            'facts' => $facts ?: null,
        ], fn ($value) => $value !== null);
    }

    /**
     * Their trip: the one under way (where they are on it, at its own times), else the way
     * to their next appointment today, as Today plans it.
     *
     * @param  string  $ask  what they asked first: leave, status, stop, arrive or next
     * @return array<string, mixed> the card
     */
    public function trip(User $user, string $ask, CarbonImmutable $now): array
    {
        $answer = ActiveTrip::currentFor($user) !== null
            ? $this->trips->onTheWay($user, $ask, $now)
            : (function () use ($user, $ask, $now): ?array {
                $start = $this->day->for($user, $now->setTimezone('Europe/Berlin')->toDateString(), null, $now)['next_trip']['target']['start_time'] ?? null;

                return $this->trips->appointment($user, ['status' => $ask === 'status'], $now, is_string($start) ? $start : null);
            })();
        if ($answer === null) {
            return self::note('You’re not on a trip, and there’s no appointment today to plan the way to.', [['label' => 'Open Travel', 'href' => '/travel']]);
        }

        return [...$answer['extra'], 'type' => isset($answer['extra']['trip']) ? 'trip' : 'note', 'text' => $answer['text']];
    }

    /**
     * Their next plan or appointment, from now on: their Composer plans and the
     * appointments Paperwork records.
     *
     * @param  array<string, array<string, mixed>>  $plans  their Composer plans, by date
     * @return array<string, mixed> the card
     */
    public function next(User $user, array $plans, bool $appointment, CarbonImmutable $now): array
    {
        $now = $now->setTimezone('Europe/Berlin');
        $items = [];
        foreach ($plans as $plan) {
            foreach ($plan['slots'] ?? [] as $slot) {
                $items[] = ['start' => CarbonImmutable::parse($slot['start_at'])->setTimezone('Europe/Berlin'), 'title' => (string) $slot['name'],
                    'location' => $slot['location'] ?? $slot['veedel'] ?? null,
                    'appointment' => ($slot['type'] ?? null) === 'appointment' || ($slot['category'] ?? null) === 'appointment' || ($slot['is_appointment'] ?? false)
                        || str_contains(mb_strtolower((string) $slot['name']), 'appointment')];
            }
        }
        foreach ($this->appointments->for($user, $now, $now->addDays(90)) as $row) {
            $items[] = ['start' => CarbonImmutable::parse($row['starts_at'])->setTimezone('Europe/Berlin'),
                'title' => $row['process_title'] !== null ? "{$row['process_title']} appointment" : 'Your recorded appointment',
                'location' => $row['location']['label'] ?? null, 'appointment' => true];
        }
        $next = collect($items)
            ->filter(fn (array $item): bool => $item['start']->greaterThanOrEqualTo($now->startOfMinute()) && (! $appointment || $item['appointment']))
            ->sortBy(fn (array $item): int => $item['start']->getTimestamp())
            ->first();
        $what = $appointment ? 'appointment' : 'plan';
        if ($next === null) {
            return self::note("You have no {$what}s from today onwards.", [['label' => 'Plan my Saturday', 'prompt' => 'Help me plan Saturday']]);
        }
        $date = $next['start']->toDateString();
        $when = $date === $now->toDateString() ? 'today' : ($date === $now->addDay()->toDateString() ? 'tomorrow' : 'on '.$next['start']->format('l j F'));

        return self::note("Your next {$what}: {$next['title']}, {$when} at {$next['start']->format('H:i')}".($next['location'] ? " in {$next['location']}" : '').'.',
            [$date === $now->toDateString() ? ['label' => 'Open Today', 'href' => '/dashboard'] : ['label' => 'My Plans', 'nav' => 'plans']]);
    }

    /**
     * A card of Composer's own words.
     *
     * @param  list<array<string, string>>  $suggestions
     * @return array<string, mixed>
     */
    public static function note(string $text, array $suggestions = []): array
    {
        return array_filter(['type' => 'note', 'text' => $text, 'suggestions' => $suggestions ?: null], fn ($value) => $value !== null);
    }
}

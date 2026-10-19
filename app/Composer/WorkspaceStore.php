<?php

namespace App\Composer;

use App\Models\ComposerWorkspace;
use App\Models\User;
use App\Places\PlaceIdentity;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/** Server-owned plan commands. Conversation text never grants authority to change a plan. */
class WorkspaceStore
{
    public function __construct(private CandidateRepository $catalogue) {}

    public function read(User $user): array
    {
        $row = ComposerWorkspace::query()->where('user_id', $user->id)->first();

        return $this->projectState($row?->state ?? $this->initial()) + ['revision' => $row?->revision ?? 0];
    }

    /** Keep the existing Today save action connected to the same durable plans. */
    public function saveConfirmed(User $user, array $plan): void
    {
        DB::transaction(function () use ($user, $plan): void {
            User::query()->whereKey($user->id)->lockForUpdate()->firstOrFail();
            $row = ComposerWorkspace::query()->firstOrCreate(['user_id' => $user->id], ['state' => $this->initial()]);
            $s = $row->state;
            $this->require($s['draft'] === null, 'Finish your unfinished Composer changes before saving another plan.');
            $plan = app(PlaceIdentity::class)->normalizePlan($plan);
            $plan['date'] = CarbonImmutable::parse($plan['constraints']['window_start'])->setTimezone('Europe/Berlin')->format('Y-m-d');
            $s['draft'] = $plan;
            $s['focus'] = $plan['date'];
            $this->commit($s);
            $row->update(['state' => $s, 'revision' => $row->revision + 1]);
        });
    }

    /**
     * Erasure worker: strip Paperwork appointments from every stored plan copy
     * (plans, draft, versions, plan messages). Personal plans the person typed
     * themselves stay; they are not Paperwork data.
     */
    public function purgeAppointmentCopies(int $userId): void
    {
        DB::transaction(function () use ($userId): void {
            $row = ComposerWorkspace::query()->where('user_id', $userId)->lockForUpdate()->first();
            if ($row === null) {
                return;
            }
            $strip = fn (?array $plan): ?array => $plan === null ? null : [...$plan, 'slots' => array_values(array_filter(
                $plan['slots'] ?? [],
                fn (array $slot): bool => ($slot['is_appointment'] ?? false) !== true && ($slot['type'] ?? null) !== 'appointment',
            ))];
            $s = $row->state;
            $s['plans'] = array_map($strip, $s['plans'] ?? []);
            $s['draft'] = $strip($s['draft'] ?? null);
            $s['versions'] = array_map(fn (array $version): array => [...$version, 'before' => $strip($version['before'] ?? null), 'after' => $strip($version['after'] ?? null)], $s['versions'] ?? []);
            foreach ($s['conversations'] ?? [] as $i => $conversation) {
                foreach ($conversation['messages'] ?? [] as $j => $message) {
                    if (isset($message['plan'])) {
                        $s['conversations'][$i]['messages'][$j]['plan'] = $strip($message['plan']);
                    }
                }
            }
            if ($s !== $row->state) {
                $row->update(['state' => $s, 'revision' => $row->revision + 1]);
            }
        });
    }

    private function projectState(array $state): array
    {
        $catalogue = app(PlanCatalogue::class);
        foreach ($state['plans'] as $date => $plan) {
            $state['plans'][$date] = $catalogue->project($plan);
        }
        if ($state['draft'] !== null) {
            $state['draft'] = $catalogue->project($state['draft']);
        }
        foreach ($state['conversations'] as $i => $conversation) {
            foreach ($conversation['messages'] as $j => $message) {
                if (isset($message['plan'])) {
                    $state['conversations'][$i]['messages'][$j]['plan'] = $catalogue->project($message['plan']);
                }
            }
        }

        return $state;
    }

    private function initial(): array
    {
        return ['approval' => 'ask', 'active' => 'initial', 'focus' => null, 'draft' => null, 'plans' => [], 'versions' => [], 'conversations' => [$this->conversation('initial')]];
    }

    private function conversation(?string $id = null): array
    {
        return ['id' => $id ?? (string) Str::uuid(), 'title' => 'New conversation', 'created_at' => now()->toIso8601String(), 'messages' => []];
    }

    public function command(User $user, array $input): array
    {
        return DB::transaction(function () use ($user, $input): array {
            User::query()->whereKey($user->id)->lockForUpdate()->firstOrFail();
            $row = ComposerWorkspace::query()->firstOrCreate(['user_id' => $user->id], ['state' => $this->initial()]);
            abort_if($row->revision !== $input['revision'], 409, 'Your plan changed in another tab. The latest version has been loaded; review it before trying again.');
            $s = $row->state;
            $action = $input['action'];
            switch ($action) {
                case 'message':
                    $this->message($s, 'user', trim($input['text']));
                    break;
                case 'reply':
                    $replies = [
                        'bureaucracy' => 'For paperwork, use our reviewed guidance. Composer does not generate legal answers.',
                        'find' => 'Browse places and events below. Pick a date before adding something to your plan.',
                        'journey' => 'Choose a place below to check a journey from your current location.',
                        'unknown' => 'I can help you plan a day and find places, events or reviewed paperwork guidance. Try “plan tomorrow afternoon”, or use the controls below.',
                        'failed' => 'I could not finish that request. Your plan is unchanged. You can retry your message.',
                    ];
                    $conversation = collect($s['conversations'])->firstWhere('id', $s['active']);
                    $query = collect($conversation['messages'])->last(fn ($message) => $message['role'] === 'user')['text'] ?? '';
                    $this->message($s, 'assistant', $replies[$input['kind']], ['kind' => $input['kind'], 'query' => $query]);
                    if (in_array($input['kind'], ['bureaucracy', 'unknown'])) {
                        $s['focus'] = null;
                    }
                    break;
                case 'proposal':
                    $this->require($s['draft'] === null, 'Apply or discard your unfinished changes before creating another plan.');
                    $plan = app(PrivatePlanCache::class)->handoff($user, $input['token']);
                    $this->require(is_array($plan), 'This suggestion expired. Please ask again.');
                    $plan['date'] = CarbonImmutable::parse($plan['constraints']['window_start'])->setTimezone('Europe/Berlin')->format('Y-m-d');
                    $this->validatePlan($plan);
                    $s['draft'] = $plan;
                    $s['focus'] = $plan['date'];
                    $this->message($s, 'assistant', 'Here is a proposal. Edit it here, then apply it when you are ready.');
                    $this->planMessage($s, $plan);
                    break;
                case 'open':
                    $this->require(isset($s['plans'][$input['date']]) || ($s['draft']['date'] ?? null) === $input['date'], 'This plan is no longer available.');
                    $s['focus'] = $input['date'];
                    $this->planMessage($s, ($s['draft']['date'] ?? null) === $input['date'] ? $s['draft'] : $s['plans'][$input['date']]);
                    break;
                case 'approval':
                    $s['approval'] = $input['mode'];
                    break;
                case 'fresh':
                    $conversation = $this->conversation();
                    $s['conversations'][] = $conversation;
                    $s['active'] = $conversation['id'];
                    $s['focus'] = null;
                    break;
                case 'resume':
                    $this->require(collect($s['conversations'])->contains('id', $input['id']), 'Conversation not found.');
                    $s['active'] = $input['id'];
                    $s['focus'] = null;
                    break;
                case 'delete_history':
                    $conversation = $this->conversation();
                    $s['conversations'] = [$conversation];
                    $s['active'] = $conversation['id'];
                    $s['focus'] = null;
                    break;
                case 'cancel_day':
                    $date = $input['date'];
                    $this->require($s['draft'] === null, 'Finish your unfinished changes before cancelling a day.');
                    $this->require(isset($s['plans'][$date]), 'This plan no longer exists.');
                    $s['draft'] = $s['plans'][$date];
                    $s['draft']['slots'] = [];
                    $s['focus'] = $date;
                    $this->commit($s);
                    break;
                case 'approve':
                    $this->require($s['draft'] !== null, 'There are no changes to apply.');
                    $this->require(count($s['draft']['slots']) > 0 || ($input['cancel'] ?? false), 'Confirm cancellation of this day before removing its last activity.');
                    $this->commit($s);
                    break;
                case 'discard':
                    $this->require($s['draft'] !== null, 'There are no changes to discard.');
                    $date = $s['draft']['date'];
                    $s['draft'] = null;
                    $this->message($s, 'assistant', 'Changes discarded. Your confirmed plans are unchanged.');
                    if (isset($s['plans'][$date])) {
                        $this->planMessage($s, $s['plans'][$date]);
                    }
                    break;
                case 'undo':
                    $last = end($s['versions']);
                    $this->require($last && ! ($last['undone'] ?? false) && $s['draft'] === null, 'Apply or discard any draft before undoing the latest change.');
                    $this->require(($s['plans'][$last['date']] ?? null) === $last['after'], 'This plan has changed. The earlier version cannot be restored automatically.');
                    if ($last['before'] === null) {
                        unset($s['plans'][$last['date']]);
                    } else {
                        $s['plans'][$last['date']] = $last['before'];
                    }
                    $s['versions'][array_key_last($s['versions'])]['undone'] = true;
                    $s['focus'] = $last['date'];
                    $this->message($s, 'assistant', 'The last applied change was undone.');
                    if ($last['before']) {
                        $this->planMessage($s, $last['before']);
                    }
                    break;
                default:
                    $displaced = $this->mutate($s, $input);
                    break;
            }
            $row->update(['state' => $s, 'revision' => $row->revision + 1]);
            if (isset($displaced)) {
                app(TasteSignals::class)->swappedAway($user, $displaced);
            }
            if ($action === 'proposal') {
                DB::afterCommit(fn () => app(PrivatePlanCache::class)->forgetHandoff($user, $input['token']));
            }

            return $this->projectState($s) + ['revision' => $row->revision];
        });
    }

    /**
     * @return array<string, mixed>|null the slot a remove or replace took out of the plan
     */
    private function mutate(array &$s, array $input): ?array
    {
        $displaced = null;
        $date = in_array($input['action'], ['appointment', 'add'])
            ? ($input['date'] ?? $s['focus'] ?? $s['draft']['date'] ?? now('Europe/Berlin')->format('Y-m-d'))
            : ($s['focus'] ?? $input['date'] ?? $s['draft']['date'] ?? now('Europe/Berlin')->format('Y-m-d'));
        $this->require($s['draft'] === null || $s['draft']['date'] === $date, 'Finish your other draft before editing this day.');
        $plan = $s['draft'] ?? $s['plans'][$date] ?? ['date' => $date, 'slots' => [], 'constraints' => ['window_start' => "{$date}T08:00:00", 'window_end' => "{$date}T23:59:00"]];
        $plan = app(PlaceIdentity::class)->normalizePlan($plan);
        if (isset($input['id'])) {
            $input['id'] = app(PlaceIdentity::class)->candidateIds([$input['id']])[0];
        }
        $slots = $plan['slots'];
        $index = isset($input['id']) ? collect($slots)->search(fn ($slot) => $slot['id'] === $input['id']) : false;
        if (in_array($input['action'], ['edit', 'remove', 'replace'])) {
            $this->require($index !== false, 'That activity is no longer in the current plan.');
        }
        switch ($input['action']) {
            case 'edit':
                if (isset($input['start'])) {
                    $this->require(! in_array($slots[$index]['type'], ['event', 'appointment']), 'This activity has a fixed time.');
                    $slots[$index] = $this->setTime($slots[$index], $date, $input['start'], $input['end']);
                }
                if (isset($input['name'])) {
                    $slots[$index]['name'] = $input['name'];
                }
                if (array_key_exists('location', $input)) {
                    $this->require($slots[$index]['type'] === 'personal', 'Only a personal plan location can be edited.');
                    $slots[$index]['location'] = $input['location'];
                    unset($slots[$index]['lat'], $slots[$index]['lng'], $slots[$index]['travel_min_from_previous']);
                }
                break;
            case 'remove':
                $displaced = $slots[$index];
                array_splice($slots, $index, 1);
                break;
            case 'appointment':
                $slots[] = $this->setTime(['id' => 'personal:'.Str::uuid(), 'type' => 'personal', 'name' => $input['name'], 'location' => $input['location'] ?? null, 'category' => 'appointment', 'swappable' => false, 'subtitle' => 'Personal plan'], $date, $input['start'], $input['end']);
                break;
            case 'add':
            case 'replace':
                $day = CarbonImmutable::parse($date, 'Europe/Berlin');
                [$originLat, $originLng] = $plan['origin'] ?? [50.9375, 6.9603];
                $candidate = $this->catalogue->byIds([$input['candidate']], $day, $originLat, $originLng)[0] ?? null;
                $this->require($candidate !== null, 'This place or event is unavailable for that day.');
                $this->require(! collect($slots)->contains('id', $candidate->id), 'This activity is already in your plan.');
                if ($input['action'] === 'replace') {
                    $this->require($slots[$index]['swappable'] ?? false, 'This activity cannot be swapped.');
                    $start = $slots[$index]['start_time'];
                    $end = $slots[$index]['end_time'];
                    $this->require($candidate->fixedStart === null || $candidate->fixedStart->format('H:i') === $start, 'This event has a different fixed time. Add it separately.');
                } else {
                    $start = $input['start'] ?? $candidate->fixedStart?->format('H:i') ?? $this->availableTime($candidate, $slots, $day, Constraints::fromArray($plan['constraints']));
                    $end = $input['end'] ?? CarbonImmutable::parse("{$date} {$start}", 'Europe/Berlin')->addMinutes($candidate->typicalDurationMin)->format('H:i');
                }
                $startAt = CarbonImmutable::parse("{$date} {$start}", 'Europe/Berlin');
                $endAt = CarbonImmutable::parse("{$date} {$end}", 'Europe/Berlin');
                if ($candidate->fixedStart !== null) {
                    $this->require($candidate->fixedStart->equalTo($startAt), 'The event time cannot be changed.');
                    $endAt = $candidate->fixedStart->addMinutes($candidate->typicalDurationMin);
                }
                $this->require(app(PlanCatalogue::class)->available($candidate, Constraints::fromArray($plan['constraints']), $startAt, $endAt), 'This place or event no longer fits the plan requirements or opening hours. Choose another time or item.');
                $slot = (new PlanSlot($candidate, $startAt, $endAt, 0))->toArray();
                if ($input['action'] === 'replace') {
                    $displaced = $slots[$index];
                    $slots[$index] = $slot;
                } else {
                    $slots[] = $slot;
                }
                break;
            case 'date':
                $this->require(! isset($s['plans'][$input['date']]) || $input['date'] === $date, 'There is already a plan on that day. Open it from My Plans.');
                $this->require(! isset($s['plans'][$date]), 'To move a confirmed day, create a separate plan for the new date.');
                foreach ($slots as &$slot) {
                    $this->require(! in_array($slot['type'], ['event', 'appointment']), 'Fixed events and appointments cannot be moved to another date.');
                    $slot = $this->setTime($slot, $input['date'], $slot['start_time'], $slot['end_time']);
                }
                unset($slot);
                $plan['date'] = $input['date'];
                $plan['constraints']['window_start'] = $input['date'].'T08:00:00';
                $plan['constraints']['window_end'] = $input['date'].'T23:59:00';
                break;
        }
        $plan['slots'] = array_values($slots);
        $plan['feasibility_checked'] = false;
        $this->validatePlan($plan);
        $s['draft'] = $plan;
        $s['focus'] = $plan['date'];
        $this->planMessage($s, $plan, false);
        if ($s['approval'] === 'routine' && count($slots) > 0) {
            $this->commit($s);
        }

        return $displaced;
    }

    private function availableTime(Candidate $candidate, array $slots, CarbonImmutable $day, Constraints $constraints): string
    {
        $earliest = $constraints->windowStart;
        $now = CarbonImmutable::now('Europe/Berlin');
        if ($day->isSameDay($now)) {
            $earliest = $earliest->max($now->addMinute()->startOfMinute());
        }
        $latest = $constraints->windowEnd->min($day->endOfDay());
        // Prefer the familiar morning start for future days, but also use an
        // earlier valid gap when the person's chosen window requires it.
        $preferred = $earliest->max($day->setTime(10, 0));
        usort($slots, fn ($a, $b) => strcmp($a['start_at'], $b['start_at']));
        foreach ($preferred->equalTo($earliest) ? [$earliest] : [$preferred, $earliest] as $start) {
            foreach ([...$slots, null] as $slot) {
                $end = $slot === null ? $latest : CarbonImmutable::parse($slot['start_at'])->min($latest);
                if ($end->greaterThan($start)) {
                    $visit = $candidate->nextVisitStart($start, $end);
                    if ($visit !== null) {
                        return $visit->format('H:i');
                    }
                }
                if ($slot !== null) {
                    $start = $start->max(CarbonImmutable::parse($slot['end_at']));
                }
            }
        }
        $this->require(false, 'There is no free time for this activity today. Adjust an existing activity or choose another day.');

        return $earliest->format('H:i');
    }

    private function setTime(array $slot, string $date, string $start, string $end): array
    {
        $slot['start_time'] = $start;
        $slot['end_time'] = $end;
        $slot['start_at'] = CarbonImmutable::parse("{$date} {$start}", 'Europe/Berlin')->toIso8601String();
        $slot['end_at'] = CarbonImmutable::parse("{$date} {$end}", 'Europe/Berlin')->toIso8601String();
        $slot['travel_min_from_previous'] = 0;

        return $slot;
    }

    private function validatePlan(array &$plan): void
    {
        $this->require(count($plan['slots']) <= 30, 'A day can contain at most 30 activities.');
        usort($plan['slots'], fn ($a, $b) => strcmp($a['start_at'], $b['start_at']));
        $previous = null;
        foreach ($plan['slots'] as $slot) {
            $start = CarbonImmutable::parse($slot['start_at'])->setTimezone('Europe/Berlin');
            $end = CarbonImmutable::parse($slot['end_at'])->setTimezone('Europe/Berlin');
            $this->require($end > $start && $start->format('Y-m-d') === $plan['date'] && $end->format('Y-m-d') === $plan['date'], 'Choose an end time after the start, within the same day.');
            $this->require($previous === null || $start >= CarbonImmutable::parse($previous['end_at']) || $this->recordedOverlap($previous, $slot), ($previous['name'] ?? 'An activity').' overlaps '.$slot['name'].'. Choose a free time.');
            $previous = $slot;
        }
    }

    /**
     * Recorded appointments are facts from the person's records, not choices:
     * two that clash stay with the plan's conflict warning, and an appointment
     * of unknown length may run into what follows.
     */
    private function recordedOverlap(array $previous, array $slot): bool
    {
        $recorded = fn (array $item): bool => ($item['is_appointment'] ?? false) === true;

        return ($recorded($previous) && $recorded($slot))
            || ($recorded($previous) && ($previous['duration_known'] ?? true) === false)
            || ($slot['may_overlap_previous'] ?? false) === true;
    }

    private function commit(array &$s): void
    {
        $plan = app(PlaceIdentity::class)->normalizePlan($s['draft']);
        $this->validatePlan($plan);
        app(PlanCatalogue::class)->assertChangesAvailable($plan, $s['plans'][$plan['date']] ?? null);
        $date = $plan['date'];
        $before = $s['plans'][$date] ?? null;
        $after = $plan['slots'] === [] ? null : $plan;
        $s['versions'][] = ['id' => (string) Str::uuid(), 'date' => $date, 'before' => $before, 'after' => $after, 'created_at' => now()->toIso8601String(), 'undone' => false];
        if ($after) {
            $s['plans'][$date] = $after;
        } else {
            unset($s['plans'][$date]);
        }
        $s['draft'] = null;
        $this->planMessage($s, $plan, false);
        $this->message($s, 'assistant', $after ? 'Your plan is saved. You can keep editing here. Undo is available.' : 'This day was cancelled. Undo is available.');
    }

    private function planMessage(array &$s, array $plan, bool $new = true): void
    {
        foreach ($s['conversations'] as &$conversation) {
            if ($conversation['id'] !== $s['active']) {
                continue;
            }
            if (! $new) {
                for ($i = count($conversation['messages']) - 1; $i >= 0; $i--) {
                    if (($conversation['messages'][$i]['kind'] ?? '') === 'plan' && $conversation['messages'][$i]['plan']['date'] === $plan['date']) {
                        $conversation['messages'][$i]['plan'] = $plan;

                        return;
                    }
                }
            }
        }
        unset($conversation);
        $this->message($s, 'assistant', '', ['kind' => 'plan', 'plan' => $plan]);
    }

    private function message(array &$s, string $role, string $text, array $extra = []): void
    {
        foreach ($s['conversations'] as &$conversation) {
            if ($conversation['id'] !== $s['active']) {
                continue;
            }
            $this->require(count($conversation['messages']) < 2000, 'This conversation is full. Start fresh to continue; its history stays available.');
            $conversation['messages'][] = ['id' => (string) Str::uuid(), 'role' => $role, 'text' => $text, 'created_at' => now()->toIso8601String()] + $extra;
            if ($role === 'user' && $conversation['title'] === 'New conversation') {
                $conversation['title'] = Str::limit($text, 64);
            }
        }
    }

    private function require(bool $condition, string $message): void
    {
        if (! $condition) {
            throw ValidationException::withMessages(['action' => $message]);
        }
    }
}

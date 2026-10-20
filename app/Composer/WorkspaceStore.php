<?php

namespace App\Composer;

use App\Models\ComposerWorkspace;
use App\Models\User;
use App\Places\PlaceIdentity;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Server-owned plan commands. Every change goes straight into the plan with
 * its own Undo; one that can't go in (a clash, a time outside listed hours)
 * changes nothing and says why (a 422 with its reason). Conversation text
 * never changes a plan by itself: only these structured, validated commands do.
 */
class WorkspaceStore
{
    /** Undo stays available for this many of the newest changes. */
    public const CHANGE_LIMIT = 50;

    public function __construct(private CandidateRepository $catalogue, private WorkspaceUpgrade $upgrade) {}

    public function read(User $user): array
    {
        $row = ComposerWorkspace::query()->where('user_id', $user->id)->first();

        return $this->projectState($this->state($row)) + ['revision' => $row?->revision ?? 0];
    }

    /** Today's Save puts the composed day straight into the plans, like any change. */
    public function saveConfirmed(User $user, array $plan): void
    {
        DB::transaction(function () use ($user, $plan): void {
            User::query()->whereKey($user->id)->lockForUpdate()->firstOrFail();
            $row = ComposerWorkspace::query()->firstOrCreate(['user_id' => $user->id], ['state' => $this->initial()]);
            $s = $this->state($row);
            $date = CarbonImmutable::parse($plan['constraints']['window_start'])->setTimezone('Europe/Berlin')->format('Y-m-d');
            try {
                $this->apply($s, $date, $plan, 'Planned '.$this->weekday($date));
            } catch (WorkspaceRefusal $refusal) {
                throw ValidationException::withMessages(['action' => $refusal->getMessage()]);
            }
            $row->update(['state' => $s, 'revision' => $row->revision + 1]);
        });
    }

    /**
     * @return array<string, mixed> the projected workspace, with `refusal` set when the change could not go in
     */
    public function command(User $user, array $input): array
    {
        return DB::transaction(function () use ($user, $input): array {
            User::query()->whereKey($user->id)->lockForUpdate()->firstOrFail();
            $row = ComposerWorkspace::query()->firstOrCreate(['user_id' => $user->id], ['state' => $this->initial()]);
            abort_if($row->revision !== $input['revision'], 409, 'Your plan changed in another tab. The latest version has been loaded; review it before trying again.');
            $s = $this->state($row);
            try {
                $displaced = $this->run($s, $user, $input);
            } catch (WorkspaceRefusal $error) {
                // A change that can't go in changes nothing, not even the revision;
                // the reply says why, and the workspace comes back as it was.
                return $this->projectState($this->state($row)) + ['revision' => $row->revision,
                    'refusal' => ['code' => $error->reason, 'message' => $error->getMessage()]];
            }
            $row->update(['state' => $s, 'revision' => $row->revision + 1]);
            if ($displaced !== null) {
                app(TasteSignals::class)->swappedAway($user, $displaced);
            }
            if ($input['action'] === 'proposal') {
                DB::afterCommit(fn () => Cache::forget("composer:handoff:{$user->id}:{$input['token']}"));
            }

            return $this->projectState($s) + ['revision' => $row->revision, 'refusal' => null];
        });
    }

    /** @return array<string, mixed>|null the slot a remove or replace took out of the plan */
    private function run(array &$s, User $user, array $input): ?array
    {
        switch ($input['action']) {
            case 'message':
                $this->message($s, 'user', trim($input['text']));

                return null;
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

                return null;
            case 'proposal':
                $plan = Cache::get("composer:handoff:{$user->id}:{$input['token']}");
                $this->require(is_array($plan), 'expired', 'This suggestion expired. Please ask again.');
                $date = CarbonImmutable::parse($plan['constraints']['window_start'])->setTimezone('Europe/Berlin')->format('Y-m-d');
                $this->apply($s, $date, $plan, 'Planned '.$this->weekday($date));

                return null;
            case 'reapply':
                [$i, $j, $message] = $this->earlierDraft($s, $input['id']);
                $this->require($message['reapplied_change'] === null, 'already_reapplied', 'This earlier proposal is already back in your plans.');
                $change = $this->apply($s, $message['date'], $message['plan'], 'Reapplied your earlier proposal');
                $s['conversations'][$i]['messages'][$j]['reapplied_change'] = $change;

                return null;
            case 'open':
                $this->require(isset($s['plans'][$input['date']]), 'not_found', 'This plan is no longer available.');
                $s['focus'] = $input['date'];
                $this->planMessage($s, $input['date'], force: true);

                return null;
            case 'fresh':
                $conversation = $this->conversation();
                $s['conversations'][] = $conversation;
                $s['active'] = $conversation['id'];
                $s['focus'] = null;

                return null;
            case 'resume':
                $this->require(collect($s['conversations'])->contains('id', $input['id']), 'not_found', 'Conversation not found.');
                $s['active'] = $input['id'];
                $s['focus'] = null;

                return null;
            case 'delete_history':
                $conversation = $this->conversation();
                $s['conversations'] = [$conversation];
                $s['active'] = $conversation['id'];
                $s['focus'] = null;

                return null;
            case 'cancel_day':
                $this->require(isset($s['plans'][$input['date']]), 'not_found', 'This plan no longer exists.');
                $this->apply($s, $input['date'], null, 'Cancelled '.$this->weekday($input['date']));

                return null;
            case 'undo':
                $this->undo($s, $input['change'] ?? null);

                return null;
            case 'complete':
            case 'uncomplete':
                // A check mark on a plan, not a plan change: no change row, no
                // Undo entry, no message. Only the account's own row is read.
                $date = $input['date'];
                $this->require(isset($s['plans'][$date]), 'not_in_plan', 'That plan is no longer in your day.');
                $identity = app(PlaceIdentity::class);
                $s['plans'][$date] = $identity->normalizePlan($s['plans'][$date]);
                $id = $identity->candidateIds([$input['id']])[0];
                $index = collect($s['plans'][$date]['slots'])->search(fn ($slot) => $slot['id'] === $id);
                $this->require($index !== false, 'not_in_plan', 'That plan is no longer in your day.');
                if ($input['action'] === 'complete') {
                    $s['plans'][$date]['slots'][$index]['completed_at'] ??= now('Europe/Berlin')->toIso8601String();
                } else {
                    unset($s['plans'][$date]['slots'][$index]['completed_at']);
                }

                return null;
            default:
                return $this->mutate($s, $input);
        }
    }

    /**
     * One change to one day's items, applied directly.
     *
     * @return array<string, mixed>|null the slot a remove or replace took out of the plan
     */
    private function mutate(array &$s, array $input): ?array
    {
        $displaced = null;
        $date = $input['date'] ?? $s['focus'] ?? now('Europe/Berlin')->format('Y-m-d');
        $plan = $s['plans'][$date] ?? ['date' => $date, 'slots' => [], 'constraints' => ['window_start' => "{$date}T08:00:00", 'window_end' => "{$date}T23:59:00"]];
        $plan = app(PlaceIdentity::class)->normalizePlan($plan);
        if (isset($input['id'])) {
            $input['id'] = app(PlaceIdentity::class)->candidateIds([$input['id']])[0];
        }
        $slots = $plan['slots'];
        $index = isset($input['id']) ? collect($slots)->search(fn ($slot) => $slot['id'] === $input['id']) : false;
        if (in_array($input['action'], ['edit', 'remove', 'replace'])) {
            $this->require($index !== false, 'not_in_plan', 'That activity is no longer in this plan.');
        }
        switch ($input['action']) {
            case 'edit':
                $name = $slots[$index]['name'];
                $label = "Changed {$name}";
                if (isset($input['start'])) {
                    $this->require(! in_array($slots[$index]['type'], ['event', 'appointment']), 'fixed_time', "{$name} has a fixed time.");
                    $slots[$index] = $this->setTime($slots[$index], $date, $input['start'], $input['end']);
                    $label = "Moved {$name} to {$input['start']}–{$input['end']}";
                }
                if (isset($input['name'])) {
                    $slots[$index]['name'] = $input['name'];
                }
                if (array_key_exists('location', $input)) {
                    $this->require($slots[$index]['type'] === 'personal', 'not_editable', 'Only a personal plan’s place can be edited.');
                    $slots[$index]['location'] = $input['location'];
                    unset($slots[$index]['lat'], $slots[$index]['lng'], $slots[$index]['travel_min_from_previous']);
                }
                break;
            case 'remove':
                $displaced = $slots[$index];
                $label = "Removed {$displaced['name']}";
                array_splice($slots, $index, 1);
                break;
            case 'appointment':
                $slots[] = $this->setTime(['id' => 'personal:'.Str::uuid(), 'type' => 'personal', 'name' => $input['name'], 'location' => $input['location'] ?? null, 'category' => 'appointment', 'swappable' => false, 'subtitle' => 'Personal plan'], $date, $input['start'], $input['end']);
                $label = "Added {$input['name']}";
                break;
            case 'add':
            case 'replace':
                $day = CarbonImmutable::parse($date, 'Europe/Berlin');
                [$originLat, $originLng] = $plan['origin'] ?? [50.9375, 6.9603];
                $candidate = $this->catalogue->byIds([$input['candidate']], $day, $originLat, $originLng)[0] ?? null;
                $this->require($candidate !== null, 'unavailable', 'This place or event is unavailable for that day.');
                $this->require(! collect($slots)->contains('id', $candidate->id), 'duplicate', "{$candidate->name} is already in your plan.");
                if ($input['action'] === 'replace') {
                    $this->require($slots[$index]['swappable'] ?? false, 'fixed_time', "{$slots[$index]['name']} can’t be swapped.");
                    $start = $slots[$index]['start_time'];
                    $end = $slots[$index]['end_time'];
                    $this->require($candidate->fixedStart === null || $candidate->fixedStart->format('H:i') === $start, 'fixed_time', "{$candidate->name} starts at a different time. Add it separately.");
                } else {
                    $start = $input['start'] ?? $candidate->fixedStart?->format('H:i') ?? $this->availableTime($candidate, $slots, $day, Constraints::fromArray($plan['constraints']));
                    $end = $input['end'] ?? CarbonImmutable::parse("{$date} {$start}", 'Europe/Berlin')->addMinutes($candidate->typicalDurationMin)->format('H:i');
                }
                $startAt = CarbonImmutable::parse("{$date} {$start}", 'Europe/Berlin');
                $endAt = CarbonImmutable::parse("{$date} {$end}", 'Europe/Berlin');
                if ($candidate->fixedStart !== null) {
                    $this->require($candidate->fixedStart->equalTo($startAt), 'fixed_time', "{$candidate->name} has a fixed time.");
                    $endAt = $candidate->fixedStart->addMinutes($candidate->typicalDurationMin);
                }
                $this->require(app(PlanCatalogue::class)->available($candidate, Constraints::fromArray($plan['constraints']), $startAt, $endAt), 'outside_hours', "{$candidate->name} isn’t listed as open then.");
                $slot = (new PlanSlot($candidate, $startAt, $endAt, 0))->toArray();
                if ($input['action'] === 'replace') {
                    $displaced = $slots[$index];
                    $slots[$index] = $slot;
                    $label = "Replaced {$displaced['name']} with {$candidate->name}";
                } else {
                    $slots[] = $slot;
                    $label = "Added {$candidate->name}";
                }
                break;
        }
        $plan['slots'] = array_values($slots);
        $plan['feasibility_checked'] = false;
        $this->apply($s, $date, $plan, $label);

        return $displaced;
    }

    /**
     * Put a day's new version into the plans with its own Undo and reply.
     * A day emptied of everything is cancelled (its Undo brings it back).
     *
     * @return string the change id
     */
    private function apply(array &$s, string $date, ?array $after, string $label): string
    {
        $before = $s['plans'][$date] ?? null;
        if ($after !== null) {
            $after = app(PlaceIdentity::class)->normalizePlan($after);
            $after['date'] = $date;
            $after = $after['slots'] === [] ? null : $after;
        }
        $this->require($before !== null || $after !== null, 'nothing_changed', 'There is nothing in that day to change.');
        if ($after !== null) {
            $this->validatePlan($after);
            try {
                app(PlanCatalogue::class)->assertChangesAvailable($after, $before);
            } catch (ValidationException $error) {
                throw new WorkspaceRefusal('unavailable', collect($error->errors())->flatten()->first());
            }
        }
        $change = [
            'id' => (string) Str::uuid(), 'date' => $date, 'label' => $label, 'before' => $before, 'after' => $after,
            'rows' => PlanChangeRows::between($before, $after), 'created_at' => now()->toIso8601String(), 'undone_at' => null,
        ];
        $s['changes'] = array_slice([...$s['changes'], $change], -self::CHANGE_LIMIT);
        if ($after !== null) {
            $s['plans'][$date] = $after;
        } else {
            unset($s['plans'][$date]);
        }
        $s['focus'] = $date;
        $this->message($s, 'assistant', $this->said($change), ['kind' => 'change', 'change_id' => $change['id'], 'date' => $date, 'rows' => $change['rows']]);
        if ($after !== null) {
            $this->planMessage($s, $date);
        }

        return $change['id'];
    }

    /** Undo one change: the newest, or the one named, while its day is still as that change left it. */
    private function undo(array &$s, ?string $id): void
    {
        $keys = array_keys(array_filter($s['changes'], fn (array $change): bool => $change['undone_at'] === null && ($id === null || $change['id'] === $id)));
        $this->require($keys !== [], 'nothing_to_undo', $id === null ? 'There is no change to undo.' : 'That change can no longer be undone.');
        $key = end($keys);
        $change = $s['changes'][$key];
        $this->require($this->same($s['plans'][$change['date']] ?? null, $change['after']), 'changed_since', 'This day has changed since, so that change can’t be undone on its own. Undo the newer change first.');
        if ($change['before'] === null) {
            unset($s['plans'][$change['date']]);
        } else {
            $s['plans'][$change['date']] = $change['before'];
        }
        $s['changes'][$key]['undone_at'] = now()->toIso8601String();
        $s['focus'] = $change['date'];
        $weekday = $this->weekday($change['date']);
        $text = match (true) {
            $change['after'] === null => "{$weekday}’s plan is back.",
            $change['before'] === null => "{$weekday} is no longer in your plans.",
            default => "Undone: {$change['label']}.",
        };
        $this->message($s, 'assistant', $text, ['kind' => 'undone', 'change_id' => $change['id'], 'date' => $change['date']]);
    }

    /** Plans compared for Undo: a ticked-off item doesn't make the day "changed". */
    private function same(?array $current, ?array $expected): bool
    {
        $canonical = function (?array $plan) use (&$canonical): ?array {
            if ($plan === null) {
                return null;
            }
            foreach ($plan['slots'] ?? [] as $i => $slot) {
                unset($plan['slots'][$i]['completed_at']);
            }

            return $this->canonical($plan);
        };

        return $canonical($current) === $canonical($expected);
    }

    /** Key order is not meaning: stored JSON comes back reordered. */
    private function canonical(array $value): array
    {
        if (! array_is_list($value)) {
            ksort($value);
        }
        foreach ($value as $key => $item) {
            if (is_array($item)) {
                $value[$key] = $this->canonical($item);
            }
        }

        return $value;
    }

    /** One item changed: said as it is now; more: the change's label. */
    private function said(array $change): string
    {
        $weekday = $this->weekday($change['date']);
        if ($change['before'] === null) {
            return "{$weekday} is in your plans.";
        }
        if ($change['after'] === null) {
            return "{$weekday}’s plan is cancelled.";
        }
        $rows = $change['rows'];
        if (count($rows) !== 1) {
            return "{$change['label']}.";
        }
        $row = $rows[0];

        return match ($row['kind']) {
            'moved' => "{$row['title']} is now {$row['to']}.",
            'added' => "{$row['title']} is in your plan, {$row['to']}.",
            'removed' => "{$row['title']} is out of your plan.",
            default => "{$row['title']} is in place of {$row['was']}, {$row['to']}.",
        };
    }

    /** @return array{0: int, 1: int, 2: array<string, mixed>} */
    private function earlierDraft(array $s, string $id): array
    {
        foreach ($s['conversations'] as $i => $conversation) {
            foreach ($conversation['messages'] as $j => $message) {
                if (($message['kind'] ?? null) === 'earlier_draft' && $message['draft_id'] === $id) {
                    return [$i, $j, $message];
                }
            }
        }
        throw new WorkspaceRefusal('not_found', 'This earlier proposal is no longer available.');
    }

    private function state(?ComposerWorkspace $row): array
    {
        return $this->upgrade->toCurrent($row?->state ?? $this->initial());
    }

    private function projectState(array $state): array
    {
        // Undo is judged on the stored plans, before availability is projected onto them.
        $changes = array_map(fn (array $change): array => [
            'id' => $change['id'], 'date' => $change['date'], 'label' => $change['label'], 'rows' => $change['rows'],
            'created_at' => $change['created_at'], 'undone_at' => $change['undone_at'],
            'undoable' => $change['undone_at'] === null && $this->same($state['plans'][$change['date']] ?? null, $change['after']),
        ], $state['changes']);
        $catalogue = app(PlanCatalogue::class);
        foreach ($state['plans'] as $date => $plan) {
            $state['plans'][$date] = $catalogue->project($plan);
        }
        foreach ($state['conversations'] as $i => $conversation) {
            foreach ($conversation['messages'] as $j => $message) {
                $kind = $message['kind'] ?? null;
                if ($kind === 'plan') {
                    // The card is the day as it is: changed in place, or gone once cancelled.
                    $state['conversations'][$i]['messages'][$j]['plan'] = $state['plans'][$message['date']] ?? null;
                } elseif ($kind === 'earlier_draft') {
                    $state['conversations'][$i]['messages'][$j]['plan'] = $catalogue->project($message['plan']);
                }
            }
        }

        return [
            'schema' => $state['schema'],
            'active' => $state['active'],
            'focus' => $state['focus'],
            'plans' => $state['plans'],
            'changes' => $changes,
            'conversations' => $state['conversations'],
        ];
    }

    private function initial(): array
    {
        return ['schema' => WorkspaceUpgrade::SCHEMA, 'active' => 'initial', 'focus' => null, 'plans' => [], 'changes' => [], 'conversations' => [$this->conversation('initial')]];
    }

    private function conversation(?string $id = null): array
    {
        return ['id' => $id ?? (string) Str::uuid(), 'title' => 'New conversation', 'created_at' => now()->toIso8601String(), 'messages' => []];
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
        throw new WorkspaceRefusal('no_free_time', "There’s no free time for {$candidate->name} that day. Move something or choose another day.");
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
        $this->require(count($plan['slots']) <= 30, 'too_many', 'A day can hold at most 30 activities.');
        usort($plan['slots'], fn ($a, $b) => strcmp($a['start_at'], $b['start_at']));
        $previous = null;
        foreach ($plan['slots'] as $slot) {
            $start = CarbonImmutable::parse($slot['start_at'])->setTimezone('Europe/Berlin');
            $end = CarbonImmutable::parse($slot['end_at'])->setTimezone('Europe/Berlin');
            $this->require($end > $start && $start->format('Y-m-d') === $plan['date'] && $end->format('Y-m-d') === $plan['date'], 'invalid_time', 'Choose an end time after the start, within the same day.');
            $this->require($previous === null || $start >= CarbonImmutable::parse($previous['end_at']), 'clash', ($previous['name'] ?? 'An activity').' overlaps '.$slot['name'].'.');
            $previous = $slot;
        }
    }

    /** One plan card per day in a conversation; it shows the day as it is. */
    private function planMessage(array &$s, string $date, bool $force = false): void
    {
        $conversation = collect($s['conversations'])->firstWhere('id', $s['active']);
        $has = collect($conversation['messages'] ?? [])->contains(fn (array $message): bool => ($message['kind'] ?? null) === 'plan' && $message['date'] === $date);
        if ($force || ! $has) {
            $this->message($s, 'assistant', '', ['kind' => 'plan', 'date' => $date]);
        }
    }

    private function message(array &$s, string $role, string $text, array $extra = []): void
    {
        foreach ($s['conversations'] as &$conversation) {
            if ($conversation['id'] !== $s['active']) {
                continue;
            }
            $this->require(count($conversation['messages']) < 2000, 'conversation_full', 'This conversation is full. Start fresh to continue; its history stays available.');
            $conversation['messages'][] = ['id' => (string) Str::uuid(), 'role' => $role, 'text' => $text, 'created_at' => now()->toIso8601String()] + $extra;
            if ($role === 'user' && $conversation['title'] === 'New conversation') {
                $conversation['title'] = Str::limit($text, 64);
            }
        }
    }

    private function weekday(string $date): string
    {
        return CarbonImmutable::parse($date, 'Europe/Berlin')->isoFormat('dddd');
    }

    private function require(bool $condition, string $reason, string $message): void
    {
        if (! $condition) {
            throw new WorkspaceRefusal($reason, $message);
        }
    }
}

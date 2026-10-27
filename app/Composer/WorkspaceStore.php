<?php

namespace App\Composer;

use App\Models\ComposerWorkspace;
use App\Models\User;
use App\Personalisation\SignalAction;
use App\Personalisation\SignalRecorder;
use App\Places\PlaceIdentity;
use Carbon\CarbonImmutable;
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

    /** The most action records kept (Composer's proposals and their Undo), newest last. */
    public const ACTION_LIMIT = 100;

    /** What the conversation says when the page couldn't get an answer to a message. */
    private const FAILED = 'I could not finish that request. Your plan is unchanged. You can retry your message.';

    /** A change Composer makes for an answer says itself in that answer (its `changed` card), not in replies of its own. */
    private bool $quiet = false;

    public function __construct(private CandidateRepository $catalogue, private WorkspaceUpgrade $upgrade) {}

    /**
     * A change to a day Composer makes for one of its answers (ComposerActions): the same
     * edit, refusals and Undo entry as one made in Plans, without a reply of its own.
     *
     * @param  array<string, mixed>  $input  an edit, as `command` takes it (add, remove, replace, cancel_day), or `undo` with its change id
     * @return array{change: ?array<string, mixed>, plan: ?array<string, mixed>} the change made, and its day after it
     *
     * @throws WorkspaceRefusal when it can't go in
     */
    public function change(User $user, array $input): array
    {
        return DB::transaction(function () use ($user, $input): array {
            User::query()->whereKey($user->id)->lockForUpdate()->firstOrFail();
            $row = ComposerWorkspace::query()->firstOrCreate(['user_id' => $user->id], ['state' => $this->initial()]);
            $s = $this->state($row);
            $this->quiet = true;
            try {
                $displaced = $this->run($s, $user, $input);
            } finally {
                $this->quiet = false;
            }
            $row->update(['state' => $s, 'revision' => $row->revision + 1]);
            if ($displaced !== null) {
                app(TasteSignals::class)->swappedAway($user, $displaced);
                app(SignalRecorder::class)->planSlots($user, SignalAction::StopSwappedOut, [$displaced]);
            }
            $change = $input['action'] === 'undo'
                ? collect($s['changes'])->firstWhere('id', $input['change'])
                : collect($s['changes'])->last();

            return ['change' => $change, 'plan' => $change === null ? null : ($s['plans'][$change['date']] ?? null)];
        });
    }

    /** A day's plan as it is now, or null. */
    public function plan(User $user, string $date): ?array
    {
        return $this->state(ComposerWorkspace::query()->where('user_id', $user->id)->first())['plans'][$date] ?? null;
    }

    /**
     * One of Composer's action records (a proposal, a change made and its Undo), by token;
     * never shown to the page as it is (projectState leaves it out).
     *
     * @return array<string, mixed>|null
     */
    public function action(User $user, string $token): ?array
    {
        $record = $this->state(ComposerWorkspace::query()->where('user_id', $user->id)->first())['actions'][$token] ?? null;

        return is_array($record) ? $record : null;
    }

    /**
     * The cards still asking them which answer is theirs (ComposerActions::choose), by token,
     * newest first: only those an answer shows (one whose answer didn't hold was never seen).
     *
     * @return array<string, array<string, mixed>>
     */
    public function asking(User $user): array
    {
        $s = $this->state(ComposerWorkspace::query()->where('user_id', $user->id)->first());
        $shown = collect($s['conversations'] ?? [])->flatMap(fn (array $c): array => $c['messages'] ?? [])
            ->flatMap(fn (array $m): array => array_column($m['blocks'] ?? [], 'id'))->all();

        return array_reverse(array_filter($s['actions'] ?? [], fn (mixed $record, string $token): bool => is_array($record) && ($record['state'] ?? null) === 'asked'
            && in_array($token, $shown, true) && CarbonImmutable::parse($record['expires_at'])->isFuture(), ARRAY_FILTER_USE_BOTH), true);
    }

    /**
     * Keeps an action record, and puts its cards, as they are now, wherever an answer shows them
     * (by their id): a proposal becomes done, done becomes undone, in place.
     *
     * @param  array<string, mixed>  $record  with `token` and `blocks`
     * @return array<string, mixed> the projected workspace
     */
    public function keepAction(User $user, array $record): array
    {
        return DB::transaction(function () use ($user, $record): array {
            User::query()->whereKey($user->id)->lockForUpdate()->firstOrFail();
            $row = ComposerWorkspace::query()->firstOrCreate(['user_id' => $user->id], ['state' => $this->initial()]);
            $s = $this->state($row);
            $actions = $s['actions'] ?? [];
            unset($actions[$record['token']]);
            // Spent after 24 hours (owner, 23 Oct 2026): a record past it is no longer kept.
            $actions = array_filter($actions, fn (mixed $kept): bool => is_array($kept) && CarbonImmutable::parse($kept['expires_at'])->isFuture());
            $s['actions'] = array_slice([...$actions, $record['token'] => $record], -self::ACTION_LIMIT, preserve_keys: true);
            // Its cards (the change, and what changed in their plan) are put back as they are now, where the first stood.
            $ours = [$record['token'], "{$record['token']}:plan"];
            foreach ($s['conversations'] as $i => $conversation) {
                foreach ($conversation['messages'] as $j => $message) {
                    if (! collect($message['blocks'] ?? [])->contains(fn (array $block): bool => in_array($block['id'] ?? null, $ours, true))) {
                        continue;
                    }
                    $blocks = [];
                    $placed = false;
                    foreach ($message['blocks'] as $block) {
                        if (! in_array($block['id'] ?? null, $ours, true)) {
                            $blocks[] = $block;
                        } elseif (! $placed) {
                            array_push($blocks, ...$record['blocks']);
                            $placed = true;
                        }
                    }
                    $s['conversations'][$i]['messages'][$j]['blocks'] = $blocks;
                }
            }
            $row->update(['state' => $s, 'revision' => $row->revision + 1]);

            return $this->projectState($s) + ['revision' => $row->revision, 'refusal' => null];
        });
    }

    /**
     * Renames one of the person's conversations; an empty title goes back to the
     * automatic one (its first message, as Composer names it). Null when it isn't theirs.
     *
     * @return array<string, mixed>|null the projected workspace
     */
    public function renameConversation(User $user, string $id, string $title): ?array
    {
        return $this->onConversation($user, $id, function (array &$s, int $i) use ($title): void {
            $first = collect($s['conversations'][$i]['messages'])->firstWhere('role', 'user');
            $title = trim(preg_replace('/\s+/', ' ', $title) ?? '');
            $s['conversations'][$i]['title'] = $title !== ''
                ? Str::limit($title, 64)
                : ($first !== null ? Str::limit($first['text'], 64) : 'New conversation');
        });
    }

    /**
     * Deletes one of the person's conversations for good: its messages, and what Composer
     * kept for it (the action records whose cards it holds, Paperwork's among them, and
     * the plan changes its messages made, with their Undo). Plans stay as they are. When
     * it's the open one, the next one opened is empty and listed nowhere until its first
     * message. Null when it isn't theirs.
     *
     * @return array<string, mixed>|null the projected workspace
     */
    public function deleteConversation(User $user, string $id): ?array
    {
        return $this->onConversation($user, $id, function (array &$s, int $i) use ($user): void {
            $messages = $s['conversations'][$i]['messages'];
            $cards = collect($messages)->flatMap(fn (array $m): array => array_column($m['blocks'] ?? [], 'id'))
                ->map(fn (mixed $card): string => preg_replace('/:plan$/', '', (string) $card) ?? '')->filter()->all();
            $changes = collect($messages)->flatMap(fn (array $m): array => [$m['change_id'] ?? null, $m['reapplied_change'] ?? null])->filter()->all();
            // Its plans waiting to be added (a proposal's hand-off), gone with it.
            $handoffs = collect($messages)->flatMap(fn (array $m): array => array_column($m['blocks'] ?? [], 'token'))->filter(fn (mixed $t): bool => is_string($t))->all();
            DB::afterCommit(fn () => collect($handoffs)->each(fn (string $token) => app(PrivatePlanCache::class)->forgetHandoff($user, $token)));
            if (isset($s['actions'])) {
                $s['actions'] = array_filter($s['actions'], fn (mixed $record, string $token): bool => ! in_array($token, $cards, true), ARRAY_FILTER_USE_BOTH);
            }
            $s['changes'] = array_values(array_filter($s['changes'], fn (array $change): bool => ! in_array($change['id'], $changes, true)));
            $wasOpen = $s['conversations'][$i]['id'] === $s['active'];
            array_splice($s['conversations'], $i, 1);
            if ($wasOpen) {
                // An empty one to open (an existing one, else a new one): never listed, so no chat appears.
                $empty = collect($s['conversations'])->first(fn (array $c): bool => $c['messages'] === []);
                if ($empty === null) {
                    $empty = $this->conversation();
                    $s['conversations'][] = $empty;
                }
                $s['active'] = $empty['id'];
                $s['focus'] = null;
            }
        });
    }

    /**
     * @param  callable(array<string, mixed>&, int): void  $change
     * @return array<string, mixed>|null
     */
    private function onConversation(User $user, string $id, callable $change): ?array
    {
        return DB::transaction(function () use ($user, $id, $change): ?array {
            $row = ComposerWorkspace::query()->where('user_id', $user->id)->lockForUpdate()->first();
            if ($row === null) {
                return null;
            }
            $s = $this->state($row);
            $i = collect($s['conversations'])->search(fn (array $c): bool => $c['id'] === $id);
            if ($i === false) {
                return null;
            }
            $change($s, $i);
            $row->update(['state' => $s, 'revision' => $row->revision + 1]);

            return $this->projectState($s) + ['revision' => $row->revision];
        });
    }

    /**
     * Erasure worker: the copies of a person's Paperwork that Composer keeps (its record and
     * change cards, the checklist card, and the proposals and Undo it holds for Paperwork).
     */
    public function purgePaperworkCopies(int $userId): void
    {
        DB::transaction(function () use ($userId): void {
            $row = ComposerWorkspace::query()->where('user_id', $userId)->lockForUpdate()->first();
            if ($row === null) {
                return;
            }
            $s = $this->state($row);
            $was = $s;
            if (isset($s['actions'])) {
                $s['actions'] = array_filter($s['actions'], fn (mixed $record): bool => is_array($record) && ($record['domain'] ?? null) !== 'paperwork');
            }
            foreach ($s['conversations'] as $i => $conversation) {
                foreach ($conversation['messages'] as $j => $message) {
                    if (! is_array($message['blocks'] ?? null)) {
                        continue;
                    }
                    $kept = array_values(array_filter($message['blocks'], fn (array $block): bool => ! in_array($block['type'] ?? null, ['record', 'checklist', 'plan_changes'], true)
                        && ($block['area'] ?? $block['domain'] ?? null) !== 'paperwork'));
                    if (count($kept) !== count($message['blocks'])) {
                        // Its words were about that record too.
                        $s['conversations'][$i]['messages'][$j] = [...array_diff_key($message, ['record' => true, 'lead' => true, 'facts' => true]),
                            'blocks' => $kept, 'text' => 'This answer showed your Paperwork record, which has since been deleted.'];
                    }
                }
            }
            if ($s !== $was) {
                $row->update(['state' => $s, 'revision' => $row->revision + 1]);
            }
        });
    }

    public function read(User $user): array
    {
        $row = ComposerWorkspace::query()->where('user_id', $user->id)->first();

        return $this->projectState($this->state($row)) + ['revision' => $row?->revision ?? 0];
    }

    /**
     * The stored workspace, upgraded but not projected: for a reader that needs only what
     * was said and how many stops each day holds (Today's "Pick up where you left off"),
     * not each place's availability now.
     *
     * @return array<string, mixed>
     */
    public function stored(User $user): array
    {
        return $this->state(ComposerWorkspace::query()->where('user_id', $user->id)->first());
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
                app(SignalRecorder::class)->planSlots($user, SignalAction::StopSwappedOut, [$displaced]);
            }
            if ($input['action'] === 'proposal') {
                DB::afterCommit(fn () => app(PrivatePlanCache::class)->forgetHandoff($user, $input['token']));
            }

            return $this->projectState($s) + ['revision' => $row->revision, 'refusal' => null];
        });
    }

    /**
     * Composer's answer to a message (Assistant\Ask): it goes into the conversation that
     * asked, even if another was opened meanwhile, and a plan it showed is added to the
     * person's plans after it, with its own Undo. A plan that can't go in says why there.
     *
     * @param  array{text: string, extra: array<string, mixed>}  $answer
     * @return array<string, mixed> the projected workspace
     */
    public function answer(User $user, string $conversation, array $answer, ?string $token): array
    {
        return DB::transaction(function () use ($user, $conversation, $answer, $token): array {
            User::query()->whereKey($user->id)->lockForUpdate()->firstOrFail();
            $row = ComposerWorkspace::query()->firstOrCreate(['user_id' => $user->id], ['state' => $this->initial()]);
            $s = $this->state($row);
            $active = $s['active'];
            $s['active'] = collect($s['conversations'])->contains('id', $conversation) ? $conversation : $active;
            $this->message($s, 'assistant', $answer['text'], $this->withoutRepeatedFacts($s, $answer['extra']));
            if ($token !== null) {
                try {
                    $this->run($s, $user, ['action' => 'proposal', 'token' => $token]);
                    DB::afterCommit(fn () => app(PrivatePlanCache::class)->forgetHandoff($user, $token));
                } catch (WorkspaceRefusal $refusal) {
                    $this->message($s, 'assistant', $refusal->getMessage(), ['kind' => 'refusal']);
                }
            }
            $s['active'] = $active;
            $row->update(['state' => $s, 'revision' => $row->revision + 1]);

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
                // The page couldn't get an answer (Ask failed or didn't respond): the conversation says so.
                $this->message($s, 'assistant', self::FAILED, ['kind' => 'failed']);

                return null;
            case 'proposal':
                $plan = app(PrivatePlanCache::class)->handoff($user, $input['token']);
                $this->require(is_array($plan), 'expired', 'This suggestion expired. Please ask again.');
                $date = CarbonImmutable::parse($plan['constraints']['window_start'])->setTimezone('Europe/Berlin')->format('Y-m-d');
                try {
                    $this->apply($s, $date, $plan, 'Planned '.$this->weekday($date));
                } catch (WorkspaceRefusal $refusal) {
                    // A composed plan's times are the planner's, not the person's: said in their terms.
                    throw $refusal->reason === 'invalid_time'
                        ? new WorkspaceRefusal('invalid_time', 'That plan runs past midnight, and a plan holds one day. Try an earlier time or another day.')
                        : $refusal;
                }

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
            case 'redo':
                // Put back what an Undo took out ("Put it back"), while the day is still as that Undo left it.
                $key = collect($s['changes'])->search(fn (array $change): bool => $change['id'] === $input['change']);
                $this->require($key !== false && $s['changes'][$key]['undone_at'] !== null, 'nothing_to_redo', 'That change can no longer be put back.');
                $change = $s['changes'][$key];
                $this->require($this->same($s['plans'][$change['date']] ?? null, $change['before']), 'changed_since', 'This day has changed since, so that can’t be put back on its own.');
                $this->apply($s, $change['date'], $change['after'], $change['label']);

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
                $this->require(app(PlanCatalogue::class)->available($candidate, Constraints::fromArray($plan['constraints']), $startAt, $endAt), 'outside_hours', $this->closedThen($candidate));
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

    /**
     * Erasure worker: strip Paperwork appointments from every stored plan copy
     * (plans, each change's before and after, earlier proposals) and their names
     * from change rows, labels and replies. Personal plans the person typed
     * themselves stay; they are not Paperwork data.
     */
    public function purgeAppointmentCopies(int $userId): void
    {
        DB::transaction(function () use ($userId): void {
            $row = ComposerWorkspace::query()->where('user_id', $userId)->lockForUpdate()->first();
            if ($row === null) {
                return;
            }
            $s = $this->state($row);
            $isAppointment = fn (array $slot): bool => ($slot['is_appointment'] ?? false) === true || ($slot['type'] ?? null) === 'appointment';
            $copies = [...array_values($s['plans']), ...array_column($s['changes'], 'before'), ...array_column($s['changes'], 'after'),
                ...array_column(array_merge(...array_column($s['conversations'], 'messages')), 'plan')];
            $found = collect($copies)->filter()->flatMap(fn (array $plan) => $plan['slots'] ?? [])->filter($isAppointment);
            $ids = $found->pluck('id')->filter()->all();
            $names = $found->pluck('name')->filter()->unique()->all();
            $mentions = fn (?string $text): bool => $text !== null && collect($names)->contains(fn (string $name) => str_contains($text, $name));
            $strip = fn (?array $plan): ?array => $plan === null ? null : [...$plan, 'slots' => array_values(array_filter($plan['slots'] ?? [], fn (array $slot) => ! $isAppointment($slot)))];
            $rows = fn (array $rows): array => array_values(array_filter($rows, fn (array $row): bool => ! in_array($row['id'], $ids, true)));

            $s['plans'] = array_map($strip, $s['plans']);
            $s['changes'] = array_map(fn (array $change): array => [...$change, 'before' => $strip($change['before']), 'after' => $strip($change['after']),
                'rows' => $rows($change['rows']), 'label' => $mentions($change['label']) ? 'Changed your plan' : $change['label']], $s['changes']);
            foreach ($s['conversations'] as $i => $conversation) {
                foreach ($conversation['messages'] as $j => $message) {
                    if (is_array($message['plan'] ?? null)) {
                        $message['plan'] = $strip($message['plan']);
                    }
                    if (isset($message['rows'])) {
                        $message['rows'] = $rows($message['rows']);
                    }
                    if ($message['role'] === 'assistant' && $mentions($message['text'])) {
                        $message['text'] = 'Your plan changed.';
                    }
                    $s['conversations'][$i]['messages'][$j] = $message;
                }
            }
            if ($s !== $row->state) {
                $row->update(['state' => $s, 'revision' => $row->revision + 1]);
            }
        });
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
        // A day that's over is history: it keeps what it said, without asking each place
        // about a day that's gone (most stored days are past ones).
        $today = CarbonImmutable::now('Europe/Berlin')->format('Y-m-d');
        foreach ($state['plans'] as $date => $plan) {
            $state['plans'][$date] = $date < $today ? $catalogue->settled($plan) : $catalogue->project($plan);
        }
        // Only the open conversation's messages travel; the others are listed by their title,
        // how many times they asked and when they last wrote (owner, 26 Oct 2026). Opening
        // one (resume) brings its messages.
        foreach ($state['conversations'] as $i => $conversation) {
            $state['conversations'][$i] += [
                'asked' => count(array_filter($conversation['messages'], fn (array $message): bool => ($message['role'] ?? null) === 'user')),
                'last_at' => ($conversation['messages'] === [] ? null : end($conversation['messages'])['created_at'] ?? null) ?? $conversation['created_at'],
            ];
            if ($conversation['id'] !== $state['active']) {
                $state['conversations'][$i]['messages'] = [];

                continue;
            }
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
            $this->require($previous === null || $start >= CarbonImmutable::parse($previous['end_at']) || $this->recordedOverlap($previous, $slot), 'clash', ($previous['name'] ?? 'An activity').' overlaps '.$slot['name'].'.');
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

    /** One plan card per day in a conversation; it shows the day as it is. */
    private function planMessage(array &$s, string $date, bool $force = false): void
    {
        $conversation = collect($s['conversations'])->firstWhere('id', $s['active']);
        $has = collect($conversation['messages'] ?? [])->contains(fn (array $message): bool => ($message['kind'] ?? null) === 'plan' && $message['date'] === $date);
        if ($force || ! $has) {
            $this->message($s, 'assistant', '', ['kind' => 'plan', 'date' => $date]);
        }
    }

    /**
     * An answer's facts less those this conversation already showed (owner's preview test,
     * 22 Oct 2026: "Visa expires" under every answer). Each shows once per conversation.
     *
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>
     */
    private function withoutRepeatedFacts(array $s, array $extra): array
    {
        $shown = collect(collect($s['conversations'])->firstWhere('id', $s['active'])['messages'] ?? [])
            ->filter(fn (array $m): bool => $m['role'] === 'assistant' && is_array($m['facts'] ?? null))
            ->flatMap(fn (array $m): array => array_map(fn (array $f): string => "{$f['label']}|{$f['value']}", $m['facts']))->flip();
        $blocks = collect($extra['blocks'] ?? [])->map(function (array $block) use ($shown): ?array {
            if (! is_array($block['facts'] ?? null)) {
                return $block;
            }
            $facts = array_values(array_filter($block['facts'], fn (array $f): bool => ! $shown->has("{$f['label']}|{$f['value']}")));

            // A card of facts alone goes once they've all been shown, and its "From:" link with it.
            return $facts === [] && $block['type'] === 'facts' ? null : array_filter([...$block, 'facts' => $facts ?: null], fn ($v) => $v !== null);
        })->filter()->values()->all();
        $facts = collect($blocks)->flatMap(fn (array $b): array => $b['facts'] ?? [])->values()->all();
        $sources = collect($blocks)->flatMap(fn (array $b): array => $b['sources'] ?? [])->unique('href')->values()->all();

        return array_filter([...$extra, 'blocks' => $blocks ?: null, 'facts' => $facts ?: null, 'sources' => $sources ?: null], fn ($v) => $v !== null);
    }

    private function message(array &$s, string $role, string $text, array $extra = []): void
    {
        if ($this->quiet && $role === 'assistant') {
            return;
        }
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

    /** Why a place can't go in then: its listed hours, or, when it lists none, the usual hours it's planned within. */
    private function closedThen(Candidate $candidate): string
    {
        if (! $candidate->hoursAssumed) {
            return "{$candidate->name} isn’t listed as open then.";
        }

        return $candidate->opensAt !== null && $candidate->closesAt !== null
            ? "Opening hours aren’t listed for {$candidate->name}, so Composer plans it between {$candidate->opensAt->format('H:i')} and {$candidate->closesAt->format('H:i')}, as is usual for its kind. Choose a time then."
            : "Opening hours aren’t listed for {$candidate->name}, so Composer can’t plan it for that time.";
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

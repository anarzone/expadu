<?php

namespace App\Personalisation;

use App\Composer\TasteSignals;
use App\Enums\SpotFeedbackState;
use App\Jobs\RankToday;
use App\Models\Spot;
use App\Models\User;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Writes the personalisation ledger from the actions that earn each signal.
 * Every method is a no-op unless the person opted in ($user->personalises()).
 * Rows carry a pseudonym, the item, the surface and a coarse context, never
 * coordinates or typed text. Writes land after the action's transaction
 * commits, and a failed write never fails the action.
 */
final class SignalRecorder
{
    public function __construct(private SignalItems $items, private SignalContext $context) {}

    /** One action on one catalogue item, now or for a given time. */
    public function record(User $user, SignalAction $action, SignalSurface $surface, SignalItemType $type, int $id, ?CarbonInterface $at = null, ?string $companions = null): void
    {
        $this->write($user, [compact('action', 'surface', 'type', 'id', 'at', 'companions')]);
    }

    /**
     * A place's standing feedback changed (or was cleared): the previous
     * feedback is withdrawn, live hide included, and the new one recorded.
     */
    /**
     * @param  list<SignalAction>|null  $replaces  which earlier signals this replaces: Been
     *                                             replaces Been, a state replaces a state
     */
    public function placeFeedback(User $user, Spot $spot, ?SpotFeedbackState $state, ?string $rating = null, ?array $replaces = null): void
    {
        if (! $user->personalises()) {
            return;
        }

        $id = (int) ($spot->canonical_spot_id ?? $spot->id);
        $subject = Pseudonym::for($user);
        $replaces ??= match (true) {
            $state === SpotFeedbackState::Been => SignalAction::placeBeen(),
            $state !== null => SignalAction::placeState(),
            default => SignalAction::placeFeedback(),
        };
        $this->afterCommit(fn () => $this->withdraw($subject, SignalItemType::Spot, $id, $replaces));

        $action = match ($state) {
            SpotFeedbackState::Saved => SignalAction::Saved,
            SpotFeedbackState::MoreLikeThis => SignalAction::MoreLikeThis,
            SpotFeedbackState::NotInterested => SignalAction::NotForMe,
            SpotFeedbackState::Been => match ($rating) {
                'up' => SignalAction::BeenLiked,
                'down' => SignalAction::BeenDisliked,
                default => null,
            },
            null => null,
        };

        if ($action !== null) {
            $this->record($user, $action, SignalSurface::PlaceSheet, SignalItemType::Spot, $id);
        }
    }

    /**
     * "Get there" for a catalogue item, from the journey request's optional
     * `get_there` tag ({item: "spot:12"|"event:34", surface}). Journeys are
     * also planned with no tap (Travel's day view), so only a tagged request
     * counts; once per item per day, as the sheet re-plans on every change.
     * A malformed tag is ignored, never a reason to refuse the journey.
     */
    public function getThere(User $user, mixed $tag): void
    {
        if (! is_array($tag) || ! $user->personalises() || ($item = SignalItemType::parse($tag['item'] ?? null)) === null) {
            return;
        }

        [$type, $id] = $item;
        $surface = SignalSurface::tryFrom((string) ($tag['surface'] ?? ''))
            ?? ($type === SignalItemType::Event ? SignalSurface::EventSheet : SignalSurface::PlaceSheet);

        if (Cache::add('signals:get-there:'.Pseudonym::for($user).":{$type->value}:{$id}", true, now()->addDay())) {
            $this->record($user, SignalAction::GetThere, $surface, $type, $id);
        }
    }

    /**
     * A trip started to a destination: the plan it was for when it names a
     * place or event, else the one place that matches the destination.
     *
     * @param  array<string, mixed>  $destination  the validated trip destination (name, lat, lng)
     * @param  array<string, mixed>|null  $target  the Day plan the trip is for (StartTripRequest::target())
     */
    public function tripStarted(User $user, array $destination, ?array $target = null): void
    {
        if (! $user->personalises()) {
            return;
        }

        $item = SignalItemType::parse($target['id'] ?? null);
        if ($item === null) {
            $spot = app(TasteSignals::class)->spotAt((string) $destination['name'], (float) $destination['lat'], (float) $destination['lng']);
            $item = $spot === null ? null : [SignalItemType::Spot, (int) $spot->id];
        }

        if ($item !== null) {
            $this->record($user, SignalAction::TripTaken, SignalSurface::Travel, $item[0], $item[1]);
        }
    }

    /**
     * Composer plan stops, each kept under its own time (the plan's day and
     * daypart). Appointments and personal plans say nothing about taste.
     *
     * @param  list<array<string, mixed>>  $slots  plan slots (PlanSlot::toArray() shape)
     */
    public function planSlots(User $user, SignalAction $action, array $slots, ?string $companions = null): void
    {
        $signals = [];
        foreach ($slots as $slot) {
            if (($item = SignalItemType::parse($slot['id'] ?? null)) === null) {
                continue;
            }
            $at = is_string($slot['start_at'] ?? null) ? rescue(fn () => CarbonImmutable::parse($slot['start_at']), report: false) : null;
            $signals[] = ['action' => $action, 'surface' => SignalSurface::Composer, 'type' => $item[0], 'id' => $item[1], 'at' => $at, 'companions' => $companions];
        }

        $this->write($user, $signals);
    }

    /**
     * Each stop of a plan saved as composed: no swap, no removed pick.
     *
     * @param  array<string, mixed>  $plan  the stored Composer plan
     */
    public function planKept(User $user, array $plan, bool $edited): void
    {
        if ($edited || array_filter($plan['rejected'] ?? []) !== [] || ($plan['excluded'] ?? []) !== []) {
            return;
        }

        $this->planSlots($user, SignalAction::PlanKeptUnedited, $plan['slots'] ?? [], $plan['constraints']['companions'] ?? null);
    }

    /**
     * A validated client batch (RecordSignalsRequest): impressions and
     * detail views. Repeats inside one batch count once.
     *
     * @param  list<array{action: string, surface: string, item_type: string, item_id: int, position?: int|null}>  $signals
     */
    public function fromClient(User $user, array $signals): void
    {
        $unique = [];
        foreach ($signals as $signal) {
            $unique["{$signal['action']}|{$signal['surface']}|{$signal['item_type']}|{$signal['item_id']}"] ??= [
                'action' => SignalAction::from($signal['action']),
                'surface' => SignalSurface::from($signal['surface']),
                'type' => SignalItemType::from($signal['item_type']),
                'id' => (int) $signal['item_id'],
                'position' => isset($signal['position']) ? (int) $signal['position'] : null,
            ];
        }

        $this->write($user, array_values($unique));
    }

    /**
     * A kind of place or event Composer looked up for them (search_places, search_events,
     * suggest_for_day, plan_day), with the Veedel they asked about: never their words, never where
     * they are. Under the second wording only (PersonalisationWording).
     */
    public function topic(User $user, string $kind, ?string $area): void
    {
        $this->interest($user, SignalAction::Searched, SignalSurface::Composer, SignalItemType::Topic, self::key("{$kind}|{$area}"), ['category' => $kind, 'veedel' => $area]);
    }

    /**
     * A KVB stop they picked in Travel (a board opened, a journey to or from it), or a line from one,
     * by KVB's own stop name and line: never a stop near where they are, Home or an address (callers
     * pass only stops they picked). Under the second wording only.
     */
    public function transit(User $user, SignalItemType $type, string $name): void
    {
        $name = trim($name);
        if ($name === '' || ! in_array($type, [SignalItemType::Stop, SignalItemType::Line], true)) {
            return;
        }
        $this->interest($user, SignalAction::LookedUp, SignalSurface::Travel, $type, self::key(mb_strtolower($name)), ['context' => [$type->value => $name]]);
    }

    /**
     * What they did with an item of Today's "Needs you": shown (once a day an item), tapped,
     * dismissed, kept, undone; by its kind and today's id only, never what it said, never Paperwork.
     * Under the second wording only.
     */
    public function need(User $user, SignalAction $action, string $kind, string $id): void
    {
        if ($kind === 'paperwork' || ! preg_match('/^[0-9a-f]{16}$/', $id)) {
            return;
        }
        $item = (int) hexdec(substr($id, 0, 12));
        if ($action === SignalAction::Impression && DB::table('user_signals')->where('subject_key', Pseudonym::for($user))->where('item_type', SignalItemType::Need->value)
            ->where('item_id', $item)->where('action', $action->value)->where('created_at', '>=', now()->startOfDay()->toIso8601String())->exists()) {
            return;
        }
        $this->interest($user, $action, SignalSurface::Today, SignalItemType::Need, $item, ['category' => $kind]);
    }

    /** Erase everything learned about the person: ledger, affinities, hides. */
    public function forget(User|int $user): void
    {
        $subject = Pseudonym::for($user);
        DB::table('user_signals')->where('subject_key', $subject)->delete();
        DB::table('user_affinities')->where('subject_key', $subject)->delete();
        DB::table('user_place_negatives')->where('subject_key', $subject)->delete();
        DB::table('taste_profiles')->where('subject_key', $subject)->delete();
    }

    /**
     * @param  list<array{action: SignalAction, surface: SignalSurface, type: SignalItemType, id: int, position?: int|null, at?: CarbonInterface|null, companions?: string|null}>  $signals
     */
    private function write(User $user, array $signals): void
    {
        if ($signals === [] || ! $user->personalises()) {
            return;
        }

        $subject = Pseudonym::for($user);
        $this->afterCommit(function () use ($subject, $signals): void {
            $ids = ['spot' => [], 'event' => []];
            foreach ($signals as $signal) {
                if (isset($ids[$signal['type']->value])) {
                    $ids[$signal['type']->value][] = $signal['id'];
                }
            }
            $items = $this->items->resolve($ids['spot'], $ids['event']);
            $now = now();

            $rows = [];
            foreach ($signals as $signal) {
                $item = $items["{$signal['type']->value}:{$signal['id']}"] ?? null;
                if ($item === null) {
                    continue; // only real catalogue items
                }
                $rows[] = [
                    'subject_key' => $subject,
                    'action' => $signal['action']->value,
                    'surface' => $signal['surface']->value,
                    ...$item,
                    'position' => $signal['position'] ?? null,
                    'context' => json_encode($this->context->at($signal['at'] ?? null, $signal['companions'] ?? null), JSON_THROW_ON_ERROR),
                    'created_at' => $now->toIso8601String(),
                ];
            }
            if ($rows === []) {
                return;
            }

            DB::table('user_signals')->insert($rows);
            foreach ($rows as $row) {
                $days = SignalAction::from($row['action'])->hideDays();
                if ($days !== null) {
                    $this->hide($subject, $row['item_type'], $row['item_id'], $now->addDays($days), (float) config("personalisation.actions.{$row['action']}.weight", 0));
                }
            }
        });
    }

    /**
     * One row of what they looked at that isn't a catalogue item, under the second wording only.
     *
     * @param  array{category?: ?string, veedel?: ?string, context?: array<string, string>}  $what
     */
    private function interest(User $user, SignalAction $action, SignalSurface $surface, SignalItemType $type, int $id, array $what): void
    {
        if (! PersonalisationWording::covers($user, 2)) {
            return;
        }
        $subject = Pseudonym::for($user);
        $userId = $user->id;
        $this->afterCommit(function () use ($subject, $userId, $action, $surface, $type, $id, $what): void {
            DB::table('user_signals')->insert([
                'subject_key' => $subject, 'action' => $action->value, 'surface' => $surface->value, 'item_type' => $type->value, 'item_id' => $id,
                'position' => null, 'category' => isset($what['category']) ? mb_substr((string) $what['category'], 0, 64) : null,
                'veedel' => isset($what['veedel']) ? mb_substr((string) $what['veedel'], 0, 100) : null, 'price_band' => null,
                'context' => json_encode([...$this->context->at(null, null), ...($what['context'] ?? [])], JSON_THROW_ON_ERROR),
                'created_at' => now()->toIso8601String(),
            ]);
            // What they look at changes how Today is ordered (TodayRank).
            RankToday::soon($userId);
        });
    }

    /** A stable id for something that isn't a catalogue item: its words, hashed. */
    private static function key(string $words): int
    {
        return (int) hexdec(substr(hash('sha256', $words), 0, 12));
    }

    /** Hides apply as the person acts; the nightly run only refreshes them. */
    private function hide(string $subject, string $type, int $id, CarbonInterface $until, float $weight): void
    {
        DB::statement(
            'insert into user_place_negatives (subject_key, item_type, item_id, context, score, hidden_until, signals_count, updated_at)
             values (?, ?, ?, ?, ?, ?, 1, ?)
             on conflict (subject_key, item_type, item_id, context) do update
             set hidden_until = greatest(user_place_negatives.hidden_until, excluded.hidden_until), updated_at = excluded.updated_at',
            [$subject, $type, $id, 'all', $weight, $until->toIso8601String(), now()->toIso8601String()],
        );
    }

    /**
     * Remove a place's earlier feedback from the ledger and recompute its live
     * hide from what is left (a "Not now" from elsewhere still hides).
     *
     * @param  list<SignalAction>  $actions
     */
    private function withdraw(string $subject, SignalItemType $type, int $id, array $actions): void
    {
        DB::table('user_signals')->where('subject_key', $subject)->where('item_type', $type->value)->where('item_id', $id)
            ->whereIn('action', array_map(fn (SignalAction $action) => $action->value, $actions))->delete();

        $until = null;
        $remaining = DB::table('user_signals')->where('subject_key', $subject)->where('item_type', $type->value)->where('item_id', $id)
            ->get(['action', 'created_at']);
        foreach ($remaining as $row) {
            $days = SignalAction::tryFrom($row->action)?->hideDays();
            if ($days !== null) {
                $candidate = CarbonImmutable::parse($row->created_at)->addDays($days);
                $until = $until === null || $candidate->greaterThan($until) ? $candidate : $until;
            }
        }

        DB::table('user_place_negatives')->where('subject_key', $subject)->where('item_type', $type->value)->where('item_id', $id)
            ->where('context', 'all')->update(['hidden_until' => $until?->toIso8601String()]);
    }

    private function afterCommit(\Closure $write): void
    {
        DB::afterCommit(function () use ($write): void {
            try {
                $write();
            } catch (Throwable $error) {
                // Personalisation never fails the person's action. No payload logged.
                Log::warning('Signal ledger write failed.', ['error_type' => $error::class]);
            }
        });
    }
}

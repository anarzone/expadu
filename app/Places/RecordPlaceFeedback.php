<?php

namespace App\Places;

use App\Enums\SpotFeedbackState;
use App\Models\Spot;
use App\Models\SpotFeedback;
use App\Models\User;
use App\Personalisation\SignalAction;
use App\Personalisation\SignalRecorder;
use App\Services\EventTrackingService;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;

/**
 * The person's standing relationship to a place: the one-of choice (more like this,
 * saved, not interested) and, apart from it, Been there (`been_at`, `been_rating`), so a
 * place can be saved and visited at once and each clears on its own. Each records the
 * SpotFeedback row AND emits a ranking signal as a user_event so IntentWeights tunes
 * the feed; a signal withdraws only its own kind. The place feedback and Been endpoints
 * and Composer's place_feedback all record through here.
 */
final class RecordPlaceFeedback
{
    public function __construct(private EventTrackingService $tracking) {}

    /**
     * The choice, or Been for older clients (it never touches the choice); `clear` clears only the choice.
     *
     * @param  string  $action  more_like_this, saved, not_interested, clear, or been
     * @return array{state: ?string, rating: null, been: bool, been_rating: ?string}
     */
    public function execute(User $user, Spot $spot, string $action, ?string $rating = null): array
    {
        if ($action === 'been') {
            return $this->been($user, $spot, $rating);
        }

        return app(PlaceIdentity::class)->withCanonicalLock($spot->id, function (Spot $spot) use ($user, $action): array {
            if ($action === 'clear') {
                $this->family($user->id, $spot)->update(['state' => null, 'rating' => null]);
                $this->deleteEmpty($user->id, $spot);
                app(SignalRecorder::class)->placeFeedback($user, $spot, null, replaces: SignalAction::placeState());

                return $this->payload($user->id, $spot);
            }

            $state = SpotFeedbackState::from($action);
            $feedback = $this->row($user->id, $spot);
            $feedback->fill(['state' => $state, 'rating' => null])->save();
            $feedback->touch();
            app(SignalRecorder::class)->placeFeedback($user, $spot, $state);
            $this->track($user, $spot, $this->signalFor($state));

            return $this->payload($user->id, $spot);
        });
    }

    /**
     * Been there, with how it was (up/down) or none; the first visit's day is kept.
     *
     * @return array{state: ?string, rating: null, been: bool, been_rating: ?string}
     */
    public function been(User $user, Spot $spot, ?string $rating = null): array
    {
        return app(PlaceIdentity::class)->withCanonicalLock($spot->id, function (Spot $spot) use ($user, $rating): array {
            $feedback = $this->row($user->id, $spot);
            $feedback->fill(['been_at' => $feedback->been_at ?? CarbonImmutable::now(), 'been_rating' => $rating])->save();
            app(SignalRecorder::class)->placeFeedback($user, $spot, SpotFeedbackState::Been, $rating);
            // A "been" with no rating just leaves discovery: no taste signal.
            $this->track($user, $spot, match ($rating) {
                'up' => 'spot_been_liked',
                'down' => 'spot_been_disliked',
                default => null,
            });

            return $this->payload($user->id, $spot);
        });
    }

    /**
     * Not been there after all: only Been is cleared, the choice stays.
     *
     * @return array{state: ?string, rating: null, been: bool, been_rating: ?string}
     */
    public function forgetBeen(User $user, Spot $spot): array
    {
        return app(PlaceIdentity::class)->withCanonicalLock($spot->id, function (Spot $spot) use ($user): array {
            $this->family($user->id, $spot)->update(['been_at' => null, 'been_rating' => null]);
            $this->deleteEmpty($user->id, $spot);
            app(SignalRecorder::class)->placeFeedback($user, $spot, null, replaces: SignalAction::placeBeen());

            return $this->payload($user->id, $spot);
        });
    }

    /**
     * Where the person stands with a place now (any of its identities): what Undo puts back.
     *
     * @return array{state: ?string, rating: null, been: bool, been_rating: ?string}
     */
    public function current(User $user, Spot $spot): array
    {
        return $this->payload($user->id, $spot);
    }

    /** @return Builder<SpotFeedback> */
    private function family(int $userId, Spot $spot): Builder
    {
        return SpotFeedback::query()->where('user_id', $userId)->whereIn('spot_id', app(PlaceIdentity::class)->familyIds($spot->id));
    }

    /**
     * The person's one row for this place family, at this id: the family's
     * choice and Been are carried onto it and the other rows go, so both
     * always sit on the same row (effectiveForUser reads one per family).
     */
    private function row(int $userId, Spot $spot): SpotFeedback
    {
        $rows = $this->family($userId, $spot)->orderByDesc('updated_at')->get();
        $row = $rows->firstWhere('spot_id', $spot->id) ?? new SpotFeedback(['user_id' => $userId, 'spot_id' => $spot->id]);
        $chosen = $rows->first(fn (SpotFeedback $r): bool => $r->state !== null);
        $been = $rows->filter(fn (SpotFeedback $r): bool => $r->been())->sortBy('been_at')->first();
        $row->fill([
            'state' => $chosen?->state, 'rating' => null,
            'been_at' => $been?->been_at, 'been_rating' => $been?->been_rating,
        ]);
        $this->family($userId, $spot)->where('spot_id', '!=', $spot->id)->delete();

        return $row;
    }

    /** A row with neither a choice nor Been means nothing. */
    private function deleteEmpty(int $userId, Spot $spot): void
    {
        $this->family($userId, $spot)->whereNull('state')->whereNull('been_at')->delete();
    }

    /** @return array{state: ?string, rating: null, been: bool, been_rating: ?string} */
    private function payload(int $userId, Spot $spot): array
    {
        $rows = $this->family($userId, $spot)->get();
        $state = $rows->first(fn (SpotFeedback $row): bool => $row->state !== null)?->state;
        $been = $rows->first(fn (SpotFeedback $row): bool => $row->been());

        // `rating` stays for older clients; Been's rating is `been_rating` now.
        return ['state' => $state?->value, 'rating' => null, 'been' => $been !== null, 'been_rating' => $been?->been_rating];
    }

    private function track(User $user, Spot $spot, ?string $eventType): void
    {
        if ($eventType === null) {
            return;
        }
        // Same pipeline as every other intent signal, keyed on category × Veedel (what IntentWeights reads).
        $this->tracking->track($user, $eventType, [
            'category' => $spot->category instanceof \BackedEnum ? $spot->category->value : ($spot->category ? (string) $spot->category : null),
            'veedel' => $spot->veedel,
            'spot_id' => $spot->id,
        ]);
    }

    private function signalFor(SpotFeedbackState $state): ?string
    {
        return match ($state) {
            SpotFeedbackState::MoreLikeThis => 'spot_more_like_this',
            SpotFeedbackState::Saved => 'spot_saved',
            SpotFeedbackState::NotInterested => 'spot_not_interested',
            SpotFeedbackState::Been => null,
        };
    }
}

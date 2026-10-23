<?php

namespace App\Places;

use App\Enums\SpotFeedbackState;
use App\Models\Spot;
use App\Models\SpotFeedback;
use App\Models\User;
use App\Personalisation\SignalRecorder;
use App\Services\EventTrackingService;

/**
 * Records the person's standing relationship to a place (the SpotFeedback row)
 * AND emits a ranking signal as a user_event so IntentWeights tunes the feed.
 * "More like this" / "Save" are forward-looking interest signals — no visit
 * implied; "been" / "not interested" also drop the place from discovery.
 * The place feedback endpoint and Composer's place_feedback both record through here.
 */
final class RecordPlaceFeedback
{
    public function __construct(private EventTrackingService $tracking) {}

    /**
     * @param  string  $action  more_like_this, saved, been, not_interested or clear
     * @return array{state: ?string, rating: ?string}
     */
    public function execute(User $user, Spot $spot, string $action, ?string $rating = null): array
    {
        return app(PlaceIdentity::class)->withCanonicalLock($spot->id, function (Spot $spot) use ($user, $action, $rating): array {
            if ($action === 'clear') {
                SpotFeedback::query()
                    ->where('user_id', $user->id)
                    ->whereIn('spot_id', app(PlaceIdentity::class)->familyIds($spot->id))
                    ->delete();
                app(SignalRecorder::class)->placeFeedback($user, $spot, null);

                return ['state' => null, 'rating' => null];
            }

            $state = SpotFeedbackState::from($action);
            $rating = $state === SpotFeedbackState::Been ? $rating : null;

            $feedback = SpotFeedback::updateOrCreate(
                ['user_id' => $user->id, 'spot_id' => $spot->id],
                ['state' => $state, 'rating' => $rating],
            );
            $feedback->touch();
            app(SignalRecorder::class)->placeFeedback($user, $spot, $state, $rating);

            // Emit the ranking signal through the same pipeline as every other
            // intent signal, keyed on category × Veedel (what IntentWeights reads).
            if ($eventType = $this->signalFor($state, $rating)) {
                $this->tracking->track($user, $eventType, [
                    'category' => $this->categoryValue($spot),
                    'veedel' => $spot->veedel,
                    'spot_id' => $spot->id,
                ]);
            }

            return ['state' => $state->value, 'rating' => $rating];
        });
    }

    /** The person's current feedback on a place (any of its identities), or null. */
    public function current(User $user, Spot $spot): ?array
    {
        $row = SpotFeedback::query()->where('user_id', $user->id)
            ->whereIn('spot_id', app(PlaceIdentity::class)->familyIds($spot->id))
            ->latest('updated_at')->first();

        return $row === null ? null : ['state' => $row->state instanceof SpotFeedbackState ? $row->state->value : (string) $row->state, 'rating' => $row->rating];
    }

    /**
     * The user_event type for this feedback, or null when there's no taste
     * signal to record (a "been" with no rating just leaves discovery).
     */
    private function signalFor(SpotFeedbackState $state, ?string $rating): ?string
    {
        return match ($state) {
            SpotFeedbackState::MoreLikeThis => 'spot_more_like_this',
            SpotFeedbackState::Saved => 'spot_saved',
            SpotFeedbackState::NotInterested => 'spot_not_interested',
            SpotFeedbackState::Been => match ($rating) {
                'up' => 'spot_been_liked',
                'down' => 'spot_been_disliked',
                default => null,
            },
        };
    }

    private function categoryValue(Spot $spot): ?string
    {
        if ($spot->category instanceof \BackedEnum) {
            return $spot->category->value;
        }

        return $spot->category ? (string) $spot->category : null;
    }
}

<?php

namespace App\Http\Controllers\Api;

use App\Enums\SpotFeedbackState;
use App\Http\Controllers\Controller;
use App\Models\Spot;
use App\Models\SpotFeedback;
use App\Services\EventTrackingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * POST /api/places/{spot}/feedback
 *
 * Records the user's standing relationship to a place (the SpotFeedback row)
 * AND emits a ranking signal as a user_event so IntentWeights tunes the feed.
 * "More like this" / "Save" are forward-looking interest signals — no visit
 * implied; "been" / "not interested" also drop the place from discovery.
 */
class PlaceFeedbackController extends Controller
{
    public function __invoke(Request $request, Spot $spot, EventTrackingService $tracking): JsonResponse
    {
        $validated = $request->validate([
            'action' => ['required', 'string', 'in:more_like_this,saved,been,not_interested,clear'],
            'rating' => ['nullable', 'string', 'in:up,down'],
        ]);

        $user = $request->user();

        if ($validated['action'] === 'clear') {
            SpotFeedback::query()
                ->where('user_id', $user->id)
                ->where('spot_id', $spot->id)
                ->delete();

            return response()->json(['state' => null, 'rating' => null]);
        }

        $state = SpotFeedbackState::from($validated['action']);
        $rating = $state === SpotFeedbackState::Been ? ($validated['rating'] ?? null) : null;

        SpotFeedback::updateOrCreate(
            ['user_id' => $user->id, 'spot_id' => $spot->id],
            ['state' => $state, 'rating' => $rating],
        );

        // Emit the ranking signal through the same pipeline as every other
        // intent signal, keyed on category × Veedel (what IntentWeights reads).
        if ($eventType = $this->signalFor($state, $rating)) {
            $tracking->track($user, $eventType, [
                'category' => $this->categoryValue($spot),
                'veedel' => $spot->veedel,
                'spot_id' => $spot->id,
            ]);
        }

        return response()->json(['state' => $state->value, 'rating' => $rating]);
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

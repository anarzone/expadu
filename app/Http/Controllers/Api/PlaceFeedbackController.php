<?php

namespace App\Http\Controllers\Api;

use App\Enums\SpotFeedbackState;
use App\Http\Controllers\Controller;
use App\Models\Spot;
use App\Models\SpotFeedback;
use App\Personalisation\SignalAction;
use App\Personalisation\SignalRecorder;
use App\Places\PlaceIdentity;
use App\Services\EventTrackingService;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * POST /api/places/{spot}/feedback — the one-of choice: more like this, saved,
 * not interested (or clear). PUT / DELETE /api/places/{spot}/been — Been there,
 * kept apart so a place can be both saved and visited.
 *
 * Each records the user's standing relationship to a place (the SpotFeedback
 * row) AND emits a ranking signal as a user_event so IntentWeights tunes the
 * feed. "More like this" / "Save" are forward-looking interest signals — no
 * visit implied; "been" / "not interested" also drop the place from discovery.
 */
class PlaceFeedbackController extends Controller
{
    public function __invoke(Request $request, Spot $spot, EventTrackingService $tracking): JsonResponse
    {
        $validated = $request->validate([
            'action' => ['required', 'string', 'in:more_like_this,saved,been,not_interested,clear'],
            'rating' => ['nullable', 'string', 'in:up,down'],
        ]);

        // Older clients set Been through this endpoint; it never touches the choice.
        if ($validated['action'] === 'been') {
            return $this->markBeen($request->user(), $spot, $validated['rating'] ?? null, $tracking);
        }

        $user = $request->user();

        return app(PlaceIdentity::class)->withCanonicalLock($spot->id, function (Spot $spot) use ($validated, $user, $tracking): JsonResponse {
            if ($validated['action'] === 'clear') {
                $this->family($user->id, $spot)->update(['state' => null, 'rating' => null]);
                $this->deleteEmpty($user->id, $spot);
                app(SignalRecorder::class)->placeFeedback($user, $spot, null, replaces: SignalAction::placeState());

                return response()->json($this->payload($user->id, $spot));
            }

            $state = SpotFeedbackState::from($validated['action']);
            $feedback = $this->row($user->id, $spot);
            $feedback->fill(['state' => $state, 'rating' => null])->save();
            $feedback->touch();
            app(SignalRecorder::class)->placeFeedback($user, $spot, $state);
            $this->track($tracking, $user, $spot, $this->signalFor($state));

            return response()->json($this->payload($user->id, $spot));
        });
    }

    /** PUT /api/places/{spot}/been {rating?: up|down} */
    public function been(Request $request, Spot $spot, EventTrackingService $tracking): JsonResponse
    {
        $validated = $request->validate(['rating' => ['nullable', 'string', 'in:up,down']]);

        return $this->markBeen($request->user(), $spot, $validated['rating'] ?? null, $tracking);
    }

    /** DELETE /api/places/{spot}/been */
    public function forgetBeen(Request $request, Spot $spot): JsonResponse
    {
        $user = $request->user();

        return app(PlaceIdentity::class)->withCanonicalLock($spot->id, function (Spot $spot) use ($user): JsonResponse {
            $this->family($user->id, $spot)->update(['been_at' => null, 'been_rating' => null]);
            $this->deleteEmpty($user->id, $spot);
            app(SignalRecorder::class)->placeFeedback($user, $spot, null, replaces: SignalAction::placeBeen());

            return response()->json($this->payload($user->id, $spot));
        });
    }

    private function markBeen($user, Spot $spot, ?string $rating, EventTrackingService $tracking): JsonResponse
    {
        return app(PlaceIdentity::class)->withCanonicalLock($spot->id, function (Spot $spot) use ($user, $rating, $tracking): JsonResponse {
            $feedback = $this->row($user->id, $spot);
            $feedback->fill(['been_at' => $feedback->been_at ?? CarbonImmutable::now(), 'been_rating' => $rating])->save();
            app(SignalRecorder::class)->placeFeedback($user, $spot, SpotFeedbackState::Been, $rating);
            // A "been" with no rating just leaves discovery: no taste signal.
            $this->track($tracking, $user, $spot, match ($rating) {
                'up' => 'spot_been_liked',
                'down' => 'spot_been_disliked',
                default => null,
            });

            return response()->json($this->payload($user->id, $spot));
        });
    }

    private function family(int $userId, Spot $spot)
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

    private function track(EventTrackingService $tracking, $user, Spot $spot, ?string $eventType): void
    {
        if ($eventType === null) {
            return;
        }
        // Same pipeline as every other intent signal, keyed on category × Veedel (what IntentWeights reads).
        $tracking->track($user, $eventType, [
            'category' => $this->categoryValue($spot),
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

    private function categoryValue(Spot $spot): ?string
    {
        if ($spot->category instanceof \BackedEnum) {
            return $spot->category->value;
        }

        return $spot->category ? (string) $spot->category : null;
    }
}

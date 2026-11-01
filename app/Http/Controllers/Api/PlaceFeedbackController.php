<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Spot;
use App\Places\RecordPlaceFeedback;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * POST /api/places/{spot}/feedback — the one-of choice: more like this, saved,
 * not interested (or clear). PUT / DELETE /api/places/{spot}/been — Been there,
 * kept apart so a place can be both saved and visited.
 *
 * Each records through RecordPlaceFeedback, which Composer's place_feedback uses too.
 */
class PlaceFeedbackController extends Controller
{
    public function __invoke(Request $request, Spot $spot, RecordPlaceFeedback $feedback): JsonResponse
    {
        $validated = $request->validate([
            'action' => ['required', 'string', 'in:more_like_this,saved,been,not_interested,clear'],
            'rating' => ['nullable', 'string', 'in:up,down'],
        ]);

        // Older clients set Been through this endpoint; it never touches the choice.
        return response()->json($feedback->execute($request->user(), $spot, $validated['action'], $validated['rating'] ?? null));
    }

    /** PUT /api/places/{spot}/been {rating?: up|down} */
    public function been(Request $request, Spot $spot, RecordPlaceFeedback $feedback): JsonResponse
    {
        $validated = $request->validate(['rating' => ['nullable', 'string', 'in:up,down']]);

        return response()->json($feedback->been($request->user(), $spot, $validated['rating'] ?? null));
    }

    /** DELETE /api/places/{spot}/been */
    public function forgetBeen(Request $request, Spot $spot, RecordPlaceFeedback $feedback): JsonResponse
    {
        return response()->json($feedback->forgetBeen($request->user(), $spot));
    }
}

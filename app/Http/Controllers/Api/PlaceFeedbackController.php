<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Spot;
use App\Places\RecordPlaceFeedback;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * POST /api/places/{spot}/feedback
 *
 * Records the user's standing relationship to a place and its ranking signal
 * (RecordPlaceFeedback, which Composer's place_feedback uses too).
 */
class PlaceFeedbackController extends Controller
{
    public function __invoke(Request $request, Spot $spot, RecordPlaceFeedback $feedback): JsonResponse
    {
        $validated = $request->validate([
            'action' => ['required', 'string', 'in:more_like_this,saved,been,not_interested,clear'],
            'rating' => ['nullable', 'string', 'in:up,down'],
        ]);

        return response()->json($feedback->execute($request->user(), $spot, $validated['action'], $validated['rating'] ?? null));
    }
}

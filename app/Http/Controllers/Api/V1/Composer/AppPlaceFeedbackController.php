<?php

namespace App\Http\Controllers\Api\V1\Composer;

use App\Http\Controllers\Api\PlaceFeedbackController as Web;
use App\Models\Spot;
use App\Places\RecordPlaceFeedback;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Saving or unsaving a place (and Not this, Been there) for the iPhone app's token. Its own class: one URL per Wayfinder action. */
class AppPlaceFeedbackController extends Web
{
    /** Where the person stands with the place now, for Composer's place sheet. */
    public function standing(Request $request, Spot $spot, RecordPlaceFeedback $feedback): JsonResponse
    {
        return response()->json($feedback->standing($request->user(), $spot));
    }
}

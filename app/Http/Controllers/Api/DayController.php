<?php

namespace App\Http\Controllers\Api;

use App\Day\DayProjection;
use App\Http\Controllers\Controller;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * GET /api/day?date= — the signed-in person's day (see DayProjection). An
 * optional live fix (lat/lng) or saved place (from_place) sets where the next
 * trip starts; it is used for this answer only and never stored.
 */
class DayController extends Controller
{
    public function __invoke(Request $request, DayProjection $projection): JsonResponse
    {
        $validated = $request->validate([
            'date' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:2000-01-01', 'before:2100-01-01'],
            'lat' => ['nullable', 'required_with:lng', 'numeric', 'between:-90,90'],
            'lng' => ['nullable', 'required_with:lat', 'numeric', 'between:-180,180'],
            'from_label' => ['nullable', 'string', 'max:120'],
            'from_place' => ['nullable', 'integer'],
        ]);

        $date = $validated['date'] ?? CarbonImmutable::now(DayProjection::TIMEZONE)->format('Y-m-d');

        return response()->json($projection->for($request->user(), $date, $request));
    }
}

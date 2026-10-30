<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\EventTrackingService;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;

class TrackEventController extends Controller
{
    /**
     * The only event types a client may record. Taste signals (IntentWeights)
     * are written server-side from the actions that earn them, so a client
     * can't shape its own recommendations.
     */
    public const CLIENT_EVENT_TYPES = [
        'page_viewed',
        'location_ping',
    ];

    public function __invoke(Request $request, EventTrackingService $tracking): Response
    {
        $validated = $request->validate([
            'event_type' => ['required', 'string', Rule::in(self::CLIENT_EVENT_TYPES)],
            'payload' => ['nullable', 'array', 'max:20'],
        ]);

        $tracking->track(
            $request->user(),
            $validated['event_type'],
            $validated['payload'] ?? [],
        );

        return response()->noContent();
    }
}

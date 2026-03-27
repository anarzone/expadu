<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\EventTrackingService;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class TrackEventController extends Controller
{
    public function __invoke(Request $request, EventTrackingService $tracking): Response
    {
        $validated = $request->validate([
            'event_type' => ['required', 'string', 'max:100'],
            'payload' => ['nullable', 'array'],
        ]);

        $tracking->track(
            $request->user(),
            $validated['event_type'],
            $validated['payload'] ?? [],
        );

        return response()->noContent();
    }
}

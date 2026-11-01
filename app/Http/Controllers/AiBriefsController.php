<?php

namespace App\Http\Controllers;

use App\Privacy\ProcessingOptIns;
use App\Privacy\ProcessingPurpose;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * AI-written day briefs, the person's own switch (owner, 26 Oct 2026: opt-in, off by default):
 * whether it's on, whether it can be, and the notice it's turned on under; on with the notice
 * they were shown, off at once.
 */
class AiBriefsController extends Controller
{
    public function show(Request $request, ProcessingOptIns $optIns): JsonResponse
    {
        $purpose = ProcessingPurpose::NotificationBrief;

        return response()->json([
            'enabled' => $optIns->active($request->user(), $purpose),
            'available' => $purpose->available(),
            'disclosure' => $purpose->disclosure(),
        ]);
    }

    public function update(Request $request, ProcessingOptIns $optIns): JsonResponse
    {
        $validated = $request->validate([
            'enabled' => ['required', 'boolean'],
            // The notice they were shown (show()'s disclosure), to turn it on.
            'notice_version' => ['required_if:enabled,true', 'nullable', 'string', 'max:100'],
            'provider_version' => ['required_if:enabled,true', 'nullable', 'string', 'max:64'],
        ]);
        $purpose = ProcessingPurpose::NotificationBrief;
        if ($validated['enabled']) {
            $optIns->turnOn($request->user(), $purpose, $validated['notice_version'], $validated['provider_version']);

            return response()->json(['enabled' => true]);
        }
        $inFlight = $optIns->turnOff($request->user(), $purpose);

        return response()->json(['enabled' => false, 'in_flight' => $inFlight,
            'message' => 'AI-written briefs are off. A brief already being written may finish at the AI service, but it won’t be used.']);
    }
}

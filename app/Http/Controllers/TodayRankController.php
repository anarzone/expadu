<?php

namespace App\Http\Controllers;

use App\Jobs\RankToday;
use App\Personalisation\TodayRankPause;
use App\Privacy\ProcessingOptIns;
use App\Privacy\ProcessingPurpose;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Today put in order by AI, again after its notice or the AI service changed (TodayRankPause):
 * agree to the notice they were shown, or put it off for a week. Turning it off is
 * personalisation's own switch.
 */
class TodayRankController extends Controller
{
    public function update(Request $request, ProcessingOptIns $optIns, TodayRankPause $pause): JsonResponse
    {
        $validated = $request->validate([
            // The notice they were shown (TodayRankPause's), to agree to it.
            'notice_version' => ['required', 'string', 'max:100'],
            'provider_version' => ['required', 'string', 'max:64'],
        ]);
        $user = $request->user();
        abort_if($pause->for($user) === null, 409, 'Today’s AI order isn’t waiting for you.');

        $optIns->turnOn($user, ProcessingPurpose::TodayRank, $validated['notice_version'], $validated['provider_version']);
        $pause->agreed($user);
        // Today's order again now, not tomorrow morning.
        dispatch(new RankToday($user->id));

        return response()->json(['enabled' => true]);
    }

    public function later(Request $request, TodayRankPause $pause): JsonResponse
    {
        return response()->json(['until' => $pause->later($request->user())]);
    }
}

<?php

namespace App\Http\Controllers\Api\V1;

use App\Day\Moments;
use App\Http\Controllers\Controller;
use App\Models\NotificationPreference;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * What the phone schedules as its own notifications for the next 36 hours (Moments): read on every
 * open and in the background, each replacing the one scheduled under its id.
 */
class MomentsController extends Controller
{
    public function index(Request $request, Moments $moments): JsonResponse
    {
        $now = CarbonImmutable::now('Europe/Berlin');
        $state = NotificationPreference::stateFor($request->user());

        return response()->json([
            /** @var list<array{id: string, kind: string, at: string, title: string, body: string, link: string}> Soonest first; leave, event, plan or brief. */
            'moments' => $moments->for($request->user(), $now),
            /** Read again by then at the latest. */
            'until' => $now->addHours(Moments::HOURS)->toIso8601String(),
            'quiet_from' => $state['quiet_from'],
            'quiet_to' => $state['quiet_to'],
        ]);
    }
}

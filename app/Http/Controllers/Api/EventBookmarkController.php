<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Events\SaveEventRequest;
use App\Services\SavedEvents;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class EventBookmarkController extends Controller
{
    public function index(Request $request, SavedEvents $events): JsonResponse
    {
        return response()->json(['data' => $events->forUser($request->user())]);
    }

    public function store(SaveEventRequest $request, SavedEvents $events): JsonResponse
    {
        $input = $request->validated();
        $events->set($request->user(), $input['event_id'], CarbonImmutable::parse($input['occurrence_start'])->setTimezone('Europe/Berlin'), $input['saved']);

        return response()->json(['saved' => $input['saved']]);
    }
}

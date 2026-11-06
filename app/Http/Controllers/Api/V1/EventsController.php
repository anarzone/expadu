<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Events\SaveEventRequest;
use App\Services\SavedEvents;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;

class EventsController extends Controller
{
    /** Save an event for later, or let it go: the web's bookmark (EventBookmarkController::store). */
    public function save(SaveEventRequest $request, SavedEvents $events): JsonResponse
    {
        $input = $request->validated();
        $events->remember($request->user(), (int) $input['event_id'], CarbonImmutable::parse($input['occurrence_start'])->setTimezone('Europe/Berlin'), (bool) $input['saved']);

        return response()->json(['saved' => (bool) $input['saved']]);
    }
}

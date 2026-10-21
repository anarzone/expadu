<?php

namespace App\Http\Controllers\Api;

use App\Composer\TasteSignals;
use App\Http\Controllers\Controller;
use App\Http\Requests\Events\SaveEventRequest;
use App\Models\Event;
use App\Personalisation\SignalAction;
use App\Personalisation\SignalItemType;
use App\Personalisation\SignalRecorder;
use App\Personalisation\SignalSurface;
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
        if ($input['saved'] && ($event = Event::query()->with('venue')->find($input['event_id'])) !== null) {
            app(TasteSignals::class)->eventSaved($request->user(), $event);
            app(SignalRecorder::class)->record($request->user(), SignalAction::Saved, SignalSurface::EventSheet, SignalItemType::Event, $event->id, CarbonImmutable::parse($input['occurrence_start']));
        }

        return response()->json(['saved' => $input['saved']]);
    }
}

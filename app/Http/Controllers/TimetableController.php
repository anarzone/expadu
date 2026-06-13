<?php

namespace App\Http\Controllers;

use App\Services\TimetableService;
use App\Services\UserLocationService;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The "Departures" page: a KVB-style live board, one stop per mode tab, from
 * the user's resolved location. The boards defer because the TRIAS real-time
 * fetch is a network round-trip — the page shell paints immediately.
 */
class TimetableController extends Controller
{
    public function __invoke(Request $request, TimetableService $timetable): Response
    {
        $location = app(UserLocationService::class)->resolve($request->user(), $request);

        return Inertia::render('timetable', [
            'boards' => Inertia::defer(fn () => $timetable->boards($location['lat'], $location['lng'])),
        ]);
    }
}

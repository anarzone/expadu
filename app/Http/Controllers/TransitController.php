<?php

namespace App\Http\Controllers;

use App\Services\GtfsDepartureService;
use App\Services\TransitService;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class TransitController extends Controller
{
    public function index(Request $request, TransitService $transitService, GtfsDepartureService $gtfsService): Response
    {
        $stop = $request->query('stop', 'Ehrenfeld');

        return Inertia::render('transit', [
            'departures' => $transitService->getDepartures($stop),
            'routes' => $transitService->getRoutes('Home', 'Work'),
            'disruptions' => $transitService->getDisruptions(),
            'routines' => $request->user()->routines ?? collect(),
            'currentStop' => $stop,
            'userPlaces' => $request->user()->places()
                ->select('id', 'emoji', 'name', 'address', 'lat', 'lng')
                ->get(),
            'gtfsDepartures' => $gtfsService->getDepartures($stop),
            'gtfsStops' => fn () => $gtfsService->searchStops($stop, 20),
        ]);
    }
}

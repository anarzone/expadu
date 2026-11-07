<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\NearbyDeparturesResource;
use App\Services\NearbyStopService;
use Illuminate\Http\Request;

class TravelController extends Controller
{
    /** Departures from the stops a short walk from here, soonest first, in KVB's own colours. */
    public function nearby(Request $request, NearbyStopService $nearby): NearbyDeparturesResource
    {
        $data = $request->validate([
            'lat' => ['required', 'numeric', 'between:-90,90'],
            'lng' => ['required', 'numeric', 'between:-180,180'],
        ]);

        return new NearbyDeparturesResource($nearby->getDeparturesByType((float) $data['lat'], (float) $data['lng']));
    }
}

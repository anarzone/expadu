<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\GeocodingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class GeocodeController extends Controller
{
    public function __invoke(Request $request, GeocodingService $geocoding): JsonResponse
    {
        $request->validate([
            'q' => ['required', 'string', 'min:2', 'max:100'],
        ]);

        // What was typed is answered and forgotten: no search log keeps it.
        return response()->json($geocoding->search($request->string('q')->toString()));
    }
}

<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\GtfsDepartureService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class StopSearchController extends Controller
{
    public function __invoke(Request $request, GtfsDepartureService $gtfsService): JsonResponse
    {
        $request->validate([
            'q' => ['required', 'string', 'min:2', 'max:100'],
        ]);

        return response()->json(
            $gtfsService->searchStops($request->query('q'), 10)
        );
    }
}

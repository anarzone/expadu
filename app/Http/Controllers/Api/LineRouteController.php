<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Transit\LineRoute;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The lines map's picked line: its whole route through the board's station
 * (LineRoute). The station's own name and position, never yours.
 */
class LineRouteController extends Controller
{
    public function __invoke(Request $request, LineRoute $route): JsonResponse
    {
        $asked = $request->validate([
            'stop' => ['required', 'string', 'min:2', 'max:100'],
            'lat' => ['required', 'numeric', 'between:-90,90'],
            'lng' => ['required', 'numeric', 'between:-180,180'],
            'line' => ['required', 'string', 'max:10'],
        ]);

        return response()->json([
            'route' => $route->for(trim($asked['stop']), (float) $asked['lat'], (float) $asked['lng'], trim($asked['line'])),
        ]);
    }
}

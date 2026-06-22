<?php

namespace App\Http\Controllers\Api;

use App\Enums\TransportMode;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Persist the user's default transport mode — the "how I travel" toggle in the
 * Places From control. A null/absent mode clears it back to "fastest realistic".
 */
class TransportModeController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'mode' => ['nullable', Rule::enum(TransportMode::class)],
        ]);

        $request->user()->update(['transport_mode' => $validated['mode'] ?? null]);

        return response()->json([
            'transport_mode' => $request->user()->transport_mode?->value,
        ]);
    }
}

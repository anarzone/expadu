<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * "I have a Deutschlandticket", said where Travel shows the fare: kept with
 * the account (users.has_deutschlandticket), which the fare advice reads.
 */
class DeutschlandticketController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $validated = $request->validate(['held' => ['required', 'boolean']]);
        $user = $request->user();
        $user->forceFill(['has_deutschlandticket' => (bool) $validated['held']])->save();

        return response()->json(['held' => (bool) $user->has_deutschlandticket]);
    }
}

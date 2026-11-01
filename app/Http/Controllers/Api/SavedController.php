<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Saved\SavedCollection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** The signed-in person's saved places and events, newest first (SavedCollection). */
class SavedController extends Controller
{
    public function __invoke(Request $request, SavedCollection $saved): JsonResponse
    {
        $items = $saved->for($request->user());

        return response()->json(['data' => $items->all(), 'count' => $items->count()]);
    }
}

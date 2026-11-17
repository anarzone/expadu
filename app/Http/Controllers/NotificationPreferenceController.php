<?php

namespace App\Http\Controllers;

use App\Http\Requests\UpdateNotificationPreferencesRequest;
use App\Models\NotificationPreference;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class NotificationPreferenceController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        return response()->json(NotificationPreference::stateFor($request->user()));
    }

    /** Save what changed (UpdateNotificationPreferencesRequest says how). */
    public function update(UpdateNotificationPreferencesRequest $request): JsonResponse
    {
        return response()->json(NotificationPreference::saveFor($request->user(), $request->validated()));
    }
}

<?php

namespace App\Http\Controllers;

use App\Models\NotificationPreference;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class NotificationPreferenceController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        $pref = $request->user()->notificationPreference;

        return response()->json([
            'preferences' => $pref?->preferences ?? NotificationPreference::defaults(),
        ]);
    }

    public function update(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'preferences' => ['required', 'array'],
            'preferences.*' => ['boolean'],
        ]);

        $request->user()->notificationPreference()->updateOrCreate(
            ['user_id' => $request->user()->id],
            ['preferences' => $validated['preferences']],
        );

        return response()->json(['message' => 'Preferences updated.']);
    }
}

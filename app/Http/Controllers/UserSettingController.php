<?php

namespace App\Http\Controllers;

use App\Models\UserSetting;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class UserSettingController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        $settings = $request->user()->userSetting?->settings
            ?? UserSetting::defaults();

        return response()->json(['settings' => $settings]);
    }

    public function update(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'settings' => ['required', 'array'],
            'settings.share_location' => ['sometimes', 'boolean'],
            'settings.personalised_content' => ['sometimes', 'boolean'],
            'settings.share_anonymised' => ['sometimes', 'boolean'],
            'settings.profile_visibility' => ['sometimes', 'string', 'in:everyone,partners,nobody'],
        ]);

        $user = $request->user();
        $current = $user->userSetting?->settings ?? UserSetting::defaults();
        $merged = array_merge($current, $validated['settings']);

        $user->userSetting()->updateOrCreate(
            ['user_id' => $user->id],
            ['settings' => $merged],
        );

        return response()->json(['settings' => $merged]);
    }
}

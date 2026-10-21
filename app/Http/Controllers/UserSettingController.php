<?php

namespace App\Http\Controllers;

use App\Models\UserSetting;
use App\Personalisation\SignalRecorder;
use App\Services\LocationDataRetention;
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

    public function update(Request $request, LocationDataRetention $retention): JsonResponse
    {
        $validated = $request->validate([
            'settings' => ['required', 'array'],
            'settings.share_location' => ['sometimes', 'boolean'],
            'settings.personalised_content' => ['sometimes', 'boolean'],
            'settings.share_anonymised' => ['sometimes', 'boolean'],
            'settings.profile_visibility' => ['sometimes', 'string', 'in:everyone,partners,nobody'],
            'settings.personalisation' => ['sometimes', 'boolean'],
        ]);

        $user = $request->user();
        $merged = $retention->withUserLock($user->id, function () use ($user, $validated, $retention): array {
            $user->load('userSetting');
            $current = $user->userSetting?->settings ?? UserSetting::defaults();
            $merged = array_merge($current, $validated['settings']);

            if (($validated['settings']['share_location'] ?? null) === false) {
                $retention->purgeUser($user->id);
            }
            // Turning personalisation off erases what was learned, not just pauses it.
            if (($validated['settings']['personalisation'] ?? null) === false) {
                app(SignalRecorder::class)->forget($user);
            }

            $user->userSetting()->updateOrCreate(
                ['user_id' => $user->id],
                ['settings' => $merged],
            );

            return $merged;
        });

        return response()->json(['settings' => $merged]);
    }
}

<?php

namespace App\Http\Controllers;

use App\Models\UserSetting;
use App\Personalisation\PersonalisationWording;
use App\Personalisation\SignalRecorder;
use App\Privacy\ProcessingOptIns;
use App\Privacy\ProcessingPurpose;
use App\Services\LocationDataRetention;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class UserSettingController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        $settings = [...UserSetting::defaults(), ...($request->user()->userSetting?->settings ?? []),
            // Whether their yes covers what personalisation says now (PersonalisationWording): Settings asks again when it doesn't.
            'personalisation_current' => PersonalisationWording::current($request->user())];

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
            'settings.composer_ask_first_everyday' => ['sometimes', 'boolean'],
            'settings.composer_ask_first_paperwork' => ['sometimes', 'boolean'],
        ]);

        $user = $request->user();
        $merged = $retention->withUserLock($user->id, function () use ($user, $validated, $retention): array {
            $user->load('userSetting');
            $current = $user->userSetting?->settings ?? UserSetting::defaults();
            $merged = PersonalisationWording::saved($current, array_merge($current, $validated['settings']), $validated['settings']);
            unset($merged['personalisation_current']);

            if (($validated['settings']['share_location'] ?? null) === false) {
                $retention->purgeUser($user->id);
            }
            // Turning personalisation off erases what was learned, not just pauses it, and stops Today's AI order.
            if (($validated['settings']['personalisation'] ?? null) === false) {
                app(SignalRecorder::class)->forget($user);
                app(ProcessingOptIns::class)->turnOff($user, ProcessingPurpose::TodayRank);
            }
            // On under this wording: its AI order too, under the notice and provider as they are now.
            if (($validated['settings']['personalisation'] ?? null) === true && ProcessingPurpose::TodayRank->available()) {
                app(ProcessingOptIns::class)->turnOn($user, ProcessingPurpose::TodayRank, config('bureaucracy_privacy.notice_version'), ProcessingPurpose::TodayRank->providerVersion());
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

<?php

namespace App\Http\Controllers;

use App\Jobs\RankToday;
use App\Models\UserSetting;
use App\Personalisation\LeaveNotice;
use App\Personalisation\PersonalisationWording;
use App\Personalisation\Routines;
use App\Personalisation\SignalRecorder;
use App\Privacy\ProcessingOptIns;
use App\Privacy\ProcessingPurpose;
use App\Services\LocationDataRetention;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

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
            'settings.routine_learning' => ['sometimes', 'boolean'],
            'settings.leave_notice' => ['sometimes', 'boolean'],
            'settings.auto_switch_trams' => ['sometimes', 'boolean'],
            'settings.composer_ask_first_everyday' => ['sometimes', 'boolean'],
            'settings.composer_ask_first_paperwork' => ['sometimes', 'boolean'],
        ]);
        // Personalisation is for people 16 or older (Settings › Privacy asks): their word with each yes.
        if (($validated['settings']['personalisation'] ?? null) === true) {
            $request->validate(['sixteen_or_older' => ['accepted']], ['sixteen_or_older.accepted' => 'Tick that you’re 16 or older to turn it on.']);
        }

        $user = $request->user();
        $merged = $retention->withUserLock($user->id, function () use ($user, $validated, $retention): array {
            $user->load('userSetting');
            $current = $user->userSetting?->settings ?? UserSetting::defaults();
            $merged = PersonalisationWording::saved($current, array_merge($current, $validated['settings']), $validated['settings']);
            unset($merged['personalisation_current']);

            if (($validated['settings']['share_location'] ?? null) === false) {
                $retention->purgeUser($user->id);
            }
            // Routines are learned only with personalisation on.
            if (($validated['settings']['routine_learning'] ?? null) === true && ($merged['personalisation'] ?? false) !== true) {
                throw ValidationException::withMessages(['settings.routine_learning' => 'Turn on personalisation first.']);
            }
            // Noticing when they leave Home or Work needs routine learning on; off erases what it noticed.
            if (($validated['settings']['leave_notice'] ?? null) === true && (($merged['routine_learning'] ?? false) !== true || ($merged['personalisation'] ?? false) !== true)) {
                throw ValidationException::withMessages(['settings.leave_notice' => 'Turn on learning from your trips first.']);
            }
            if (($validated['settings']['leave_notice'] ?? null) === false) {
                app(LeaveNotice::class)->forget($user);
            }
            // Routine learning off: noticing goes with it.
            if (($validated['settings']['routine_learning'] ?? null) === false || ($validated['settings']['personalisation'] ?? null) === false) {
                $merged['leave_notice'] = false;
            }
            // Turning routine learning off erases the trips kept for it and the routines.
            if (($validated['settings']['routine_learning'] ?? null) === false) {
                app(Routines::class)->erase($user);
            }
            // Turning personalisation off erases what was learned, not just pauses it, and stops Today's AI order.
            if (($validated['settings']['personalisation'] ?? null) === false) {
                app(SignalRecorder::class)->forget($user);
                app(ProcessingOptIns::class)->turnOff($user, ProcessingPurpose::TodayRank);
            }
            // On under this wording: its AI order too, under the notice and provider as they are now.
            if (($validated['settings']['personalisation'] ?? null) === true && ProcessingPurpose::TodayRank->available()) {
                app(ProcessingOptIns::class)->turnOn($user, ProcessingPurpose::TodayRank, ProcessingPurpose::TodayRank->noticeVersion(), ProcessingPurpose::TodayRank->providerVersion());
            }

            $user->userSetting()->updateOrCreate(
                ['user_id' => $user->id],
                ['settings' => $merged],
            );

            return $merged;
        });

        // Saved on: Today put in order now rather than at the next morning's run.
        if (($validated['settings']['personalisation'] ?? null) === true && ProcessingPurpose::TodayRank->available()) {
            dispatch(new RankToday($request->user()->id));
        }

        return response()->json(['settings' => $merged]);
    }
}

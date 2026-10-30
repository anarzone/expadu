<?php

namespace App\Http\Controllers;

use App\Models\NotificationPreference;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Validation\Validator;

class NotificationPreferenceController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        return response()->json(NotificationPreference::stateFor($request->user()));
    }

    /**
     * Save what changed: toggles merge into the saved ones (one toggle never
     * resets the rest), quiet hours start and end at different times, and a
     * set brief time falls outside them, or it would wait until they end.
     */
    public function update(Request $request): JsonResponse
    {
        $user = $request->user();
        $current = NotificationPreference::stateFor($user);
        $validated = $request->validate([
            'preferences' => ['sometimes', 'array'],
            'preferences.*' => ['boolean'],
            'quiet_from' => ['sometimes', 'date_format:H:i'],
            'quiet_to' => ['sometimes', 'date_format:H:i'],
            'brief_at' => ['sometimes', 'nullable', 'date_format:H:i'],
        ]);

        validator($validated)->after(function (Validator $validator) use ($validated, $current): void {
            $from = $validated['quiet_from'] ?? $current['quiet_from'];
            $to = $validated['quiet_to'] ?? $current['quiet_to'];
            $brief = array_key_exists('brief_at', $validated) ? $validated['brief_at'] : $current['brief_at'];
            if ($from === $to) {
                $validator->errors()->add('quiet_from', 'Quiet hours need to start and end at different times.');
            } elseif ($brief !== null && NotificationPreference::inQuietHours($brief, $from, $to)) {
                $validator->errors()->add('brief_at', "Your brief would arrive in quiet hours ({$from}–{$to}). Pick a time outside them.");
            }
        })->validate();

        $saved = $user->notificationPreference()->first()?->preferences ?? [];
        $user->notificationPreference()->updateOrCreate(['user_id' => $user->id], [
            'preferences' => Arr::except([...$saved, ...($validated['preferences'] ?? [])], NotificationPreference::RETIRED),
            ...Arr::only($validated, ['quiet_from', 'quiet_to', 'brief_at']),
        ]);

        return response()->json(NotificationPreference::stateFor($user));
    }
}

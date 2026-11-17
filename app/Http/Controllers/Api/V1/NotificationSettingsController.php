<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\UpdateNotificationPreferencesRequest;
use App\Models\NotificationPreference;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Their notifications on the iPhone (Settings › Notifications), as the web's Settings keeps them:
 * which kinds they get, quiet hours, and when the morning brief comes. The moments the phone
 * schedules (/moments) follow these.
 */
class NotificationSettingsController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        return $this->state(NotificationPreference::stateFor($request->user()));
    }

    /**
     * Change some: a kind turned on or off keeps the others as they were.
     */
    public function update(UpdateNotificationPreferencesRequest $request): JsonResponse
    {
        return $this->state(NotificationPreference::saveFor($request->user(), $request->validated()));
    }

    /** @param  array{preferences: array<string, bool>, quiet_from: string, quiet_to: string, brief_at: string|null}  $state */
    private function state(array $state): JsonResponse
    {
        return response()->json([
            /** @var array<string, bool> Each kind on or off: transit, events, weather, brief and the rest. */
            'preferences' => $state['preferences'],
            /** @var string Quiet hours start (HH:MM); they may run past midnight. */
            'quiet_from' => $state['quiet_from'],
            /** @var string Quiet hours end (HH:MM). */
            'quiet_to' => $state['quiet_to'],
            /** @var string|null When the morning brief comes (HH:MM); null: before their first thing of the day. */
            'brief_at' => $state['brief_at'],
        ]);
    }
}

<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Models\NotificationPreference;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class NotificationController extends Controller
{
    /**
     * Show the notification settings page. Saving is handled by the existing
     * NotificationPreferenceController@update (PUT /notification-preferences).
     */
    public function edit(Request $request): Response
    {
        return Inertia::render('settings/notifications', [
            'preferences' => $request->user()->notificationPreference?->preferences
                ?? NotificationPreference::defaults(),
        ]);
    }
}

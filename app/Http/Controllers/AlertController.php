<?php

namespace App\Http\Controllers;

use App\Models\Alert;
use App\Models\NotificationPreference;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class AlertController extends Controller
{
    public function index(Request $request): Response
    {
        $query = $request->user()->alerts()->orderByDesc('created_at');

        if ($tab = $request->query('tab')) {
            if (in_array($tab, ['system', 'social', 'reminder'])) {
                $query->where('type', $tab);
            }
        }

        $userAlerts = $request->user()->alerts();

        return Inertia::render('alerts', [
            'alerts' => $query->paginate(30),
            'unreadCount' => (clone $userAlerts)->whereNull('read_at')->count(),
            'counts' => [
                'unread' => (clone $userAlerts)->whereNull('read_at')->count(),
                'system' => (clone $userAlerts)->where('type', 'system')->count(),
                'social' => (clone $userAlerts)->where('type', 'social')->count(),
                'reminder' => (clone $userAlerts)->where('type', 'reminder')->count(),
            ],
            'tab' => $request->query('tab', 'all'),
            'notificationPreferences' => $request->user()->notificationPreference?->preferences
                ?? NotificationPreference::defaults(),
        ]);
    }

    public function markRead(Request $request, Alert $alert): JsonResponse|RedirectResponse
    {
        if ($alert->user_id === $request->user()->id) {
            $alert->update(['read_at' => now()]);
        }

        if ($request->wantsJson()) {
            return response()->json(['ok' => true]);
        }

        return back();
    }

    public function markAllRead(Request $request): JsonResponse|RedirectResponse
    {
        $request->user()->alerts()->whereNull('read_at')->update(['read_at' => now()]);

        if ($request->wantsJson()) {
            return response()->json(['ok' => true]);
        }

        return back();
    }
}

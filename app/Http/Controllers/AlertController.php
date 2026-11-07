<?php

namespace App\Http\Controllers;

use App\Alerts\AlertPresenter;
use App\Models\Alert;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class AlertController extends Controller
{
    public function index(Request $request, AlertPresenter $presenter): Response
    {
        // The v4 screen groups into lanes + filters by category client-side, so
        // ship a flat enriched list (recent, capped) rather than a page. Settings
        // now live on their own page (/settings/notifications); the right rail is
        // the shared widgets panel, so this only feeds the list + header count.
        $alerts = $presenter->recent($request->user());
        $notices = $presenter->notices($alerts);

        return Inertia::render('alerts', [
            'alerts' => $alerts->map(fn (Alert $alert) => $presenter->present($alert, $notices[$alert->group_key] ?? null))->all(),
            'unreadCount' => $presenter->unread($request->user()),
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

    public function dismiss(Request $request, Alert $alert): JsonResponse|RedirectResponse
    {
        if ($alert->user_id === $request->user()->id) {
            $alert->update([
                'dismissed_at' => now(),
                'read_at' => $alert->read_at ?? now(),
            ]);
        }

        if ($request->wantsJson()) {
            return response()->json(['ok' => true]);
        }

        return back();
    }
}

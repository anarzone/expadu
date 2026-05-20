<?php

namespace App\Http\Controllers;

use App\Models\Alert;
use App\Models\NotificationPreference;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class AlertController extends Controller
{
    public function index(Request $request): Response
    {
        $userId = $request->user()->id;
        $query = $request->user()->alerts()->whereNull('dismissed_at')->orderByDesc('created_at');

        if ($tab = $request->query('tab')) {
            if (in_array($tab, ['system', 'social', 'reminder'])) {
                $query->where('type', $tab);
            }
        }

        // Single grouped aggregate replaces five clone+count queries.
        /** @var array<int, object{type: ?string, total: int, unread: int}> $aggregates */
        $aggregates = DB::table('alerts')
            ->selectRaw('type, COUNT(*) AS total, COUNT(*) FILTER (WHERE read_at IS NULL) AS unread')
            ->where('user_id', $userId)
            ->whereNull('dismissed_at')
            ->groupBy('type')
            ->get()
            ->all();

        $unreadCount = (int) array_sum(array_column($aggregates, 'unread'));
        $byType = collect($aggregates)->keyBy('type');

        return Inertia::render('alerts', [
            'alerts' => $query->paginate(30),
            'unreadCount' => $unreadCount,
            'counts' => [
                'unread' => $unreadCount,
                'system' => (int) ($byType->get('system')->total ?? 0),
                'social' => (int) ($byType->get('social')->total ?? 0),
                'reminder' => (int) ($byType->get('reminder')->total ?? 0),
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

<?php

namespace App\Http\Controllers;

use App\Alerts\AlertClassifier;
use App\Models\Alert;
use App\Models\NotificationPreference;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class AlertController extends Controller
{
    public function index(Request $request): Response
    {
        $userId = $request->user()->id;

        // The v4 screen groups into lanes + filters by category client-side, so
        // ship a flat enriched list (recent, capped) rather than a page.
        $alerts = $request->user()->alerts()
            ->whereNull('dismissed_at')
            ->orderByDesc('created_at')
            ->limit(80)
            ->get();

        // Single grouped aggregate replaces five clone+count queries.
        /** @var Collection<int, object{type: ?string, total: int, unread: int}> $aggregates */
        $aggregates = DB::table('alerts')
            ->selectRaw('type, COUNT(*) AS total, COUNT(*) FILTER (WHERE read_at IS NULL) AS unread')
            ->where('user_id', $userId)
            ->whereNull('dismissed_at')
            ->groupBy('type')
            ->get();

        $unreadCount = (int) $aggregates->sum('unread');
        $byType = $aggregates->keyBy('type');

        return Inertia::render('alerts', [
            'alerts' => $alerts->map(fn (Alert $alert) => $this->present($alert))->all(),
            'unreadCount' => $unreadCount,
            'counts' => [
                'unread' => $unreadCount,
                'system' => (int) ($byType->get('system')->total ?? 0),
                'social' => (int) ($byType->get('social')->total ?? 0),
                'reminder' => (int) ($byType->get('reminder')->total ?? 0),
            ],
            'notificationPreferences' => $request->user()->notificationPreference?->preferences
                ?? NotificationPreference::defaults(),
        ]);
    }

    /**
     * Shape one alert for the v4 screen, deriving lane / category / severity /
     * source / action from `subtype` when the stored column is null (old rows).
     *
     * @return array<string, mixed>
     */
    private function present(Alert $alert): array
    {
        $subtype = $alert->subtype;
        $severity = $alert->severity ?: 'info';

        return [
            'id' => $alert->id,
            'category' => $alert->category ?: AlertClassifier::category($subtype),
            'lane' => $alert->lane ?: AlertClassifier::lane($subtype, $severity),
            'severity' => $severity,
            'source' => AlertClassifier::source($subtype),
            'action_label' => $alert->deep_link ? AlertClassifier::actionLabel($subtype) : null,
            'title' => $alert->title,
            'body' => (string) $alert->body,
            'deep_link' => $alert->deep_link,
            'read' => $alert->read_at !== null,
            'created_at' => $alert->created_at?->toIso8601String(),
        ];
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

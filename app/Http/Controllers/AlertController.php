<?php

namespace App\Http\Controllers;

use App\Alerts\AlertClassifier;
use App\Models\Alert;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class AlertController extends Controller
{
    public function index(Request $request): Response
    {
        // The v4 screen groups into lanes + filters by category client-side, so
        // ship a flat enriched list (recent, capped) rather than a page. Settings
        // now live on their own page (/settings/notifications); the right rail is
        // the shared widgets panel, so this only feeds the list + header count.
        $alerts = $request->user()->alerts()
            ->whereNull('dismissed_at')
            ->orderByDesc('created_at')
            ->limit(80)
            ->get();

        return Inertia::render('alerts', [
            'alerts' => $alerts->map(fn (Alert $alert) => $this->present($alert))->all(),
            'unreadCount' => $request->user()->alerts()
                ->whereNull('dismissed_at')
                ->whereNull('read_at')
                ->count(),
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

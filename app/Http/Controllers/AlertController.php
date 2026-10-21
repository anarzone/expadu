<?php

namespace App\Http\Controllers;

use App\Alerts\AlertClassifier;
use App\Bureaucracy\GuidancePublication;
use App\Models\Alert;
use App\Models\CityNews;
use App\Models\NoticeBrief;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
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
            ->publishable()
            ->whereNull('dismissed_at')
            // updated_at so a coalesced card (a weather warning refreshed this
            // poll) rises to the top instead of sitting at its first-seen time.
            ->orderByDesc('updated_at')
            ->limit(80)
            ->get();

        $briefs = $this->briefs($alerts);

        return Inertia::render('alerts', [
            'alerts' => $alerts->map(fn (Alert $alert) => $this->present($alert, $briefs[$alert->group_key] ?? null))->all(),
            'unreadCount' => $request->user()->alerts()
                ->publishable()
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
    private function present(Alert $alert, ?NoticeBrief $brief = null): array
    {
        $subtype = $alert->subtype;
        $severity = $alert->severity ?: 'info';
        $withdrawn = in_array($subtype, ['permanent_residency', 'permanent_residency_eligible'], true)
            || ($subtype === 'bureaucracy_deadline'
                && ! app(GuidancePublication::class)->allowsReference($alert->guidance_reference, (int) $alert->user_id));

        return [
            'id' => $alert->id,
            'category' => $alert->category ?: AlertClassifier::category($subtype),
            'lane' => $withdrawn ? 'action' : ($alert->lane ?: AlertClassifier::lane($subtype, $severity)),
            'severity' => $withdrawn ? 'info' : $severity,
            'source' => $withdrawn ? 'Expadu' : AlertClassifier::source($subtype),
            'action_label' => $withdrawn ? 'Review your current plan' : ($alert->deep_link ? AlertClassifier::actionLabel($subtype) : null),
            // A KVB notice's English brief, when one is written, says what the
            // card's own words can't; its impact line is never shown.
            'title' => $withdrawn ? 'Saved guidance needs review' : ($brief?->headline ?: $alert->title),
            'body' => $withdrawn ? 'This saved message is not current guidance. Your history is preserved; open Bureaucracy for the current plan.' : ($brief?->body ?: (string) $alert->body),
            'deep_link' => $withdrawn ? '/bureaucracy' : $alert->deep_link,
            'guidance_status' => $withdrawn ? 'review_required' : null,
            'read' => $alert->read_at !== null,
            // The card's timestamp is its last activity, so a refreshed weather
            // card reads "just now", not the hour it first appeared.
            'created_at' => ($alert->updated_at ?? $alert->created_at)?->toIso8601String(),
            // >1 when the same subject recurred while this card stayed live.
            'occurrences' => (int) ($alert->occurrence_count ?? 1),
        ];
    }

    /**
     * The English briefs (App\Transit\NoticeBriefWriter) of the KVB notices
     * behind these transit cards, keyed by the card's group (disruption:news_<id>).
     * Read live, so a brief written after the card appeared still shows.
     *
     * @param  Collection<int, Alert>  $alerts
     * @return array<string, NoticeBrief>
     */
    private function briefs(Collection $alerts): array
    {
        $ids = $alerts
            ->where('subtype', 'transit_disruption')
            ->map(fn (Alert $alert) => preg_match('/^disruption:news_(\d+)$/', (string) $alert->group_key, $m) === 1 ? (int) $m[1] : null)
            ->filter()
            ->unique()
            ->values();
        if ($ids->isEmpty()) {
            return [];
        }

        $hashes = CityNews::query()->whereKey($ids)->get(['id', 'title', 'summary'])
            ->mapWithKeys(fn (CityNews $notice) => [$notice->id => NoticeBrief::hashFor((string) $notice->title, $notice->summary)]);
        $briefs = NoticeBrief::query()->where('locale', 'en')->whereIn('input_hash', $hashes->values())->get()->keyBy('input_hash');

        return $hashes
            ->filter(fn (string $hash) => $briefs->has($hash))
            ->mapWithKeys(fn (string $hash, int $id) => ["disruption:news_{$id}" => $briefs->get($hash)])
            ->all();
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

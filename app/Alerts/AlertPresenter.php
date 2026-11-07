<?php

namespace App\Alerts;

use App\Bureaucracy\GuidancePublication;
use App\Models\Alert;
use App\Models\CityNews;
use App\Models\NoticeBrief;
use App\Models\User;
use App\Services\DisruptionService;
use Illuminate\Support\Collection;

/**
 * One alert as the bell shows it, for the web's Alerts page and the iPhone's sheet alike: the
 * same list, the same withdrawn-guidance wording, and the same KVB notice behind a transit card.
 */
final class AlertPresenter
{
    /** How many cards the list holds. */
    private const LIMIT = 80;

    /**
     * Their recent cards, newest activity first: updated_at, so a coalesced card (a weather
     * warning refreshed this poll) rises to the top instead of sitting at its first-seen time.
     *
     * @return Collection<int, Alert>
     */
    public function recent(User $user): Collection
    {
        return $user->alerts()
            ->publishable()
            ->whereNull('dismissed_at')
            ->orderByDesc('updated_at')
            ->limit(self::LIMIT)
            ->get();
    }

    public function unread(User $user): int
    {
        return $user->alerts()
            ->publishable()
            ->whereNull('dismissed_at')
            ->whereNull('read_at')
            ->count();
    }

    /**
     * Shape one alert for the v4 screen, deriving lane / category / severity /
     * source / action from `subtype` when the stored column is null (old rows).
     *
     * @param  array{brief: ?NoticeBrief, title: string, details: ?string, url: ?string, lines: list<string>, stops: list<string>}|null  $notice
     * @return array<string, mixed>
     */
    public function present(Alert $alert, ?array $notice = null): array
    {
        $subtype = $alert->subtype;
        $severity = $alert->severity ?: 'info';
        $withdrawn = $this->withdrawn($alert);

        return [
            'id' => $alert->id,
            'category' => $alert->category ?: AlertClassifier::category($subtype),
            'lane' => $withdrawn ? 'action' : ($alert->lane ?: AlertClassifier::lane($subtype, $severity)),
            'severity' => $withdrawn ? 'info' : $severity,
            'source' => $withdrawn ? 'Expadu' : AlertClassifier::source($subtype),
            'action_label' => $withdrawn ? 'Review your current plan' : ($alert->deep_link ? AlertClassifier::actionLabel($subtype) : null),
            // A KVB notice's card says it in the English brief when one is
            // written, else in KVB's own words (German); its page is one tap
            // away either way. The brief's impact line is never shown.
            'title' => $withdrawn ? 'Saved guidance needs review' : ($notice === null ? $alert->title : ($notice['brief']?->headline ?: $notice['title'])),
            'body' => $withdrawn ? 'This saved message is not current guidance. Your history is preserved; open Paperwork for the current plan.'
                : ($notice === null ? (string) $alert->body : ($notice['brief'] !== null ? ($notice['brief']->body ?: (string) $alert->body) : ($notice['details'] ?? ''))),
            'notice' => $withdrawn || $notice === null ? null : ['lang' => $notice['brief'] !== null ? 'en' : 'de', 'url' => $notice['url']],
            'deep_link' => $withdrawn ? route('paperwork', [], false) : $alert->deep_link,
            'guidance_status' => $withdrawn ? 'review_required' : null,
            'read' => $alert->read_at !== null,
            // The card's timestamp is its last activity, so a refreshed weather
            // card reads "just now", not the hour it first appeared.
            'created_at' => ($alert->updated_at ?? $alert->created_at)?->toIso8601String(),
            // >1 when the same subject recurred while this card stayed live.
            'occurrences' => (int) ($alert->occurrence_count ?? 1),
        ];
    }

    /** Saved guidance that is no longer current reads as "needs review", never as its old words. */
    private function withdrawn(Alert $alert): bool
    {
        return in_array($alert->subtype, ['permanent_residency', 'permanent_residency_eligible'], true)
            || ($alert->subtype === 'bureaucracy_deadline'
                && ! app(GuidancePublication::class)->allowsReference($alert->guidance_reference, (int) $alert->user_id));
    }

    /**
     * The lines a transit card is about: its KVB notice's own, else the line a delay card is
     * grouped on (delay:<line>:<direction>). Nothing for any other card.
     *
     * @param  array{lines: list<string>}|null  $notice
     * @return list<string>
     */
    public function lines(Alert $alert, ?array $notice): array
    {
        if ($notice !== null) {
            return $notice['lines'];
        }

        return $alert->subtype === 'transit_delay' && preg_match('/^delay:([^:]+):/', (string) $alert->group_key, $m) === 1 ? [$m[1]] : [];
    }

    /**
     * The KVB notices behind these transit cards, keyed by the card's group
     * (disruption:news_<id>): KVB's own title and text, its page, the lines and
     * stops it names, and the English brief (App\Transit\NoticeBriefWriter) when
     * one is written. Read live, so a brief written after the card appeared still shows.
     *
     * @param  Collection<int, Alert>  $alerts
     * @return array<string, array{brief: ?NoticeBrief, title: string, details: ?string, url: ?string, lines: list<string>, stops: list<string>}>
     */
    public function notices(Collection $alerts): array
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

        $notices = CityNews::query()->whereKey($ids)->get(['id', 'title', 'summary', 'source_url', 'affected_lines', 'affected_stops']);
        $hashes = $notices->mapWithKeys(fn (CityNews $notice) => [$notice->id => NoticeBrief::hashFor((string) $notice->title, $notice->summary)]);
        $briefs = NoticeBrief::query()->where('locale', 'en')->whereIn('input_hash', $hashes->values())->get()->keyBy('input_hash');
        $names = fn (mixed $list): array => array_values(array_filter(array_map(fn (mixed $name) => is_scalar($name) ? trim((string) $name) : '', (array) ($list ?? [])), fn (string $name) => $name !== ''));

        return $notices
            ->mapWithKeys(fn (CityNews $notice) => ["disruption:news_{$notice->id}" => [
                'brief' => $briefs->get($hashes[$notice->id]),
                'title' => (string) $notice->title,
                'details' => ($notice->summary ?? '') !== '' ? $notice->summary : null,
                'url' => DisruptionService::kvbPage($notice->source_url),
                'lines' => $names($notice->affected_lines),
                'stops' => $names($notice->affected_stops),
            ]])
            ->all();
    }
}

<?php

namespace App\Composer\Actions;

use App\Models\Alert;
use App\Models\User;
use App\Services\KvbApiService;
use App\Services\MuteService;
use Carbon\CarbonImmutable;

/**
 * Alerts, as the app keeps them: a KVB line's disruption and delay pushes muted for today or
 * a week (MuteService, which ActionBus checks before a push), and alerts dismissed from the
 * Alerts page as its Dismiss does. What an alert says stays out of what the AI is told
 * (a Paperwork deadline is one): only how many.
 */
final class AlertActions implements ComposerAction
{
    /** The pushes a line's mute silences, as MuteService::muteSubjectFor keys them. */
    private const LINE_KINDS = ['transit_disruption', 'transit_delay'];

    /** What can be dismissed at once, by the Alerts page's categories (AlertClassifier::category). */
    private const KINDS = ['transit' => ['transit'], 'city' => ['city'], 'events' => ['events'], 'all' => null];

    private const LINK = ['label' => 'See your alerts →', 'href' => '/alerts'];

    public function __construct(private MuteService $mutes, private KvbApiService $kvb) {}

    public function prepare(User $user, string $action, array $input, CarbonImmutable $now): array
    {
        return $action === 'mute_alerts' ? $this->prepareMute($user, $input, $now) : $this->prepareDismiss($user, $input);
    }

    public function run(User $user, string $action, array $prepared, CarbonImmutable $now): array
    {
        if ($action === 'mute_alerts') {
            $this->mute($user, $prepared['line'], $prepared['on'] ? $prepared['seconds'] : null);
            $title = $prepared['on'] ? "No alerts about line {$prepared['label']} {$prepared['until']}" : "Alerts about line {$prepared['label']} are back on";

            return ['title' => $title, 'link' => self::LINK, 'said' => "{$title}.",
                'undo' => ['line' => $prepared['line'], 'label' => $prepared['label'], 'on' => $prepared['on'], 'previous' => $prepared['previous']]];
        }
        $dismissed = Alert::query()->where('user_id', $user->id)->whereIn('id', $prepared['ids'])->whereNull('dismissed_at')->get(['id', 'read_at']);
        if ($dismissed->isEmpty()) {
            throw new ActionRefused('Those alerts are gone already.');
        }
        Alert::query()->whereIn('id', $dismissed->pluck('id'))->update(['dismissed_at' => now(), 'read_at' => now()]);
        // Only the ones unread before are unread again on Undo.
        $unread = $dismissed->whereNull('read_at')->pluck('id')->all();
        $title = self::count($dismissed->count()).' dismissed';

        return ['title' => $title, 'link' => self::LINK, 'said' => "{$title}.", 'undo' => ['ids' => $dismissed->pluck('id')->all(), 'unread' => $unread]];
    }

    public function undo(User $user, string $action, array $undo, CarbonImmutable $now): array
    {
        if ($action === 'mute_alerts') {
            // Muted for as long as it was before, or not at all.
            $this->mute($user, $undo['line'], $undo['on'] ? null : $undo['previous']);

            return ['title' => $undo['on'] ? "Undone. Alerts about line {$undo['label']} are on." : "Undone. Alerts about line {$undo['label']} are muted again."];
        }
        Alert::query()->where('user_id', $user->id)->whereIn('id', $undo['ids'])->update(['dismissed_at' => null]);
        Alert::query()->where('user_id', $user->id)->whereIn('id', $undo['unread'])->update(['read_at' => null]);

        return ['title' => 'Undone. '.self::count(count($undo['ids'])).' back.'];
    }

    /** @return array<string, mixed> */
    private function prepareMute(User $user, array $input, CarbonImmutable $now): array
    {
        $said = trim((string) ($input['line'] ?? ''));
        $label = collect($this->kvb->getStops())->pluck('lines')->flatten()->unique()
            ->first(fn (mixed $line): bool => mb_strtolower((string) $line) === mb_strtolower(preg_replace('/^(line|linie)\s+/i', '', $said)));
        if ($label === null) {
            throw new ActionRefused($said === '' ? 'Say which KVB line.' : "No KVB line is called \"{$said}\".");
        }
        $line = mb_strtolower((string) $label);
        $on = ($input['on'] ?? true) !== false;
        // How long it's muted now: what Undo puts back.
        $previous = collect($this->mutes->activeMutes($user))->firstWhere(fn (array $m): bool => $m['type'] === self::LINE_KINDS[0] && $m['key'] === $line)['ttl_seconds'] ?? null;
        if ($on === ($previous !== null)) {
            throw new ActionRefused($on ? "Alerts about line {$label} are muted already." : "Alerts about line {$label} aren’t muted.");
        }
        $week = ($input['for'] ?? 'today') === 'week';
        $seconds = $week ? 7 * 86400 : max(60, (int) $now->setTimezone('Europe/Berlin')->endOfDay()->diffInSeconds($now, true));
        $until = $week ? 'for a week' : 'until midnight';

        return ['line' => $line, 'label' => (string) $label, 'on' => $on, 'seconds' => $seconds, 'until' => $until, 'previous' => $previous,
            'propose' => $on ? "Mute alerts about line {$label} {$until}?" : "Turn alerts about line {$label} back on?"];
    }

    /** @return array<string, mixed> */
    private function prepareDismiss(User $user, array $input): array
    {
        $kind = is_string($input['kind'] ?? null) && array_key_exists($input['kind'], self::KINDS) ? $input['kind'] : 'all';
        $ids = Alert::query()->where('user_id', $user->id)->whereNull('dismissed_at')
            ->when(self::KINDS[$kind] !== null, fn ($q) => $q->whereIn('category', self::KINDS[$kind]))->pluck('id')->all();
        if ($ids === []) {
            throw new ActionRefused($kind === 'all' ? 'They have no alerts to dismiss.' : "They have no {$kind} alerts to dismiss.");
        }

        return ['ids' => $ids, 'kind' => $kind, 'propose' => 'Dismiss '.mb_strtolower(self::count(count($ids))).'?'];
    }

    /** Mutes a line's pushes for this long, or unmutes them (null). */
    private function mute(User $user, string $line, ?int $seconds): void
    {
        foreach (self::LINE_KINDS as $type) {
            $seconds === null ? $this->mutes->unmute($user, $type, $line) : $this->mutes->mute($user, $type, $line, $seconds);
        }
    }

    private static function count(int $n): string
    {
        return $n === 1 ? '1 alert' : "{$n} alerts";
    }
}

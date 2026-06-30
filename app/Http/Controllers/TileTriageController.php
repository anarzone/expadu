<?php

namespace App\Http\Controllers;

use App\Home\TileTriage;
use App\Models\UserEvent;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Triage controls on the Today "Right now" tiles — Done / Snooze / Dismiss.
 *
 * Each writes a TTL'd suppression (TileTriage) so the tile stays gone across
 * reloads, with the duration carrying the meaning:
 *   - done    → handled, hide for the rest of today
 *   - snooze  → not now, back in a few hours
 *   - dismiss → not relevant, hide for the day AND record a thumbs-down that
 *               demotes this kind of alert in the feed for a week (TileComposer)
 *
 * undo() lifts one triage and reverses its dismiss signal — the per-tile Undo.
 */
class TileTriageController extends Controller
{
    private const SNOOZE_SECONDS = 3 * 3600;

    private const DISMISS_SECONDS = 24 * 3600;

    public function __construct(private TileTriage $triage) {}

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'type' => ['required', 'string', 'max:64'],
            'key' => ['required', 'string', 'max:191'],
            'action' => ['required', Rule::in(['done', 'snooze', 'dismiss'])],
        ]);

        $user = $request->user();

        $ttl = match ($data['action']) {
            'snooze' => self::SNOOZE_SECONDS,
            'dismiss' => self::DISMISS_SECONDS,
            default => $this->secondsUntilEndOfDay(),
        };

        $this->triage->apply($user->id, $data['type'], $data['key'], $data['action'], $ttl);

        // "Not relevant" is a learning signal for the feed, not just a hide.
        if ($data['action'] === 'dismiss') {
            UserEvent::create([
                'user_id' => $user->id,
                'event_type' => 'card_dismissed',
                'session_id' => session()->getId() ?: 'tile-triage',
                'payload' => [
                    'action_key' => $data['type'].':'.$data['key'],
                    'card_type' => $data['type'],
                    'source' => 'tile_dismiss',
                ],
            ]);
        }

        return response()->json(['ok' => true]);
    }

    public function undo(Request $request): JsonResponse
    {
        $data = $request->validate([
            'type' => ['required', 'string', 'max:64'],
            'key' => ['required', 'string', 'max:191'],
        ]);

        $user = $request->user();

        $this->triage->clear($user->id, $data['type'], $data['key']);

        // Reverse the dismiss learning signal too, so Undo fully un-does a
        // dismiss (no lingering demotion). A no-op for done/snooze.
        UserEvent::query()
            ->where('user_id', $user->id)
            ->where('event_type', 'card_dismissed')
            ->where('payload->action_key', $data['type'].':'.$data['key'])
            ->delete();

        return response()->json(['ok' => true]);
    }

    private function secondsUntilEndOfDay(): int
    {
        $now = CarbonImmutable::now('Europe/Berlin');

        return max(60, (int) $now->diffInSeconds($now->endOfDay()));
    }
}

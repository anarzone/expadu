<?php

namespace App\Http\Controllers;

use App\Models\UserEvent;
use App\Services\MuteService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Roadmap #6 (mute) + #7 (thumbs-down) — frontend endpoints.
 *
 * Mute: per (type, key) Redis entry with TTL. ActionBus strips push
 *   channel for muted (type, key) before zadd.
 * Thumbs-down: records a UserEvent of card_dismissed with the action_key
 *   in payload; ActionBus consults this for the next 7 days as a
 *   suppression signal AND it's a learning signal for personalisation.
 */
class MuteController extends Controller
{
    public function __construct(private MuteService $mutes) {}

    public function store(Request $request): JsonResponse|RedirectResponse
    {
        $data = $request->validate([
            'type' => ['required', 'string', Rule::in([
                'transit_disruption', 'transit_delay', 'weather_alert',
                'rhine_level', 'buergeramt_slot', 'market_closure',
                'leave_by', 'alternative_route',
            ])],
            'key' => ['required', 'string', 'max:128'],
            'duration_seconds' => ['nullable', 'integer', 'min:60', 'max:604800'],
        ]);

        $this->mutes->mute(
            $request->user(),
            $data['type'],
            mb_strtolower($data['key']),
            $data['duration_seconds'] ?? MuteService::DEFAULT_TTL_SECONDS,
        );

        if ($request->wantsJson()) {
            return response()->json(['ok' => true]);
        }

        return back();
    }

    public function destroy(Request $request): JsonResponse|RedirectResponse
    {
        $data = $request->validate([
            'type' => ['required', 'string'],
            'key' => ['required', 'string', 'max:128'],
        ]);

        $this->mutes->unmute(
            $request->user(),
            $data['type'],
            mb_strtolower($data['key']),
        );

        if ($request->wantsJson()) {
            return response()->json(['ok' => true]);
        }

        return back();
    }

    public function index(Request $request): JsonResponse
    {
        return response()->json([
            'mutes' => $this->mutes->activeMutes($request->user()),
        ]);
    }

    public function thumbsDown(Request $request): JsonResponse|RedirectResponse
    {
        $data = $request->validate([
            'action_key' => ['required', 'string', 'max:255'],
            'card_type' => ['nullable', 'string', 'max:64'],
        ]);

        UserEvent::create([
            'user_id' => $request->user()->id,
            'event_type' => 'card_dismissed',
            'session_id' => session()->getId() ?: 'mute-controller',
            'payload' => [
                'action_key' => $data['action_key'],
                'card_type' => $data['card_type'] ?? null,
                'source' => 'thumbs_down',
            ],
        ]);

        if ($request->wantsJson()) {
            return response()->json(['ok' => true]);
        }

        return back();
    }
}

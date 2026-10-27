<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Personalisation\SignalAction;
use App\Personalisation\SignalRecorder;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * POST /api/signals/needs — what only Today's "Needs you" strip sees: an item shown (once a day an
 * item counts), tapped, dismissed, kept or undone, by its kind and today's id (TodayNeeds), never
 * what it said. Kept only with personalisation on under the second wording (SignalRecorder::need),
 * never for Paperwork; otherwise accepted and dropped, the same 202.
 */
class TodayNeedSignalController extends Controller
{
    private const ACTIONS = ['shown' => SignalAction::Impression, 'tapped' => SignalAction::Tapped, 'dismissed' => SignalAction::Dismissed,
        'kept' => SignalAction::Kept, 'undone' => SignalAction::Undone];

    public function __invoke(Request $request, SignalRecorder $signals): Response
    {
        $validated = $request->validate([
            'action' => ['required', 'string', 'in:'.implode(',', array_keys(self::ACTIONS))],
            'id' => ['required', 'string', 'regex:/^[0-9a-f]{16}$/'],
            'kind' => ['required', 'string', 'regex:/^[a-z_]{2,32}$/'],
        ]);
        $signals->need($request->user(), self::ACTIONS[$validated['action']], $validated['kind'], $validated['id']);

        return response()->noContent(202);
    }
}

<?php

namespace App\Console\Commands;

use App\ContextEngine\ActionBus;
use App\Models\Alert;
use App\Models\User;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * Replay shadow ZSET state vs the actual Alert table for a user.
 *
 * Reads the live `pending_actions:{userId}_shadow` ZSET (or `pending_actions:{userId}`
 * when --live is passed) and the user's last N days of Alert rows. Emits a JSON diff:
 * what the new pipeline would have surfaced vs what was actually delivered.
 *
 * Use during the 48h shadow window to validate parity before flipping
 * CONTEXT_ENGINE_ENABLED=true.
 */
#[Signature('context:replay {--user= : User ID} {--days=7 : Days of alerts to compare} {--live : Read live ZSET instead of shadow}')]
#[Description('Compare the new context-engine ZSET output against the actual Alert table for a user.')]
class ContextReplay extends Command
{
    public function handle(ActionBus $bus): int
    {
        $userId = (int) $this->option('user');
        if (! $userId) {
            $this->error('--user is required');

            return self::FAILURE;
        }

        $user = User::find($userId);
        if (! $user) {
            $this->error("User {$userId} not found");

            return self::FAILURE;
        }

        $days = (int) $this->option('days');

        // Force read from chosen ZSET regardless of current config flag
        $original = config('context_engine.shadow');
        config(['context_engine.shadow' => ! $this->option('live')]);
        $actions = $bus->topK($userId, 50);
        config(['context_engine.shadow' => $original]);

        $alerts = Alert::where('user_id', $userId)
            ->where('created_at', '>', now()->subDays($days))
            ->orderByDesc('created_at')
            ->get(['id', 'type', 'subtype', 'title', 'created_at']);

        $report = [
            'user' => [
                'id' => $user->id,
                'email' => $user->email,
                'situation' => $user->situation?->value,
            ],
            'window_days' => $days,
            'source_zset' => $this->option('live') ? 'pending_actions' : 'pending_actions_shadow',
            'engine_actions' => array_map(fn ($a) => [
                'type' => $a->type,
                'severity' => $a->severity,
                'score' => round($a->score, 1),
                'channels' => $a->deliverChannels,
                'action_key' => $a->actionKey,
            ], $actions),
            'engine_action_count' => count($actions),
            'actual_alerts' => $alerts->map(fn (Alert $a) => [
                'subtype' => $a->subtype,
                'title' => $a->title,
                'created_at' => $a->created_at?->toIso8601String(),
            ])->all(),
            'actual_alert_count' => $alerts->count(),
        ];

        $this->line(json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

        return self::SUCCESS;
    }
}

<?php

namespace App\Events\Context;

use App\ContextEngine\ScoredAction;
use App\Models\User;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Fired by ActionBus immediately after a ScoredAction is written to the
 * pending_actions ZSET. Listeners can react to drive side-effects:
 * - ScoredActionPushDispatcher → fires the matching Notification class
 *   (so push delivery flows through the same pipeline that drives the
 *   dashboard, instead of the dual-write the source commands had).
 * - Future: digest emailer, Slack channel, anything consuming the bus.
 */
class ScoredActionInserted
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public User $user,
        public ScoredAction $action,
    ) {}
}

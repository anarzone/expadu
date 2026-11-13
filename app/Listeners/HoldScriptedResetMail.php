<?php

namespace App\Listeners;

use App\Security\FormTraps;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Notifications\Events\NotificationSending;
use Illuminate\Support\Facades\Log;

/**
 * The web form for a password-reset link mails whatever address is typed
 * in, so a script could flood strangers through it as it did the waitlist.
 * When the post trips FormTraps the mail isn't sent, while the page still
 * says the link is on its way: the script learns nothing.
 *
 * Only the web form carries the traps; the iPhone app asks for its links
 * through the API, which this leaves alone.
 */
class HoldScriptedResetMail
{
    public function __construct(private readonly FormTraps $traps) {}

    public function handle(NotificationSending $event): bool
    {
        if (! $event->notification instanceof ResetPassword) {
            return true;
        }

        $request = request();
        if (! $request->routeIs('password.email')) {
            return true;
        }

        $suspicion = $this->traps->suspicion($request);
        if ($suspicion === null) {
            return true;
        }

        Log::info('signup.blocked', ['form' => 'forgot-password', 'reason' => $suspicion]);

        return false;
    }
}

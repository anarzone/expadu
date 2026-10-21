<?php

namespace App\Http\Responses;

use Illuminate\Support\Facades\Password;
use Laravel\Fortify\Http\Responses\FailedPasswordResetLinkRequestResponse;
use Laravel\Fortify\Http\Responses\SuccessfulPasswordResetLinkRequestResponse;

/**
 * Asking for a reset link never says whether an account has that email: an
 * unknown address gets the same answer as a known one ("The link is on its
 * way"), so the form can't be used to find out who has an account. So does
 * asking again within a minute: only an account's link can be throttled, and
 * its link from that minute is on its way and works. The per-IP limit on
 * every Fortify form (fortify-forms) still answers too many tries.
 */
class PasswordResetLinkFailedResponse extends FailedPasswordResetLinkRequestResponse
{
    public function toResponse($request)
    {
        if (in_array($this->status, [Password::INVALID_USER, Password::RESET_THROTTLED], true)) {
            return (new SuccessfulPasswordResetLinkRequestResponse(Password::RESET_LINK_SENT))->toResponse($request);
        }

        return parent::toResponse($request);
    }
}

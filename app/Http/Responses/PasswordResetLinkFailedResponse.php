<?php

namespace App\Http\Responses;

use Illuminate\Support\Facades\Password;
use Laravel\Fortify\Http\Responses\FailedPasswordResetLinkRequestResponse;
use Laravel\Fortify\Http\Responses\SuccessfulPasswordResetLinkRequestResponse;

/**
 * Asking for a reset link never says whether an account has that email: an
 * unknown address gets the same answer as a known one ("The link is on its
 * way"), so the form can't be used to find out who has an account. A
 * throttled request still says so.
 */
class PasswordResetLinkFailedResponse extends FailedPasswordResetLinkRequestResponse
{
    public function toResponse($request)
    {
        if ($this->status === Password::INVALID_USER) {
            return (new SuccessfulPasswordResetLinkRequestResponse(Password::RESET_LINK_SENT))->toResponse($request);
        }

        return parent::toResponse($request);
    }
}

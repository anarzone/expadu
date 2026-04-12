<?php

namespace App\Http\Responses;

use Laravel\Fortify\Contracts\LogoutResponse as LogoutResponseContract;
use Symfony\Component\HttpFoundation\Response;

class LogoutResponse implements LogoutResponseContract
{
    public function toResponse($request): Response
    {
        $marketingDomain = config('app.marketing_domain');

        // In production, redirect to the marketing site login
        if ($marketingDomain) {
            return redirect("https://{$marketingDomain}/login");
        }

        // Local dev: redirect to /login on same host
        return redirect('/login');
    }
}

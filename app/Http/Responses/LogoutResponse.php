<?php

namespace App\Http\Responses;

use Laravel\Fortify\Contracts\LogoutResponse as LogoutResponseContract;
use Symfony\Component\HttpFoundation\Response;

class LogoutResponse implements LogoutResponseContract
{
    public function toResponse($request): Response
    {
        // Always redirect to the app's own login page to avoid cross-origin CORS errors
        // (Inertia submits logout as XHR — cross-domain redirects are blocked by browsers)
        return redirect('/login');
    }
}

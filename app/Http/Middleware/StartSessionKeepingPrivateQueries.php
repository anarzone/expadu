<?php

namespace App\Http\Middleware;

use Illuminate\Contracts\Session\Session;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Illuminate\Session\Middleware\StartSession;

/**
 * Laravel's StartSession, except that a page marked KeepQueryPrivate is
 * remembered as the previous URL without its query: the session store (and
 * any redirect back) gets `/explore`, never `/explore?near=…&time=…`.
 */
class StartSessionKeepingPrivateQueries extends StartSession
{
    /**
     * @param  Session  $session
     */
    protected function storeCurrentUrl(Request $request, $session)
    {
        if (! $request->attributes->getBoolean(KeepQueryPrivate::ATTRIBUTE)) {
            parent::storeCurrentUrl($request, $session);

            return;
        }

        if ($request->isMethod('GET') && $request->route() instanceof Route && ! $request->ajax() && ! $request->prefetch() && ! $request->isPrecognitive()) {
            $session->setPreviousUrl($request->url());

            if (method_exists($session, 'setPreviousRoute')) {
                $session->setPreviousRoute($request->route()->getName());
            }
        }
    }
}

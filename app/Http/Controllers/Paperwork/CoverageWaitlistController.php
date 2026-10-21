<?php

namespace App\Http\Controllers\Paperwork;

use App\Http\Controllers\Controller;
use App\Waitlist\CoverageWaitlist;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Paperwork outside Cologne: "Tell me when it's covered" (App\Waitlist\
 * CoverageWaitlist). It takes no input: the email and the city are the
 * account's own, so nobody can sign up someone else's address from here.
 */
final class CoverageWaitlistController extends Controller
{
    public function store(Request $request, CoverageWaitlist $waitlist): RedirectResponse
    {
        $waitlist->join($request->user());

        return back();
    }
}

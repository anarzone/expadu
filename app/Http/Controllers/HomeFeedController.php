<?php

namespace App\Http\Controllers;

use App\Home\HomeFeed;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class HomeFeedController extends Controller
{
    public function __invoke(Request $request, HomeFeed $feed): Response
    {
        $user = $request->user();

        return Inertia::render('dashboard', [
            // Chips are cheap + above the fold, so they ship with the page.
            // (This first read builds the shared HomeContext.)
            'chips' => $feed->chips($user),
            // Urgency tiles and discovery rails defer — they hit the DB/feed
            // and resolve from a single shared build per request. The weather
            // chip + right-panel widgets are now shared globally (see
            // HandleInertiaRequests) so every page is fed, not just this one.
            'tiles' => Inertia::defer(fn () => $feed->tiles($user)),
            'rails' => Inertia::defer(fn () => $feed->rails($user)),
        ]);
    }
}

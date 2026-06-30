<?php

namespace App\Http\Controllers;

use App\Composer\TodayPlanStore;
use App\Home\HomeFeed;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class HomeFeedController extends Controller
{
    public function __invoke(Request $request, HomeFeed $feed, TodayPlanStore $todayPlan): Response
    {
        $user = $request->user();

        return Inertia::render('dashboard', [
            // Chips are cheap + above the fold, so they ship with the page.
            // (This first read builds the shared HomeContext.)
            'chips' => $feed->chips($user),
            // The plan the user pinned via the composer's "Save to Today" — a
            // cheap Redis read, reloadable on its own (router.reload).
            'savedPlan' => $todayPlan->get($user),
            // Urgency tiles and discovery rails defer — they hit the DB/feed
            // and resolve from a single shared build per request. The weather
            // chip + right-panel widgets are now shared globally (see
            // HandleInertiaRequests) so every page is fed, not just this one.
            'tiles' => Inertia::defer(fn () => $feed->tiles($user)),
            'rails' => Inertia::defer(fn () => $feed->rails($user)),
            // Count of tiles hidden by triage, so "Restore all" survives a reload
            // (the client's session-hidden set is empty then). Same deferred group
            // as tiles → resolves in the one build, no extra round-trip.
            'restorableTiles' => Inertia::defer(fn () => $feed->suppressedTileCount($user)),
        ]);
    }
}

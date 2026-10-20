<?php

namespace App\Http\Controllers;

use App\Day\DayProjection;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class HomeFeedController extends Controller
{
    public function __invoke(Request $request): Response
    {
        return Inertia::render('dashboard', [
            // The person's day: plans, the trips between them and when to
            // leave for the next (DayProjection; deferred, its own group).
            'day' => DayProjection::deferred($request),
        ]);
    }
}

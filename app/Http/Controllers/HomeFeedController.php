<?php

namespace App\Http\Controllers;

use App\Services\RecommendationService;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class HomeFeedController extends Controller
{
    public function __invoke(Request $request, RecommendationService $recommendationService): Response
    {
        $user = $request->user();

        return Inertia::render('dashboard', [
            'feed' => $recommendationService->buildDashboardFeed($user, $request),
            'commuteRecommendation' => $recommendationService->getCommuteRecommendation($user),
        ]);
    }
}

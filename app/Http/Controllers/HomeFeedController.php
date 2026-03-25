<?php

namespace App\Http\Controllers;

use App\Services\HomeCardService;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class HomeFeedController extends Controller
{
    public function __invoke(Request $request, HomeCardService $cardService): Response
    {
        return Inertia::render('dashboard', [
            'cards' => $cardService->buildFeed($request->user()),
        ]);
    }
}

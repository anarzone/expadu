<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class ProfilePageController extends Controller
{
    public function __invoke(Request $request): Response
    {
        return Inertia::render('profile', [
            'userPlaces' => $request->user()->places()->get(),
        ]);
    }
}

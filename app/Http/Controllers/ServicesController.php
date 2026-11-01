<?php

namespace App\Http\Controllers;

use Inertia\Inertia;
use Inertia\Response;

class ServicesController extends Controller
{
    /**
     * Coming soon: provider search waits for the post-launch Services page.
     * The `services` table and its import stay for that page.
     */
    public function index(): Response
    {
        return Inertia::render('services', ['comingSoon' => true]);
    }
}

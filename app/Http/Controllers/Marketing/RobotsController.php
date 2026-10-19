<?php

namespace App\Http\Controllers\Marketing;

use App\Http\Controllers\Controller;
use App\Marketing\SearchIndexing;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class RobotsController extends Controller
{
    /**
     * robots.txt for whichever host asked: staging and non-production
     * environments disallow everything, production points at the sitemap.
     */
    public function __invoke(Request $request): Response
    {
        $body = SearchIndexing::isHidden($request)
            ? "User-agent: *\nDisallow: /\n"
            : implode("\n", [
                'User-agent: *',
                'Allow: /',
                'Disallow: /dashboard',
                'Disallow: /alerts',
                'Disallow: /events',
                'Disallow: /transit',
                'Disallow: /explore',
                'Disallow: /bureaucracy',
                'Disallow: /profile',
                'Disallow: /settings',
                'Disallow: /onboarding',
                'Disallow: /api/',
                '',
                'Sitemap: '.route('sitemap'),
                '',
            ]);

        return response($body, 200, ['Content-Type' => 'text/plain; charset=UTF-8']);
    }
}

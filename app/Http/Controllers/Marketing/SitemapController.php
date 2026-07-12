<?php

namespace App\Http\Controllers\Marketing;

use App\Http\Controllers\Controller;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Cache;

class SitemapController extends Controller
{
    /**
     * The marketing sitemap — public, indexable pages only. App routes are
     * auth-walled and stay out (robots.txt already disallows them).
     */
    public function __invoke(): Response
    {
        $xml = Cache::remember('marketing.sitemap', now()->addHour(), function (): string {
            $urls = [
                ['loc' => route('home'), 'priority' => '1.0'],
                ['loc' => route('impressum'), 'priority' => '0.1'],
                ['loc' => route('datenschutz'), 'priority' => '0.1'],
            ];

            $entries = collect($urls)->map(fn (array $url): string => <<<XML
                    <url>
                        <loc>{$url['loc']}</loc>
                        <lastmod>{$this->lastmod()}</lastmod>
                        <priority>{$url['priority']}</priority>
                    </url>
                XML)->implode("\n");

            return <<<XML
                <?xml version="1.0" encoding="UTF-8"?>
                <urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">
                {$entries}
                </urlset>
                XML;
        });

        return response($xml, 200, ['Content-Type' => 'application/xml']);
    }

    private function lastmod(): string
    {
        return now()->toDateString();
    }
}

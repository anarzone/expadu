<?php

namespace App\Http\Controllers\Marketing;

use App\Http\Controllers\Controller;
use App\Marketing\BlogPosts;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Cache;

class SitemapController extends Controller
{
    public function __construct(private readonly BlogPosts $posts) {}

    /**
     * The marketing sitemap — public, indexable pages only. App routes are
     * auth-walled and stay out (robots.txt already disallows them).
     */
    public function __invoke(): Response
    {
        $xml = Cache::remember('marketing.sitemap', now()->addHour(), function (): string {
            $urls = [
                ['loc' => route('home'), 'priority' => '1.0'],
                ['loc' => route('tools.index'), 'priority' => '0.8'],
                ['loc' => route('tools.dticket'), 'priority' => '0.8'],
                ['loc' => route('tools.residency'), 'priority' => '0.8'],
                ['loc' => route('tools.citizenship'), 'priority' => '0.8'],
                ['loc' => route('blog.index'), 'priority' => '0.7'],
                ['loc' => route('impressum'), 'priority' => '0.1'],
                ['loc' => route('datenschutz'), 'priority' => '0.1'],
            ];

            foreach ($this->posts->all() as $post) {
                $urls[] = ['loc' => route('blog.show', $post['slug']), 'priority' => '0.7'];
            }

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

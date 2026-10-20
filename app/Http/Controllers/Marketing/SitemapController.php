<?php

namespace App\Http\Controllers\Marketing;

use App\Http\Controllers\Controller;
use App\Marketing\BlogPosts;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Cache;

class SitemapController extends Controller
{
    /**
     * The day each static page's content last changed. Bump the date when you
     * change a page's copy. File mtimes can't stand in: the CI checkout stamps
     * every file with the build time, and a lastmod that moves on every deploy
     * teaches crawlers to ignore it.
     */
    private const PAGE_UPDATED = [
        'home' => '2026-07-18',
        'tools.index' => '2026-07-17',
        'tools.dticket' => '2026-07-17',
        'tools.residency' => '2026-07-17',
        'tools.citizenship' => '2026-07-17',
        'tools.netto' => '2026-07-17',
        'blog.index' => '2026-07-17',
        'impressum' => '2026-07-18',
        'datenschutz' => '2026-07-18',
    ];

    public function __construct(private readonly BlogPosts $posts) {}

    /**
     * The marketing sitemap — public, indexable pages only. App routes are
     * auth-walled and stay out (robots.txt already disallows them).
     */
    public function __invoke(): Response
    {
        $xml = Cache::remember('marketing.sitemap', now()->addHour(), function (): string {
            $posts = $this->posts->all();

            // The blog index changes whenever a post does.
            $blogUpdated = collect($posts)
                ->map(fn (array $post): string => $post['updated_at']->toDateString())
                ->push(self::PAGE_UPDATED['blog.index'])
                ->max();

            $urls = [
                ['loc' => route('home'), 'lastmod' => self::PAGE_UPDATED['home'], 'priority' => '1.0'],
                ['loc' => route('tools.index'), 'lastmod' => self::PAGE_UPDATED['tools.index'], 'priority' => '0.8'],
                ['loc' => route('tools.dticket'), 'lastmod' => self::PAGE_UPDATED['tools.dticket'], 'priority' => '0.8'],
                ['loc' => route('tools.residency'), 'lastmod' => self::PAGE_UPDATED['tools.residency'], 'priority' => '0.8'],
                ['loc' => route('tools.citizenship'), 'lastmod' => self::PAGE_UPDATED['tools.citizenship'], 'priority' => '0.8'],
                ['loc' => route('tools.netto'), 'lastmod' => self::PAGE_UPDATED['tools.netto'], 'priority' => '0.8'],
                ['loc' => route('blog.index'), 'lastmod' => $blogUpdated, 'priority' => '0.7'],
                ['loc' => route('impressum'), 'lastmod' => self::PAGE_UPDATED['impressum'], 'priority' => '0.1'],
                ['loc' => route('datenschutz'), 'lastmod' => self::PAGE_UPDATED['datenschutz'], 'priority' => '0.1'],
            ];

            foreach ($posts as $post) {
                $urls[] = [
                    'loc' => route('blog.show', $post['slug']),
                    'lastmod' => $post['updated_at']->toDateString(),
                    'priority' => '0.7',
                ];
            }

            $entries = collect($urls)->map(fn (array $url): string => <<<XML
                    <url>
                        <loc>{$url['loc']}</loc>
                        <lastmod>{$url['lastmod']}</lastmod>
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
}

<?php

namespace App\Console\Commands\Marketing;

use App\Bureaucracy\Verification\Escalations;
use App\Bureaucracy\Verification\SourcePageFetcher;
use App\Marketing\BlogPosts;
use App\Models\BureaucracyEscalation;
use Illuminate\Console\Command;
use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * The marketing pages' content is written by hand (guides in markdown, tool
 * sources in the views), so nothing else notices when a city page moves or a
 * phone number changes. Every morning this reads each page as a visitor gets
 * it, then checks every outside link and phone number against the live web:
 *
 * - a link that is gone (404/410, or a "page not found" page) alerts at once;
 * - a link that can't be reached alerts only on the second day in a row, so a
 *   short outage of a city site stays quiet;
 * - a link that now redirects to a different page alerts with the new address;
 * - a phone number alerts when none of the pages its own page links to shows it.
 *
 * Alerts go through the same escalations as the catalogue's source check: each
 * opens once, alerts once, and closes by itself when the link or number passes
 * again or leaves the pages.
 */
class CheckLinksCommand extends Command
{
    protected $signature = 'marketing:check-links {--strict : Exit non-zero when anything is flagged}';

    protected $description = 'Check every outside link and phone number on the marketing pages against the live web';

    private const Broken = 'marketing_link_broken';

    private const Moved = 'marketing_link_moved';

    private const Phone = 'marketing_phone_unconfirmed';

    /** Each fetch is one polite request; a short pause keeps the city's servers unbothered. */
    private const PauseMicroseconds = 250_000;

    public function handle(SourcePageFetcher $fetcher, Escalations $escalations, BlogPosts $posts, HttpKernel $kernel): int
    {
        // Rendering the pages here must not write a session per page.
        config(['session.driver' => 'array']);

        $links = [];   // url => ['pages' => [path, ...], 'text' => string]
        $phones = [];  // path => [digits => shown number]
        $pageLinks = []; // path => [url, ...]
        foreach ($this->pages($posts) as $path => $url) {
            $html = $this->render($kernel, $url);
            if ($html === null) {
                $this->warn("Couldn't render {$path}; its links are skipped today.");

                continue;
            }
            $ownHost = parse_url($url, PHP_URL_HOST);
            foreach ($this->anchors($html) as [$href, $text]) {
                if (str_starts_with($href, 'tel:')) {
                    $phones[$path][$this->digits(substr($href, 4))] = $text;

                    continue;
                }
                $href = explode('#', $href, 2)[0];
                $host = parse_url($href, PHP_URL_HOST);
                if (! preg_match('#^https?://#i', $href) || ! is_string($host) || $this->ownHost($host, $ownHost)) {
                    continue;
                }
                $links[$href]['pages'][] = $path;
                $links[$href]['text'] ??= $text;
                $pageLinks[$path][] = $href;
            }
        }

        $hosts = array_values(array_unique(array_map(fn (string $url): string => (string) parse_url($url, PHP_URL_HOST), array_keys($links))));
        $today = now('Europe/Berlin')->toDateString();
        $flagged = [self::Broken => [], self::Moved => [], self::Phone => []];
        $texts = [];
        $rows = [];

        foreach ($links as $url => $link) {
            $where = implode(', ', array_unique($link['pages']));
            if ($this->manual($url)) {
                $rows[] = [$url, 'check by hand (bot check)', $where];

                continue;
            }
            $result = $fetcher->fetch($url, $hosts);
            usleep(self::PauseMicroseconds);
            $failingKey = 'marketing.link-check.failing.'.sha1($url);

            if ($result['status'] === 'ok' && $this->notFoundPage($result['title'] ?? '')) {
                $result = ['status' => 'missing', 'reason' => 'not_found_page', 'url' => $result['url'] ?? $url];
            }

            if ($result['status'] === 'ok') {
                Cache::forget($failingKey);
                $texts[$url] = $result['text'] ?? '';
                $escalations->resolve(self::Broken, $url);
                $final = $result['url'] ?? $url;
                if ($this->samePage($url, $final)) {
                    $escalations->resolve(self::Moved, $url);
                    $rows[] = [$url, 'ok', $where];
                } else {
                    $flagged[self::Moved][] = $url;
                    $escalations->raise(self::Moved, $url, Escalations::High,
                        "Marketing link moved: {$url} now goes to {$final}. Update it on {$where}.",
                        ['now' => $final, 'title' => $result['title'] ?? '', 'pages' => array_values(array_unique($link['pages']))]);
                    $rows[] = [$url, "moved → {$final}", $where];
                }

                continue;
            }

            $reason = $result['reason'] ?? $result['status'];
            if ($result['status'] === 'unreachable') {
                $since = Cache::get($failingKey);
                if (! is_string($since)) {
                    Cache::put($failingKey, $today, now()->addDays(3));
                }
                if (! is_string($since) || $since === $today) {
                    // First day it fails: wait for tomorrow before alerting anyone.
                    $rows[] = [$url, "unreachable today ({$reason}); alerts if it fails again tomorrow", $where];

                    continue;
                }
            }
            $flagged[self::Broken][] = $url;
            $escalations->raise(self::Broken, $url, Escalations::High,
                "Marketing link is broken ({$reason}): {$url} on {$where}.",
                ['reason' => $reason, 'http_status' => $result['http_status'] ?? null, 'pages' => array_values(array_unique($link['pages']))]);
            $rows[] = [$url, "broken ({$reason})", $where];
        }

        foreach ($phones as $path => $numbers) {
            $sources = array_filter(array_map(fn (string $url): ?string => $texts[$url] ?? null, array_unique($pageLinks[$path] ?? [])));
            foreach ($numbers as $digits => $shown) {
                $digits = (string) $digits;
                $subject = "{$path} {$shown}";
                if ($sources === []) {
                    $rows[] = ["tel: {$shown}", 'not checked (no page it links to could be read)', $path];

                    continue;
                }
                $found = array_filter($sources, fn (string $text): bool => str_contains($this->digits($text), $this->local($digits)));
                if ($found !== []) {
                    $escalations->resolve(self::Phone, $subject);
                    $rows[] = ["tel: {$shown}", 'ok', $path];

                    continue;
                }
                $flagged[self::Phone][] = $subject;
                $escalations->raise(self::Phone, $subject, Escalations::High,
                    "Phone number {$shown} on {$path} doesn't appear on any page that page links to. Check the number, or link the page it comes from.",
                    ['page' => $path, 'number' => $shown]);
                $rows[] = ["tel: {$shown}", 'not on any linked page', $path];
            }
        }

        // Links and numbers that left the pages close their alerts.
        $current = [self::Broken => array_keys($links), self::Moved => array_keys($links), self::Phone => []];
        foreach ($phones as $path => $numbers) {
            foreach ($numbers as $shown) {
                $current[self::Phone][] = "{$path} {$shown}";
            }
        }
        BureaucracyEscalation::query()->whereIn('kind', array_keys($current))->whereNull('resolved_at')->get(['kind', 'subject'])
            ->each(function (BureaucracyEscalation $open) use ($current, $escalations): void {
                if (! in_array($open->subject, $current[$open->kind], true)) {
                    $escalations->resolve($open->kind, $open->subject);
                }
            });

        $this->table(['Link', 'Result', 'On'], $rows);
        $count = count($flagged[self::Broken]) + count($flagged[self::Moved]) + count($flagged[self::Phone]);
        $this->info(sprintf('%d links and %d phone numbers checked; %d broken, %d moved, %d numbers unconfirmed.',
            count($links), array_sum(array_map('count', $phones)), count($flagged[self::Broken]), count($flagged[self::Moved]), count($flagged[self::Phone])));

        return $this->option('strict') && $count > 0 ? self::FAILURE : self::SUCCESS;
    }

    /**
     * Every marketing page with hand-written content, by path.
     *
     * @return array<string, string>
     */
    private function pages(BlogPosts $posts): array
    {
        $urls = [];
        foreach (['home', 'tools.index', 'tools.netto', 'tools.dticket', 'tools.residency', 'tools.citizenship', 'blog.index', 'impressum', 'datenschutz'] as $name) {
            $urls[] = route($name);
        }
        foreach ($posts->all() as $post) {
            $urls[] = route('blog.show', $post['slug']);
        }

        $pages = [];
        foreach ($urls as $url) {
            $pages['/'.ltrim((string) parse_url($url, PHP_URL_PATH), '/')] = $url;
        }

        return $pages;
    }

    /** The page as a visitor gets it, or null when it doesn't render. */
    private function render(HttpKernel $kernel, string $url): ?string
    {
        try {
            $request = Request::create($url, 'GET', server: ['HTTP_ACCEPT' => 'text/html']);
            $response = $kernel->handle($request);
            $kernel->terminate($request, $response);
        } catch (Throwable) {
            return null;
        }

        return $response->getStatusCode() === 200 ? (string) $response->getContent() : null;
    }

    /** @return array<int, array{0: string, 1: string}> href and visible text of each link in the page body */
    private function anchors(string $html): array
    {
        $body = stristr($html, '<body') ?: $html;
        preg_match_all('#<a\b[^>]*\bhref="([^"]+)"[^>]*>(.*?)</a>#is', $body, $matches, PREG_SET_ORDER);

        return array_map(fn (array $m): array => [
            html_entity_decode($m[1], ENT_QUOTES | ENT_HTML5, 'UTF-8'),
            trim((string) preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags($m[2]), ENT_QUOTES | ENT_HTML5, 'UTF-8'))),
        ], $matches);
    }

    private function ownHost(string $host, mixed $pageHost): bool
    {
        $own = array_filter([$pageHost, config('app.marketing_domain'), config('app.app_domain'), parse_url((string) config('app.url'), PHP_URL_HOST)]);

        return in_array(strtolower($host), array_map('strtolower', $own), true);
    }

    private function manual(string $url): bool
    {
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));
        foreach (config('marketing_links.manual_hosts', []) as $manual) {
            if ($host === $manual || str_ends_with($host, '.'.$manual)) {
                return true;
            }
        }

        return false;
    }

    /** A redirect to the same page with only a session query or a trailing slash added isn't a move. */
    private function samePage(string $from, string $to): bool
    {
        $key = fn (string $url): string => strtolower((string) parse_url($url, PHP_URL_HOST)).rtrim((string) parse_url($url, PHP_URL_PATH), '/');

        return $key($from) === $key($to);
    }

    /** City sites answer some removed pages with a normal page titled "not found". */
    private function notFoundPage(string $title): bool
    {
        return preg_match('/nicht gefunden|not found|seite existiert nicht|\b404\b/i', $title) === 1;
    }

    private function digits(string $text): string
    {
        return (string) preg_replace('/\D+/', '', $text);
    }

    /** +49 221 … as written on German pages: 0221 … */
    private function local(string $digits): string
    {
        return str_starts_with($digits, '49') ? '0'.substr($digits, 2) : $digits;
    }
}

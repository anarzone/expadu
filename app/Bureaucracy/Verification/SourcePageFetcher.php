<?php

namespace App\Bureaucracy\Verification;

use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Reads an official page as plain text. Only allowlisted HTTPS hosts are fetched,
 * and redirects are followed only within those hosts.
 *
 * "missing" means the page is gone (404/410), which fails the claims built on it.
 * "unreachable" covers everything that says nothing about the content — timeouts,
 * blocks, server errors — so a network hiccup never pulls a card on its own.
 */
class SourcePageFetcher
{
    private const MaxRedirects = 3;

    private const MaxBytes = 3_000_000;

    /** @return array{status: 'ok'|'missing'|'unreachable', text?: string, reason?: string, http_status?: int|null} */
    public function fetch(string $url): array
    {
        for ($hop = 0; $hop <= self::MaxRedirects; $hop++) {
            if (! $this->allowed($url)) {
                return ['status' => 'unreachable', 'reason' => 'host_not_allowed', 'http_status' => null];
            }
            try {
                $response = Http::withHeaders(['User-Agent' => 'ExpaduSourceCheck/1.0 (+https://expadu.com)', 'Accept' => 'text/html,text/plain'])
                    ->withoutRedirecting()->connectTimeout(5)->timeout(20)->get($url);
            } catch (Throwable) {
                return ['status' => 'unreachable', 'reason' => 'connection_or_tls', 'http_status' => null];
            }
            $status = $response->status();
            if ($status >= 300 && $status < 400 && is_string($location = $response->header('Location')) && $location !== '') {
                $url = $this->resolve($url, $location);

                continue;
            }
            if (in_array($status, [404, 410], true)) {
                return ['status' => 'missing', 'reason' => 'page_missing', 'http_status' => $status];
            }
            if ($status < 200 || $status >= 300) {
                return ['status' => 'unreachable', 'reason' => 'http_'.$status, 'http_status' => $status];
            }
            $text = SourceText::fromHtml($this->utf8(substr($response->body(), 0, self::MaxBytes), (string) $response->header('Content-Type')));
            // A bot-check interstitial says nothing about the page; it is never solved or worked around.
            if (mb_strlen($text) < 400 || preg_match('/verifying your browser|just a moment|enable javascript and cookies|captcha|access denied|radware/i', mb_substr($text, 0, 600)) === 1) {
                return ['status' => 'unreachable', 'reason' => 'bot_check_or_empty', 'http_status' => $status];
            }

            return ['status' => 'ok', 'text' => $text, 'http_status' => $status];
        }

        return ['status' => 'unreachable', 'reason' => 'too_many_redirects', 'http_status' => null];
    }

    public function allowed(string $url): bool
    {
        $parts = parse_url($url);
        if (! is_array($parts) || strtolower((string) ($parts['scheme'] ?? '')) !== 'https' || ! isset($parts['host'])
            || isset($parts['user']) || isset($parts['pass']) || (isset($parts['port']) && $parts['port'] !== 443)) {
            return false;
        }
        $host = strtolower(rtrim($parts['host'], '.'));
        $hosts = [...config('bureaucracy_sources.primary_hosts', []), ...config('bureaucracy_sources.implementation_hosts', [])];
        foreach ($hosts as $allowed) {
            $allowed = strtolower(trim((string) $allowed, '.'));
            if ($allowed !== '' && ($host === $allowed || str_ends_with($host, '.'.$allowed))) {
                return true;
            }
        }

        return false;
    }

    private function resolve(string $base, string $location): string
    {
        if (preg_match('#^https?://#i', $location)) {
            return $location;
        }
        $parts = parse_url($base);
        $origin = 'https://'.$parts['host'];

        return str_starts_with($location, '/') ? $origin.$location
            : $origin.rtrim(dirname($parts['path'] ?? '/'), '/').'/'.$location;
    }

    private function utf8(string $body, string $contentType): string
    {
        $charset = null;
        if (preg_match('/charset=["\']?([\w-]+)/i', $contentType, $m)) {
            $charset = $m[1];
        } elseif (preg_match('/<meta[^>]+charset=["\']?([\w-]+)/i', substr($body, 0, 4096), $m)) {
            $charset = $m[1];
        }
        $charset = strtoupper($charset ?? (mb_check_encoding($body, 'UTF-8') ? 'UTF-8' : 'ISO-8859-1'));
        if ($charset === 'UTF-8' || ! in_array($charset, array_map('strtoupper', mb_list_encodings()), true)) {
            return mb_check_encoding($body, 'UTF-8') ? $body : mb_convert_encoding($body, 'UTF-8', 'ISO-8859-1');
        }

        return mb_convert_encoding($body, 'UTF-8', $charset);
    }
}

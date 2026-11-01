<?php

namespace App\Bureaucracy\Catalogue;

use DomainException;

final class VerifiedActionDirectory
{
    /** Only authored URLs enter here; no user-supplied fetch or automatic redirect. */
    public function compile(string $id, string $url, string $purpose, string $jurisdiction): array
    {
        $parts = parse_url($url);
        $host = strtolower($parts['host'] ?? '');
        $allowed = collect(config('bureaucracy_catalogue.action_hosts', []))->contains(fn ($allowedHost) => $host === $allowedHost || str_ends_with($host, '.'.$allowedHost));
        if (! $allowed || ($parts['scheme'] ?? null) !== 'https' || isset($parts['user']) || isset($parts['pass'])
            || (isset($parts['port']) && $parts['port'] !== 443) || preg_match('/[\s\\\\\x00-\x1f]/', $url)
            || ! array_key_exists($jurisdiction, config('bureaucracy_catalogue.jurisdictions', []))
            || ! in_array($purpose, ['information', 'application', 'appointment', 'contact', 'document_form'], true)) {
            throw new DomainException('An action requires an approved HTTPS host, jurisdiction and explicit purpose.');
        }

        return ['id' => $id, 'url' => $url, 'purpose' => $purpose, 'channel' => 'official_website', 'jurisdiction' => $jurisdiction];
    }
}

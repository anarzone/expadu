<?php

namespace App\Security;

use Illuminate\Support\Facades\Storage;

/**
 * Throwaway inbox services (mailinator.com and ~10,000 others): addresses
 * people use once and abandon, and what sign-up scripts reach for. Their
 * signups never become people we can reach, so the waitlist, registration
 * and email changes turn them away.
 *
 * The list is the public disposable-email-domains blocklist (CC0,
 * github.com/disposable-email-domains/disposable-email-domains). A copy
 * ships in resources/data; security:refresh-disposable-domains fetches a
 * newer one monthly into storage, which wins while it's there.
 */
final class DisposableEmail
{
    public const Source = 'https://raw.githubusercontent.com/disposable-email-domains/disposable-email-domains/main/disposable_email_blocklist.conf';

    /** Where the monthly refresh keeps its copy, on the local disk. */
    public const Refreshed = 'security/disposable-email-domains.txt';

    /** A real list has thousands of entries; fewer means a broken download. */
    public const MinDomains = 5000;

    /** @var array<string, true>|null */
    private ?array $domains = null;

    public function isDisposable(string $email): bool
    {
        $domain = strtolower(trim((string) substr(strrchr($email, '@') ?: '', 1)));

        if ($domain === '') {
            return false;
        }

        // The domain itself and each parent: x.mailinator.com is mailinator.com's.
        $labels = explode('.', $domain);
        while (count($labels) >= 2) {
            if (isset($this->domains()[implode('.', $labels)])) {
                return true;
            }
            array_shift($labels);
        }

        return false;
    }

    /**
     * Only lines that are domain names, lower-cased.
     *
     * @return array<int, string>
     */
    public static function parse(string $text): array
    {
        $domains = [];
        foreach (preg_split('/\R/', $text) ?: [] as $line) {
            $line = strtolower(trim($line));
            if (preg_match('/^[a-z0-9-]+(\.[a-z0-9-]+)+$/', $line) === 1) {
                $domains[] = $line;
            }
        }

        return $domains;
    }

    /** @return array<string, true> */
    private function domains(): array
    {
        if ($this->domains !== null) {
            return $this->domains;
        }

        $refreshed = Storage::disk('local')->exists(self::Refreshed)
            ? self::parse((string) Storage::disk('local')->get(self::Refreshed))
            : [];
        $list = count($refreshed) >= self::MinDomains
            ? $refreshed
            : self::parse((string) file_get_contents(resource_path('data/disposable-email-domains.txt')));

        return $this->domains = array_fill_keys($list, true);
    }
}

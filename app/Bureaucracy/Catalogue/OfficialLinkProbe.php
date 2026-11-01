<?php

namespace App\Bureaucracy\Catalogue;

use DomainException;
use Illuminate\Support\Facades\Http;
use Throwable;

final class OfficialLinkProbe
{
    public function __construct(private VerifiedActionDirectory $directory) {}

    public function check(string $url, int $timeout = 20): array
    {
        try {
            $this->directory->compile('probe', $url, 'information', array_key_first(config('bureaucracy_catalogue.jurisdictions', [])) ?? '');
        } catch (DomainException) {
            return ['status' => 'unverifiable', 'reason' => 'host_review_required', 'http_status' => null];
        }
        try {
            $status = Http::withHeaders(['User-Agent' => 'ExpaduLinkCheck/2.0'])
                ->withoutRedirecting()->connectTimeout(5)->timeout(max(1, min(30, $timeout)))->head($url)->status();

            return match (true) {
                $status >= 200 && $status < 300 => ['status' => 'healthy', 'reason' => null, 'http_status' => $status],
                in_array($status, [404, 410], true) => ['status' => 'dead', 'reason' => 'page_missing', 'http_status' => $status],
                default => ['status' => 'unverifiable', 'reason' => $status >= 300 && $status < 400 ? 'redirect_requires_review' : 'server_or_access_failure', 'http_status' => $status],
            };
        } catch (Throwable) {
            return ['status' => 'unverifiable', 'reason' => 'connection_or_tls', 'http_status' => null];
        }
    }
}

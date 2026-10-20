<?php

namespace App\Support\Ssr;

use Exception;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\StrayRequestException;
use Illuminate\Support\Facades\Http;
use Inertia\Ssr\HttpGateway;
use Inertia\Ssr\Response;

/**
 * Inertia's SSR gateway with a short timeout. The stock HttpGateway posts with
 * Laravel's default 30 s timeout to a single Node process, so a stuck SSR
 * server held a PHP-FPM worker for 30 s on every page load. A page that misses
 * the timeout renders client-side instead — the same fallback as SSR being down.
 */
class SsrGateway extends HttpGateway
{
    public function dispatch(array $page): ?Response
    {
        if (! $this->shouldDispatch()) {
            return null;
        }

        try {
            $response = $this->client()->post($this->getUrl('/render'), $page)->throw()->json();
        } catch (Exception $e) {
            if ($e instanceof StrayRequestException) {
                throw $e;
            }

            return null;
        }

        if (is_null($response)) {
            return null;
        }

        return new Response(
            implode("\n", $response['head']),
            $response['body']
        );
    }

    public function client(): PendingRequest
    {
        return Http::timeout((float) config('inertia.ssr.timeout', 1.5))
            ->connectTimeout((float) config('inertia.ssr.connect_timeout', 0.5));
    }
}

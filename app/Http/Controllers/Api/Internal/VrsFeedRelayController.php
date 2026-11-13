<?php

namespace App\Http\Controllers\Api\Internal;

use App\Http\Controllers\Controller;
use App\Services\VrsRealtimeService;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * VRS's live feed (GTFS-RT) for our router, MOTIS, so it plans around delays and cancellations.
 * VRS hands the feed out only to our client certificate, which MOTIS can't present: the app reads
 * it (kept fresh while the router polls) and passes the bytes on. Only for the router's token; to
 * anyone else this route doesn't exist.
 */
class VrsFeedRelayController extends Controller
{
    public function __invoke(Request $request, VrsRealtimeService $realtime): Response
    {
        $token = $realtime->relayToken();
        if (! config('services.vrs.enabled') || $token === null
            || ! hash_equals($token, (string) $request->header('X-Relay-Token'))) {
            abort(404);
        }

        $realtime->markRelayWanted();
        $raw = $realtime->raw();
        if ($raw === null || time() - $raw['fetched_at'] > VrsRealtimeService::RAW_FRESH_SECONDS) {
            $realtime->refreshRaw();
            $raw = $realtime->raw() ?? $raw;
        }
        if ($raw === null) {
            return response('', 503, ['Retry-After' => '30']);
        }

        return response($raw['body'], 200, [
            'Content-Type' => 'application/x-protobuf',
            'Cache-Control' => 'no-store',
            'X-Feed-Age' => (string) (time() - $raw['fetched_at']),
        ]);
    }
}

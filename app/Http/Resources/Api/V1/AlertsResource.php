<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The bell's sheet for the iPhone: the web's Alerts list (App\Alerts\AlertPresenter), newest
 * activity first, each card in the app's filter groups, and how many are unread.
 */
class AlertsResource extends JsonResource
{
    public static $wrap = null;

    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            /** @var array<int, array{id: int, category: string, severity: string, title: string, body: string|null, source: string, at: string|null, read: bool, url: string|null, lines: array<int, string>, stops: array<int, string>}> */
            'alerts' => array_map(fn (array $alert) => [
                'id' => (int) $alert['id'],
                'category' => self::category($alert),
                // danger, warn, success or info, as the web's cards.
                'severity' => (string) $alert['severity'],
                'title' => (string) $alert['title'],
                'body' => ($alert['body'] ?? '') === '' ? null : (string) $alert['body'],
                'source' => (string) $alert['source'],
                // Its last activity: a refreshed card reads "just now".
                'at' => $alert['created_at'] ?? null,
                'read' => (bool) $alert['read'],
                // The KVB notice's own page, for a transit card that has one.
                'url' => $alert['notice']['url'] ?? null,
                'lines' => array_values($alert['lines']),
                'stops' => array_values($alert['stops']),
            ], $this->resource['alerts']),
            'unread' => (int) $this->resource['unread'],
        ];
    }

    /** The app's filter chips: lines, weather, paper, and city for everything else (Rhine, markets, events). */
    private static function category(array $alert): string
    {
        return match (true) {
            $alert['category'] === 'transit' => 'lines',
            $alert['category'] === 'bureau' => 'paper',
            $alert['subtype'] === 'weather' => 'weather',
            default => 'city',
        };
    }
}

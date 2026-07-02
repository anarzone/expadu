<?php

namespace App\Services\SmartCjm;

use GuzzleHttp\Cookie\CookieJar;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Reads appointment availability from the Smart CJM booking system on
 * termine.stadt-koeln.de. The wizard is server-rendered: a session (wsid)
 * plus a per-step __RequestVerificationToken, advanced with a step_goto=+1
 * POST. After the service is selected we hit the wizard's own
 * `search_result?search_mode=earliest` endpoint, which returns the soonest
 * appointment per office as JSON — including a deep-link that books that
 * exact slot. That is both lighter and more honest than scraping the
 * near-term day cards, which look "fully booked" whenever the soonest
 * appointment is further out than the visible window.
 *
 * Read-only: never advances to the booking step, so nothing is reserved.
 */
class SmartCjmClient
{
    /**
     * Identify honestly — Expadu drives bookings to the city, not away from it.
     */
    private const USER_AGENT = 'Expadu/1.0 (+https://expadu.com; appointment availability check)';

    /**
     * The soonest appointment per office for one service.
     *
     * Each entry: office label ("Kundenzentrum Porz"), ISO-8601 datetime,
     * duration in minutes, and an absolute booking deep-link for that slot.
     *
     * @return list<array{office: string, unit_uid: string, datetime: string, duration: int, booking_url: string}>
     */
    public function fetchEarliest(string $calendarUrl, string $serviceUid): array
    {
        $jar = new CookieJar;
        $baseUrl = strtok($calendarUrl, '?');
        $host = Str::before($calendarUrl, '/m/');
        $calendarUid = $this->queryParam($calendarUrl, 'uid');

        $page = $this->request($jar)->get($calendarUrl)->throw()->body();
        $wsid = $this->parseWsid($page);

        // Select the service and step forward; the response (locations step)
        // is not needed — the earliest endpoint reads the session directly.
        $this->submitServiceStep($jar, $baseUrl, $page, $serviceUid);

        $json = $this->request($jar)->get($baseUrl.'search_result', [
            'search_mode' => 'earliest',
            'uid' => $calendarUid,
            'wsid' => $wsid,
            'lang' => 'de',
            'set_lang_ui' => 'de',
        ])->throw()->body();

        return $this->parseEarliest($json, $host);
    }

    private function request(CookieJar $jar): PendingRequest
    {
        return Http::withOptions(['cookies' => $jar])
            ->withHeaders(['User-Agent' => self::USER_AGENT])
            ->connectTimeout(5)
            ->timeout(15);
    }

    /**
     * POST the services step: re-extract the per-step token and form action
     * from the calendar page, then submit the chosen service with amount 1.
     */
    private function submitServiceStep(CookieJar $jar, string $baseUrl, string $page, string $serviceUid): void
    {
        $body = $this->formBody([
            '__RequestVerificationToken' => $this->parseToken($page),
            'access_code_key' => '',
            'action_type' => '',
            'step_current' => 'services',
            'step_current_index' => $this->parseHiddenValue($page, 'step_current_index') ?? '0',
            'step_goto' => '+1',
            'steps' => $this->parseHiddenValue($page, 'steps') ?? '',
            'services' => $serviceUid,
            "service_{$serviceUid}_amount" => '1',
        ]);

        $this->request($jar)
            ->withBody($body, 'application/x-www-form-urlencoded')
            ->post($baseUrl.$this->parseFormAction($page))
            ->throw();
    }

    /**
     * @param  array<string, string>  $fields
     */
    private function formBody(array $fields): string
    {
        $pairs = [];
        foreach ($fields as $key => $value) {
            $pairs[] = urlencode($key).'='.urlencode($value);
        }

        return implode('&', $pairs);
    }

    private function parseToken(string $page): string
    {
        if (! preg_match('/name=[\'"]__RequestVerificationToken[\'"]\s+value=[\'"]([^\'"]+)[\'"]/', $page, $m)) {
            throw new RuntimeException('SmartCJM page carries no __RequestVerificationToken.');
        }

        return $m[1];
    }

    private function parseFormAction(string $page): string
    {
        if (! preg_match('/<form[^>]*\saction="([^"]+)"/', $page, $m)) {
            throw new RuntimeException('SmartCJM page carries no form action.');
        }

        // The action is a query string relative to the calendar path; the
        // #top fragment must not be sent back.
        return strtok(html_entity_decode($m[1]), '#');
    }

    private function parseWsid(string $page): string
    {
        if (! preg_match('/wsid=([0-9a-f-]{36})/', $page, $m)) {
            throw new RuntimeException('SmartCJM calendar page established no wsid session.');
        }

        return $m[1];
    }

    private function parseHiddenValue(string $page, string $name): ?string
    {
        return preg_match('/name="'.preg_quote($name, '/').'"\s+value="([^"]*)"/', $page, $m) ? $m[1] : null;
    }

    private function queryParam(string $url, string $key): ?string
    {
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

        return $query[$key] ?? null;
    }

    /**
     * Parse the `#json_appointment_list` payload. `appointments` is either a
     * list of per-office earliest slots or the string "nothing_Found".
     *
     * @return list<array{office: string, unit_uid: string, datetime: string, duration: int, booking_url: string}>
     */
    private function parseEarliest(string $page, string $host): array
    {
        if (! preg_match('/id="json_appointment_list"[^>]*>(.*?)<\/div>/s', $page, $m)) {
            throw new RuntimeException('SmartCJM earliest response carries no appointment list.');
        }

        $decoded = json_decode(trim($m[1]), true);
        $appointments = $decoded['appointments'] ?? null;
        if (! is_array($appointments)) {
            // "nothing_Found" (or anything non-list) → no availability.
            return [];
        }

        return array_map(fn (array $a) => [
            'office' => trim((string) $a['unit']),
            'unit_uid' => (string) $a['unit_uid'],
            'datetime' => Carbon::parse($a['datetime_iso86001'])->toIso8601String(),
            'duration' => (int) $a['duration'],
            'booking_url' => Str::startsWith($a['link'], 'http') ? $a['link'] : $host.$a['link'],
        ], $appointments);
    }
}

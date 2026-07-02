<?php

namespace App\Services\SmartCjm;

use GuzzleHttp\Cookie\CookieJar;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Drives the Smart CJM booking wizard on termine.stadt-koeln.de to read
 * appointment availability. The wizard is server-rendered: a session (wsid)
 * plus a per-step __RequestVerificationToken, advanced with step_goto=+1
 * POSTs. Three requests reach the search_results page, which carries every
 * free slot per location as data-slot-from attributes.
 *
 * The locations step is skipped by the server when a calendar has a single
 * location (e.g. KFZ), so each step branches on the returned step_current.
 *
 * Read-only: never advances past search_results, so no slot is ever reserved.
 */
class SmartCjmClient
{
    /**
     * Identify honestly — Expadu drives bookings to the city, not away from it.
     */
    private const USER_AGENT = 'Expadu/1.0 (+https://expadu.com; appointment availability check)';

    /**
     * Fetch availability for one service across all of a calendar's locations.
     *
     * `locations` lists every location label the wizard offered (empty when
     * the server skipped the step) — a location in that list with no slots
     * entry is genuinely fully booked. `slots` maps location label to a
     * sorted list of ISO-8601 slot datetimes.
     *
     * @return array{locations: list<string>, slots: array<string, list<string>>}
     */
    public function fetchAvailability(string $calendarUrl, string $serviceUid): array
    {
        $jar = new CookieJar;
        $baseUrl = strtok($calendarUrl, '?');

        $page = $this->request($jar)->get($calendarUrl)->throw()->body();

        $page = $this->submitStep($jar, $baseUrl, $page, [
            'services' => $serviceUid,
            "service_{$serviceUid}_amount" => '1',
        ]);

        $checkedLocations = [];
        if ($this->currentStep($page) === 'locations') {
            [$locationUids, $checkedLocations] = $this->parseLocations($page);
            $page = $this->submitStep($jar, $baseUrl, $page, ['locations' => $locationUids]);
        }

        if ($this->currentStep($page) !== 'search_results') {
            throw new RuntimeException('SmartCJM wizard ended on unexpected step: '.($this->currentStep($page) ?? 'unknown'));
        }

        return ['locations' => $checkedLocations, 'slots' => $this->parseSlots($page)];
    }

    private function request(CookieJar $jar): PendingRequest
    {
        return Http::withOptions(['cookies' => $jar])
            ->withHeaders(['User-Agent' => self::USER_AGENT])
            ->connectTimeout(5)
            ->timeout(15);
    }

    /**
     * Advance the wizard one step: re-extract the per-step token and form
     * action from the current page, then POST it with step_goto=+1.
     *
     * @param  array<string, string|list<string>>  $fields
     */
    private function submitStep(CookieJar $jar, string $baseUrl, string $page, array $fields): string
    {
        $action = $this->parseFormAction($page);

        $body = $this->formBody(array_merge([
            '__RequestVerificationToken' => $this->parseToken($page),
            'access_code_key' => '',
            'action_type' => '',
            'step_current' => $this->currentStep($page) ?? '',
            'step_current_index' => $this->parseHiddenValue($page, 'step_current_index') ?? '0',
            'step_goto' => '+1',
            'steps' => $this->parseHiddenValue($page, 'steps') ?? '',
        ], $fields));

        return $this->request($jar)
            ->withBody($body, 'application/x-www-form-urlencoded')
            ->post($baseUrl.$action)
            ->throw()
            ->body();
    }

    /**
     * The wizard expects repeated keys (locations=a&locations=b), which
     * http_build_query cannot produce — it brackets array keys.
     *
     * @param  array<string, string|list<string>>  $fields
     */
    private function formBody(array $fields): string
    {
        $pairs = [];
        foreach ($fields as $key => $value) {
            foreach ((array) $value as $item) {
                $pairs[] = urlencode($key).'='.urlencode($item);
            }
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

    private function currentStep(string $page): ?string
    {
        return $this->parseHiddenValue($page, 'step_current');
    }

    private function parseHiddenValue(string $page, string $name): ?string
    {
        return preg_match('/name="'.preg_quote($name, '/').'"\s+value="([^"]*)"/', $page, $m) ? $m[1] : null;
    }

    /**
     * @return array{0: list<string>, 1: list<string>} Location checkbox uids and their labels.
     */
    private function parseLocations(string $page): array
    {
        preg_match_all('/name="locations"[^>]*\svalue="([^"]+)"/', $page, $uids);
        preg_match_all('/<label class="location_title"[^>]*>\s*<b>([^<]+)<\/b>/', $page, $labels);

        return [
            array_values(array_unique($uids[1])),
            array_map(fn (string $label) => trim($label), $labels[1]),
        ];
    }

    /**
     * Slots are grouped by <h4 class="timeslot_cards_locations"> headings;
     * every slot button after a heading belongs to that location. The full
     * datetime lives in data-slot-from, so day containers need no parsing.
     *
     * @return array<string, list<string>>
     */
    private function parseSlots(string $page): array
    {
        preg_match_all(
            '/<h4 class="timeslot_cards_locations"[^>]*>\s*(.*?)\s*<\/h4>|data-slot-from="([^"]+)"/s',
            $page,
            $matches,
            PREG_SET_ORDER,
        );

        $slots = [];
        $location = null;
        foreach ($matches as $match) {
            if ($match[1] !== '') {
                $location = trim($match[1]);
            } elseif (isset($match[2]) && $location !== null) {
                $slots[$location][] = Carbon::parse($match[2])->toIso8601String();
            }
        }

        foreach ($slots as &$times) {
            sort($times);
        }

        return $slots;
    }
}

<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;

class BuergeramtService
{
    const BASE_URL = 'https://termine-online.stadt-koeln.de/index.php?company=stadtkoeln';

    /**
     * Cologne offices with addresses and Google Maps links.
     * Booking is service-based (user picks service → system shows available offices).
     *
     * @var array<string, array{name: string, address: string, id: string}>
     */
    const OFFICES = [
        'deutz' => ['name' => 'Bürgeramt Deutz', 'address' => 'Deutzer Freiheit 64', 'id' => 'deutz'],
        'ehrenfeld' => ['name' => 'Bürgeramt Ehrenfeld', 'address' => 'Venloer Str. 419-421', 'id' => 'ehrenfeld'],
        'kalk' => ['name' => 'Bürgeramt Kalk', 'address' => 'Kalker Hauptstr. 247-273', 'id' => 'kalk'],
        'lindenthal' => ['name' => 'Bürgeramt Lindenthal', 'address' => 'Aachener Str. 220', 'id' => 'lindenthal'],
        'chorweiler' => ['name' => 'Bürgeramt Chorweiler', 'address' => 'Pariser Platz 1', 'id' => 'chorweiler'],
        'muelheim' => ['name' => 'Bürgeramt Mülheim', 'address' => 'Wiener Platz 2a', 'id' => 'muelheim'],
        'nippes' => ['name' => 'Bürgeramt Nippes', 'address' => 'Neusser Str. 450', 'id' => 'nippes'],
        'porz' => ['name' => 'Bürgeramt Porz', 'address' => 'Friedrich-Ebert-Ufer 64-70', 'id' => 'porz'],
        'rodenkirchen' => ['name' => 'Bürgeramt Rodenkirchen', 'address' => 'Hauptstr. 85', 'id' => 'rodenkirchen'],
        'innenstadt' => ['name' => 'Bürgeramt Innenstadt', 'address' => 'Laurenzplatz 1-3', 'id' => 'innenstadt'],
        'auslaenderbehoerde' => ['name' => 'Ausländerbehörde Köln', 'address' => 'Dillenburger Str. 1, Kalk', 'id' => 'auslaenderbehoerde'],
        'finanzamt_altstadt' => ['name' => 'Finanzamt Köln-Altstadt', 'address' => 'Am Weidenbach 2-4', 'id' => 'finanzamt_altstadt'],
        'finanzamt_nord' => ['name' => 'Finanzamt Köln-Nord', 'address' => 'Innere Kanalstr. 214', 'id' => 'finanzamt_nord'],
        'finanzamt_sued' => ['name' => 'Finanzamt Köln-Süd', 'address' => 'Euskirchener Str. 6', 'id' => 'finanzamt_sued'],
        'kfz' => ['name' => 'KFZ-Zulassungsstelle Köln', 'address' => 'Max-Glomsda-Str. 4, Poll', 'id' => 'kfz'],
    ];

    /**
     * The concrete office a task's "take me there" should target. Bürgeramt
     * services route to the user's Bezirk office (any Kundenzentrum works,
     * the local one is the natural pick); single-site services route to
     * their one address.
     *
     * @return array{name: string, address: string}|null
     */
    public function officeForTask(?string $bookingServiceKey, ?string $veedel): ?array
    {
        if ($bookingServiceKey === null) {
            return null;
        }

        // Booking keys are either a SERVICES entry (with a category) or a
        // category name itself (auslaenderbehoerde, finanzamt, kfz).
        $category = self::SERVICES[$bookingServiceKey]['category']
            ?? (isset(self::BOOKING_URLS[$bookingServiceKey]) ? $bookingServiceKey : null);

        $officeKey = match ($category) {
            'auslaenderbehoerde' => 'auslaenderbehoerde',
            'kfz' => 'kfz',
            'finanzamt' => 'finanzamt_altstadt',
            'buergeramt' => $this->bezirkOfficeKey($veedel),
            default => null,
        };

        $office = $officeKey !== null ? (self::OFFICES[$officeKey] ?? null) : null;

        return $office ? ['name' => $office['name'], 'address' => $office['address']] : null;
    }

    private function bezirkOfficeKey(?string $veedel): string
    {
        if ($veedel !== null) {
            foreach (config('veedels', []) as $bezirk => $stadtteile) {
                if (in_array($veedel, $stadtteile, true)) {
                    $key = str_replace(['ü', 'ö', 'ä'], ['ue', 'oe', 'ae'], mb_strtolower($bezirk));

                    return isset(self::OFFICES[$key]) ? $key : 'innenstadt';
                }
            }
        }

        return 'innenstadt';
    }

    /**
     * Booking URLs per category.
     */
    const BOOKING_URLS = [
        'buergeramt' => 'https://termine.stadt-koeln.de/m/kundenzentren/extern/calendar/?uid=b5a5a394-ec33-4130-9af3-490f99517071',
        'auslaenderbehoerde' => 'https://termine.stadt-koeln.de/m/auslaenderamt/extern/calendar/?uid=f3737466-3187-492f-8d7e-6082d47aeb84',
        'finanzamt' => 'https://www.elster.de/eportal/start',
        'kfz' => 'https://termine.stadt-koeln.de/m/kfz-zulassung/extern/calendar/?uid=67523a04-37af-4131-9495-0a3566e0eb8b',
    ];

    /**
     * Common services with deep-link UIDs.
     * Adding &service=<uid> to the booking URL pre-selects the service, skipping step 1.
     *
     * @var array<string, array{name: string, name_en: string, uid: string, category: string, duration: int, emoji: string}>
     */
    const SERVICES = [
        'anmeldung' => ['name' => 'Anmeldung', 'name_en' => 'Address Registration', 'uid' => '0d2f4ea5-74f2-4699-b954-8907a1ca5f80', 'category' => 'buergeramt', 'duration' => 15, 'emoji' => '📋'],
        'abmeldung' => ['name' => 'Abmeldung', 'name_en' => 'Deregistration', 'uid' => '58f5b5d5-4400-4d21-86bb-ae57bb6dc78a', 'category' => 'buergeramt', 'duration' => 10, 'emoji' => '📝'],
        'ummeldung' => ['name' => 'Ummeldung', 'name_en' => 'Change of Address', 'uid' => 'b9028f0e-2b37-41c1-9176-966da3823e88', 'category' => 'buergeramt', 'duration' => 10, 'emoji' => '🏠'],
        'personalausweis' => ['name' => 'Personalausweis', 'name_en' => 'ID Card Application', 'uid' => 'd29a92ab-4112-40c5-b772-427ce186cc35', 'category' => 'buergeramt', 'duration' => 10, 'emoji' => '🆔'],
        'reisepass' => ['name' => 'Reisepass', 'name_en' => 'Passport Application', 'uid' => 'd1c1e4d7-44a6-434d-884c-6aa1fe43d7a1', 'category' => 'buergeramt', 'duration' => 10, 'emoji' => '🛂'],
    ];

    /**
     * @var array<string, string>
     */
    const OFFICE_CATEGORIES = [
        'deutz' => 'buergeramt', 'ehrenfeld' => 'buergeramt', 'kalk' => 'buergeramt',
        'lindenthal' => 'buergeramt', 'chorweiler' => 'buergeramt', 'muelheim' => 'buergeramt',
        'nippes' => 'buergeramt', 'porz' => 'buergeramt', 'rodenkirchen' => 'buergeramt',
        'innenstadt' => 'buergeramt', 'auslaenderbehoerde' => 'auslaenderbehoerde',
        'finanzamt_altstadt' => 'finanzamt', 'finanzamt_nord' => 'finanzamt',
        'finanzamt_sued' => 'finanzamt', 'kfz' => 'kfz',
    ];

    /**
     * The office directory with appointment availability. Offices covered by
     * a recent `slots:check` run carry real next_slot/slots_today (or a
     * confirmed fully_booked); everything else falls back to check_online
     * with a direct booking link. Results cached for 3 minutes.
     *
     * @return array<string, array{name: string, address: string, category: string, status: string, next_slot: ?string, slots_today: int, booking_url: string}>
     */
    public function checkSlots(): array
    {
        return Cache::remember('buergeramt_slots', 180, function () {
            return $this->applyLiveAvailability($this->officeDirectory());
        });
    }

    /**
     * The static office directory: every office as check_online with its
     * booking link, before any live availability is overlaid.
     */
    protected function officeDirectory(): array
    {
        $slots = [];

        foreach (self::OFFICES as $key => $office) {
            $category = self::OFFICE_CATEGORIES[$key] ?? 'other';
            $slots[$key] = [
                'name' => $office['name'],
                'address' => $office['address'],
                'category' => $category,
                'status' => 'check_online',
                'next_slot' => null,
                'slots_today' => 0,
                'booking_url' => self::BOOKING_URLS[$category] ?? '',
            ];
        }

        return $slots;
    }

    /**
     * Overlay real availability from the last `slots:check` run. Only
     * offices that run actually covered are touched: covered with slots →
     * available, covered without → fully_booked (honest city-wide scarcity),
     * never checked → left as check_online.
     */
    protected function applyLiveAvailability(array $slots): array
    {
        $live = Cache::get('buergeramt_slots_live');
        if (! is_array($live) || ! isset($live['offices'])) {
            return $slots;
        }

        foreach ($live['offices'] as $key => $availability) {
            if (! isset($slots[$key])) {
                continue;
            }

            $slots[$key]['status'] = $availability['slots_total'] > 0 ? 'available' : 'fully_booked';
            $slots[$key]['next_slot'] = $availability['next_slot'];
            $slots[$key]['slots_today'] = $availability['slots_today'];
        }

        return $slots;
    }

    /**
     * Map a Smart CJM booking location label onto an OFFICES key, e.g.
     * "Kundenzentrum Innenstadt I" → innenstadt, "Kundenzentrum Mülheim" →
     * muelheim. Null for locations the directory does not know.
     */
    public function officeKeyForLocation(string $label): ?string
    {
        $normalized = str_replace(['ü', 'ö', 'ä'], ['ue', 'oe', 'ae'], mb_strtolower($label));

        foreach (array_keys(self::OFFICES) as $key) {
            if (str_contains($normalized, $key)) {
                return $key;
            }
        }

        return null;
    }
}

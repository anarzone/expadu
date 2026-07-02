<?php

namespace App\Services;

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
     * The service catalog exposed for availability checks and deep-links,
     * with the real Smart CJM service UIDs (verified against the city's live
     * get_service_list on 2026-07-02). Bürgeramt services share the ten
     * Kundenzentren; KFZ services route to the Zulassungsstelle. Adding
     * &service=<uid> to the booking URL pre-selects the service.
     *
     * @var array<string, array{name: string, name_en: string, uid: string, category: string, duration: int, emoji: string}>
     */
    const SERVICES = [
        // ── Bürgeramt / Kundenzentren ──────────────────────────────────
        'anmeldung' => ['name' => 'Anmeldung', 'name_en' => 'Address Registration', 'uid' => '0d2f4ea5-74f2-4699-b954-8907a1ca5f80', 'category' => 'buergeramt', 'duration' => 15, 'emoji' => '📋'],
        'ummeldung' => ['name' => 'Ummeldung Wohnsitz', 'name_en' => 'Change of Address', 'uid' => 'b9028f0e-2b37-41c1-9176-966da3823e88', 'category' => 'buergeramt', 'duration' => 10, 'emoji' => '🏠'],
        'abmeldung' => ['name' => 'Abmeldung', 'name_en' => 'Deregistration', 'uid' => '58f5b5d5-4400-4d21-86bb-ae57bb6dc78a', 'category' => 'buergeramt', 'duration' => 10, 'emoji' => '📝'],
        'wohnsitz_elektronisch' => ['name' => 'elektronische Wohnsitzanmeldung', 'name_en' => 'Electronic Registration', 'uid' => 'aa82c612-d46b-4aba-87fc-0b1303082ec8', 'category' => 'buergeramt', 'duration' => 20, 'emoji' => '💻'],
        'nebenwohnsitz' => ['name' => 'Erklärung zum Nebenwohnsitz', 'name_en' => 'Secondary Residence Declaration', 'uid' => 'd528518e-95c3-4dd8-850f-2db689fe0551', 'category' => 'buergeramt', 'duration' => 10, 'emoji' => '🏘️'],
        'personalausweis' => ['name' => 'Personalausweis', 'name_en' => 'ID Card Application', 'uid' => 'd29a92ab-4112-40c5-b772-427ce186cc35', 'category' => 'buergeramt', 'duration' => 10, 'emoji' => '🆔'],
        'reisepass' => ['name' => 'Reisepass', 'name_en' => 'Passport Application', 'uid' => 'd1c1e4d7-44a6-434d-884c-6aa1fe43d7a1', 'category' => 'buergeramt', 'duration' => 10, 'emoji' => '🛂'],
        'befreiung_ausweispflicht' => ['name' => 'Befreiung Ausweispflicht', 'name_en' => 'ID Requirement Exemption', 'uid' => 'e1d5bacf-1498-44c6-9489-2dbc7e322dec', 'category' => 'buergeramt', 'duration' => 10, 'emoji' => '📄'],
        'bewohnerparkausweis' => ['name' => 'Bewohnerparkausweis', 'name_en' => 'Resident Parking Permit', 'uid' => '179c690a-ef74-46c8-a6a3-d65269729601', 'category' => 'buergeramt', 'duration' => 10, 'emoji' => '🅿️'],
        'konfessionsaenderung' => ['name' => 'Konfessionsänderung', 'name_en' => 'Change of Religious Affiliation', 'uid' => '2307dc91-2bca-4b30-a8ac-eb1a03b4b3a2', 'category' => 'buergeramt', 'duration' => 10, 'emoji' => '⛪'],
        'personenstandsaenderung' => ['name' => 'Personenstandsänderung', 'name_en' => 'Civil Status Change', 'uid' => '631bf247-a668-48b0-9f05-015322f379bb', 'category' => 'buergeramt', 'duration' => 15, 'emoji' => '📇'],
        'kfz_stilllegen' => ['name' => 'Kfz stilllegen', 'name_en' => 'Deregister a Vehicle', 'uid' => '057d9cf7-3d7b-4d40-a578-f4ed2e2432b2', 'category' => 'buergeramt', 'duration' => 10, 'emoji' => '🚗'],
        'fahrzeug_anschrift' => ['name' => 'Anschrift in Fahrzeugpapieren ändern', 'name_en' => 'Update Address on Vehicle Papers', 'uid' => '24194702-ab60-4ea0-9c64-647797f5267b', 'category' => 'buergeramt', 'duration' => 10, 'emoji' => '🚙'],
        'hausauskuenfte' => ['name' => 'Hausauskünfte für Vermieter', 'name_en' => 'Landlord Residence Info', 'uid' => 'e9e6d2eb-67ae-4e09-aa06-56b37d1b3467', 'category' => 'buergeramt', 'duration' => 10, 'emoji' => '🏢'],
        'anwohnerschutz' => ['name' => 'Anwohnerschutzkonzept (Lindenthal)', 'name_en' => 'Resident Protection Scheme', 'uid' => '638de2ae-20e3-4902-be70-aacd9e314fd1', 'category' => 'buergeramt', 'duration' => 10, 'emoji' => '🛡️'],
        // ── KFZ-Zulassungsstelle ───────────────────────────────────────
        'kfz_gebraucht' => ['name' => 'Anmeldung Gebrauchtfahrzeug', 'name_en' => 'Register a Used Vehicle', 'uid' => '772634af-40da-42f9-b4af-960ecb346307', 'category' => 'kfz', 'duration' => 30, 'emoji' => '🚗'],
        'kfz_neu' => ['name' => 'Anmeldung Neufahrzeug', 'name_en' => 'Register a New Vehicle', 'uid' => '2d33993c-592b-40c6-aabd-7cc8d7b8e4e4', 'category' => 'kfz', 'duration' => 30, 'emoji' => '🚗'],
        'kfz_kennzeichenwechsel' => ['name' => 'Kennzeichenwechsel', 'name_en' => 'Change License Plate', 'uid' => 'f9868fa2-9faa-486d-b049-df19b5e703a9', 'category' => 'kfz', 'duration' => 20, 'emoji' => '🔢'],
        'kfz_wiederzulassung' => ['name' => 'Wiederzulassung', 'name_en' => 'Re-register a Vehicle', 'uid' => '6aceef77-056e-4b28-bfc6-8cf4e7c41fdd', 'category' => 'kfz', 'duration' => 20, 'emoji' => '🚗'],
        'kfz_kurzzeit' => ['name' => 'Kurzzeitkennzeichen', 'name_en' => 'Short-term Plate', 'uid' => '49e5f787-ba89-4954-9da3-43ce6bfe586f', 'category' => 'kfz', 'duration' => 15, 'emoji' => '🔢'],
    ];

    /**
     * Single-site service categories — services offered at exactly one
     * address, so a task can confidently show the office + Take-me-there.
     * Bürgeramt services span the ten Kundenzentren and the concrete office
     * is only chosen at the end of the city's booking flow, so they carry no
     * pinned office.
     *
     * @var list<string>
     */
    const SINGLE_SITE_CATEGORIES = ['auslaenderbehoerde', 'kfz', 'finanzamt'];
}

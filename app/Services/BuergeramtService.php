<?php

namespace App\Services;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class BuergeramtService
{
    /**
     * Base URL for the Cologne NetAppoint system.
     */
    const BASE_URL = 'https://termine-online.stadt-koeln.de/index.php?company=stadtkoeln';

    /**
     * Cologne Buergeramt offices with their metadata.
     *
     * @var array<string, array{name: string, address: string, id: string}>
     */
    const OFFICES = [
        'deutz' => ['name' => 'Buergeramt Deutz', 'address' => 'Deutzer Freiheit 64', 'id' => 'deutz'],
        'ehrenfeld' => ['name' => 'Buergeramt Ehrenfeld', 'address' => 'Venloer Str. 419-421', 'id' => 'ehrenfeld'],
        'kalk' => ['name' => 'Buergeramt Kalk', 'address' => 'Kalker Hauptstr. 247-273', 'id' => 'kalk'],
        'lindenthal' => ['name' => 'Buergeramt Lindenthal', 'address' => 'Aachener Str. 220', 'id' => 'lindenthal'],
        'chorweiler' => ['name' => 'Buergeramt Chorweiler', 'address' => 'Pariser Platz 1', 'id' => 'chorweiler'],
        'muelheim' => ['name' => 'Buergeramt Muelheim', 'address' => 'Wiener Platz 2a', 'id' => 'muelheim'],
        'nippes' => ['name' => 'Buergeramt Nippes', 'address' => 'Neusser Str. 450', 'id' => 'nippes'],
        'porz' => ['name' => 'Buergeramt Porz', 'address' => 'Friedrich-Ebert-Ufer 64-70', 'id' => 'porz'],
        'rodenkirchen' => ['name' => 'Buergeramt Rodenkirchen', 'address' => 'Hauptstr. 85', 'id' => 'rodenkirchen'],
        'innenstadt' => ['name' => 'Bürgeramt Innenstadt', 'address' => 'Laurenzplatz 1-3', 'id' => 'innenstadt'],
        // Foreigners Office
        'auslaenderbehoerde' => ['name' => 'Ausländerbehörde Köln', 'address' => 'Dillenburger Str. 1, Kalk', 'id' => 'auslaenderbehoerde'],
        // Tax offices
        'finanzamt_altstadt' => ['name' => 'Finanzamt Köln-Altstadt', 'address' => 'Am Weidenbach 2-4', 'id' => 'finanzamt_altstadt'],
        'finanzamt_nord' => ['name' => 'Finanzamt Köln-Nord', 'address' => 'Innere Kanalstr. 214', 'id' => 'finanzamt_nord'],
        'finanzamt_sued' => ['name' => 'Finanzamt Köln-Süd', 'address' => 'Euskirchener Str. 6', 'id' => 'finanzamt_sued'],
        // Vehicle registration
        'kfz' => ['name' => 'KFZ-Zulassungsstelle Köln', 'address' => 'Max-Glomsda-Str. 4, Poll', 'id' => 'kfz'],
    ];

    /**
     * Check appointment slots across all offices.
     * Returns simulated data until real scraping is approved.
     *
     * @return array<string, array{name: string, address: string, status: string, next_slot: ?string, slots_today: int}>
     */
    public function checkSlots(): array
    {
        return $this->getSimulatedSlots();
    }

    /**
     * Returns realistic simulated slot data.
     * Structure matches what the real scraper would return.
     *
     * @return array<string, array{name: string, address: string, status: string, next_slot: ?string, slots_today: int}>
     */
    protected function getSimulatedSlots(): array
    {
        $slots = [];

        foreach (self::OFFICES as $key => $office) {
            $status = $this->randomStatus($key);
            $nextSlot = $status === 'available'
                ? Carbon::now()->addDays(rand(1, 14))->setHour(rand(8, 16))->setMinute(rand(0, 3) * 15)->toIso8601String()
                : null;

            $slots[$key] = [
                'name' => $office['name'],
                'address' => $office['address'],
                'status' => $status,
                'next_slot' => $nextSlot,
                'slots_today' => $status === 'available' ? rand(1, 5) : 0,
            ];
        }

        return $slots;
    }

    /**
     * Generate a deterministic-ish status based on the office key.
     * This simulates real-world patterns where some offices are busier.
     */
    protected function randomStatus(string $officeKey): string
    {
        // Use a seeded approach so results are somewhat consistent within a check cycle
        $hash = crc32($officeKey.date('Y-m-d-H'));
        $mod = $hash % 100;

        if ($mod < 30) {
            return 'available';
        }

        if ($mod < 60) {
            return 'mostly_booked';
        }

        return 'booked';
    }

    /**
     * Real scraping method for a single office.
     * To be enabled when ToS compliance is confirmed and rate-limiting is approved.
     *
     * Uses Http::get() to navigate the NetAppoint HTML form:
     * Step 1: Load main page to get session cookie
     * Step 2: Select service type (Anmeldung, Personalausweis, etc.)
     * Step 3: Select office location
     * Step 4: Parse calendar page for available dates
     * Step 5: Return structured slot data
     *
     * Rate limit: max 1 request per 3 seconds per office.
     *
     * @return array{status: string, next_slot: ?string, slots_today: int}
     */
    protected function scrapeOffice(string $officeId): array
    {
        try {
            // Step 1: Initialize session
            $session = Http::withOptions(['cookies' => true])
                ->timeout(10)
                ->get(self::BASE_URL);

            if (! $session->successful()) {
                Log::warning("Buergeramt scraper: failed to load main page for {$officeId}", [
                    'status' => $session->status(),
                ]);

                return ['status' => 'error', 'next_slot' => null, 'slots_today' => 0];
            }

            // Rate limit between requests
            usleep(3_000_000);

            // Step 2: Select service (Anmeldung = registration)
            // The actual form fields would be extracted from the HTML response
            // This is a placeholder for the real implementation

            // Step 3: Select office
            // Step 4: Parse calendar

            Log::info("Buergeramt scraper: scraping not yet enabled for {$officeId}");

            return ['status' => 'unavailable', 'next_slot' => null, 'slots_today' => 0];
        } catch (\Exception $e) {
            Log::error("Buergeramt scraper: error scraping {$officeId}", [
                'error' => $e->getMessage(),
            ]);

            return ['status' => 'error', 'next_slot' => null, 'slots_today' => 0];
        }
    }
}

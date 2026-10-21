<?php

namespace App\Transit\MockSample;

/**
 * The mock's departure boards for SampleTimetable: the stops the mock has a
 * capture for, each as one row per line and direction. To show another
 * capture, change these rows; nothing else reads the mock.
 *
 * Neumarkt: departures-next/station-data.js, KVB's live board read on
 * 4 October 2026 at 22:19 ("Recorded Sun 4 Oct, 22:19" in the mock). Each
 * row is [line, destination, mode, minutes from now, delay in minutes,
 * disrupted, via]: the mock shows the captured countdowns as they were, so
 * the app counts them from the mock clock.
 */
final class SampleBoards
{
    /** @var array<string, array{lat: float, lng: float, observed_at: string, source: string, rows: list<array{0: string, 1: string, 2: string, 3: list<int>, 4: int, 5: bool, 6: list<string>}>}> */
    public const STATIONS = [
        'Neumarkt' => [
            'lat' => 50.93576,
            'lng' => 6.94811,
            'observed_at' => '2026-10-04T22:19:08+02:00',
            'source' => 'trias_rt',
            'rows' => [
                ['7', 'Zündorf', 'tram', [0], 0, true, ['Heumarkt', 'Deutzer Freiheit']],
                ['18', 'Buchheim', 'tram', [0], 2, true, ['Appellhofplatz', 'Dom/Hbf']],
                ['3', 'Görlinger-Zentrum', 'tram', [2], 0, true, ['Appellhofplatz', 'Friesenplatz']],
                ['4', 'Schlebusch', 'tram', [3], 0, true, ['Poststr.', 'Severinstr.']],
                ['136', 'Daimlerstr.', 'bus', [3], 0, false, ['Rudolfplatz', 'Moltkestr.']],
                ['9', 'Königsforst', 'tram', [4], 1, false, ['Heumarkt', 'Deutzer Freiheit']],
                ['16', 'Niehl Sebastianstr.', 'tram', [7], 2, true, ['Appellhofplatz', 'Dom/Hbf']],
                ['1', 'Weiden West', 'tram', [7], 1, false, ['Rudolfplatz', 'Moltkestr.']],
                ['3', 'Thielenbruch', 'tram', [11], 0, true, ['Poststr.', 'Severinstr.']],
                ['1', 'Brück', 'tram', [11], 0, false, ['Heumarkt']],
                ['7', 'Moltkestr.', 'tram', [11], 1, true, ['Rudolfplatz', 'Moltkestr.']],
                ['146', 'Deckstein', 'bus', [11], 0, true, ['Rudolfplatz', 'Moltkestr.']],
                ['4', 'Rochusplatz', 'tram', [12], 2, true, ['Appellhofplatz', 'Friesenplatz']],
                ['18', 'Klettenberg', 'tram', [13], 0, true, ['Poststr.', 'Barbarossaplatz']],
                ['9', 'Sülz', 'tram', [14], 0, false, ['Mauritiuskirche', 'Zülpicher Platz']],
                ['16', 'Sürth', 'tram', [19], 0, true, ['Poststr.', 'Barbarossaplatz']],
            ],
        ],
    ];
}

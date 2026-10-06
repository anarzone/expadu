<?php

use App\Console\Commands\Events\ImportOfficialEvents;

test('official event price classification only confirms unconditional free admission', function (?string $source, bool $isFree, ?float $price) {
    $parser = new ReflectionMethod(ImportOfficialEvents::class, 'parsePrice');

    expect($parser->invoke(new ImportOfficialEvents, $source))->toBe([$isFree, $price]);
})->with([
    'missing price' => [null, false, null],
    'explicitly free' => ['Die Teilnahme ist kostenlos.', true, null],
    'kostenfrei' => ['Die Teilnahme ist kostenfrei.', true, null],
    'optional donation' => ['Kostenlos. Spenden sind Willkommen.', true, null],
    'single stated fee' => ['Die Materialgebühr beträgt 3 Euro. Du bezahlst sie in bar vor Ort.', false, 3.0],
    'free park with paid guided event' => ['Der Eintritt in den Park ist frei. Kosten für die Führungen: Erwachsene: 8 Euro, ermäßigt 2 Euro; Kinder bis 10 Jahre: kostenlos', false, null],
    'free only for some participants' => ['Kinder: kostenlos. Erwachsene: 8 Euro.', false, null],
    'reduced and regular prices' => ['Der Eintritt kostet 8 Euro, ermäßigt 6 Euro, mit Schwerbehindertenausweis 4 Euro.', false, null],
    'free cancellation is not a free event' => ['63 Euro pro Paket. Bei einer Stornierung: bis 14 Tage vor der Veranstaltung kostenfrei.', false, null],
]);

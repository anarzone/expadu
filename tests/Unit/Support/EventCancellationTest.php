<?php

use App\Support\EventCancellation;

test('a listing that says it is off is cancelled', function (string $title, ?string $description) {
    expect(EventCancellation::said($title, $description))->toBeTrue();
})->with([
    'koeln.de boilerplate (#3685)' => ['Dú Maroc – Block Bladi Gangster Tour', 'Im Oktober 2026 geht er auf Tournee und ist in Köln zu erleben. Diese Veranstaltung wurde abgesagt. Umtausch-Informationen sind eventuell hier zu finden (falls vorhanden.)'],
    'city feed title' => ['Abgesagt – MINT-Vorlesen am Samstag', 'Diese Veranstaltung ist abgesagt.'],
    'title in capitals' => ['ABGESAGT: Jazz im Park', null],
    'title says it falls out' => ['Lesung fällt aus', null],
    'postponed without a new date' => ['Herbstkonzert', 'Das Konzert wird leider verschoben. Tickets behalten ihre Gültigkeit.'],
    'falls out in the text' => ['Kinderführung', 'Die Führung fällt wegen Krankheit leider aus.'],
]);

test('a listing that only mentions the words stays on', function (string $title, ?string $description) {
    expect(EventCancellation::said($title, $description))->toBeFalse();
})->with([
    'free entry' => ['Sommerfest', 'Eintritt entfällt, Spenden willkommen.'],
    'no box office' => ['Konzert im Park', 'Abendkasse entfällt, nur Vorverkauf.'],
    'a replacement date' => ['Ersatztermin für das abgesagte Konzert vom 3.10.', 'Alle Karten bleiben gültig.'],
    'a character falls' => ['Richard II. – Solo eines Königs', 'Als Richard fällt, sieht er in ihm nur einen Menschen.'],
    'a show called Cancel Culture' => ['Salim Samatou – Cancel Culture', 'Bei Salim Samatou ist kein Abend wie der andere.'],
    'moved earlier, this is the new date' => ['Jules Ahoi – Abschiedstour', 'Die Tour wird auf Grund aktueller Entwicklungen ins Jahr 2026 verschoben. Bereits gekaufte Tickets behalten ihre Gültigkeit.'],
    'moved to a given date' => ['Herbstkonzert', 'Das Konzert wird verschoben auf den 12.12.2026.'],
]);

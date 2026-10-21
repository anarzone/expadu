<?php

use App\Support\EventEvidence;

it('keeps a chip only when the listing says so', function () {
    expect(EventEvidence::chips(['English-friendly', 'newcomers welcome', 'solo-OK'], 'Stammtisch auf Englisch, Neuankömmlinge willkommen'))
        ->toBe(['English-friendly', 'newcomers welcome'])
        ->and(EventEvidence::chips(['English-friendly', 'solo-OK'], 'Konzert im Stadtgarten'))->toBe([])
        ->and(EventEvidence::chips(['solo-OK'], 'Gern auch allein kommen'))->toBe(['solo-OK']);
});

it('shows a language only when the listing names it', function (?string $language, string $source, ?string $shown) {
    expect(EventEvidence::language($language, $source))->toBe($shown);
})->with([
    'English named' => ['en', 'Talk in English', 'en'],
    'English guessed' => ['en', 'Lesung im Literaturhaus', null],
    'Deutschland is not German' => ['de', 'Die größte Messe in Deutschland', null],
    'German named' => ['de', 'Führung auf Deutsch', 'de'],
    'none needed, said' => ['none_needed', 'Ohne Deutschkenntnisse möglich', 'none_needed'],
    'none needed, guessed' => ['none_needed', '5 km laufen', null],
    'mixed needs both' => ['mixed', 'Deutsch und English', 'mixed'],
]);

it('shows a summary only when every figure in it is in the listing', function (string $text, string $source, bool $shown) {
    expect(EventEvidence::text($text, $source))->toBe($shown ? $text : null);
})->with([
    'figures from the source' => ['Doors at 19:30, tickets 12 €.', 'Einlass 19.30 Uhr, Eintritt 12 Euro', true],
    'an invented time' => ['Starts at 20:00.', 'Einlass 19.30 Uhr', false],
    'an invented price' => ['Tickets cost 15 €.', 'Eintritt frei', false],
    'no figures at all' => ['A jazz evening in the Stadtgarten.', 'Jazzabend im Stadtgarten', true],
]);

<?php

function homeV2PrototypeHtml(): string
{
    $html = file_get_contents(dirname(__DIR__, 2).'/prototype/home-v2.html');

    expect($html)->not->toBeFalse();

    return $html;
}

it('replaces the application concept with the complete guides design', function () {
    expect(homeV2PrototypeHtml())
        ->toContain('--paper: #f6f5f1')
        ->toContain('data-page="guides"')
        ->toContain('Germany,')
        ->toContain('without the folklore.')
        ->toContain('Editorial trust ledger')
        ->toContain('Filter guides by topic')
        ->not->toContain('Home (discovery)')
        ->not->toContain('Composer result');
});

it('contains two complete source-checked article views', function () {
    $html = homeV2PrototypeHtml();
    $normalizedHtml = preg_replace('/\s+/', ' ', $html);

    expect($normalizedHtml)->not->toBeNull();

    expect($html)
        ->toContain('data-page="anmeldung"')
        ->toContain('data-page="first-90-days"')
        ->toContain('The rule: 14 days after moving in')
        ->toContain('Weeks 2–8: residence status, if you need it')
        ->toContain('After 90 days: build the longer plan')
        ->and(substr_count($html, 'class="source-panel"'))
        ->toBe(2);

    expect($normalizedHtml)->toContain('The trap: your rental contract is not the landlord form');
});

it('supports in-page article navigation and responsive reading layouts', function () {
    $html = homeV2PrototypeHtml();
    $normalizedHtml = preg_replace('/\s+/', ' ', $html);

    expect($normalizedHtml)->not->toBeNull();

    expect($html)
        ->toContain('data-guide-open="anmeldung"')
        ->toContain('data-guide-open="first-90-days"')
        ->toContain("window.addEventListener('hashchange', routeFromHash)")
        ->toContain("history.pushState(null, '', targetHash)")
        ->toContain('@media (max-width: 800px)');

    expect($normalizedHtml)->toContain('grid-template-columns: minmax(0, 170px) minmax(0, 720px) minmax( 0, 210px );');
});

<?php

function landingV2Html(): string
{
    $html = file_get_contents(dirname(__DIR__, 2).'/prototype/landing/v2.html');

    expect($html)->not->toBeFalse();

    return $html;
}

it('tells a distinctive Expadu story and makes trust explicit', function () {
    expect(landingV2Html())
        ->toContain('Tell Expadu your day. It plans the rest.')
        ->toContain('Why Expadu exists')
        ->toContain('No invented fees, hours or deadlines')
        ->toContain('Official source and checked date shown');
});

it('offers a balanced tool grid and links to two practical guides', function () {
    $html = landingV2Html();

    expect($html)
        ->toContain('grid-template-columns:repeat(2,minmax(0,1fr))')
        ->toContain('id="guides"')
        ->toContain('href="./blog-post.html"')
        ->toContain('href="./blog-post-90days.html"')
        ->and(substr_count($html, '<a class="guide-card"'))
        ->toBe(2);
});

it('keeps small interface text readable and removes the redundant faq eyebrow', function () {
    expect(landingV2Html())
        ->toContain('.eyebrow { font-family:var(--fm); font-size:12px;')
        ->toContain('.demo-label { font-family:var(--fm); font-size:12px;')
        ->not->toContain('<span class="eyebrow">Before you ask</span>');
});

it('keeps each local landing destination available', function (string $file) {
    expect(dirname(__DIR__, 2).'/prototype/landing/'.$file)->toBeFile();
})->with([
    'blog.html',
    'blog-post.html',
    'blog-post-90days.html',
    'tools.html',
    'tool-dticket.html',
    'tool-residency.html',
    'tool-citizenship.html',
    'tool-netto.html',
    'guide.css',
    'proto.css',
]);

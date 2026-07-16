<?php

function prototypeContents(string $relativePath): string
{
    $contents = file_get_contents(dirname(__DIR__, 2).'/prototype/'.$relativePath);

    expect($contents)->not->toBeFalse();

    return $contents;
}

it('keeps home v2 as the discovery and composer application concept', function () {
    expect(prototypeContents('home-v2.html'))
        ->toContain('Home (discovery)')
        ->toContain('Composer result')
        ->toContain('Plan something, ask about paperwork, find a place…')
        ->toContain('Suggestions built from your situation')
        ->not->toContain('Germany, without the folklore.');
});

it('uses the original blog typography in both transferred guide articles', function () {
    $guideStyles = prototypeContents('landing/guide.css');

    expect($guideStyles)
        ->toContain("--sans: 'Geist'")
        ->toContain("--serif: 'Fraunces'")
        ->toContain("--mono: 'Geist Mono'")
        ->toContain('.article-body h2')
        ->toContain('font-family: var(--serif)');

    foreach (['landing/blog-post.html', 'landing/blog-post-90days.html'] as $article) {
        expect(prototypeContents($article))
            ->toContain('href="./guide.css?v=4"')
            ->toContain('class="article-hero"')
            ->toContain('article-layout')
            ->toContain('class="article-body"');
    }
});

<?php

use App\Bureaucracy\Catalogue\OfficialLinkProbe;
use App\Bureaucracy\Catalogue\VerifiedActionDirectory;
use Illuminate\Support\Facades\Http;

test('official action URLs are allow-listed and carry purpose rather than invented booking behavior', function () {
    $directory = app(VerifiedActionDirectory::class);
    $action = $directory->compile('fixture.authority', 'https://www.stadt-koeln.de/service/produkte/00415/index.html', 'information', 'de-nrw-cologne');
    expect($action['purpose'])->toBe('information')->and($action['channel'])->toBe('official_website');
    foreach (['http://www.stadt-koeln.de/', 'https://stadt-koeln.de.attacker.invalid/', 'https://user:pass@www.stadt-koeln.de/', 'https://127.0.0.1/', 'javascript:alert(1)', 'https://www.stadt-koeln.de:444/'] as $url) {
        expect(fn () => $directory->compile('fixture.bad', $url, 'information', 'de-nrw-cologne'))->toThrow(DomainException::class);
    }
});

test('link probes never follow redirects off an approved host or fetch arbitrary URLs', function () {
    Http::fake(['www.stadt-koeln.de/*' => Http::response('', 302, ['Location' => 'http://127.0.0.1/private'])]);
    $probe = app(OfficialLinkProbe::class);
    expect($probe->check('https://www.stadt-koeln.de/service/')['status'])->toBe('unverifiable');
    Http::assertSent(fn ($request) => $request->method() === 'HEAD' && $request->url() === 'https://www.stadt-koeln.de/service/');
    Http::assertSentCount(1);
    expect($probe->check('https://unreviewed.invalid/')['reason'])->toBe('host_review_required');
    Http::assertSentCount(1);
});

test('a working source, missing page and temporary failure remain distinct', function (int $status, string $result) {
    Http::fake(['www.stadt-koeln.de/*' => Http::response('', $status)]);
    expect(app(OfficialLinkProbe::class)->check('https://www.stadt-koeln.de/service/')['status'])->toBe($result);
})->with([[200, 'healthy'], [404, 'dead'], [410, 'dead'], [503, 'unverifiable'], [403, 'unverifiable']]);

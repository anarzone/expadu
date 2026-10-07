<?php

use App\Support\SentryScrubber;
use Illuminate\Support\Facades\Auth;
use Sentry\Breadcrumb;
use Sentry\Event;
use Sentry\ExceptionDataBag;
use Sentry\Frame;
use Sentry\Stacktrace;

test('the scrubber redacts sensitive keys and coordinates', function () {
    $event = Event::createEvent();
    $event->setRequest([
        'url' => 'https://app.expadu.com/api/location/confirm?lat=50.9&lng=6.9',
        'query_string' => 'lat=50.9&lng=6.9',
        'data' => ['password' => 'hunter2', 'lat' => 50.9, 'name' => 'Anna', 'nested' => ['api_key' => 'sk-live-xxx']],
    ]);
    $event->setExtra(['session' => 'abc', 'harmless' => 'ok']);

    $out = SentryScrubber::scrub($event);

    $req = $out->getRequest();
    expect($req['url'])->toBe('https://app.expadu.com/api/location/confirm');
    expect($req['query_string'])->toBe('[redacted]');
    expect($req['data']['password'])->toBe('[redacted]');
    expect($req['data']['lat'])->toBe('[redacted]');
    expect($req['data']['nested']['api_key'])->toBe('[redacted]');
    expect($req['data']['name'])->toBe('Anna');
    expect($out->getExtra()['session'])->toBe('[redacted]');
    expect($out->getExtra()['harmless'])->toBe('ok');
});

test('error reports cannot include raw questions answers facts or provider headers', function () {
    $event = Event::createEvent();
    $event->setRequest([
        'data' => [
            'message' => 'Private letter',
            'facts' => ['current_residence_title' => 'blue_card'],
            'nested' => ['answer' => 'Private answer'],
        ],
        'headers' => ['x-private-provider-header' => 'secret'],
    ]);
    $event->setExtra([
        'prompt' => 'Private prompt',
        'raw_text' => 'Private input',
        'candidate' => ['value' => 'Private extracted value'],
        'response_content' => 'Private provider response',
        'operation' => 'extract_case_fact',
    ]);

    $out = SentryScrubber::scrub($event);

    expect(json_encode([$out->getRequest(), $out->getExtra()]))->not->toContain('Private', 'secret', 'blue_card')
        ->and($out->getExtra()['operation'])->toBe('extract_case_fact');
});

test('error reports remove unstructured provider text and stack variables while retaining error location', function () {
    $stack = new Stacktrace([
        new Frame('extract', '/app/Extractor.php', 42, vars: ['unlabelled' => 'Private fact']),
    ]);
    $event = Event::createEvent();
    $event->setExceptions([new ExceptionDataBag(new RuntimeException('Private provider response'), $stack)]);
    $event->setStacktrace(new Stacktrace([
        new Frame('parse', '/app/Parser.php', 24, vars: ['unlabelled' => 'Different private fact']),
    ]));
    $event->setMessage('Private log text', ['Private log parameter'], 'Private formatted text');
    $event->setContext('processing', ['message' => 'Private context', 'status' => 503]);
    $event->setBreadcrumb([new Breadcrumb('error', 'http', 'provider', 'Private breadcrumb', [
        'response_content' => 'Private response', 'status' => 503,
    ])]);

    $out = SentryScrubber::scrub($event);

    expect($out->getExceptions()[0]->getValue())->toBe('[redacted]')
        ->and($out->getExceptions()[0]->getType())->toBe(RuntimeException::class)
        ->and($out->getExceptions()[0]->getStacktrace()->getFrame(0)->getVars())->toBe([])
        ->and($out->getExceptions()[0]->getStacktrace()->getFrame(0)->getLine())->toBe(42)
        ->and($out->getStacktrace()->getFrame(0)->getVars())->toBe([])
        ->and($out->getStacktrace()->getFrame(0)->getLine())->toBe(24)
        ->and($out->getMessage())->toBe('[redacted]')
        ->and($out->getMessageParams())->toBe([])
        ->and($out->getMessageFormatted())->toBe('[redacted]')
        ->and($out->getContexts()['processing']['message'])->toBe('[redacted]')
        ->and($out->getBreadcrumbs()[0]->getMessage())->toBe('[redacted]')
        ->and($out->getBreadcrumbs()[0]->getMetadata()['response_content'])->toBe('[redacted]')
        ->and($out->getBreadcrumbs()[0]->getMetadata()['status'])->toBe(503);
});

test('bureaucracy onboarding and composer request bodies are never sent to error reporting', function (string $path) {
    $event = Event::createEvent();
    $event->setRequest([
        'url' => 'https://app.expadu.test'.$path,
        'data' => ['current_residence_title' => 'Private status', 'arbitrary' => 'Private text'],
    ]);

    expect(SentryScrubber::scrub($event)->getRequest()['data'])->toBe('[redacted]');
})->with(['/bureaucracy/case/messages', '/onboarding', '/composer/parse', '/api/composer/parse']);

test('a failed scrub does not send the original private event', function () {
    Auth::shouldReceive('id')->andThrow(new RuntimeException('Auth context unavailable'));
    $event = Event::createEvent();
    $event->setExtra(['message' => 'Private text']);

    expect(SentryScrubber::scrub($event))->toBeNull();
});

test('sensitive context names protect the entire context even when its inner names are ordinary', function () {
    $event = Event::createEvent();
    $event->setContext('facts', ['current_residence_title' => 'Private legal status']);
    $event->setContext('request_headers', ['x-provider' => 'Private header']);
    $event->setContext('runtime', ['status' => 503]);

    $out = SentryScrubber::scrub($event);

    expect(json_encode($out->getContexts()))->not->toContain('Private legal status', 'Private header')
        ->and($out->getContexts()['runtime']['status'])->toBe(503);
});

test('object shaped telemetry is withheld without invoking its serializers', function () {
    $serializable = new class implements JsonSerializable
    {
        public bool $called = false;

        public function jsonSerialize(): mixed
        {
            $this->called = true;

            return ['unexpected' => 'Private serialized value'];
        }
    };
    $event = Event::createEvent();
    $event->setExtra(['response' => (object) ['content' => 'Private response']]);
    $event->setBreadcrumb([new Breadcrumb('error', 'http', 'provider', null, [
        'response' => $serializable,
    ])]);

    $out = SentryScrubber::scrub($event);
    $serialized = json_encode([$out->getExtra(), $out->getBreadcrumbs()[0]->getMetadata()]);

    expect($serialized)->not->toContain('Private response', 'Private serialized value')
        ->and($serializable->called)->toBeFalse();
});

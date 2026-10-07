<?php

use App\Bureaucracy\Assessment\AssessmentInput;
use App\Bureaucracy\Catalogue\CatalogueCompiler;
use App\Bureaucracy\Catalogue\CatalogueReleaseStore;
use App\Bureaucracy\GuidancePublication;
use App\Bureaucracy\People\EnsureAccountHolder;
use App\Bureaucracy\ReadModel\CoverageUnits;
use App\Bureaucracy\ReadModel\ReassessPerson;
use App\Bureaucracy\RuleSourcePolicy;
use App\Bureaucracy\Verification\ClaimCheck;
use App\Bureaucracy\Verification\EscalationRaised;
use App\Bureaucracy\Verification\Escalations;
use App\Bureaucracy\Verification\Figures;
use App\Bureaucracy\Verification\SourceCheckRecorder;
use App\Bureaucracy\Verification\SourcePageFetcher;
use App\Bureaucracy\Verification\SourceText;
use App\Bureaucracy\Verification\UnansweredPlan;
use App\Models\BureaucracyEscalation;
use App\Models\BureaucracySourceCheck;
use App\Models\Task;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Http;

const BMG17 = 'https://www.gesetze-im-internet.de/bmg/__17.html';
const KOELN = 'https://www.stadt-koeln.de/service/produkte/00415/index.html';

beforeEach(fn () => app()->bind(SourceCheckRecorder::class, fn ($app) => new SourceCheckRecorder($app->make(GuidancePublication::class))));

function bmgPage(string $text = 'innerhalb von zwei Wochen nach dem Einzug'): string
{
    return '<html><head><meta charset="iso-8859-1"><title>&#167; 17 BMG - Einzelnorm</title></head><body>'
        .'<h1>&#167; 17 Anmeldung, Abmeldung</h1><p>(1) Wer eine Wohnung bezieht, hat sich '.$text.' bei der Meldebeh&ouml;rde anzumelden.</p>'
        .'<p>'.str_repeat('(2) Wer aus einer Wohnung auszieht, hat sich bei der Meldebehörde abzumelden. ', 6).'</p></body></html>';
}

function checkedCard(array $overrides = []): array
{
    return array_replace([
        'title' => 'Register your address',
        'description' => 'Register within a two-week period under §17 BMG after moving in.',
        'deadline_type' => 'days_since_move_in', 'deadline_days' => 14,
        'legal_sources' => [
            ['kind' => 'primary', 'label' => '§17 BMG', 'url' => BMG17],
            ['kind' => 'implementation', 'label' => 'Stadt Köln', 'url' => KOELN],
        ],
        'claims' => [
            ['id' => 'period', 'states' => 'two-week period under §17 BMG', 'source' => '§17 BMG', 'quote' => 'innerhalb von zwei Wochen nach dem Einzug'],
            ['id' => 'deadline', 'covers' => 'deadline', 'source' => '§17 BMG', 'quote' => 'innerhalb von zwei Wochen nach dem Einzug'],
        ],
    ], $overrides);
}

function okPage(string $html): array
{
    return ['status' => 'ok', 'text' => SourceText::fromHtml($html)];
}

it('compares durations, money, sections and levels across English and German', function () {
    $tokens = fn (string $text) => Figures::tokens(SourceText::key($text));

    expect($tokens('a two-week period'))->toBe(['duration:days:14'])
        ->and($tokens('innerhalb von zwei Wochen'))->toBe(['duration:days:14'])
        ->and($tokens('binnen 14 Tagen'))->toBe(['duration:days:14'])
        ->and($tokens('up to €1,000'))->toBe(['money:1000'])
        ->and($tokens('bis zu 1.000 Euro'))->toBe(['money:1000'])
        ->and($tokens('§19(2) BMG'))->toBe(['section:19'])
        ->and($tokens('nach einundzwanzig Monaten mit B1'))->toBe(['duration:months:21', 'level:b1'])
        ->and($tokens('fünf Werktage'))->toBe(['duration:workdays:5'])
        ->and($tokens('an 11-digit number'))->toBe(['number:11']);
});

it('reads official HTML as plain comparable text', function () {
    expect(SourceText::fromHtml('<p>Wohnungs&shy;geber&nbsp;best&auml;tigung</p><script>x=1</script><p>&bdquo;Einzug&ldquo;</p>'))
        ->toBe('Wohnungsgeber bestätigung "Einzug"');
});

it('passes a card whose every figure is quoted from its source', function () {
    expect(app(ClaimCheck::class)->offlineErrors(checkedCard()))->toBe([])
        ->and(app(ClaimCheck::class)->check(checkedCard(), [BMG17 => okPage(bmgPage())])['outcome'])->toBe('passed');
});

it('refuses figures that no claim covers', function () {
    $card = checkedCard(['description' => 'Register within a two-week period under §17 BMG after moving in. Fines reach €1,000.']);

    expect(app(ClaimCheck::class)->offlineErrors($card))->toHaveCount(1)
        ->and(app(ClaimCheck::class)->offlineErrors($card)[0])->toStartWith('unclaimed figure "€1,000" in:');
});

it('refuses a claim whose number differs from its quote', function () {
    $card = checkedCard([
        'description' => 'Register within a three-week period under §17 BMG after moving in.',
        'claims' => [['id' => 'period', 'states' => 'three-week period under §17 BMG', 'source' => '§17 BMG', 'quote' => 'innerhalb von zwei Wochen nach dem Einzug'],
            ['id' => 'deadline', 'covers' => 'deadline', 'source' => '§17 BMG', 'quote' => 'innerhalb von zwei Wochen nach dem Einzug']],
    ]);

    expect(app(ClaimCheck::class)->offlineErrors($card))->toBe(['claim `period`: "21 days" is not in its quote']);
});

it('requires the stored deadline itself to be quoted', function () {
    $card = checkedCard(['deadline_days' => 30]);

    expect(app(ClaimCheck::class)->offlineErrors($card))
        ->toBe(['the 30-day deadline needs a `covers: deadline` claim whose quote states that period']);
});

it('rejects malformed claims before anything is fetched', function (array $claims, string $error) {
    expect(app(ClaimCheck::class)->offlineErrors(checkedCard(['claims' => $claims])))->toContain($error);
})->with([
    'unknown source' => [[['id' => 'a', 'states' => 'two-week period under §17 BMG', 'source' => 'Somewhere', 'quote' => 'innerhalb von zwei Wochen nach dem Einzug']],
        'claims.0.source must name one of this card\'s legal_sources labels'],
    'no primary quote' => [[['id' => 'a', 'states' => 'two-week period under §17 BMG', 'source' => 'Stadt Köln', 'quote' => 'innerhalb von zwei Wochen nach dem Einzug']],
        'at least one claim must quote a primary legal source'],
    'text not on the card' => [[['id' => 'a', 'states' => 'a fortnight', 'source' => '§17 BMG', 'quote' => 'innerhalb von zwei Wochen nach dem Einzug'],
        ['id' => 'b', 'covers' => 'deadline', 'source' => '§17 BMG', 'quote' => 'innerhalb von zwei Wochen nach dem Einzug']],
        'claim `a`: its text is not on the card: "a fortnight"'],
    'both states and covers' => [[['id' => 'a', 'states' => 'x', 'covers' => 'deadline', 'source' => '§17 BMG', 'quote' => 'innerhalb von zwei Wochen nach dem Einzug']],
        'claims.0 needs exactly one of `states` (card text) or `covers: deadline`'],
]);

it('fails when the quote has gone from the page or the page is gone', function () {
    $changed = app(ClaimCheck::class)->check(checkedCard(), [BMG17 => okPage(bmgPage('innerhalb von drei Wochen nach dem Einzug'))]);
    $gone = app(ClaimCheck::class)->check(checkedCard(), [BMG17 => ['status' => 'missing']]);

    expect($changed['outcome'])->toBe('failed')
        ->and($changed['failures'])->toContain('claim `period`: the quote is no longer on §17 BMG')
        ->and($gone['outcome'])->toBe('failed');
});

it('treats an unreachable page as no news rather than a failure', function () {
    expect(app(ClaimCheck::class)->check(checkedCard(), [BMG17 => ['status' => 'unreachable']]))
        ->toBe(['outcome' => 'unreachable', 'failures' => [], 'unreachable' => [BMG17]]);
});

it('requires a cited section to be the section of the quoted law page', function () {
    $card = checkedCard(['description' => 'Register within a two-week period under §27 BMG after moving in.',
        'claims' => [['id' => 'period', 'states' => 'two-week period under §27 BMG', 'source' => '§17 BMG', 'quote' => 'innerhalb von zwei Wochen nach dem Einzug'],
            ['id' => 'deadline', 'covers' => 'deadline', 'source' => '§17 BMG', 'quote' => 'innerhalb von zwei Wochen nach dem Einzug']]]);

    expect(app(ClaimCheck::class)->check($card, [BMG17 => okPage(bmgPage())])['failures'])
        ->toBe(['claim `period`: "§27" is not the section cited by §17 BMG']);
});

it('fetches only allowlisted pages and separates missing from unreachable', function () {
    Http::fake([
        'www.gesetze-im-internet.de/bmg/__17.html' => Http::response(mb_convert_encoding(bmgPage(), 'ISO-8859-1', 'UTF-8'), 200, ['Content-Type' => 'text/html']),
        'www.gesetze-im-internet.de/gone.html' => Http::response('', 404),
        'www.stadt-koeln.de/*' => Http::response('', 503),
        'www.gesetze-im-internet.de/moved.html' => Http::response('', 301, ['Location' => 'https://evil.example/page']),
    ]);
    $fetcher = app(SourcePageFetcher::class);

    expect($fetcher->fetch(BMG17)['text'])->toContain('Meldebehörde')
        ->and($fetcher->fetch('https://www.gesetze-im-internet.de/gone.html')['status'])->toBe('missing')
        ->and($fetcher->fetch(KOELN)['status'])->toBe('unreachable')
        ->and($fetcher->fetch('https://www.gesetze-im-internet.de/moved.html')['reason'])->toBe('host_not_allowed')
        ->and($fetcher->fetch('https://example.com/law')['reason'])->toBe('host_not_allowed');
    Http::assertNotSent(fn ($request) => str_contains($request->url(), 'example'));
});

it('treats a bot-check interstitial as unreachable and never as the page', function () {
    Http::fake(['*' => Http::response('<html><body>Radware Page Verifying your browser before proceeding...</body></html>')]);

    expect(app(SourcePageFetcher::class)->fetch(KOELN))->toMatchArray(['status' => 'unreachable', 'reason' => 'bot_check_or_empty']);
});

it('records each check and escalates a failed residence or deadline card to the owner once', function () {
    Exceptions::fake();
    $task = Task::factory()->create([...checkedCard(), 'key' => 'core.anmeldung', 'is_published' => true, 'urgency' => 'critical']);
    $before = $task->only(['review_status', 'verified_at', 'review_due_at', 'reviewed_by']);

    $law = bmgPage();
    Http::fake(function ($request) use (&$law) {
        return Http::response(str_contains($request->url(), 'bmg') ? $law : '<p>Stadt</p>');
    });
    $this->artisan('bureaucracy:verify-sources')->assertSuccessful();
    expect(BureaucracySourceCheck::query()->sole()->outcome)->toBe('passed');

    $law = bmgPage('innerhalb von drei Wochen nach dem Einzug');
    $this->artisan('bureaucracy:verify-sources --strict')->assertFailed();
    $this->artisan('bureaucracy:verify-sources')->assertSuccessful();

    $escalation = BureaucracyEscalation::query()->sole();
    expect(BureaucracySourceCheck::query()->sole()->outcome)->toBe('failed')
        ->and($escalation->only(['kind', 'subject', 'severity', 'occurrences']))
        ->toBe(['kind' => 'source_check_failed', 'subject' => 'core.anmeldung', 'severity' => 'high', 'occurrences' => 2])
        // Report-only: nothing about the card's publication changes here.
        ->and($task->fresh()->only(['review_status', 'verified_at', 'review_due_at', 'reviewed_by']))->toEqual($before);
    Exceptions::assertReportedCount(1);
    Exceptions::assertReported(fn (EscalationRaised $error) => $error->subject === 'core.anmeldung');

    $law = bmgPage();
    $this->artisan('bureaucracy:verify-sources')->assertSuccessful();
    expect($escalation->fresh()->resolved_at)->not->toBeNull();
});

it('keeps an unreachable source quiet', function () {
    Exceptions::fake();
    Task::factory()->create([...checkedCard(), 'key' => 'core.anmeldung', 'is_published' => true]);
    Http::fake(['*' => Http::response('', 503)]);

    $this->artisan('bureaucracy:verify-sources')->assertSuccessful();

    expect(BureaucracySourceCheck::query()->sole()->outcome)->toBe('unreachable')
        ->and(BureaucracyEscalation::query()->count())->toBe(0);
    Exceptions::assertNothingReported();
});

it('records normal escalations without alerting and alerts again when one reopens', function () {
    Exceptions::fake();
    $escalations = app(Escalations::class);

    $escalations->raise('source_check_failed', 'core.bank_account', Escalations::Normal, 'Changed');
    $escalations->raise('source_check_failed', 'core.anmeldung', Escalations::High, 'Changed');
    $escalations->raise('source_check_failed', 'core.anmeldung', Escalations::High, 'Still changed');
    Exceptions::assertReportedCount(1);

    $escalations->resolve('source_check_failed', 'core.anmeldung');
    $escalations->raise('source_check_failed', 'core.anmeldung', Escalations::High, 'Changed again');
    Exceptions::assertReportedCount(2);
});

it('schedules the daily source re-check', function () {
    app(Kernel::class)->bootstrap();

    expect(collect(app(Schedule::class)->events())->contains(fn ($event) => str_contains($event->command ?? '', 'bureaucracy:verify-sources')))->toBeTrue();
});

/** Serves every cited page with the quotes its cards rely on, minus any quote in $drop. */
function fakeOfficialPages(array $drop = []): void
{
    $pages = [];
    foreach (Task::query()->whereNotNull('claims')->get() as $task) {
        $urls = collect($task->legal_sources)->pluck('url', 'label');
        foreach ($task->claims as $claim) {
            $pages[$urls[$claim['source']]][] = in_array($claim['quote'], $drop, true) ? '' : $claim['quote'];
        }
    }
    // Http::fake stubs accumulate, so later calls only swap the served pages.
    $first = ! app()->bound('test.official_pages');
    app()->instance('test.official_pages', $pages);
    if (! $first) {
        return;
    }
    Http::fake(function ($request) {
        $section = preg_match('#/__(\w+)\.html#', $request->url(), $m) ? '§ '.$m[1] : 'Stadt Köln';
        $quotes = app('test.official_pages')[$request->url()] ?? [];

        return Http::response("<h1>{$section} Einzelnorm</h1><p>".implode(' </p><p>', $quotes).'</p><p>'.str_repeat('Weitere amtliche Hinweise zum Verfahren. ', 15).'</p>');
    });
}

it('publishes an automatically checked card only after its source check passes, and pulls it on failure', function () {
    $this->artisan('bureaucracy:import-tasks')->assertSuccessful();
    $this->artisan('bureaucracy:compile-catalogue --deploy')->assertSuccessful();
    $published = fn () => Task::query()->authoritative()->where('key', 'core.anmeldung')->exists();
    expect(Task::query()->where('key', 'core.anmeldung')->value('reviewed_by'))->toBe('automated_source_check')
        ->and($published())->toBeFalse();

    fakeOfficialPages();
    $this->artisan('bureaucracy:verify-sources --deploy')->assertSuccessful();
    $release = app(CatalogueReleaseStore::class)->current();
    expect($published())->toBeTrue()
        ->and(collect($release['definitions'])->flatMap(fn ($d) => $d['variants'])->pluck('task_key'))->toContain('core.anmeldung');

    // A re-import at deploy keeps the pass: the window comes from the stored check.
    $this->artisan('bureaucracy:import-tasks --retire-missing')->assertSuccessful();
    expect($published())->toBeTrue();

    fakeOfficialPages(drop: ['hat sich innerhalb von zwei Wochen nach dem Einzug bei der Meldebehörde anzumelden']);
    $this->artisan('bureaucracy:verify-sources')->assertSuccessful();
    $release = app(CatalogueReleaseStore::class)->current();
    $unit = collect(app(CoverageUnits::class)->for(new AssessmentInput([], [], [], $release, 'de-nrw-cologne', CarbonImmutable::now())))
        ->firstWhere('unit_id', 'core.anmeldung');
    expect($published())->toBeFalse()
        ->and($release['withdrawn'])->toContain('core.anmeldung')
        ->and($unit['state'])->toBe('unconfirmed')
        ->and($unit['source_urls']['legal'])->toContain(BMG17)
        ->and(BureaucracyEscalation::query()->where('subject', 'core.anmeldung')->value('severity'))->toBe('high');
});

it('withholds an unconfirmed card from a release without blocking the rest', function () {
    $this->artisan('bureaucracy:import-tasks')->assertSuccessful();

    $artifact = app(CatalogueCompiler::class)->compile(Task::query()->whereNotNull('key')->orderBy('key')->get()->all());

    expect(collect($artifact['inventory'])->firstWhere('key', 'core.anmeldung')['status'])->toBe('source_unconfirmed')
        ->and(collect($artifact['definitions'])->flatMap(fn ($d) => $d['variants'])->pluck('task_key'))
        ->not->toContain('core.anmeldung')->toContain('case.family.first_permit.prepare');
});

it('keeps the automated stamp and the automated check together', function (array $card, string $error) {
    $base = ['review_status' => 'approved', 'jurisdiction' => 'de-nrw-cologne', 'content_version' => '2026-10-07.1', ...checkedCard()];

    expect(app(RuleSourcePolicy::class)->importErrors([...$base, ...$card]))->toContain($error);
})->with([
    'a person cannot sign off as the check' => [['reviewed_by' => 'automated_source_check', 'source_verification' => 'dual_source', 'verified_at' => '2026-10-07'],
        '`quote_checked` and reviewed_by `automated_source_check` must be used together'],
    'the check cannot carry a human stamp' => [['reviewed_by' => 'expadu_content_owner', 'source_verification' => 'quote_checked'],
        '`quote_checked` and reviewed_by `automated_source_check` must be used together'],
    'no authored verification date' => [['reviewed_by' => 'automated_source_check', 'source_verification' => 'quote_checked', 'verified_at' => '2026-10-07'],
        'verified_at is set by the source check, not authored, on `quote_checked` cards'],
    'claims are required' => [['reviewed_by' => 'automated_source_check', 'source_verification' => 'quote_checked', 'claims' => null],
        '`quote_checked` cards need source claims'],
]);

it('names the processes a plan cannot answer and spots an empty plan', function () {
    $plan = ['coverage' => ['state' => 'partial', 'processes' => [
        ['definition_id' => 'address.registration', 'relevance' => 'unknown', 'coverage' => ['status' => 'review_required']],
        ['definition_id' => 'tax.identification', 'relevance' => 'relevant', 'coverage' => ['status' => 'partial']],
        ['definition_id' => 'residence.family.first', 'relevance' => 'not_relevant', 'coverage' => ['status' => 'review_required']],
    ]], 'actions' => []];

    expect(UnansweredPlan::processes($plan))->toBe(['address.registration'])
        ->and(UnansweredPlan::isEmpty($plan))->toBeFalse()
        ->and(UnansweredPlan::isEmpty(['coverage' => ['state' => 'partial', 'processes' => []], 'actions' => []]))->toBeTrue()
        ->and(UnansweredPlan::isEmpty(['coverage' => ['state' => 'not_activated', 'processes' => []], 'actions' => []]))->toBeFalse();
});

it('escalates once when the active catalogue has nothing for someone who finished onboarding', function () {
    Exceptions::fake();
    $this->artisan('bureaucracy:compile-catalogue --deploy')->assertSuccessful();
    $actor = User::factory()->onboarded()->create();
    $case = app(EnsureAccountHolder::class)->dossier($actor);

    app(ReassessPerson::class)->execute($case->person_id);
    app(ReassessPerson::class)->execute($case->person_id);

    expect(BureaucracyEscalation::query()->sole()->only(['kind', 'subject', 'occurrences']))
        ->toBe(['kind' => 'empty_plan', 'subject' => 'de-nrw-cologne', 'occurrences' => 2])
        ->and(json_encode(BureaucracyEscalation::query()->sole()->getAttributes()))->not->toContain($actor->email);
    Exceptions::assertReportedCount(1);
});

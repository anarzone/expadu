<?php

use App\Alerts\AlertClassifier;
use App\Bureaucracy\Catalogue\CatalogueCompiler;
use App\Bureaucracy\Catalogue\CatalogueReleaseStore;
use App\Bureaucracy\Facts\RecordFactChange;
use App\Bureaucracy\People\EnsureAccountHolder;
use App\Bureaucracy\ReadModel\AccountHolderPlan;
use App\Bureaucracy\ReadModel\PlanAttention;
use App\Bureaucracy\Reminders\PlanReminderReference;
use App\ContextEngine\ContextNotificationFactory;
use App\ContextEngine\Listeners\RecordContextAlert;
use App\ContextEngine\Listeners\ScoredActionPushDispatcher;
use App\ContextEngine\ScoredAction;
use App\Events\Context\ScoredActionInserted;
use App\Listeners\CreateAlertFromNotification;
use App\Models\Alert;
use App\Models\Task;
use App\Models\User;
use App\Notifications\BureaucracyDeadlineNotification;
use App\Notifications\TransitDelayNotification;
use App\Notifications\TransitDisruptionNotification;
use Carbon\CarbonImmutable;
use Illuminate\Notifications\Events\NotificationSent;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Notification as NotificationFacade;

function contextAction(string $type, string $severity, array $channels, array $payload): ScoredAction
{
    return new ScoredAction(
        type: $type,
        actionKey: "{$type}:".uniqid(),
        score: 50.0,
        severity: $severity,
        validUntil: CarbonImmutable::now()->addHour(),
        deliverChannels: $channels,
        payload: $payload,
        createdAt: CarbonImmutable::now(),
    );
}

test('an alert_page action lands in the center tagged with lane / category / severity', function () {
    $user = User::factory()->create();
    $action = contextAction('transit_disruption', 'major', ['dashboard', 'alert_page'], ['lines' => ['7'], 'stops_affected' => []]);

    app(RecordContextAlert::class)->handle(new ScoredActionInserted($user, $action));

    $alert = Alert::where('user_id', $user->id)->first();
    expect($alert)->not->toBeNull()
        ->and($alert->subtype)->toBe('transit_disruption')
        ->and($alert->category)->toBe('transit')
        ->and($alert->lane)->toBe('action')
        ->and($alert->severity)->toBe('warn')
        ->and($alert->title)->toContain('7');
});

test('it records a canonical sealed reminder even when push was never granted', function () {
    $this->travelTo('2026-09-08 10:00:00');
    NotificationFacade::fake();
    config(['context_engine.push_via_bus' => true]);
    $user = User::factory()->onboarded()->create(['city' => 'Köln', 'situation' => null]);
    $case = app(EnsureAccountHolder::class)->dossier($user);
    app(RecordFactChange::class)->execute($user, $case->person, 'arrival_date', now()->subDays(10)->toDateString(), null, 1);

    // Synthetic timing and review metadata exercise reminder mechanics, not legal content.
    $task = Task::factory()->approvedFixture()->create([
        'key' => 'fixture.alert.reminder', 'title' => 'Synthetic reviewed alert deadline',
        'type' => 'task', 'applies_if' => [], 'depends_on' => [],
        'deadline_type' => 'days_since_arrival', 'deadline_days' => 5,
        'documents_required' => [], 'how_to_steps' => [], 'links' => [],
    ])->fresh();
    $mapping = [$task->key => [
        'process_id' => 'fixture.alert.reminder', 'topic' => 'address', 'kind' => 'preparation', 'coverage' => 'partial',
        'temporal_policy' => [
            'kind' => 'legal_due', 'version' => 'synthetic-test.1',
            'content_version' => $task->content_version, 'reviewed_by' => $task->reviewed_by,
            'verified_at' => $task->verified_at->toDateString(),
            'source_url' => collect($task->legal_sources)->firstWhere('kind', 'primary')['url'],
        ],
    ]];
    $store = app(CatalogueReleaseStore::class);
    $release = $store->stage(app(CatalogueCompiler::class)->compile([$task], $mapping));
    $store->activate($release->id, null);

    $attention = app(PlanAttention::class)->for(app(AccountHolderPlan::class)->for($user));
    expect($attention)->toHaveCount(1)
        ->and($attention[0]['kind'])->toBe('legal_due')
        ->and($attention[0]['urgency'])->toBe('overdue')
        ->and($attention[0]['date'])->toBe(now()->subDays(5)->toDateString());
    $references = app(PlanReminderReference::class);
    $reference = $references->for($user, $attention[0]);
    // No 'push' channel — exactly the case that used to vanish from the center.
    $action = contextAction('bureaucracy_task', 'critical', ['dashboard', 'alert_page'], $reference);

    expect($user->pushSubscriptions()->count())->toBe(0)
        ->and($action->deliverChannels)->not->toContain('push')
        ->and($references->resolve($reference, $user->id)['event_revision'])->toBe($attention[0]['event_revision']);
    $event = new ScoredActionInserted($user, $action);
    app(RecordContextAlert::class)->handle($event);
    app(ScoredActionPushDispatcher::class)->handle($event);

    $alert = Alert::where('user_id', $user->id)->firstOrFail();
    expect(Alert::where('user_id', $user->id)->count())->toBe(1)
        ->and($alert->category)->toBe('bureau')
        ->and($alert->lane)->toBe('action')
        ->and($alert->severity)->toBe('danger')
        ->and($alert->deep_link)->toBe('/bureaucracy')
        ->and($alert->guidance_reference)->toEqual($reference)
        ->and($references->resolve($alert->guidance_reference, $user->id)['event_revision'])->toBe($attention[0]['event_revision'])
        ->and($alert->title)->toBe('Your paperwork needs attention')
        ->and(json_encode($alert->toArray()))->not->toContain($task->title, $attention[0]['date']);
    NotificationFacade::assertNothingSent();
});

test('a replayed plaintext bureaucracy action cannot create an alert or notification', function () {
    NotificationFacade::fake();
    config(['context_engine.push_via_bus' => true]);
    $user = User::factory()->create();
    $action = contextAction('bureaucracy_task', 'critical', ['dashboard', 'alert_page', 'push'], [
        'title' => 'Synthetic unreviewed legacy reminder', 'tier' => 'overdue', 'days_remaining' => -2,
        'deadline' => now()->subDays(2)->toDateString(), 'task_id' => 5,
    ]);

    expect(app(ContextNotificationFactory::class)->build($action))->toBeNull();
    foreach (range(1, 2) as $attempt) {
        $event = new ScoredActionInserted($user, $action);
        app(RecordContextAlert::class)->handle($event);
        app(ScoredActionPushDispatcher::class)->handle($event);

        expect(Alert::where('user_id', $user->id)->count())->toBe(0);
        NotificationFacade::assertNothingSent();
    }
});

test('a replayed duration-only permanent-residency action cannot create a good-news alert or notification', function () {
    NotificationFacade::fake();
    config(['context_engine.push_via_bus' => true]);
    $user = User::factory()->create();
    // Legacy payload shape without a reviewed eligibility assessment.
    $action = contextAction('permanent_residency_eligible', 'success', ['dashboard', 'alert_page', 'push'], [
        'months_held' => 48,
        'threshold_months' => 36,
        'track_note' => 'Synthetic unsupported duration-only claim.',
    ]);

    expect(app(ContextNotificationFactory::class)->build($action))->toBeNull();
    foreach (range(1, 2) as $attempt) {
        $event = new ScoredActionInserted($user, $action);
        app(RecordContextAlert::class)->handle($event);
        app(ScoredActionPushDispatcher::class)->handle($event);

        expect(Alert::where('user_id', $user->id)->count())->toBe(0);
        NotificationFacade::assertNothingSent();
    }
});

test('a dashboard-only action never reaches the center', function () {
    $user = User::factory()->create();
    $action = contextAction('market_closure', 'minor', ['dashboard'], ['market_id' => 'all', 'day' => '2026-07-04', 'reason' => 'Einheitstag']);

    app(RecordContextAlert::class)->handle(new ScoredActionInserted($user, $action));

    expect(Alert::where('user_id', $user->id)->count())->toBe(0);
});

test('re-inserting the same action within a day does not duplicate the alert', function () {
    $user = User::factory()->create();
    $action = contextAction('transit_disruption', 'major', ['dashboard', 'alert_page'], ['lines' => ['7']]);

    app(RecordContextAlert::class)->handle(new ScoredActionInserted($user, $action));
    app(RecordContextAlert::class)->handle(new ScoredActionInserted($user, $action));

    expect(Alert::where('user_id', $user->id)->count())->toBe(1);
});

test('weather changing through the day collapses onto ONE evolving card', function () {
    $user = User::factory()->create();
    // Three DISTINCT weather warnings (different ids) — the exact pile-up the
    // owner reported: a new card every time conditions shift.
    foreach (['rain-1', 'wind-2', 'heat-3'] as $id) {
        // Distinct action keys + payloads the way the real WeatherEvaluator does.
        $action = new ScoredAction(
            type: 'weather_alert', actionKey: "weather:{$id}:user:{$user->id}", score: 50.0,
            severity: 'moderate', validUntil: CarbonImmutable::now()->addHour(),
            deliverChannels: ['dashboard', 'alert_page'],
            payload: ['alert' => ['title' => "Weather update {$id}", 'description' => 'Conditions changing']],
            createdAt: CarbonImmutable::now(),
        );
        app(RecordContextAlert::class)->handle(new ScoredActionInserted($user, $action));
    }

    $weather = Alert::where('user_id', $user->id)->where('subtype', 'weather')->get();
    expect($weather)->toHaveCount(1);
    expect((int) $weather->first()->occurrence_count)->toBe(3);
});

test('different transit lines stay as separate cards', function () {
    $user = User::factory()->create();
    foreach (['1', '9'] as $line) {
        $action = new ScoredAction(
            type: 'transit_disruption', actionKey: "disruption:d{$line}:user:{$user->id}", score: 50.0,
            severity: 'major', validUntil: CarbonImmutable::now()->addHour(),
            deliverChannels: ['dashboard', 'alert_page'], payload: ['lines' => [$line]],
            createdAt: CarbonImmutable::now(),
        );
        app(RecordContextAlert::class)->handle(new ScoredActionInserted($user, $action));
    }

    expect(Alert::where('user_id', $user->id)->count())->toBe(2);
});

test('a coalesced card re-surfaces as unread only when the situation escalates', function () {
    $user = User::factory()->create();
    $key = "weather:storm:user:{$user->id}";
    $make = fn (string $sev) => new ScoredAction(
        type: 'weather_alert', actionKey: $key, score: 50.0, severity: $sev,
        validUntil: CarbonImmutable::now()->addHour(), deliverChannels: ['dashboard', 'alert_page'],
        payload: ['alert' => ['title' => 'Storm warning', 'description' => 'Heavy storm']],
        createdAt: CarbonImmutable::now(),
    );

    app(RecordContextAlert::class)->handle(new ScoredActionInserted($user, $make('moderate')));
    Alert::where('user_id', $user->id)->update(['read_at' => now()]); // user reads it

    // A routine refresh at the same severity must NOT re-nag.
    app(RecordContextAlert::class)->handle(new ScoredActionInserted($user, $make('moderate')));
    expect(Alert::where('user_id', $user->id)->first()->read_at)->not->toBeNull();

    // Escalation to critical re-surfaces it as unread.
    app(RecordContextAlert::class)->handle(new ScoredActionInserted($user, $make('critical')));
    $alert = Alert::where('user_id', $user->id)->first();
    expect($alert->read_at)->toBeNull();
    expect($alert->severity)->toBe('danger');
});

test('the notification path skips ContextEngine classes so the center is not double-written', function () {
    $user = User::factory()->create();
    $n = new BureaucracyDeadlineNotification('Register your address', 'overdue', -2, '2026-06-20', 5);

    app(CreateAlertFromNotification::class)->handle(new NotificationSent($user, $n, 'database'));

    expect(Alert::where('user_id', $user->id)->count())->toBe(0);
});

test('the notification path still records + tags a direct (non-context) notification', function () {
    $user = User::factory()->create();
    $n = new class extends Notification
    {
        /** @return array<string, string> */
        public function toArray(mixed $notifiable): array
        {
            return ['title' => 'Tomorrow: Jazz Night', 'body' => 'Starts at 19:00.', 'url' => '/events'];
        }
    };

    app(CreateAlertFromNotification::class)->handle(new NotificationSent($user, $n, 'database'));

    $alert = Alert::where('user_id', $user->id)->firstOrFail();
    expect($alert->severity)->toBe('info')
        ->and($alert->category)->not->toBeNull()
        ->and($alert->lane)->toBe('posted');
});

test('a transit disruption alert deep-links to the live board, not the removed /transit page', function () {
    // The reported bug: /transit was deleted in the v2 pivot, so tapping this
    // alert did an Inertia visit that 404'd — surfacing a misleading "ad blocker"
    // toast. The deep link must resolve to the live departures board.
    $user = User::factory()->create();
    $action = contextAction('transit_disruption', 'major', ['dashboard', 'alert_page'], ['lines' => ['7'], 'stops_affected' => []]);

    app(RecordContextAlert::class)->handle(new ScoredActionInserted($user, $action));

    expect(Alert::where('user_id', $user->id)->firstOrFail()->deep_link)->toBe('/timetable');
});

test('transit notifications point their url at the live /timetable route', function () {
    $user = User::factory()->create();
    $disruption = new TransitDisruptionNotification(['line' => '7', 'summary' => 'Signal fault']);
    $delay = new TransitDelayNotification('7', 12, 'Neumarkt');

    expect($disruption->toArray($user)['url'])->toBe('/timetable')
        ->and($delay->toArray($user)['url'])->toBe('/timetable');
});

test('the classifier maps subtypes to the v4 taxonomy', function () {
    expect(AlertClassifier::category('event_reminder'))->toBe('events')
        ->and(AlertClassifier::lane('event_reminder', 'info'))->toBe('posted')
        ->and(AlertClassifier::lane('bureaucracy_deadline', 'success'))->toBe('good')
        ->and(AlertClassifier::category('weather'))->toBe('city')
        ->and(AlertClassifier::severity('critical'))->toBe('danger')
        ->and(AlertClassifier::actionLabel('transit_disruption'))->toBe('See alternatives')
        ->and(AlertClassifier::actionLabel('weather'))->toBeNull()
        // The good-news producer's subtype routes to the bureau/good lane.
        ->and(AlertClassifier::category('permanent_residency'))->toBe('bureau')
        ->and(AlertClassifier::lane('permanent_residency', 'success'))->toBe('good')
        ->and(AlertClassifier::severity('success'))->toBe('success')
        ->and(AlertClassifier::source('permanent_residency'))->toBe('Permit tracker')
        ->and(AlertClassifier::actionLabel('permanent_residency'))->toBe('See requirements')
        ->and(AlertClassifier::subtypeForActionType('permanent_residency_eligible'))->toBe('permanent_residency');
});

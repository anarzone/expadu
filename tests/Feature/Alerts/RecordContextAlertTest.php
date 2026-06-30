<?php

use App\Alerts\AlertClassifier;
use App\ContextEngine\Listeners\RecordContextAlert;
use App\ContextEngine\ScoredAction;
use App\Events\Context\ScoredActionInserted;
use App\Listeners\CreateAlertFromNotification;
use App\Models\Alert;
use App\Models\User;
use App\Notifications\BureaucracyDeadlineNotification;
use Carbon\CarbonImmutable;
use Illuminate\Notifications\Events\NotificationSent;
use Illuminate\Notifications\Notification;

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

test('it records the alert even when push was never granted (the old blind spot)', function () {
    $user = User::factory()->create();
    // No 'push' channel — exactly the case that used to vanish from the center.
    $action = contextAction('bureaucracy_task', 'critical', ['dashboard', 'alert_page'], [
        'title' => 'Register your address', 'tier' => 'overdue', 'days_remaining' => -2,
        'deadline' => '2026-06-20', 'task_id' => 5,
    ]);

    app(RecordContextAlert::class)->handle(new ScoredActionInserted($user, $action));

    $alert = Alert::where('user_id', $user->id)->firstOrFail();
    expect($alert->category)->toBe('bureau')
        ->and($alert->lane)->toBe('action')
        ->and($alert->severity)->toBe('danger')
        ->and($alert->deep_link)->toBe('/bureaucracy?focus=5');
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

test('the classifier maps subtypes to the v4 taxonomy', function () {
    expect(AlertClassifier::category('event_reminder'))->toBe('events')
        ->and(AlertClassifier::lane('event_reminder', 'info'))->toBe('posted')
        ->and(AlertClassifier::lane('bureaucracy_deadline', 'success'))->toBe('good')
        ->and(AlertClassifier::category('weather'))->toBe('city')
        ->and(AlertClassifier::severity('critical'))->toBe('danger')
        ->and(AlertClassifier::actionLabel('transit_disruption'))->toBe('See alternatives')
        ->and(AlertClassifier::actionLabel('weather'))->toBeNull();
});

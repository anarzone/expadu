<?php

use App\Bureaucracy\Verification\Escalations;
use App\Filament\Pages\SignupsSecurity;
use App\Filament\Resources\SecurityEvents\Pages\ListSecurityEvents;
use App\Filament\Resources\SecurityEvents\Tables\SecurityEventsTable;
use App\Filament\Widgets\Security\AlertBanner;
use App\Filament\Widgets\Security\NewestSignups;
use App\Filament\Widgets\Security\SecurityOverview;
use App\Filament\Widgets\Security\TurnedAwayAndAlerts;
use App\Models\SecurityEvent;
use App\Models\User;
use App\Models\WaitlistSignup;
use App\Security\SecurityStats;
use Filament\Facades\Filament;
use Livewire\Livewire;
use PragmaRX\Google2FA\Google2FA;

beforeEach(function () {
    Filament::setCurrentPanel('admin');
    $this->admin = User::factory()->create(['is_admin' => true]);
    // The panel opens only for admins with two-step sign-in (AdminTwoFactorTest).
    $this->admin->saveAppAuthenticationSecret(app(Google2FA::class)->generateSecretKey());
});

function securityEvent(string $type, array $more = []): SecurityEvent
{
    $id = SecurityEvent::query()->insertGetId(['type' => $type, 'channel' => 'web', 'created_at' => now()->subMinutes(10), ...$more]);

    return SecurityEvent::query()->findOrFail($id);
}

test('🔒 only admins can open Sign-ups & security and the log', function () {
    $person = User::factory()->create(['is_admin' => false]);

    $this->actingAs($person, 'admin')->get(SignupsSecurity::getUrl())->assertForbidden();
    $this->actingAs($person, 'admin')->get(ListSecurityEvents::getUrl())->assertForbidden();

    $this->actingAs($this->admin, 'admin')->get(SignupsSecurity::getUrl())->assertOk()->assertSee('Sign-ups &amp; security', false);
    $this->actingAs($this->admin, 'admin')->get(ListSecurityEvents::getUrl())->assertOk();
});

test('🔒 the numbers follow the chosen period', function () {
    $this->actingAs($this->admin, 'admin');
    securityEvent('blocked', ['reason' => 'trap']);
    securityEvent('blocked', ['reason' => 'trap', 'created_at' => now()->subDays(20)]);
    securityEvent('login_failed');

    Livewire::test(SecurityOverview::class, ['pageFilters' => ['period' => '7d']])
        ->assertSee('Turned away')->assertSee('Hidden field filled in 1')->assertSee('Failed sign-ins');
    Livewire::test(SecurityOverview::class, ['pageFilters' => ['period' => '30d']])
        ->assertSee('Hidden field filled in 2');
});

test('🔒 the newest sign-ups flag a scanner’s confirmation, one IP signing up again and again, and an unverified account', function () {
    $this->actingAs($this->admin, 'admin');
    WaitlistSignup::factory()->create(['email' => 'scanned@example.com', 'created_at' => now()->subHour(), 'confirmed_at' => now()->subHour()->addSeconds(2)]);
    foreach (['a', 'b', 'c'] as $who) {
        $user = User::factory()->create(['email' => "{$who}@example.org"]);
        securityEvent('signup', ['user_id' => $user->id, 'ip' => '203.0.113.7']);
    }
    User::factory()->unverified()->create(['email' => 'late@example.org', 'created_at' => now()->subDays(3)]);

    Livewire::test(NewestSignups::class)
        ->assertSee('scanned@example.com')->assertSee('Scanner?')
        ->assertSee('Same IP ×3')
        ->assertSee('late@example.org')->assertSee('Unverified 3d');
});

test('🔒 why posts were turned away, and the latest alerts with their local time', function () {
    $this->actingAs($this->admin, 'admin');
    securityEvent('blocked', ['reason' => 'disposable', 'email_domain' => 'mailinator.com']);
    app(Escalations::class)->raise('security_signups', now('UTC')->toDateString(), 'high', '35 sign-ups in the last hour (the alert mark is 20).');

    Livewire::test(TurnedAwayAndAlerts::class, ['pageFilters' => ['period' => 'today']])
        ->assertSee('Throwaway inbox')->assertSee('mailinator.com')
        ->assertSee('35 sign-ups in the last hour')->assertSee(now()->format('H:i'))->assertSee('emailed');

    expect(AlertBanner::canView())->toBeTrue();
    Livewire::test(AlertBanner::class)->assertSee('35 sign-ups in the last hour')->assertSee(now()->format('H:i').' today');
});

test('🔒 the log’s tabs show only their kind, and search finds an IP', function () {
    $this->actingAs($this->admin, 'admin');
    $blocked = securityEvent('blocked', ['reason' => 'trap', 'ip' => '185.220.101.4']);
    $login = securityEvent('login', ['ip' => '93.184.10.22']);

    Livewire::test(ListSecurityEvents::class)
        ->assertCanSeeTableRecords([$blocked, $login])
        ->set('activeTab', 'blocked')->assertCanSeeTableRecords([$blocked])->assertCanNotSeeTableRecords([$login])
        ->set('activeTab', 'all')->searchTable('93.184')->assertCanSeeTableRecords([$login])->assertCanNotSeeTableRecords([$blocked]);
});

test('🔒 an IP that only sent turned-away posts reads as scripted; an ordinary one does not', function () {
    foreach (range(1, 12) as $i) {
        securityEvent('blocked', ['reason' => 'trap', 'ip' => '185.220.101.4', 'user_agent' => 'Mozilla/5.0 (X11; Linux x86_64) Chrome/124']);
    }
    $user = User::factory()->create(['name' => 'Lena']);
    securityEvent('signup', ['ip' => '203.0.113.7', 'user_id' => $user->id]);

    $bot = app(SecurityStats::class)->ipProfile('185.220.101.4');
    $person = app(SecurityStats::class)->ipProfile('203.0.113.7');

    expect($bot['scripted'])->toBeTrue()->and($bot['verdict'])->toContain('12 posts')->and($bot['accounts'])->toBe([])
        ->and($person['scripted'])->toBeFalse()->and($person['accounts'][0]['name'])->toBe('Lena');

    $html = view('filament.security.ip-profile', ['profile' => $bot, 'types' => SecurityEventsTable::Types])->render();
    expect($html)->toContain('Turned away')->toContain('None: nothing it sent got through.');
});

test('the main dashboard keeps its own widgets', function () {
    expect(collect(Filament::getWidgets())->filter(fn (string $w) => str_contains($w, '\\Security\\')))->toBeEmpty();
});

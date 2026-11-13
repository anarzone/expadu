<?php

use App\Admin\PeopleActions;
use App\Admin\ReviewQueue;
use App\Filament\Clusters\People\Pages\NeedsALook;
use App\Filament\Resources\Users\Pages\ListUsers;
use App\Filament\Resources\WaitlistSignups\Pages\ListWaitlistSignups;
use App\Http\Middleware\EnsureAccountIsActive;
use App\Models\SecurityEvent;
use App\Models\User;
use App\Models\WaitlistSignup;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Livewire\Livewire;

beforeEach(function () {
    Filament::setCurrentPanel('admin');
    $this->admin = User::factory()->create(['is_admin' => true]);
    $this->actingAs($this->admin, 'admin');
});

test('🔒 a suspended account is signed out of the website on its next request, and told why', function () {
    $person = User::factory()->create();
    $this->actingAs($person, 'web')->get('/settings/account')->assertOk();

    app(PeopleActions::class)->suspend($person);

    $this->get('/settings/account')->assertRedirect(route('login'))->assertSessionHas('error', EnsureAccountIsActive::Message);
    $this->assertGuest('web');
});

test('🔒 a suspended account loses its app tokens and can’t get new ones; lifting it lets it back in', function () {
    $person = User::factory()->create(['email' => 'sam@example.org']);
    $token = $person->createToken('iPhone')->plainTextToken;

    app(PeopleActions::class)->suspend($person);

    $this->withToken($token)->getJson('/api/v1/me')->assertUnauthorized();
    $this->postJson('/api/v1/auth/token', ['email' => 'sam@example.org', 'password' => 'password', 'device_name' => 'iPhone'])
        ->assertUnprocessable()->assertJsonFragment(['email' => [EnsureAccountIsActive::Message]]);

    app(PeopleActions::class)->unsuspend($person->fresh());

    $this->postJson('/api/v1/auth/token', ['email' => 'sam@example.org', 'password' => 'password', 'device_name' => 'iPhone'])->assertOk();
});

test('🔒 admins can’t be suspended or deleted from the People pages', function () {
    $other = User::factory()->create(['is_admin' => true]);

    expect(app(PeopleActions::class)->suspend($other))->toBeFalse()
        ->and(app(PeopleActions::class)->deleteAccount($other))->toBeFalse()
        ->and($other->fresh()->isSuspended())->toBeFalse();
});

test('🔒 Needs a look lists what the checks flagged; Keep takes it off, Undo puts it back', function () {
    $scanned = WaitlistSignup::factory()->create(['email' => 'scanned@example.com', 'created_at' => now()->subHour(), 'confirmed_at' => now()->subHour()->addSeconds(2)]);
    WaitlistSignup::factory()->confirmed()->create(['email' => 'real@example.com', 'created_at' => now()->subDay(), 'confirmed_at' => now()->subHours(20)]);
    $unverified = User::factory()->unverified()->create(['email' => 'late@example.org', 'created_at' => now()->subDays(3)]);

    $keys = collect(app(ReviewQueue::class)->items())->pluck('key')->all();
    expect($keys)->toContain("waitlist:{$scanned->id}", "account:{$unverified->id}")->not->toContain('account:'.$this->admin->id)
        ->and(collect(app(ReviewQueue::class)->items())->pluck('title')->implode(' '))->not->toContain('real@example.com');

    Livewire::test(NeedsALook::class)
        ->assertSee('scanned@example.com')->assertSee('Scanner?')->assertSee('Unverified 3d')
        ->callAction('keep', arguments: ['key' => "waitlist:{$scanned->id}"])
        ->assertDontSee('scanned@example.com')
        ->dispatch('undoKeep', key: "waitlist:{$scanned->id}")
        ->assertSee('scanned@example.com');
});

test('🔒 deleting from the queue goes the whole way and is in the log, without the admin’s IP', function () {
    $person = User::factory()->unverified()->create(['created_at' => now()->subDays(3)]);
    $person->createToken('iPhone');

    Livewire::test(NeedsALook::class)->callAction('delete', arguments: ['key' => "account:{$person->id}"]);

    expect(User::query()->find($person->id))->toBeNull()
        ->and(DB::table('personal_access_tokens')->where('tokenable_id', $person->id)->count())->toBe(0);
    $entry = SecurityEvent::query()->where('type', 'admin_action')->where('reason', 'delete_account')->firstOrFail();
    expect($entry->ip)->toBeNull()->and($entry->channel)->toBe('admin');
});

test('🔒 Users: flags show, and the bulk delete skips admins', function () {
    $suspended = User::factory()->create(['name' => 'Sam Taylor', 'suspended_at' => now()]);
    $person = User::factory()->create(['name' => 'Lena']);

    Livewire::test(ListUsers::class)
        ->assertCanSeeTableRecords([$suspended, $person])
        ->assertSee('Suspended')->assertSee('Admin')
        ->callTableBulkAction('deleteAccounts', [$person, $this->admin]);

    // The sheet's own Suspend, as the admin presses it there.
    $other = User::factory()->create(['name' => 'Kai']);
    Livewire::test(ListUsers::class)
        ->callAction([TestAction::make('sheet')->table($other), TestAction::make('suspend')]);
    expect($other->fresh()->isSuspended())->toBeTrue();

    expect(User::query()->find($person->id))->toBeNull()
        ->and(User::query()->find($this->admin->id))->not->toBeNull();

    $sheet = view('filament.people.person-sheet', ['user' => $suspended, 'from' => 'Website', 'history' => collect(), 'composer' => 0])->render();
    expect($sheet)->toContain('Suspended')->toContain('Two-step sign-in')->toContain('Nothing in the security log');
});

test('🔒 Waitlist: the tabs split waiting, scanner-confirmed and confirmed; removing asks and logs', function () {
    $waiting = WaitlistSignup::factory()->create(['created_at' => now()->subDay()]);
    $scanned = WaitlistSignup::factory()->create(['created_at' => now()->subHour(), 'confirmed_at' => now()->subHour()->addSeconds(3)]);
    $confirmed = WaitlistSignup::factory()->create(['email' => 'anna@example.com', 'created_at' => now()->subDays(2), 'confirmed_at' => now()->subDay()]);

    Livewire::test(ListWaitlistSignups::class)
        ->assertCanSeeTableRecords([$waiting])->assertCanNotSeeTableRecords([$scanned, $confirmed])
        ->set('activeTab', 'flagged')->assertCanSeeTableRecords([$scanned])->assertCanNotSeeTableRecords([$waiting, $confirmed])
        ->set('activeTab', 'confirmed')->assertCanSeeTableRecords([$scanned, $confirmed])
        ->callTableAction('remove', $confirmed);

    expect(WaitlistSignup::query()->find($confirmed->id))->toBeNull()
        ->and(SecurityEvent::query()->where('reason', 'remove_waitlist')->value('email_domain'))->toBe('example.com');
});

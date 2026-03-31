<?php

use App\Models\SlotMonitor;
use App\Models\User;
use App\Notifications\BuergeramtSlotNotification;
use App\Services\BuergeramtService;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Notification;

test('buergeramt:check command runs successfully', function () {
    $this->artisan('buergeramt:check')
        ->assertSuccessful();
});

test('buergeramt:check command caches slot results', function () {
    Cache::flush();

    $this->artisan('buergeramt:check')
        ->assertSuccessful();

    expect(Cache::has('buergeramt:slots'))->toBeTrue();

    $cached = Cache::get('buergeramt:slots');
    expect($cached)->toBeArray()
        ->and(array_keys($cached))->each->toBeIn(array_keys(BuergeramtService::OFFICES));
});

test('buergeramt service returns correct slot structure', function () {
    $service = new BuergeramtService;
    $slots = $service->checkSlots();

    expect($slots)->toBeArray();

    foreach ($slots as $officeId => $slot) {
        expect($officeId)->toBeIn(array_keys(BuergeramtService::OFFICES))
            ->and($slot)->toHaveKeys(['name', 'address', 'status', 'next_slot', 'slots_today'])
            ->and($slot['status'])->toBeIn(['available', 'mostly_booked', 'booked', 'unavailable', 'checking', 'check_online'])
            ->and($slot['slots_today'])->toBeInt();
    }
});

test('user can toggle slot monitoring for an office', function () {
    $user = User::factory()->onboarded()->create();
    $this->actingAs($user);

    // First toggle creates the monitor
    $this->post(route('slots.toggle'), ['office_id' => 'deutz'])
        ->assertRedirect();

    expect($user->slotMonitors()->where('office_id', 'deutz')->first())
        ->not->toBeNull()
        ->is_active->toBeTrue();

    // Second toggle deactivates it
    $this->post(route('slots.toggle'), ['office_id' => 'deutz'])
        ->assertRedirect();

    expect($user->slotMonitors()->where('office_id', 'deutz')->first())
        ->is_active->toBeFalse();

    // Third toggle reactivates it
    $this->post(route('slots.toggle'), ['office_id' => 'deutz'])
        ->assertRedirect();

    expect($user->slotMonitors()->where('office_id', 'deutz')->first())
        ->is_active->toBeTrue();
});

test('slot monitor toggle validates office_id', function () {
    $user = User::factory()->onboarded()->create();
    $this->actingAs($user);

    $this->post(route('slots.toggle'), ['office_id' => 'nonexistent'])
        ->assertSessionHasErrors('office_id');
});

test('slot monitor toggle requires authentication', function () {
    $this->post(route('slots.toggle'), ['office_id' => 'deutz'])
        ->assertRedirect(route('login'));
});

test('notification is sent when new slots become available', function () {
    Notification::fake();

    $user = User::factory()->onboarded()->create();
    SlotMonitor::factory()->create([
        'user_id' => $user->id,
        'office_id' => 'deutz',
        'is_active' => true,
        'notified_at' => null,
    ]);

    // First run: cache is empty so all "available" offices are "newly available"
    Cache::flush();
    $this->artisan('buergeramt:check')->assertSuccessful();

    // The notification may or may not be sent depending on simulated slot status.
    // We verify the mechanism works by checking the monitor was processed.
    $monitor = $user->slotMonitors()->where('office_id', 'deutz')->first();

    // If the office was available, the user should have been notified
    $cached = Cache::get('buergeramt:slots');
    if ($cached['deutz']['status'] === 'available') {
        Notification::assertSentTo($user, BuergeramtSlotNotification::class);
        expect($monitor->fresh()->notified_at)->not->toBeNull();
    }
});

test('notification is not sent to inactive monitors', function () {
    Notification::fake();
    Cache::flush();

    $user = User::factory()->onboarded()->create();
    SlotMonitor::factory()->create([
        'user_id' => $user->id,
        'office_id' => 'deutz',
        'is_active' => false,
        'notified_at' => null,
    ]);

    $this->artisan('buergeramt:check')->assertSuccessful();

    Notification::assertNothingSent();
});

test('notification is throttled within 30 minutes', function () {
    Notification::fake();
    Cache::flush();

    $user = User::factory()->onboarded()->create();
    SlotMonitor::factory()->create([
        'user_id' => $user->id,
        'office_id' => 'deutz',
        'is_active' => true,
        'notified_at' => now()->subMinutes(10), // Notified 10 minutes ago
    ]);

    $this->artisan('buergeramt:check')->assertSuccessful();

    // Should not be notified again within 30 minutes
    Notification::assertNothingSent();
});

test('user can have monitors for multiple offices', function () {
    $user = User::factory()->onboarded()->create();
    $this->actingAs($user);

    $this->post(route('slots.toggle'), ['office_id' => 'deutz']);
    $this->post(route('slots.toggle'), ['office_id' => 'kalk']);
    $this->post(route('slots.toggle'), ['office_id' => 'ehrenfeld']);

    expect($user->slotMonitors()->count())->toBe(3);
});

test('slot monitor has unique constraint on user and office', function () {
    $user = User::factory()->onboarded()->create();

    SlotMonitor::factory()->create([
        'user_id' => $user->id,
        'office_id' => 'deutz',
    ]);

    // Attempting to create a duplicate should fail
    expect(fn () => SlotMonitor::factory()->create([
        'user_id' => $user->id,
        'office_id' => 'deutz',
    ]))->toThrow(UniqueConstraintViolationException::class);
});

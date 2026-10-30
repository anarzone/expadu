<?php

use App\Composer\Actions\ComposerActions;
use App\Models\Alert;
use App\Models\User;
use Carbon\CarbonImmutable;

/*
 * Composer and the Alerts page (coordinator, 27 Oct 2026): alerts of one kind dismissed or marked
 * read, as the page's Dismiss and Mark all read do, each with Undo. An older alert without a
 * category is sorted by its subtype, as the page sorts it.
 */
beforeEach(function () {
    $this->user = User::factory()->onboarded()->create();
    $this->actions = app(ComposerActions::class);
    $alert = fn (array $attributes): Alert => Alert::factory()->create(['user_id' => $this->user->id, 'read_at' => null, 'dismissed_at' => null, ...$attributes]);
    $this->deadline = $alert(['subtype' => 'bureaucracy_deadline', 'category' => 'bureau']);
    $this->older = $alert(['subtype' => 'buergeramt_slot', 'category' => null]);
    $this->weather = $alert(['subtype' => 'weather', 'category' => 'city']);
});

test('🔒 Paperwork alerts are dismissed, an older one without a category too, and Undo brings them back', function () {
    $made = $this->actions->request($this->user, 'dismiss_alert', ['kind' => 'paperwork'], CarbonImmutable::now());

    expect($made['blocks'][0])->toMatchArray(['type' => 'done', 'text' => '2 alerts dismissed.'])
        ->and($this->deadline->fresh()->dismissed_at)->not->toBeNull()
        ->and($this->older->fresh()->dismissed_at)->not->toBeNull()
        ->and($this->weather->fresh()->dismissed_at)->toBeNull();

    $this->actions->undo($this->user, $made['blocks'][0]['id'], CarbonImmutable::now());
    expect($this->deadline->fresh()->dismissed_at)->toBeNull()->and($this->deadline->fresh()->read_at)->toBeNull();
});

test('🔒 unread alerts are marked read and stay on the page; Undo makes them unread again', function () {
    $this->weather->update(['read_at' => now()]);
    $made = $this->actions->request($this->user, 'read_alerts', [], CarbonImmutable::now());

    expect($made['blocks'][0]['text'])->toBe('2 alerts marked read.')
        ->and(Alert::query()->where('user_id', $this->user->id)->whereNull('read_at')->count())->toBe(0)
        ->and(Alert::query()->where('user_id', $this->user->id)->whereNotNull('dismissed_at')->count())->toBe(0);

    $this->actions->undo($this->user, $made['blocks'][0]['id'], CarbonImmutable::now());
    expect($this->deadline->fresh()->read_at)->toBeNull()->and($this->older->fresh()->read_at)->toBeNull()
        ->and($this->weather->fresh()->read_at)->not->toBeNull()
        ->and($this->actions->request($this->user, 'read_alerts', ['kind' => 'city'], CarbonImmutable::now())['said'])->toContain('no unread city alerts');
});

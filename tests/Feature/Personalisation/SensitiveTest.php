<?php

use App\Models\Event;
use App\Models\Spot;
use App\Models\User;
use App\Personalisation\AffinityAggregator;
use App\Personalisation\Pseudonym;
use App\Personalisation\SignalAction;
use App\Personalisation\SignalItemType;
use App\Personalisation\SignalRecorder;
use App\Personalisation\SignalSurface;
use Illuminate\Support\Facades\DB;

/*
 * What personalisation never learns from (the consent page's "What it never uses"): health
 * services, places of worship, LGBTQ+ venues and sensitive events are never recorded, and
 * whatever was recorded before is dropped before the rebuild.
 */
beforeEach(function () {
    $this->user = User::factory()->onboarded()->create();
    $this->user->userSetting()->create(['settings' => ['personalisation' => true, 'personalisation_wording' => 2]]);
    $this->user = $this->user->fresh();
});

function sensitiveLedger(User $user): array
{
    return DB::table('user_signals')->where('subject_key', Pseudonym::for($user))->orderBy('id')->get(['item_type', 'item_id'])
        ->map(fn ($row) => "{$row->item_type}:{$row->item_id}")->all();
}

test('🔒 health services, places of worship, LGBTQ+ venues and sensitive events are never recorded', function () {
    $pharmacy = Spot::factory()->create(['category' => 'pharmacy']);
    $cathedral = Spot::factory()->create(['category' => 'attraction', 'tags' => ['amenity' => 'place_of_worship', 'religion' => 'christian']]);
    $bar = Spot::factory()->create(['category' => 'bar', 'tags' => ['lgbtq' => 'primary']]);
    $cafe = Spot::factory()->create(['category' => 'cafe', 'tags' => ['lgbtq' => 'no']]);
    $service = Event::factory()->create(['sensitive' => true]);
    $concert = Event::factory()->create();

    foreach ([$pharmacy, $cathedral, $bar, $cafe] as $spot) {
        app(SignalRecorder::class)->record($this->user, SignalAction::Saved, SignalSurface::Places, SignalItemType::Spot, $spot->id);
    }
    foreach ([$service, $concert] as $event) {
        app(SignalRecorder::class)->record($this->user, SignalAction::Saved, SignalSurface::Events, SignalItemType::Event, $event->id);
    }
    app(SignalRecorder::class)->topic($this->user, 'pharmacy', 'Ehrenfeld');

    expect(sensitiveLedger($this->user))->toBe(["spot:{$cafe->id}", "event:{$concert->id}"]);
});

test('🔒 what was recorded before is dropped before the rebuild', function () {
    $pharmacy = Spot::factory()->create(['category' => 'pharmacy']);
    $cafe = Spot::factory()->create(['category' => 'cafe']);
    $subject = Pseudonym::for($this->user);
    foreach ([[$pharmacy->id, 'pharmacy'], [$cafe->id, 'cafe']] as [$id, $category]) {
        DB::table('user_signals')->insert(['subject_key' => $subject, 'action' => 'saved', 'surface' => 'places', 'item_type' => 'spot', 'item_id' => $id,
            'category' => $category, 'context' => json_encode([]), 'created_at' => now()->toIso8601String()]);
    }

    app(AffinityAggregator::class)->rebuild();

    expect(sensitiveLedger($this->user))->toBe(["spot:{$cafe->id}"])
        ->and(DB::table('user_affinities')->where('subject_key', $subject)->where('key', 'pharmacy')->exists())->toBeFalse();
});

<?php

use App\Models\BureaucracyCase;
use App\Models\BureaucracyCaseMessage;
use App\Models\User;
use Illuminate\Support\Facades\Artisan;

test('expired raw messages are pruned while unexpired messages remain', function () {
    $this->freezeTime();
    $case = BureaucracyCase::factory()->for(User::factory()->onboarded())->create();
    $expired = BureaucracyCaseMessage::factory()->for($case, 'case')->create([
        'expires_at' => now()->subSecond(),
    ]);
    $unexpired = BureaucracyCaseMessage::factory()->for($case, 'case')->create([
        'expires_at' => now()->addSecond(),
    ]);

    Artisan::call('model:prune', [
        '--model' => [BureaucracyCaseMessage::class],
    ]);

    $this->assertModelMissing($expired);
    $this->assertModelExists($unexpired);
});

test('raw message pruning is scheduled daily', function () {
    Artisan::call('schedule:list', ['--json' => true]);
    $events = json_decode(Artisan::output(), true, 512, JSON_THROW_ON_ERROR);
    $event = collect($events)
        ->firstWhere('command', 'php artisan model:prune');

    expect($event)->not->toBeNull()
        ->and($event['expression'])->toBe('45 3 * * *');
});

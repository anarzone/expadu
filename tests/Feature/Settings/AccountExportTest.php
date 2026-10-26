<?php

use App\Bureaucracy\People\EnsureAccountHolder;
use App\Jobs\BuildAccountExport;
use App\Mail\ConfirmationCode;
use App\Models\AccountExport;
use App\Models\Spot;
use App\Models\SpotFeedback;
use App\Models\User;
use App\Models\UserPlace;
use App\Privacy\Export\AccountData;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;

/*
 * "Download your data": confirmed like deleting the account, a ZIP of every
 * area holding only the asker's data, ready for 24 hours, then gone.
 */

beforeEach(function () {
    Storage::fake('local');
    Bus::fake([BuildAccountExport::class]);
    Mail::fake();
});

/** Runs the queued build for the export, as the worker would. */
function buildExport(AccountExport $export): AccountExport
{
    (new BuildAccountExport($export))->handle(app(AccountData::class));

    return $export->fresh();
}

/** @return array<string, string> file name => contents */
function exportFiles(AccountExport $export): array
{
    $zip = new ZipArchive;
    $zip->open(Storage::disk('local')->path($export->path));
    $files = [];
    for ($i = 0; $i < $zip->numFiles; $i++) {
        $name = $zip->getNameIndex($i);
        $files[$name] = $zip->getFromName($name);
    }
    $zip->close();

    return $files;
}

/** Gives an account something in many areas, marked with $mark. */
function dataFor(User $user, string $mark): void
{
    DB::table('user_settings')->insert(['user_id' => $user->id, 'settings' => json_encode(['note' => $mark]), 'created_at' => now(), 'updated_at' => now()]);
    $spot = Spot::factory()->create(['name' => "Café {$mark}"]);
    SpotFeedback::factory()->create(['user_id' => $user->id, 'spot_id' => $spot->id]);
    UserPlace::factory()->create(['user_id' => $user->id, 'name' => "Home {$mark}"]);
    DB::table('favourite_stops')->insert(['user_id' => $user->id, 'name' => "Stop {$mark}", 'created_at' => now(), 'updated_at' => now()]);
    DB::table('composer_workspaces')->insert(['user_id' => $user->id, 'revision' => 1, 'state' => json_encode(['conversations' => [['title' => "Chat {$mark}"]], 'plans' => [], 'changes' => []]), 'created_at' => now(), 'updated_at' => now()]);
    $person = app(EnsureAccountHolder::class)->person($user);
    app(EnsureAccountHolder::class)->dossier($user);
    expect($person)->not->toBeNull();
}

test('🔒 asking for your data needs your password: none or a wrong one starts nothing', function () {
    $user = User::factory()->onboarded()->create();

    $this->actingAs($user)->postJson(route('account-export.store'))->assertUnprocessable()->assertJsonValidationErrors('password');
    $this->actingAs($user)->postJson(route('account-export.store'), ['password' => 'wrong'])->assertUnprocessable();

    expect(AccountExport::count())->toBe(0);
    Bus::assertNotDispatched(BuildAccountExport::class);
});

test('🔒 an account made with Google confirms with the emailed code', function () {
    $user = User::factory()->onboarded()->create(['password' => null, 'social_provider' => 'google', 'social_id' => 'google-export']);

    $this->actingAs($user)->postJson(route('account-export.store'), ['code' => '000000'])->assertUnprocessable()->assertJsonValidationErrors('code');
    expect(AccountExport::count())->toBe(0);

    $this->postJson(route('email-confirmation.send'))->assertStatus(202);
    $code = null;
    Mail::assertSent(ConfirmationCode::class, function (ConfirmationCode $mail) use (&$code) {
        $code = $mail->code;

        return true;
    });

    $this->postJson(route('account-export.store'), ['code' => $code])->assertStatus(202)->assertJsonPath('export.status', 'queued');
    Bus::assertDispatched(BuildAccountExport::class);
});

test('🔒 the file holds every area and only the asker’s data', function () {
    $user = User::factory()->onboarded()->create(['email' => 'asker@example.com']);
    $other = User::factory()->onboarded()->create(['email' => 'someone-else@example.com']);
    dataFor($user, 'MINE');
    dataFor($other, 'THEIRS');

    $this->actingAs($user)->postJson(route('account-export.store'), ['password' => 'password'])->assertStatus(202);
    $export = buildExport(AccountExport::sole());

    expect($export->status)->toBe('ready')
        ->and($export->expires_at->between(now()->addHours(23), now()->addHours(25)))->toBeTrue();
    $files = exportFiles($export);
    expect(array_keys($files))->toEqualCanonicalizing([
        'README.txt', 'account.json', 'settings.json', 'consents.json', 'paperwork.json', 'composer.json', 'places.json',
        'events.json', 'travel.json', 'alerts.json', 'activity.json', 'personalisation.json', 'location.json',
    ]);
    $all = implode("\n", $files);
    expect($all)->toContain('asker@example.com', 'MINE', 'Café MINE', 'Home MINE', 'Stop MINE', 'Chat MINE')
        ->not->toContain('someone-else@example.com', 'THEIRS')
        ->and($files['README.txt'])->toContain('places.json', 'paperwork.json');
    expect(json_decode($files['paperwork.json'], true)['data']['people'])->toHaveCount(1);
    // Secrets stay out.
    expect($files['account.json'])->not->toContain('password', 'remember_token', 'two_factor_secret');
});

test('🔒 one at a time: asking again while a file is being made gives the same one', function () {
    $user = User::factory()->onboarded()->create();

    $first = $this->actingAs($user)->postJson(route('account-export.store'), ['password' => 'password'])->json('export.id');
    $second = $this->postJson(route('account-export.store'), ['password' => 'password'])->json('export.id');

    expect($second)->toBe($first)->and(AccountExport::count())->toBe(1);
});

test('🔒 only the asker can download, and only for 24 hours; then the file is deleted', function () {
    $user = User::factory()->onboarded()->create();
    $other = User::factory()->onboarded()->create();
    $this->actingAs($user)->postJson(route('account-export.store'), ['password' => 'password']);
    $export = buildExport(AccountExport::sole());

    $this->actingAs($other)->get(route('account-export.download', $export))->assertNotFound();
    $this->actingAs($user)->get(route('account-export.download', $export))->assertOk()->assertHeader('content-type', 'application/zip');

    $this->travel(AccountExport::KEEP_HOURS + 1)->hours();
    $this->actingAs($user)->get(route('account-export.download', $export))->assertStatus(410);
    $this->actingAs($user)->getJson(route('account-export.show'))->assertJsonPath('export', null);

    $this->artisan('account-exports:prune')->assertSuccessful();
    expect(Storage::disk('local')->exists($export->path))->toBeFalse()
        ->and(AccountExport::count())->toBe(0);
});

test('🔒 guests can’t ask or download', function () {
    $this->postJson(route('account-export.store'), ['password' => 'password'])->assertUnauthorized();
    $this->getJson(route('account-export.show'))->assertUnauthorized();
});

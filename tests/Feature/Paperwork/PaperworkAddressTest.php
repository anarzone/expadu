<?php

use App\Composer\WorkspaceUpgrade;
use App\Models\Alert;
use App\Models\ComposerWorkspace;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\Finder\Finder;

test('Paperwork and its API live at /paperwork, and nothing answers at /bureaucracy', function () {
    $this->actingAs(User::factory()->onboarded()->create());

    $this->get('/paperwork')->assertOk();
    expect(route('paperwork', ['view' => 'detail', 'process' => 'blue_card.first'], false))->toBe('/paperwork?view=detail&process=blue_card.first')
        ->and(route('paperwork.v2.plan', [], false))->toBe('/paperwork/v2/plan');
    $this->getJson('/paperwork/v2/plan')->assertSuccessful();

    $this->get('/bureaucracy')->assertNotFound();
    $this->get('/bureaucracy?view=detail&process=x')->assertNotFound();
    $this->getJson('/bureaucracy/v2/plan')->assertNotFound();
});

test('links the app stored point to /paperwork; page-view history keeps the address it had', function () {
    $user = User::factory()->onboarded()->create();
    $alert = Alert::factory()->create(['user_id' => $user->id, 'deep_link' => '/bureaucracy?view=detail&process=blue_card.first']);
    $other = Alert::factory()->create(['user_id' => $user->id, 'deep_link' => '/timetable?view=route']);
    $at = now()->toIso8601String();
    ComposerWorkspace::query()->create(['user_id' => $user->id, 'revision' => 1, 'state' => [
        'schema' => WorkspaceUpgrade::SCHEMA, 'active' => 'c', 'focus' => null, 'plans' => [], 'changes' => [], 'actions' => [],
        'conversations' => [['id' => 'c', 'title' => 'Visa', 'created_at' => $at, 'messages' => [
            ['id' => 'm', 'role' => 'assistant', 'created_at' => $at, 'text' => 'Paperwork (once at /bureaucracy) has it.', 'blocks' => [
                ['type' => 'record', 'rows' => [], 'link' => ['label' => 'Open Paperwork →', 'href' => '/bureaucracy']],
                ['type' => 'facts', 'facts' => [], 'sources' => [['label' => 'Paperwork', 'href' => '/bureaucracy?view=detail&process=blue_card.first']]],
            ]],
        ]]],
    ]]);
    $reminders = collect([json_encode(['url' => '/bureaucracy?view=detail&process=x', 'title' => 'Your paperwork needs attention']), '{"url":"/bureaucracy"}'])
        ->map(fn (string $data): string => tap((string) Str::uuid(), fn (string $id) => DB::table('notifications')->insert([
            'id' => $id, 'type' => 'App\\Notifications\\BureaucracyPlanNotification', 'notifiable_type' => User::class, 'notifiable_id' => $user->id,
            'data' => $data, 'created_at' => now(), 'updated_at' => now(),
        ])));
    DB::table('user_events')->insert(['user_id' => $user->id, 'event_type' => 'page_view', 'payload' => json_encode(['page' => '/bureaucracy']), 'created_at' => now()]);

    (require database_path('migrations/2026_10_27_120000_rewrite_paperwork_links.php'))->up();

    $blocks = ComposerWorkspace::query()->where('user_id', $user->id)->sole()->state['conversations'][0]['messages'][0];
    expect($alert->fresh()->deep_link)->toBe('/paperwork?view=detail&process=blue_card.first')
        ->and($other->fresh()->deep_link)->toBe('/timetable?view=route')
        ->and(DB::table('notifications')->where('id', $reminders[0])->value('data'))->toBe(json_encode(['url' => '/paperwork?view=detail&process=x', 'title' => 'Your paperwork needs attention']))
        ->and(DB::table('notifications')->where('id', $reminders[1])->value('data'))->toBe('{"url":"/paperwork"}')
        ->and($blocks['blocks'][0]['link']['href'])->toBe('/paperwork')
        ->and($blocks['blocks'][1]['sources'][0]['href'])->toBe('/paperwork?view=detail&process=blue_card.first')
        // Words aren't links: only a stored address changes.
        ->and($blocks['text'])->toBe('Paperwork (once at /bureaucracy) has it.')
        ->and(json_decode(DB::table('user_events')->where('user_id', $user->id)->value('payload'), true)['page'])->toBe('/bureaucracy');
});

test('nothing in the app links to the old /bureaucracy addresses', function () {
    $files = Finder::create()->files()->in([app_path(), base_path('routes'), base_path('bootstrap'), config_path(), resource_path('js'), resource_path('views')])
        ->exclude(['actions', 'routes', 'wayfinder'])->name(['*.php', '*.ts', '*.tsx'])->notPath('#^(actions|routes|wayfinder)/#');
    $found = [];
    foreach ($files as $file) {
        foreach (preg_split('/\R/', $file->getContents()) as $number => $line) {
            if (preg_match('#[\'"`(]/bureaucracy(?=[/?\'"`\#]|$)|bureaucracy/v2|bureaucracy\.v2\.|route\([\'"]bureaucracy[\'"]#', $line) === 1) {
                $found[] = $file->getRelativePathname().':'.($number + 1);
            }
        }
    }

    expect($found)->toBe([]);
});

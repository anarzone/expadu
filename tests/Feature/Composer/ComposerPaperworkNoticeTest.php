<?php

use App\Composer\Actions\ComposerActions;
use App\Composer\Assistant\Assistant;
use App\Composer\Assistant\AssistantTools;
use App\Composer\WorkspaceStore;
use App\Models\BureaucracyProcessingConsent;
use App\Models\Spot;
use App\Models\User;
use App\Privacy\ProcessingConsentStore;
use App\Privacy\ProcessingPurpose;
use App\Services\WeatherService;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\Support\ExternalProcessingFixtures;
use Tests\Support\ReviewedHomePlan;

/*
 * Composer with the person's Paperwork (owner, 23 Oct 2026: "make sure its accurate"): the
 * request Composer really sends, part by part, against its own notice. A part the notice
 * doesn't name fails, so the notice can't drift from the code.
 */
beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-09-08 10:00', 'Europe/Berlin'));
    config()->set('services.llm', [
        'driver' => 'anthropic', 'base_url' => 'https://processor.example.test', 'model' => 'claude-haiku-5-5', 'key' => 'synthetic',
        'processor_name' => 'Anthropic PBC', 'processor_privacy_url' => 'https://processor.example.test/privacy', 'prompt_version' => 'test.1',
    ]);
    app()->instance(WeatherService::class, new class extends WeatherService
    {
        public function __construct() {}

        public function getCurrentWeather(float $lat = 50.9375, float $lng = 6.9603): array
        {
            return ['available' => false];
        }
    });
    $this->user = User::factory()->onboarded()->create(['veedel' => 'Ehrenfeld']);
    ReviewedHomePlan::activate($this->user, ['fixture.entry' => ['title' => 'Synthetic preparation']],
        ['arrival_date' => '2026-09-01', 'visa_expires_at' => '2026-10-01']);
    // Home named by the address they typed: an address that must never be sent.
    $this->user->places()->create(['emoji' => '🏠', 'name' => 'Venloer Straße 210', 'address' => 'Venloer Straße 210', 'category' => 'home',
        'lat' => 50.94721, 'lng' => 6.91573, 'sort_order' => 0]);
});

/** A Messages reply holding these tool calls. */
function noticeTurn(array $uses): array
{
    return ['id' => 'msg_1', 'type' => 'message', 'role' => 'assistant', 'model' => 'claude-haiku-5-5', 'stop_reason' => 'tool_use',
        'content' => array_map(fn (array $use, int $i): array => ['type' => 'tool_use', 'id' => "toolu_{$i}", 'name' => $use[0], 'input' => $use[1]], $uses, array_keys($uses)),
        'usage' => ['input_tokens' => 900, 'output_tokens' => 120]];
}

/**
 * What each part of the request is, as the notice names it: the message's own parts by their
 * lines, and each tool result by its tool. A part this can't name is returned as unknown.
 *
 * @return array{categories: list<string>, unknown: list<string>}
 */
function requestParts(array $body, bool $paperwork): array
{
    $categories = [];
    $unknown = [];
    $tools = [];
    foreach ($body['messages'] as $message) {
        if ($message['role'] === 'assistant') {
            // The AI's own replies, sent back to it: its tool calls, by id.
            foreach ($message['content'] as $block) {
                if (($block['type'] ?? null) === 'tool_use') {
                    $tools[$block['id']] = $block['name'];
                }
            }

            continue;
        }
        if (is_string($message['content'])) {
            $matched = preg_match('/^(Earlier in this conversation \(oldest first\):\n(?:- [^\n]*\n)+\n)?((?:Their plan for [^\n]*, made in this conversation, as it is now:\n(?:- [^\n]*\n)+\n)*)Now: [^\n]*?, Cologne\.( They opened Composer on the [^.\n]+ page\.)?\nHome Veedel: [^\n]*\.\n\nMessage: "(.*)"(\n\(They sent this same message before in this conversation[^\n]*\))?$/s', $message['content'], $m);
            if ($matched !== 1) {
                $unknown[] = $message['content'];

                continue;
            }
            array_push($categories, ...array_values(array_filter([
                ($m[1] ?? '') !== '' || ($m[5] ?? '') !== '' ? Assistant::CONTEXT['earlier'] : null, ($m[2] ?? '') !== '' ? Assistant::CONTEXT['plan'] : null,
                Assistant::CONTEXT['now'], ($m[3] ?? '') !== '' ? Assistant::CONTEXT['page'] : null, Assistant::CONTEXT['home'], Assistant::CONTEXT['message'],
            ])));

            continue;
        }
        foreach ($message['content'] as $block) {
            $tool = $tools[$block['tool_use_id'] ?? ''] ?? null;
            $category = ($paperwork ? AssistantTools::PAPERWORK_CATEGORIES[$tool] ?? null : null) ?? AssistantTools::CATEGORIES[$tool] ?? null;
            if ($category === null) {
                $unknown[] = ($tool ?? 'no tool').': '.json_encode($block['content'] ?? null);
            } else {
                $categories[] = $category;
            }
        }
    }

    return ['categories' => array_values(array_unique($categories)), 'unknown' => $unknown];
}

test('🔒 a Paperwork message sends only what Composer’s Paperwork notice names, under that permission', function () {
    $spot = Spot::factory()->create(['name' => 'Bau', 'category' => 'cafe', 'veedel' => 'Mülheim', 'is_active' => true]);
    Http::fake(['processor.example.test/*' => Http::sequence()
        ->push(noticeTurn([['where_am_i', []], ['my_places', []], ['paperwork', []], ['guidance', ['query' => 'blue card']],
            ['change_paperwork_fact', ['key' => 'visa_expires_at', 'value' => '2027-02-12']], ['place_feedback', ['place' => "spot:{$spot->id}", 'state' => 'saved']]]))
        ->push(noticeTurn([['answer', ['sentences' => [['text' => 'Here’s the change; tap Apply to make it.', 'uses' => ['x5']]], 'show' => []]]]))]);

    $this->actingAs($this->user);
    $revision = $this->getJson('/composer/workspace')->assertOk()->json('revision');
    $this->postJson('/composer/workspace', ['revision' => $revision, 'action' => 'message', 'text' => 'my visa now runs until 12 Feb 2027'])->assertOk();
    $this->postJson(route('composer.ask'), ['lat' => 50.94721, 'lng' => 6.91573, 'from_label' => 'Home',
        'processing' => ExternalProcessingFixtures::acceptance(ProcessingPurpose::ComposerAssist),
        'paperwork_processing' => ExternalProcessingFixtures::acceptance(ProcessingPurpose::ComposerPaperwork)])->assertOk();

    $bodies = collect(Http::recorded())->map(fn (array $pair): array => $pair[0]->data())->filter(fn (array $b): bool => isset($b['messages']))->values();
    expect($bodies)->toHaveCount(2);
    $disclosure = ProcessingPurpose::ComposerPaperwork->disclosure();
    $notice = implode(' ', $disclosure['notice']);
    foreach ($bodies as $body) {
        $parts = requestParts($body, true);
        // Every part is one the notice names, in its words.
        expect($parts['unknown'])->toBe([])
            ->and(array_diff($parts['categories'], $disclosure['payload_categories']))->toBe([]);
        foreach ($parts['categories'] as $category) {
            expect($notice)->toContain(ProcessingPurpose::WORDS[$category]);
        }
    }
    // The second request carries the record and the change: both named.
    expect(requestParts($bodies[1], true)['categories'])->toContain('your_paperwork_answers_and_tasks', 'paperwork_changes_you_ask_for',
        'your_saved_places_names', 'your_nearest_stop_and_veedel', 'changes_you_ask_for', 'paperwork_guide_titles', 'your_text', 'home_area', 'reference_time');

    // What the notice says is sent is sent: their answers, as the record says them.
    expect(json_encode($bodies[1]['messages'], JSON_UNESCAPED_UNICODE))->toContain('Your visa runs until: 1 Oct 2026');

    // Never sent: their coordinates, the address of a saved place.
    $sent = $bodies->map(fn (array $b): string => json_encode($b['messages'], JSON_UNESCAPED_UNICODE))->implode("\n");
    expect($sent)->not->toContain('50.94721')->not->toContain('6.91573')->not->toContain('Venloer Straße 210')
        ->and($notice)->toContain('it sends to Anthropic PBC: ')
        ->and($notice)->toContain('It never sends your coordinates, the addresses of your saved places, your documents or evidence');

    // What it says is kept is what the code keeps: the answer and its cards in their History, the change's
    // Undo for ComposerActions::HOURS, the permission for the request's minutes; never "15 minutes" for an answer.
    $workspace = app(WorkspaceStore::class)->read($this->user);
    $proposal = collect(collect($workspace['conversations'][0]['messages'])->last()['blocks'] ?? [])->firstWhere('type', 'proposal');
    $record = app(WorkspaceStore::class)->action($this->user, $proposal['token']);
    expect($notice)->toContain('Composer keeps this conversation, its answers and cards, in your History until you delete it')
        ->toContain('can be undone for '.ComposerActions::HOURS.' hours')
        ->toContain('lasts '.ProcessingPurpose::requestMinutes().' minutes')
        ->toContain('Deleting your Paperwork record removes Composer’s copies of it')
        ->not->toContain('keeps its answer')
        ->and($disclosure['kept'])->toBe(['history' => 'until_you_delete_it', 'undo_hours' => ComposerActions::HOURS, 'permission_minutes' => ProcessingPurpose::requestMinutes()])
        ->and($disclosure)->not->toHaveKey('response_retention_minutes')
        ->and((int) round(CarbonImmutable::now()->diffInHours(CarbonImmutable::parse($record['expires_at']))))->toBe(ComposerActions::HOURS)
        ->and(BureaucracyProcessingConsent::query()->where('actor_id', $this->user->id)->where('purpose', 'composer_paperwork')->value('expires_at')
            ->diffInMinutes(now()))->toBeLessThanOrEqual(ProcessingPurpose::requestMinutes());

    // The exchange ran under the Paperwork permission, bound to their own record.
    $permit = BureaucracyProcessingConsent::query()->where('actor_id', $this->user->id)->where('purpose', 'composer_paperwork')->sole();
    expect($permit->state)->toBe('completed')
        ->and($permit->case_id)->toBe($this->user->bureaucracyCase()->value('id'));
});

test('🔒 without the Paperwork permission, the request is Composer’s own notice and no record is sent', function () {
    Http::fake(['processor.example.test/*' => Http::sequence()
        ->push(noticeTurn([['paperwork', []], ['change_paperwork_fact', ['key' => 'visa_expires_at', 'value' => '2027-02-12']]]))
        ->push(noticeTurn([['answer', ['sentences' => [['text' => 'A card asks for your permission.', 'uses' => []]], 'show' => []]]]))]);

    $this->actingAs($this->user);
    $revision = $this->getJson('/composer/workspace')->assertOk()->json('revision');
    $this->postJson('/composer/workspace', ['revision' => $revision, 'action' => 'message', 'text' => 'my visa now runs until 12 Feb 2027'])->assertOk();
    $this->postJson(route('composer.ask'), ['processing' => ExternalProcessingFixtures::acceptance(ProcessingPurpose::ComposerAssist)])->assertOk();

    $disclosure = ProcessingPurpose::ComposerAssist->disclosure();
    foreach (collect(Http::recorded())->map(fn (array $pair): array => $pair[0]->data())->filter(fn (array $b): bool => isset($b['messages'])) as $body) {
        $parts = requestParts($body, false);
        expect($parts['unknown'])->toBe([])
            ->and(array_diff($parts['categories'], $disclosure['payload_categories']))->toBe([])
            ->and(json_encode($body['messages']))->not->toContain('2026-10-01')->not->toContain('1 Oct 2026');
    }
    expect(BureaucracyProcessingConsent::query()->where('purpose', 'composer_paperwork')->exists())->toBeFalse();
});

test('🔒 withdrawing Composer’s AI withdraws its Paperwork permission too, and its notice is served', function () {
    $this->actingAs($this->user)->getJson('/privacy/processing/composer_paperwork')->assertOk()
        ->assertJsonPath('purpose', 'composer_paperwork')->assertJsonPath('never_sent', ['coordinates', 'addresses', 'documents', 'other_people'])
        ->assertJsonPath('notice.0', fn (string $line): bool => str_starts_with($line, 'Once you allow it, Composer reads and can change your Paperwork record when a message needs it.'));
    $case = $this->user->bureaucracyCase()->firstOrFail();
    app(ProcessingConsentStore::class)->grant($this->user, ProcessingPurpose::ComposerPaperwork, ['text' => 'x', 'veedel' => 'Ehrenfeld', 'page' => null],
        ExternalProcessingFixtures::acceptance(ProcessingPurpose::ComposerPaperwork), $case);

    $this->deleteJson('/privacy/processing/composer_assist')->assertOk();

    expect(BureaucracyProcessingConsent::query()->where('purpose', 'composer_paperwork')->value('state'))->toBe('withdrawn');
});

<?php

use App\ContextEngine\BriefWriter;
use App\ContextEngine\DayBriefs;
use App\Models\BureaucracyProcessingConsent;
use App\Models\User;
use App\Privacy\ProcessingPurpose;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

/*
 * AI-written day briefs (owner, 26 Oct 2026): opt-in, off by default, under their own notice. Each
 * brief goes through the gate under its own permission; the request it sends, part by part, is
 * what the notice says it sends, and Paperwork never goes.
 */
uses()->group('context-engine');

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-11 07:00', 'Europe/Berlin'));
    config()->set('services.llm', [
        'driver' => 'anthropic', 'base_url' => 'https://processor.example.test', 'model' => 'claude-haiku-5-5', 'key' => 'synthetic',
        'processor_name' => 'Anthropic PBC', 'processor_privacy_url' => 'https://processor.example.test/privacy', 'prompt_version' => 'test.1',
    ]);
    $this->user = User::factory()->onboarded()->create();
    $this->sent = [];
    Http::fake(['processor.example.test/*' => function (Request $request) {
        $this->sent[] = $request->data();

        return Http::response(['id' => 'msg_1', 'type' => 'message', 'role' => 'assistant', 'stop_reason' => 'tool_use',
            'content' => [['type' => 'tool_use', 'id' => 'toolu_1', 'name' => 'brief', 'input' => ['lines' => [
                ['text' => 'Your 08:10 way to Ausländerbehörde is cancelled.', 'uses' => ['i1']],
                ['text' => 'Line 13 is disrupted at Sülzgürtel.', 'uses' => ['i2']],
                ['text' => 'Jazz im Stadtgarten starts at 12:00.', 'uses' => ['i3']],
            ]]]], 'usage' => ['input_tokens' => 300, 'output_tokens' => 60]]);
    }]);
});

/** One item of every kind a brief can hold, Paperwork included. */
function aiBriefItems(): array
{
    $items = [
        ['kind' => 'trip_change', 'text' => 'Your 08:10 way to Ausländerbehörde is cancelled.', 'facts' => 'Planned way to "Ausländerbehörde", leaving 08:10, is cancelled.'],
        ['kind' => 'disruption', 'text' => 'Line 13 disrupted at Sülzgürtel.', 'facts' => 'KVB disruption on line 13, stops affected: Sülzgürtel, one of their lines.'],
        ['kind' => 'event', 'text' => '12:00 Jazz im Stadtgarten at Stadtgarten.', 'facts' => 'An event they\'re going to: "Jazz im Stadtgarten", starting 12:00 at Stadtgarten.'],
        ['kind' => 'trip', 'text' => '17:30 leave for Dinner.', 'facts' => 'Planned way to "Dinner", leaving 17:30.'],
        ['kind' => 'plan', 'text' => 'Your plan: 2 stops from 14:00, first Kolumba.', 'facts' => 'Their plan for the day: 2 stops, the first "Kolumba" at 14:00.'],
        ['kind' => 'delay', 'text' => 'Line 18 running 12 min late at Neumarkt.', 'facts' => 'Line 18 running 12 min late at Neumarkt, one of their lines.'],
        ['kind' => 'weather', 'text' => 'Rain expected from 16:00.', 'facts' => 'Weather today: Rain expected from 16:00.'],
        ['kind' => 'shops', 'text' => 'Shops are closed tomorrow (Sunday).', 'facts' => 'Shops are closed tomorrow: Sunday.'],
        ['kind' => 'paperwork', 'text' => 'Your Paperwork needs attention.', 'facts' => 'Their Paperwork needs attention.'],
    ];

    return array_map(fn (array $item, int $i): array => ['id' => 'i'.($i + 1), 'own' => true, 'rank' => $i, ...$item], $items, array_keys($items));
}

test('the AI writes no brief until they turn it on under the current notice; then each goes through the gate under its own permission, until they turn it off', function () {
    // Every risk said: the reply below says the way and the disruption, so no delay here.
    $items = array_values(array_filter(aiBriefItems(), fn (array $item): bool => $item['kind'] !== 'delay'));
    $write = fn () => app(BriefWriter::class)->write($this->user, $items, CarbonImmutable::parse('2026-11-11', 'Europe/Berlin'), DayBriefs::MORNING);

    expect($write())->toBeNull();
    Http::assertNothingSent();

    $state = $this->actingAs($this->user)->getJson('/notification-preferences/ai-briefs')->assertOk()->json();
    expect($state['enabled'])->toBeFalse()->and($state['disclosure']['scope'])->toBe('until_you_turn_it_off');

    // Not the notice they were shown: refused.
    $this->putJson('/notification-preferences/ai-briefs', ['enabled' => true, 'notice_version' => 'old', 'provider_version' => $state['disclosure']['provider_version']])
        ->assertUnprocessable();
    $this->putJson('/notification-preferences/ai-briefs', ['enabled' => true, 'notice_version' => $state['disclosure']['notice_version'],
        'provider_version' => $state['disclosure']['provider_version']])->assertOk()->assertJson(['enabled' => true]);

    expect($write())->toBe(['Your 08:10 way to Ausländerbehörde is cancelled.', 'Line 13 is disrupted at Sülzgürtel.', 'Jazz im Stadtgarten starts at 12:00.', 'Your Paperwork needs attention.'])
        ->and(BureaucracyProcessingConsent::query()->where('actor_id', $this->user->id)->where('purpose', 'notification_brief_ai')->pluck('state')->all())->toBe(['completed']);

    // A new model or prompt: their yes was to the old one, so it lapses until they say yes again.
    config()->set('services.llm.prompt_version', 'test.2');
    expect($write())->toBeNull();
    config()->set('services.llm.prompt_version', 'test.1');

    $this->putJson('/notification-preferences/ai-briefs', ['enabled' => false])->assertOk()->assertJson(['enabled' => false]);
    expect($write())->toBeNull()->and($this->sent)->toHaveCount(1);
});

test('the notice names everything a brief sends, and Paperwork never goes', function () {
    $disclosure = ProcessingPurpose::NotificationBrief->disclosure();
    $this->actingAs($this->user)->putJson('/notification-preferences/ai-briefs', ['enabled' => true,
        'notice_version' => $disclosure['notice_version'], 'provider_version' => $disclosure['provider_version']])->assertOk();

    app(BriefWriter::class)->write($this->user, aiBriefItems(), CarbonImmutable::parse('2026-11-11', 'Europe/Berlin'), DayBriefs::MORNING);

    $content = $this->sent[0]['messages'][0]['content'];
    $kinds = array_column(aiBriefItems(), 'kind', 'id');
    $parts = array_map(fn (string $line): string => match (true) {
        str_starts_with($line, 'Today is ') => 'the_day',
        preg_match('/^(i\d+): /', $line, $m) === 1 => BriefWriter::CATEGORIES[$kinds[$m[1]]] ?? 'unknown:'.$kinds[$m[1]],
        default => 'unknown:'.$line,
    }, explode("\n", $content));
    $notice = implode(' ', $disclosure['notice']);

    expect(array_diff(array_unique($parts), $disclosure['payload_categories']))->toBe([])
        ->and(collect(array_unique($parts))->every(fn (string $part): bool => str_contains($notice, ProcessingPurpose::BRIEF_WORDS[$part])))->toBeTrue()
        ->and($content)->not->toContain('Paperwork')
        ->and($disclosure['never_sent'])->toContain('paperwork');
});

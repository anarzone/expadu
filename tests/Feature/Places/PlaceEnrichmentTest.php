<?php

use App\Enums\VibeTag;
use App\Models\Spot;
use App\Models\SpotEnrichment;
use App\Models\SpotEnrichmentBatch;
use App\Places\Enrichment\PlaceEnrichmentBatches;
use App\Places\Enrichment\PlaceEnrichmentInput;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    config([
        'services.anthropic.key' => 'test-key',
        'services.place_enrichment.enabled' => true,
        'services.place_enrichment.daily_budget_usd' => 3,
    ]);
    Http::preventStrayRequests();
});

function enrichableCafe(array $attributes = []): Spot
{
    return Spot::factory()->create([
        'name' => 'Café Kranich',
        'category' => 'cafe',
        'description' => 'Gemütliches Kindercafe mit großer Spielecke und Terrasse.',
        'tags' => ['amenity' => 'cafe', 'cuisine' => 'coffee_shop;cake', 'outdoor_seating' => 'yes', 'wheelchair' => 'yes'],
        ...$attributes,
    ]);
}

/** @param list<array{tag: string, source: string, ref: string}> $tags */
function enrichmentMessage(string $description, array $tags = [], string $stopReason = 'tool_use', ?array $content = null): array
{
    return [
        'model' => 'claude-haiku-5-5',
        'stop_reason' => $stopReason,
        'content' => $content ?? [['type' => 'tool_use', 'name' => 'store_place_enrichment', 'input' => ['description' => $description, 'tags' => $tags]]],
        'usage' => ['input_tokens' => 1600, 'output_tokens' => 100],
    ];
}

function fakeEnrichment(array $message): void
{
    Http::fake(['api.anthropic.com/v1/messages' => Http::response($message)]);
}

test('a place gets a linted description and only tags whose evidence checks out', function () {
    $spot = enrichableCafe();
    fakeEnrichment(enrichmentMessage('Café Kranich is a cosy children\'s café with a big play corner and a terrace.', [
        ['tag' => 'cosy', 'source' => 'text', 'ref' => 'Gemütliches Kindercafe'],
        ['tag' => 'kid_play', 'source' => 'text', 'ref' => 'großer Spielecke'],
        // Not in the text, a quote without a cue word, a code-only tag, and an OSM pair the rules don't map.
        ['tag' => 'quiet', 'source' => 'text', 'ref' => 'ruhige Lage'],
        ['tag' => 'lively', 'source' => 'text', 'ref' => 'Kindercafe'],
        ['tag' => 'step_free', 'source' => 'text', 'ref' => 'Terrasse'],
        ['tag' => 'work_friendly', 'source' => 'osm', 'ref' => 'cuisine=coffee_shop;cake'],
    ]));

    $this->artisan('places:enrich', ['--sync' => true])->assertSuccessful();

    $row = SpotEnrichment::query()->where('spot_id', $spot->id)->sole();
    expect($row->status)->toBe('ready')
        ->and($row->needs_review)->toBeFalse()
        ->and($row->summary_status)->toBe('ok')
        ->and($row->summary)->toBe('Café Kranich is a cosy children\'s café with a big play corner and a terrace.')
        ->and($row->vibeTagNames())->toEqualCanonicalizing(['outdoor_seating', 'indoors', 'step_free', 'cosy', 'kid_play'])
        ->and(collect($row->vibe_tags)->firstWhere('tag', 'step_free'))->toEqual(['tag' => 'step_free', 'by' => 'rule', 'evidence' => ['source' => 'osm', 'ref' => 'wheelchair=yes']])
        ->and(collect($row->vibe_tags)->firstWhere('tag', 'cosy')['evidence'])->toEqual(['source' => 'text', 'ref' => 'Gemütliches Kindercafe'])
        ->and($row->source_hash)->toBe(PlaceEnrichmentInput::fromSpot($spot)->hash())
        ->and($row->source_snapshot['osm_tags'])->toHaveKey('wheelchair');

    // Composer's read API sees it; the call is logged without any text.
    expect(SpotEnrichment::forSpots([$spot->id])->get($spot->id)?->hasVibeTag(VibeTag::Cosy))->toBeTrue()
        ->and(SpotEnrichment::query()->withVibeTag(VibeTag::KidPlay)->pluck('spot_id')->all())->toBe([$spot->id]);
    $call = DB::table('llm_calls')->sole();
    expect($call->feature)->toBe('place_enrichment')->and($call->outcome)->toBe('success')->and((float) $call->cost_usd)->toBe(0.00021);
    Http::assertSent(fn (Request $request) => $request['model'] === 'claude-haiku-5-5'
        && $request['output_config']['effort'] === 'low'
        && $request['tool_choice'] === ['type' => 'tool', 'name' => 'store_place_enrichment']
        && ! array_key_exists('temperature', $request->data())
        // Hours, hard needs and addresses never reach the model.
        && ! str_contains($request['messages'][0]['content'], 'wheelchair')
        && str_contains($request['messages'][0]['content'], '<source_text>Gemütliches Kindercafe'));
});

test('a description that says more than the data is thrown away and the place keeps its tags', function (string $description, string $reason) {
    $spot = enrichableCafe();
    fakeEnrichment(enrichmentMessage($description));

    $this->artisan('places:enrich', ['--sync' => true])->assertSuccessful();

    $row = SpotEnrichment::query()->where('spot_id', $spot->id)->sole();
    expect($row->summary)->toBeNull()
        ->and($row->summary_status)->toBe('rejected')
        ->and($row->needs_review)->toBeTrue()
        ->and($row->last_error)->toBe('summary_rejected:'.$reason)
        ->and($row->vibeTagNames())->toContain('outdoor_seating', 'step_free')
        ->and(DB::table('llm_calls')->value('outcome'))->toBe('rejected:'.$reason);
})->with([
    'a digit' => ['Café Kranich has 40 seats on its terrace.', 'digit'],
    'a price' => ['Café Kranich serves cake for a few euros on the terrace.', 'currency'],
    'a clock time' => ['Café Kranich serves cake on the terrace until midnight.', 'clock'],
    'a weekday' => ['Café Kranich serves cake on the terrace on Sundays.', 'weekday'],
    'a month' => ['Café Kranich serves cake on its terrace in June.', 'date'],
    'a street' => ['A café on Venloer Straße with a terrace.', 'name'],
    'the city' => ['A café in Cologne with a terrace.', 'name'],
    'praise' => ['Café Kranich is a popular café with a terrace.', 'praise'],
    'an unbacked vibe' => ['Café Kranich is a quiet café with a terrace.', 'ungrounded:quiet'],
    'too long' => [str_repeat('A café with a terrace. ', 10), 'too_long'],
]);

test('a sentence about the data is dropped and the rest is kept', function () {
    $spot = enrichableCafe();
    fakeEnrichment(enrichmentMessage('Café Kranich is a café with a terrace. The data gives no further detail about it.'));

    $this->artisan('places:enrich', ['--sync' => true])->assertSuccessful();

    expect(SpotEnrichment::query()->where('spot_id', $spot->id)->value('summary'))->toBe('Café Kranich is a café with a terrace.');
});

test('a refused, truncated or malformed reply writes no row', function (array $message, string $outcome) {
    enrichableCafe();
    fakeEnrichment($message);

    $this->artisan('places:enrich', ['--sync' => true])->assertSuccessful();

    expect(SpotEnrichment::query()->count())->toBe(0)
        ->and(DB::table('llm_calls')->value('outcome'))->toBe($outcome);
})->with([
    'refusal' => [enrichmentMessage('A café.', [], 'refusal'), 'refused'],
    'max tokens' => [enrichmentMessage('A café.', [], 'max_tokens'), 'max_tokens'],
    'no tool call' => [enrichmentMessage('', [], 'tool_use', [['type' => 'text', 'text' => 'A café.']]), 'malformed:tool'],
    'two tool calls' => [enrichmentMessage('', [], 'tool_use', [
        ['type' => 'tool_use', 'name' => 'store_place_enrichment', 'input' => ['description' => 'A café.', 'tags' => []]],
        ['type' => 'tool_use', 'name' => 'store_place_enrichment', 'input' => ['description' => 'A café.', 'tags' => []]],
    ]), 'malformed:tool'],
]);

test('an unchanged place is skipped, a changed OSM tag re-runs it, and a thin place needs no call', function () {
    $spot = enrichableCafe();
    $thin = Spot::factory()->create(['name' => 'Apotheke am Ring', 'category' => 'pharmacy', 'description' => null, 'tags' => ['amenity' => 'pharmacy', 'wheelchair' => 'yes']]);
    fakeEnrichment(enrichmentMessage('Café Kranich is a café with a terrace.'));

    $this->artisan('places:enrich', ['--sync' => true])->assertSuccessful();
    $this->artisan('places:enrich', ['--sync' => true])->assertSuccessful();
    Http::assertSentCount(1);

    expect(SpotEnrichment::query()->where('spot_id', $thin->id)->sole())
        ->summary->toBeNull()
        ->summary_status->toBe('unknown')
        ->model->toBeNull()
        ->needs_review->toBeFalse()
        ->and(SpotEnrichment::query()->where('spot_id', $thin->id)->sole()->vibeTagNames())->toBe(['step_free']);

    $spot->update(['tags' => [...$spot->tags, 'diet:vegan' => 'yes']]);
    $this->artisan('places:enrich', ['--sync' => true])->assertSuccessful();
    Http::assertSentCount(2);
    expect(SpotEnrichment::query()->where('spot_id', $spot->id)->sole()->vibeTagNames())->toContain('vegan_options');
});

test('a stale enrichment whose input has not changed is shown as ready again without a call', function () {
    $spot = enrichableCafe();
    fakeEnrichment(enrichmentMessage('Café Kranich is a café with a terrace.'));
    $this->artisan('places:enrich', ['--sync' => true])->assertSuccessful();

    $spot->update(['veedel' => 'Nippes']); // not an enrichment input, but it stales the row
    expect(SpotEnrichment::query()->where('spot_id', $spot->id)->value('status'))->toBe('stale');

    $this->artisan('places:enrich', ['--sync' => true])->assertSuccessful();
    Http::assertSentCount(1);
    expect(SpotEnrichment::query()->where('spot_id', $spot->id)->sole())
        ->status->toBe('ready')
        ->stale_at->toBeNull();
});

test('a batch is submitted, then its succeeded results are applied and errored or expired ones leave no row', function () {
    $ok = enrichableCafe();
    $errored = enrichableCafe(['name' => 'Café Elster']);
    $expired = enrichableCafe(['name' => 'Café Meise']);
    $changed = enrichableCafe(['name' => 'Café Specht']);
    Http::fake([
        'api.anthropic.com/v1/messages/batches' => Http::response(['id' => 'msgbatch_test', 'type' => 'message_batch', 'processing_status' => 'in_progress']),
    ]);

    $this->artisan('places:enrich')->assertSuccessful();

    $batch = SpotEnrichmentBatch::query()->sole();
    expect($batch->status)->toBe('in_progress')
        ->and($batch->request_count)->toBe(4)
        ->and($batch->spot_ids)->toEqualCanonicalizing([$ok->id, $errored->id, $expired->id, $changed->id]);
    Http::assertSent(fn (Request $request) => $request->url() === 'https://api.anthropic.com/v1/messages/batches'
        && count($request['requests']) === 4
        && preg_match('/^spot-\d+-[0-9a-f]{16}$/', $request['requests'][0]['custom_id']) === 1
        && $request['requests'][0]['params']['tool_choice']['name'] === 'store_place_enrichment');

    // A place in an open batch isn't sent again.
    $this->artisan('places:enrich')->assertSuccessful();
    expect(SpotEnrichmentBatch::query()->count())->toBe(1);

    $idFor = fn (Spot $spot): string => PlaceEnrichmentBatches::customId(PlaceEnrichmentInput::fromSpot($spot));
    $lines = [
        ['custom_id' => $idFor($ok), 'result' => ['type' => 'succeeded', 'message' => enrichmentMessage('Café Kranich is a café with a terrace.')]],
        ['custom_id' => $idFor($errored), 'result' => ['type' => 'errored', 'error' => ['type' => 'error', 'error' => ['type' => 'invalid_request_error', 'message' => 'bad']]]],
        ['custom_id' => $idFor($expired), 'result' => ['type' => 'expired']],
        ['custom_id' => $idFor($changed), 'result' => ['type' => 'succeeded', 'message' => enrichmentMessage('Café Specht is a café with a terrace.')]],
    ];
    $changed->update(['description' => 'Ein ganz anderer Text.']);
    Http::fake([
        'api.anthropic.com/v1/messages/batches/msgbatch_test/results' => Http::response(implode("\n", array_map('json_encode', $lines))),
        'api.anthropic.com/v1/messages/batches/msgbatch_test' => Http::sequence()
            ->push(['id' => 'msgbatch_test', 'processing_status' => 'in_progress'])
            ->push(['id' => 'msgbatch_test', 'processing_status' => 'ended']),
    ]);

    $this->artisan('places:enrich-collect')->assertSuccessful();
    expect($batch->fresh()->status)->toBe('in_progress');

    $this->artisan('places:enrich-collect')->assertSuccessful();
    expect($batch->fresh()->status)->toBe('collected')
        ->and($batch->fresh()->result_counts)->toBe(['success' => 1, 'batch_errored:invalid_request_error' => 1, 'batch_expired' => 1, 'stale_input' => 1])
        ->and(SpotEnrichment::query()->pluck('spot_id')->all())->toBe([$ok->id])
        ->and(SpotEnrichment::query()->sole()->producer)->toBe('place_enricher:batch');

    // Billed at half price; unbilled results carry no tokens.
    $calls = DB::table('llm_calls')->where('feature', 'place_enrichment_batch')->get()->keyBy('outcome');
    expect((float) $calls['success']->cost_usd)->toBe(0.000105)
        ->and($calls['batch_expired']->cost_usd)->toBeNull();
});

test('the spend cap stops submission, and nothing is sent when the switch is off', function () {
    enrichableCafe();
    Http::fake();

    config(['services.place_enrichment.enabled' => false]);
    $this->artisan('places:enrich')->assertSuccessful();

    config(['services.place_enrichment.enabled' => true, 'services.place_enrichment.daily_budget_usd' => 0.00001]);
    $this->artisan('places:enrich')->expectsOutputToContain('budget reached')->assertSuccessful();
    $this->artisan('places:enrich', ['--sync' => true])->expectsOutputToContain('budget reached')->assertSuccessful();

    Http::assertNothingSent();
    expect(SpotEnrichmentBatch::query()->count())->toBe(0)
        ->and(SpotEnrichment::query()->count())->toBe(0);
});

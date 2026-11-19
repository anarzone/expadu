<?php

use App\Ai\AiRequest;

/*
 * The prompt cache: the fixed instructions of every kind of call are marked, so later calls read
 * them for a tenth of the price. Only Composer marked them, and the account's hit rate was 1%
 * (owner, 19 Nov 2026).
 */

test('a plain system prompt is marked for the cache', function () {
    $body = AiRequest::shape(['model' => 'claude-haiku-5-5', 'system' => 'You classify Cologne events.', 'messages' => [['role' => 'user', 'content' => 'Jazz tonight']]]);

    expect($body['system'])->toBe([['type' => 'text', 'text' => 'You classify Cologne events.', 'cache_control' => ['type' => 'ephemeral']]])
        // What changes per call stays after it.
        ->and($body['messages'][0]['content'])->toBe('Jazz tonight');
});

test('the last block of a system prompt in parts is marked', function () {
    $body = AiRequest::shape(['model' => 'claude-haiku-5-5', 'system' => [['type' => 'text', 'text' => 'One.'], ['type' => 'text', 'text' => 'Two.']], 'messages' => []]);

    expect($body['system'][0])->not->toHaveKey('cache_control')
        ->and($body['system'][1]['cache_control'])->toBe(['type' => 'ephemeral']);
});

test('a caller that marks its own cache is left as it is', function () {
    $system = [['type' => 'text', 'text' => 'Composer.', 'cache_control' => ['type' => 'ephemeral']], ['type' => 'text', 'text' => 'Personal.']];

    expect(AiRequest::shape(['model' => 'claude-haiku-5-5', 'system' => $system, 'messages' => []])['system'])->toBe($system);
});

test('a line added per round stays outside what is cached', function () {
    // A model that takes no forced tool gets an instruction instead; it comes after the cached part.
    $body = AiRequest::shape(['model' => 'claude-sonnet-5-5', 'system' => 'Rank these.', 'tool_choice' => ['type' => 'tool', 'name' => 'rank'], 'messages' => []]);

    expect($body['system'][0])->toMatchArray(['text' => 'Rank these.', 'cache_control' => ['type' => 'ephemeral']])
        ->and($body['system'])->toHaveCount(2)
        ->and($body['system'][1])->not->toHaveKey('cache_control');
});

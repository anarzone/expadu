<?php

namespace App\Ai;

/**
 * The last step before a Messages API request goes out. Normally it only lets a model that
 * rejects a forced tool (ToolChoice) answer through `auto` and an instruction instead. An eval
 * comparing models (the --model, --effort, --max-tokens and --think options of the eval commands) sets
 * `ai.override` for its own process, and every request then runs on that model and effort; the
 * app never sets it.
 *
 * It also marks the end of the fixed instructions for the prompt cache when the caller marked
 * nothing: the tools and system prompt are the same on every call of a kind (an event to
 * classify, a notice to rewrite), so later calls read them at a tenth of the price. Without it
 * only Composer cached, and the account's hit rate stood at 1% (owner, 19 Nov 2026).
 */
final class AiRequest
{
    /** Efforts above which a model accepts no disabled thinking. */
    private const THINKING_OFF_UP_TO = ['low', 'medium', 'high'];

    /**
     * @param  array<string, mixed>  $body
     * @return array<string, mixed>
     */
    public static function shape(array $body): array
    {
        $override = config('ai.override');
        if (is_array($override)) {
            if (is_string($override['model'] ?? null)) {
                $body['model'] = $override['model'];
            }
            if (is_string($override['effort'] ?? null)) {
                $body['output_config'] = [...(is_array($body['output_config'] ?? null) ? $body['output_config'] : []), 'effort' => $override['effort']];
            }
            if (is_int($override['max_tokens'] ?? null)) {
                $body['max_tokens'] = max((int) ($body['max_tokens'] ?? 0), $override['max_tokens']);
            }
        }
        $model = (string) ($body['model'] ?? '');
        $body = self::cached($body);

        // Disabled thinking: Sonnet 5.5 never takes it, Haiku 5.5 only up to high effort.
        $effort = $body['output_config']['effort'] ?? null;
        if (($body['thinking']['type'] ?? null) === 'disabled' && (! ToolChoice::forced($model) || ! in_array($effort ?? 'medium', self::THINKING_OFF_UP_TO, true))) {
            unset($body['thinking']);
        }

        // A forced tool call skips thinking (Haiku 5.5): an eval's `think` asks as for a model that takes none.
        $think = is_array($override) && ($override['think'] ?? false) === true;
        $choice = $body['tool_choice'] ?? null;
        if (is_array($choice) && in_array($choice['type'] ?? null, ['tool', 'any'], true) && (! ToolChoice::forced($model) || $think)) {
            $body['tool_choice'] = ['type' => 'auto'];
            $line = is_string($choice['name'] ?? null) ? ToolChoice::instruction($choice['name']) : 'Answer only by calling one of the tools.';
            if ($think) {
                // Thinking blocks hold only while what came before them is unchanged, so the system line is the
                // same every round, and the round that must use one tool says so after its results.
                [$line, $body['messages']] = ['Answer only by calling one of the tools.',
                    is_string($choice['name'] ?? null) ? self::after($body['messages'] ?? [], ToolChoice::instruction($choice['name'])) : ($body['messages'] ?? [])];
            }
            $body['system'] = match (true) {
                is_string($body['system'] ?? null) => $body['system'].' '.$line,
                is_array($body['system'] ?? null) => [...$body['system'], ['type' => 'text', 'text' => $line]],
                default => $line,
            };
        }

        return $body;
    }

    /**
     * The fixed instructions marked for the prompt cache, before a per-round line is added after them
     * (that line differs from round to round, so it stays outside what's cached). Left alone when the
     * caller marked something itself (Composer), or there's no system prompt. Too short a prefix
     * (under the model's minimum) simply isn't cached: no error, no cost.
     *
     * @param  array<string, mixed>  $body
     * @return array<string, mixed>
     */
    private static function cached(array $body): array
    {
        if (isset($body['cache_control']) || str_contains((string) json_encode([$body['system'] ?? null, $body['tools'] ?? null, $body['messages'] ?? null]), '"cache_control"')) {
            return $body;
        }
        if (is_string($body['system'] ?? null) && trim($body['system']) !== '') {
            $body['system'] = [['type' => 'text', 'text' => $body['system'], 'cache_control' => ['type' => 'ephemeral']]];
        } elseif (is_array($body['system'] ?? null) && $body['system'] !== []) {
            $last = array_key_last($body['system']);
            if (is_array($body['system'][$last]) && ($body['system'][$last]['type'] ?? null) === 'text') {
                $body['system'][$last]['cache_control'] = ['type' => 'ephemeral'];
            }
        }

        return $body;
    }

    /**
     * @param  list<array<string, mixed>>  $messages
     * @return list<array<string, mixed>>
     */
    private static function after(array $messages, string $text): array
    {
        $last = array_key_last($messages);
        if ($last === null || ($messages[$last]['role'] ?? null) !== 'user') {
            return [...$messages, ['role' => 'user', 'content' => $text]];
        }
        $content = $messages[$last]['content'];
        $messages[$last]['content'] = [...(is_string($content) ? [['type' => 'text', 'text' => $content]] : $content), ['type' => 'text', 'text' => $text]];

        return $messages;
    }
}

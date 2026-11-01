<?php

namespace App\Ai;

/**
 * Asking a model to answer through one tool. Claude Haiku takes a forced tool_choice; Claude
 * Sonnet 5.5, Opus 5.5 and Fable 5.1 reject it with a 400, so those get `auto` and the request's
 * own instruction to call the tool (instruction()). Lets any service run on either for an eval.
 */
final class ToolChoice
{
    /** Models that reject a forced tool_choice (`tool` or `any`). */
    private const UNFORCED = ['claude-sonnet-5-5', 'claude-opus-5-5', 'claude-fable-5-1', 'claude-mythos-5-1'];

    /** @return array{type: string, name?: string} */
    public static function tool(string $model, string $tool): array
    {
        return self::forced($model) ? ['type' => 'tool', 'name' => $tool] : ['type' => 'auto'];
    }

    /** "Some tool", as Composer's loop asks between its lookups. */
    public static function any(string $model): array
    {
        return self::forced($model) ? ['type' => 'any'] : ['type' => 'auto'];
    }

    /** The line a system prompt carries so a model without a forced choice still answers through the tool. */
    public static function instruction(string $tool): string
    {
        return "Answer only by calling the {$tool} tool, once.";
    }

    public static function forced(string $model): bool
    {
        return ! in_array($model, self::UNFORCED, true);
    }
}

<?php

namespace App\Composer\Actions;

use App\Models\User;
use Carbon\CarbonImmutable;

/**
 * Changes Composer can make, each through the same code as the app's own control for it.
 * Everything a card shows comes from the records it changed, never from the AI's words.
 */
interface ComposerAction
{
    /**
     * Checks and resolves what to change, as it is now, without changing anything.
     *
     * @param  array<string, mixed>  $input  the tool's input
     * @return array<string, mixed> what `run` takes, with `propose` (the question a proposal asks) and the card's `sub`
     *
     * @throws ActionRefused
     */
    public function prepare(User $user, string $action, array $input, CarbonImmutable $now): array;

    /**
     * Makes the change.
     *
     * @param  array<string, mixed>  $prepared
     * @return array<string, mixed> the card's `title` and its other fields, with `undo` (what Undo takes) and `said` (a line for the AI)
     *
     * @throws ActionRefused
     */
    public function run(User $user, string $action, array $prepared, CarbonImmutable $now): array;

    /**
     * Puts it back.
     *
     * @param  array<string, mixed>  $undo
     * @return array<string, mixed> the undone card's `title`, and its other fields
     *
     * @throws ActionRefused
     */
    public function undo(User $user, string $action, array $undo, CarbonImmutable $now): array;
}

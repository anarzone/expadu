<?php

namespace App\Composer\Actions;

use App\Models\User;
use App\Personalisation\Memory;
use Carbon\CarbonImmutable;

/**
 * Composer remembering a lasting fact the person told it about themselves, or forgetting one
 * (Memory): through the same store as the "What Expadu knows" screen, each with Undo. The card
 * says the fact as kept, never the AI's words around it.
 */
final class MemoryActions implements ComposerAction
{
    public function __construct(private Memory $memory) {}

    public function prepare(User $user, string $action, array $input, CarbonImmutable $now): array
    {
        if ($action === 'remember') {
            $text = Memory::clean(is_string($input['fact'] ?? null) ? $input['fact'] : '');
            if (($why = $this->memory->refusal($user, $text)) !== null) {
                throw new ActionRefused($why);
            }

            return ['text' => $text, 'propose' => "Remember: {$text}?", 'tell' => 'Remember what they said about themselves?'];
        }
        $fact = $this->resolve($user, $input['fact'] ?? null);
        if ($fact === null) {
            throw new ActionRefused('Nothing like that is remembered.');
        }

        return ['fact' => $fact, 'propose' => "Forget: {$fact['text']}?", 'tell' => 'Forget what they asked to forget?'];
    }

    public function run(User $user, string $action, array $prepared, CarbonImmutable $now): array
    {
        if ($action === 'remember') {
            $kept = $this->memory->remember($user, $prepared['text']);
            if (is_string($kept)) {
                throw new ActionRefused($kept);
            }

            return ['title' => "Remembered: {$kept['text']}", 'link' => self::link(), 'said' => "Remembered that they said: {$kept['text']}.", 'undo' => ['id' => $kept['id']]];
        }
        $fact = $this->memory->forget($user, (int) $prepared['fact']['id']);
        if ($fact === null) {
            throw new ActionRefused('That is already forgotten.');
        }

        return ['title' => "Forgotten: {$fact['text']}", 'link' => self::link(), 'said' => "Forgot that they said: {$fact['text']}.", 'undo' => ['fact' => $fact]];
    }

    public function undo(User $user, string $action, array $undo, CarbonImmutable $now): array
    {
        if ($action === 'remember') {
            $fact = $this->memory->forget($user, (int) $undo['id']);

            return ['title' => 'Undone. '.($fact !== null ? "Not remembered: {$fact['text']}." : 'Not remembered.')];
        }
        $kept = $this->memory->restore($user, $undo['fact']);
        if (is_string($kept)) {
            throw new ActionRefused($kept);
        }

        return ['title' => "Undone. Remembered again: {$kept['text']}."];
    }

    /** The fact they mean: by its id as Composer read it ("k3"), else the one whose words match. */
    private function resolve(User $user, mixed $said): ?array
    {
        if (! is_string($said) || trim($said) === '') {
            return null;
        }
        $facts = $this->memory->facts($user);
        if (preg_match('/^k(\d+)$/', trim($said), $m) === 1) {
            return $facts[(int) $m[1] - 1] ?? null;
        }
        $words = mb_strtolower(Memory::clean($said));

        return collect($facts)->first(fn (array $fact): bool => mb_strtolower($fact['text']) === $words)
            ?? collect($facts)->first(fn (array $fact): bool => str_contains(mb_strtolower($fact['text']), $words) || str_contains($words, mb_strtolower($fact['text'])));
    }

    /** @return array{label: string, href: string} */
    private static function link(): array
    {
        return ['label' => 'See what Expadu knows →', 'href' => '/settings/privacy'];
    }
}

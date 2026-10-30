<?php

namespace App\Composer\Actions;

use App\Models\PlaceWorkAnswer;
use App\Models\Spot;
use App\Models\User;
use App\Places\PlaceWorkAnswers;
use Carbon\CarbonImmutable;

/**
 * Their answer to a place's "Good for laptop work?" (the place sheet's question), from what
 * they tell Composer: only for a place they've been to or saved, as the sheet asks. Other
 * people read it in the place's totals, so it's always proposed: their Apply makes it theirs.
 */
final class WorkAnswerAction implements ComposerAction
{
    /** The sheet's questions and their follow-ups, each yes or no. */
    public const QUESTIONS = ['laptop' => 'laptops welcome', 'wifi' => 'Wi-Fi', 'sockets' => 'sockets', 'quiet' => 'quiet'];

    public function __construct(private PlaceWorkAnswers $answers, private PlaceFeedbackAction $places) {}

    public function prepare(User $user, string $action, array $input, CarbonImmutable $now): array
    {
        $spot = $this->places->spot(trim((string) ($input['place'] ?? '')), is_string($input['area'] ?? null) ? $input['area'] : null);
        if (! $this->answers->canAnswer($user, $spot)) {
            throw new ActionRefused("Only people who’ve been to {$spot->name} or saved it answer: mark it Been there or Saved first.");
        }
        if (! in_array($input['laptop'] ?? null, ['yes', 'no'], true)) {
            throw new ActionRefused('Say whether laptops are welcome there: yes or no.');
        }
        $previous = self::answer($this->answers->answerOf($user, $spot));
        $answer = ['laptop' => $input['laptop']];
        foreach (['wifi', 'sockets', 'quiet'] as $follow) {
            $answer[$follow] = in_array($input[$follow] ?? null, ['yes', 'no'], true) ? $input[$follow] : ($previous[$follow] ?? null);
        }
        if ($answer === $previous) {
            throw new ActionRefused("That’s their answer for {$spot->name} already.");
        }

        return ['spot' => $spot->id, 'name' => $spot->name, 'answer' => $answer, 'previous' => $previous,
            'propose' => "Answer “Good for laptop work?” for {$spot->name}: ".self::said($answer).'?',
            'sub' => 'Others see it only in the place’s totals, never as yours.'];
    }

    public function run(User $user, string $action, array $prepared, CarbonImmutable $now): array
    {
        $spot = Spot::query()->find($prepared['spot']) ?? throw new ActionRefused('That place is no longer listed.');
        $this->answers->save($user, $spot, $prepared['answer']);
        $title = "Your answer for {$prepared['name']}: ".self::said($prepared['answer']);

        return ['title' => $title, 'sub' => $prepared['sub'], 'said' => "{$title}.",
            'undo' => ['spot' => $spot->id, 'name' => $prepared['name'], 'previous' => $prepared['previous']]];
    }

    public function undo(User $user, string $action, array $undo, CarbonImmutable $now): array
    {
        $spot = Spot::query()->find($undo['spot']) ?? throw new ActionRefused('That place is no longer listed.');
        $undo['previous'] === null ? $this->answers->withdraw($user, $spot) : $this->answers->save($user, $spot, $undo['previous']);

        return ['title' => "Undone. Your answer for {$undo['name']} is back as it was."];
    }

    /** "laptops welcome: yes, Wi-Fi: no" */
    private static function said(array $answer): string
    {
        return implode(', ', array_map(fn (string $q): string => self::QUESTIONS[$q].': '.$answer[$q], array_keys(array_filter($answer, fn ($v) => $v !== null))));
    }

    /** @return array{laptop: string, wifi: ?string, sockets: ?string, quiet: ?string}|null */
    private static function answer(?PlaceWorkAnswer $row): ?array
    {
        return $row === null ? null : ['laptop' => $row->laptop, 'wifi' => $row->wifi, 'sockets' => $row->sockets, 'quiet' => $row->quiet];
    }
}

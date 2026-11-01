<?php

namespace App\Personalisation;

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * What personalisation has learned, as Settings › Privacy says it (owner, 29 Oct 2026, the consent
 * canvas's boards 1–2): how many of their actions it learned from, the kinds of things they lean
 * to (TasteFacts, once there's enough to say), and when Today was put in order for them today.
 * The same numbers say what turning it off deletes.
 */
final class Learned
{
    public function __construct(private TasteFacts $taste, private TodayTaste $today) {}

    /** @return array{actions: int, leanings: list<string>, ordered_at: ?string}|null null when it's off */
    public function for(User $user): ?array
    {
        if (! $user->personalises()) {
            return null;
        }
        $leanings = collect($this->taste->for($user)['categories'] ?? [])
            ->filter(fn (array $row): bool => in_array($row['level'] ?? null, ['strong', 'some'], true))
            ->pluck('name')->take(3)->values()->all();
        $order = $this->today->order($user);

        return [
            'actions' => DB::table('user_signals')->where('subject_key', Pseudonym::for($user))->where('action', '!=', SignalAction::Impression->value)->count(),
            'leanings' => $leanings,
            'ordered_at' => isset($order['at']) ? CarbonImmutable::parse($order['at'])->setTimezone('Europe/Berlin')->format('H:i') : null,
        ];
    }
}

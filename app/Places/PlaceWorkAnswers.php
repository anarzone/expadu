<?php

namespace App\Places;

use App\Enums\SpotFeedbackState;
use App\Models\PlaceWorkAnswer;
use App\Models\Spot;
use App\Models\SpotFeedback;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * "Good for laptop work?" — people's answers and the anonymous place fact made
 * from them (docs/places/design/laptop-answers.md, owner decisions 23 Oct 2026).
 *
 * Only people who marked a place Been there or Saved may answer. The fact shows
 * from 3 real answers by 3 people within 12 months (unweighted); the age
 * weighting (1 up to 6 months, 0.5 to 12) only decides the yes share:
 * ≥ 70% yes, ≤ 30% no, mixed between. Accounts younger than a day are stored
 * but not counted. Nothing about a person leaves this class except totals.
 */
class PlaceWorkAnswers
{
    public const MIN_PEOPLE = 3;

    public const WINDOW_MONTHS = 12;

    public const PRUNE_MONTHS = 18;

    /** Question => the practical fact it becomes. */
    public const QUESTIONS = ['laptop' => 'laptops', 'wifi' => 'internet_access', 'sockets' => 'power_supply', 'quiet' => 'quiet'];

    public function __construct(private PlaceIdentity $identity) {}

    /** Been there or Saved, on this place or any name it's known by. */
    public function canAnswer(User $user, Spot $spot): bool
    {
        return SpotFeedback::query()->where('user_id', $user->id)
            ->whereIn('spot_id', $this->identity->familyIds($spot->id))
            ->where(fn ($q) => $q->where('state', SpotFeedbackState::Saved->value)->orWhereNotNull('been_at'))
            ->exists();
    }

    /** @param array{laptop: string, wifi?: ?string, sockets?: ?string, quiet?: ?string} $answer */
    public function save(User $user, Spot $spot, array $answer): PlaceWorkAnswer
    {
        $canonical = $this->canonical($spot);
        $row = PlaceWorkAnswer::query()->updateOrCreate(
            ['user_id' => $user->id, 'spot_id' => $canonical],
            [
                'laptop' => $answer['laptop'],
                'wifi' => $answer['wifi'] ?? null,
                'sockets' => $answer['sockets'] ?? null,
                'quiet' => $answer['quiet'] ?? null,
                'answered_at' => CarbonImmutable::now(),
            ],
        );
        $this->refresh($canonical);

        return $row;
    }

    public function withdraw(User $user, Spot $spot): void
    {
        $canonical = $this->canonical($spot);
        PlaceWorkAnswer::query()->where('user_id', $user->id)->where('spot_id', $canonical)->delete();
        $this->refresh($canonical);
    }

    public function answerOf(User $user, Spot $spot): ?PlaceWorkAnswer
    {
        return PlaceWorkAnswer::query()->where('user_id', $user->id)->where('spot_id', $this->canonical($spot))->first();
    }

    /** Account deletion: their answers go, and the places' totals are recomputed without them. */
    public function forget(User $user): void
    {
        $spots = PlaceWorkAnswer::query()->where('user_id', $user->id)->pluck('spot_id')->all();
        PlaceWorkAnswer::query()->where('user_id', $user->id)->delete();
        foreach ($spots as $spotId) {
            $this->refresh((int) $spotId);
        }
    }

    /**
     * The totals for one place, per question: null below the threshold.
     *
     * @return array<string, array{value: string, yes: int, total: int}|null>
     */
    public function totals(int $spotId, ?CarbonImmutable $now = null): array
    {
        return $this->totalsMany([$spotId], $now)[$spotId];
    }

    /**
     * totals() for many places in one query (Around here's rows).
     *
     * @param  list<int>  $spotIds  canonical ids
     * @return array<int, array<string, array{value: string, yes: int, total: int}|null>>
     */
    public function totalsMany(array $spotIds, ?CarbonImmutable $now = null): array
    {
        $now ??= CarbonImmutable::now();
        $answers = $spotIds === [] ? collect() : PlaceWorkAnswer::query()
            ->join('users', 'users.id', '=', 'place_work_answers.user_id')
            ->whereIn('place_work_answers.spot_id', $spotIds)
            ->where('place_work_answers.answered_at', '>=', $now->subMonths(self::WINDOW_MONTHS))
            ->where('users.created_at', '<=', $now->subDay())
            ->get(['place_work_answers.*'])
            ->groupBy('spot_id');

        return collect($spotIds)->mapWithKeys(fn (int $id): array => [$id => $this->tally($answers->get($id, collect()), $now)])->all();
    }

    /**
     * @param  Collection<int, PlaceWorkAnswer>  $answers
     * @return array<string, array{value: string, yes: int, total: int}|null>
     */
    private function tally(Collection $answers, CarbonImmutable $now): array
    {
        return collect(self::QUESTIONS)->mapWithKeys(function (string $_fact, string $question) use ($answers, $now): array {
            $given = $answers->filter(fn (PlaceWorkAnswer $a): bool => in_array($a->{$question}, ['yes', 'no'], true));
            if ($given->count() < self::MIN_PEOPLE) {
                return [$question => null];
            }
            $weight = fn (PlaceWorkAnswer $a): float => $a->answered_at->greaterThanOrEqualTo($now->subMonths(6)) ? 1.0 : 0.5;
            $share = $given->filter(fn (PlaceWorkAnswer $a): bool => $a->{$question} === 'yes')->sum($weight) / $given->sum($weight);

            return [$question => [
                'value' => $share >= 0.7 ? 'yes' : ($share <= 0.3 ? 'no' : 'mixed'),
                'yes' => $given->where($question, 'yes')->count(),
                'total' => $given->count(),
            ]];
        })->all();
    }

    /** Writes the place's anonymous totals as its "people" observation. */
    public function refresh(int $spotId): void
    {
        $spot = Spot::query()->find($spotId);
        if (! $spot instanceof Spot) {
            return;
        }
        $practical = [];
        $evidence = [];
        foreach ($this->totals($spotId) as $question => $total) {
            if ($total === null) {
                continue;
            }
            $fact = self::QUESTIONS[$question];
            $practical[$fact] = $fact === 'internet_access' && $total['value'] === 'yes' ? 'wlan' : $total['value'];
            $evidence[$fact] = "{$total['yes']} of {$total['total']} people who've been there said yes (last 12 months)";
        }
        // Skip a write that changes nothing (each one is a new observation).
        $latest = $spot->factObservations()->where('provider', 'people')->orderByDesc('observed_at')->orderByDesc('id')->first();
        $payload = ['practical' => $practical] + ($evidence === [] ? [] : ['evidence' => $evidence]);
        if ($latest === null && $practical === []) {
            return;
        }
        if ($latest !== null && ($latest->payload['practical'] ?? []) == $practical && ($latest->payload['evidence'] ?? []) == ($payload['evidence'] ?? [])) {
            return;
        }
        $at = CarbonImmutable::now()->startOfSecond();
        app(RecordPlaceObservation::class)->record($spot, [
            'provider' => 'people',
            'provider_record_id' => (string) $spot->id,
            'source_url' => null,
            'observed_at' => $at->toIso8601String(),
            'ingestion_key' => 'people:'.$spot->id.':'.$at->format('YmdHis').':'.substr(hash('sha256', json_encode($payload)), 0, 12),
            'payload' => $payload,
        ]);
    }

    /** @return int answers deleted */
    public function prune(): int
    {
        $old = PlaceWorkAnswer::query()->where('answered_at', '<', CarbonImmutable::now()->subMonths(self::PRUNE_MONTHS));
        $spots = (clone $old)->distinct()->pluck('spot_id')->all();
        $deleted = $old->delete();
        foreach ($spots as $spotId) {
            $this->refresh((int) $spotId);
        }

        return $deleted;
    }

    /** The place id answers are kept on: its canonical record. */
    public function canonical(Spot $spot): int
    {
        return (int) ($this->identity->canonicalIds([$spot->id])[$spot->id] ?? $spot->id);
    }
}

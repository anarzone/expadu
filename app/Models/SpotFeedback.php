<?php

namespace App\Models;

use App\Enums\SpotFeedbackState;
use Database\Factories\SpotFeedbackFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Collection;

/**
 * One row per (user, spot) capturing the user's standing relationship to a
 * place — the persistent half of place feedback. The transient ranking signal
 * is emitted separately as a user_event (see IntentWeights); this table powers
 * discovery suppression and the (later) saved/been views.
 *
 * `state` is one choice (Saved, More like this, Not interested) or null;
 * "Been there" is separate (`been_at`, `been_rating`), so a place can be
 * saved and been to, and each clears on its own. A row with neither is deleted.
 */
class SpotFeedback extends Model
{
    /** @use HasFactory<SpotFeedbackFactory> */
    use HasFactory;

    protected $table = 'spot_feedback';

    protected $fillable = ['user_id', 'spot_id', 'state', 'rating', 'been_at', 'been_rating'];

    /** @return Collection<int, SpotFeedback> */
    public static function effectiveForUser(int $userId, ?int $canonicalId = null): Collection
    {
        return self::query()->join('spots', 'spots.id', '=', 'spot_feedback.spot_id')
            ->where('spot_feedback.user_id', $userId)
            ->when($canonicalId !== null, fn ($query) => $query->whereRaw('coalesce(spots.canonical_spot_id, spots.id) = ?', [$canonicalId]))
            ->select('spot_feedback.*')
            ->selectRaw('coalesce(spots.canonical_spot_id, spots.id) as identity_spot_id')
            ->orderByDesc('spot_feedback.updated_at')
            ->orderByRaw('case when spots.canonical_spot_id is null then 0 else 1 end')
            ->orderByDesc('spot_feedback.id')
            ->get()->unique('identity_spot_id')->keyBy('identity_spot_id');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['state' => SpotFeedbackState::class, 'been_at' => 'immutable_datetime'];
    }

    public function been(): bool
    {
        return $this->been_at !== null;
    }

    /**
     * What the place payloads carry: `feedback_state` / `feedback_rating` as
     * older clients read them (Been shows only when no choice is set), plus
     * Been on its own.
     *
     * @return array{feedback_state: ?string, feedback_rating: ?string, feedback_been: bool, feedback_been_rating: ?string}
     */
    public static function forPlace(?self $row): array
    {
        $been = $row?->been() ?? false;

        return [
            'feedback_state' => $row?->state?->value ?? ($been ? SpotFeedbackState::Been->value : null),
            'feedback_rating' => $row?->state === null && $been ? $row->been_rating : null,
            'feedback_been' => $been,
            'feedback_been_rating' => $been ? $row->been_rating : null,
        ];
    }

    /** Hidden from discovery feeds (Today's "open near"): been to, or not for them. */
    public function hiddenFromDiscovery(): bool
    {
        return $this->been() || $this->state === SpotFeedbackState::NotInterested;
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<Spot, $this> */
    public function spot(): BelongsTo
    {
        return $this->belongsTo(Spot::class);
    }
}

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
 */
class SpotFeedback extends Model
{
    /** @use HasFactory<SpotFeedbackFactory> */
    use HasFactory;

    protected $table = 'spot_feedback';

    protected $fillable = ['user_id', 'spot_id', 'state', 'rating'];

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
        return ['state' => SpotFeedbackState::class];
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

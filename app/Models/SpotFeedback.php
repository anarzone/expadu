<?php

namespace App\Models;

use App\Enums\SpotFeedbackState;
use Database\Factories\SpotFeedbackFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

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

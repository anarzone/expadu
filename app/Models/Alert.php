<?php

namespace App\Models;

use App\Enums\AlertType;
use Database\Factories\AlertFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['user_id', 'type', 'subtype', 'group_key', 'occurrence_count', 'severity', 'category', 'lane', 'title', 'body', 'deep_link', 'read_at', 'dismissed_at'])]
class Alert extends Model
{
    /** @use HasFactory<AlertFactory> */
    use HasFactory;

    /** How long a live card keeps coalescing recurrences of the same subject. */
    private const COALESCE_WINDOW_HOURS = 24;

    /**
     * Record an alert, GROUPING recurrences of the same subject onto one card.
     * A weather warning re-emitted every poll, or an ongoing line disruption,
     * updates the existing live card (refreshing its text/severity, bumping it
     * to the top, counting the recurrence) instead of spawning a duplicate.
     * A brand-new subject — or one whose card was dismissed — creates a row.
     *
     * @param  array<string, mixed>  $attributes  the freshly-classified alert fields
     */
    public static function record(int $userId, ?string $groupKey, array $attributes): self
    {
        $existing = $groupKey === null ? null : static::query()
            ->where('user_id', $userId)
            ->where('group_key', $groupKey)
            ->whereNull('dismissed_at')
            ->where('created_at', '>=', now()->subHours(self::COALESCE_WINDOW_HOURS))
            ->latest('id')
            ->first();

        if ($existing !== null) {
            $escalated = self::severityRank($attributes['severity'] ?? 'info') > self::severityRank($existing->severity ?? 'info');

            $existing->fill($attributes);
            $existing->occurrence_count = (int) $existing->occurrence_count + 1;
            // A worse situation than last time re-surfaces as unread; a routine
            // refresh of an already-seen card does not re-nag.
            if ($escalated) {
                $existing->read_at = null;
            }
            $existing->touch();      // bump updated_at so the card sorts to the top
            $existing->save();

            return $existing;
        }

        return static::create([
            'user_id' => $userId,
            'group_key' => $groupKey,
            ...$attributes,
        ]);
    }

    /** Ordering of severities so an escalation can re-surface a read card. */
    private static function severityRank(string $severity): int
    {
        return match ($severity) {
            'danger' => 3,
            'warn' => 2,
            'success', 'info' => 1,
            default => 0,
        };
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => AlertType::class,
            'read_at' => 'datetime',
            'dismissed_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}

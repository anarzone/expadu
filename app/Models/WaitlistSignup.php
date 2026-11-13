<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Database\Factories\WaitlistSignupFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\MassPrunable;
use Illuminate\Database\Eloquent\Model;

/**
 * A city-waitlist entry from the marketing site. Kept only after double
 * opt-in (confirmed_at); unconfirmed rows are consent-pending and must not
 * be mailed beyond the single confirmation message, and model:prune deletes
 * them after a week.
 */
#[Fillable(['email', 'city', 'source'])]
class WaitlistSignup extends Model
{
    /** @use HasFactory<WaitlistSignupFactory> */
    use HasFactory, MassPrunable;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'confirmed_at' => 'datetime',
            'reviewed_at' => 'datetime',
        ];
    }

    /**
     * Unconfirmed after a week: no consent behind it (config/cleanup.php).
     *
     * @return Builder<static>
     */
    public function prunable(): Builder
    {
        return static::query()->whereNull('confirmed_at')
            ->where('created_at', '<', now()->subDays((int) config('cleanup.waitlist.unconfirmed_days')))
            ->where('created_at', '>=', CarbonImmutable::parse((string) config('cleanup.waitlist.from')));
    }

    public function isConfirmed(): bool
    {
        return $this->confirmed_at !== null;
    }
}

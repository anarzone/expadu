<?php

namespace App\Models;

use Database\Factories\WaitlistSignupFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * A city-waitlist entry from the marketing site. Kept only after double
 * opt-in (confirmed_at); unconfirmed rows are consent-pending and must not
 * be mailed beyond the single confirmation message.
 */
#[Fillable(['email', 'city', 'source'])]
class WaitlistSignup extends Model
{
    /** @use HasFactory<WaitlistSignupFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'confirmed_at' => 'datetime',
        ];
    }

    public function isConfirmed(): bool
    {
        return $this->confirmed_at !== null;
    }
}

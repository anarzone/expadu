<?php

namespace App\Models;

use App\ContextEngine\DayImportance;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ComposerWorkspace extends Model
{
    /** Their plans changed: today's and tomorrow's importance is worked out again (DayImportance). */
    protected static function booted(): void
    {
        static::saved(fn (self $workspace) => DayImportance::forget($workspace->user_id));
    }

    protected $fillable = ['user_id', 'revision', 'state'];

    protected $attributes = ['revision' => 0];

    protected function casts(): array
    {
        return ['state' => 'array', 'revision' => 'integer'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}

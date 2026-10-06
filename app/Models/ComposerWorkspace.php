<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ComposerWorkspace extends Model
{
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

<?php

namespace App\Models;

use Database\Factories\BureaucracyCaseMessageFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\MassPrunable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BureaucracyCaseMessage extends Model
{
    /** @use HasFactory<BureaucracyCaseMessageFactory> */
    use HasFactory;

    use MassPrunable;

    /** @var list<string> */
    protected $fillable = [
        'case_id',
        'role',
        'content',
        'operation',
        'prompt_version',
        'expires_at',
    ];

    /** @var list<string> */
    protected $hidden = [
        'content',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'content' => 'encrypted',
            'expires_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<BureaucracyCase, $this> */
    public function case(): BelongsTo
    {
        return $this->belongsTo(BureaucracyCase::class, 'case_id');
    }

    public function prunable(): Builder
    {
        return static::query()->where('expires_at', '<=', now());
    }
}

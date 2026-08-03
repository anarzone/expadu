<?php

namespace App\Models;

use Database\Factories\BureaucracyCaseQuestionFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BureaucracyCaseQuestion extends Model
{
    /** @use HasFactory<BureaucracyCaseQuestionFactory> */
    use HasFactory;

    /** @var list<string> */
    protected $fillable = [
        'case_id',
        'fact_key',
        'attempt',
        'asked_at',
        'answered_at',
        'outcome',
    ];

    /** @var array<string, mixed> */
    protected $attributes = [
        'attempt' => 1,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'attempt' => 'integer',
            'asked_at' => 'datetime',
            'answered_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<BureaucracyCase, $this> */
    public function case(): BelongsTo
    {
        return $this->belongsTo(BureaucracyCase::class, 'case_id');
    }
}

<?php

namespace App\Models;

use Database\Factories\BureaucracyCaseQuestionFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BureaucracyCaseQuestion extends Model
{
    protected $dateFormat = 'Y-m-d H:i:sP';

    protected $hidden = ['offer_token', 'answer_fingerprint'];

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
        'session_id', 'request_id', 'protocol_version', 'dependency_token', 'fact_revision', 'offer_token',
        'offer_expires_at', 'malformed_attempts', 'answer_fingerprint', 'answer_fact_id', 'answer_fact_revision',
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
            'fact_revision' => 'integer', 'answer_fact_revision' => 'integer', 'malformed_attempts' => 'integer',
            'offer_token' => 'encrypted', 'offer_expires_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<BureaucracyCase, $this> */
    public function case(): BelongsTo
    {
        return $this->belongsTo(BureaucracyCase::class, 'case_id');
    }
}

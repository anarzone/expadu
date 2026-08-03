<?php

namespace App\Models;

use Database\Factories\BureaucracyPlanSnapshotFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BureaucracyPlanSnapshot extends Model
{
    /** @use HasFactory<BureaucracyPlanSnapshotFactory> */
    use HasFactory;

    /** @var list<string> */
    protected $fillable = [
        'case_id',
        'fact_version',
        'rules_hash',
        'rule_versions',
        'coverage_state',
        'sections',
        'unresolved_facts',
        'reassessment_at',
        'generated_at',
        'superseded_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'fact_version' => 'integer',
            'rule_versions' => 'array',
            'sections' => 'array',
            'unresolved_facts' => 'array',
            'reassessment_at' => 'datetime',
            'generated_at' => 'datetime',
            'superseded_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<BureaucracyCase, $this> */
    public function case(): BelongsTo
    {
        return $this->belongsTo(BureaucracyCase::class, 'case_id');
    }
}

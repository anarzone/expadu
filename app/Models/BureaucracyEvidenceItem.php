<?php

namespace App\Models;

use App\Models\Concerns\UsesUtcTimestamps;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class BureaucracyEvidenceItem extends Model
{
    use UsesUtcTimestamps;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = ['id', 'person_id', 'version', 'status', 'details'];

    protected $hidden = ['details'];

    protected function casts(): array
    {
        return ['version' => 'integer', 'details' => 'encrypted:array'];
    }

    public function person(): BelongsTo
    {
        return $this->belongsTo(BureaucracyPerson::class, 'person_id');
    }

    public function events(): HasMany
    {
        return $this->hasMany(BureaucracyEvidenceEvent::class, 'evidence_id');
    }
}

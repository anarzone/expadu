<?php

namespace App\Models;

use App\Models\Concerns\UsesUtcTimestamps;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class BureaucracyProcess extends Model
{
    use UsesUtcTimestamps;

    protected $fillable = ['case_id', 'definition_id', 'topic', 'jurisdiction', 'occurrence_key', 'context_id',
        'catalogue_hash', 'version', 'state', 'step_definitions'];

    protected $hidden = ['state'];

    protected function casts(): array
    {
        return ['version' => 'integer', 'state' => 'encrypted:array', 'step_definitions' => 'array'];
    }

    public function dossier(): BelongsTo
    {
        return $this->belongsTo(BureaucracyCase::class, 'case_id');
    }

    public function events(): HasMany
    {
        return $this->hasMany(BureaucracyProcessEvent::class, 'process_id');
    }
}

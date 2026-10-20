<?php

namespace App\Models;

use App\Models\Concerns\UsesUtcTimestamps;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class BureaucracyPerson extends Model
{
    use UsesUtcTimestamps;

    protected $fillable = ['workspace_id', 'account_user_id', 'display_label', 'kind', 'record_status', 'record_version', 'erased_at'];

    protected $hidden = ['display_label'];

    protected $attributes = ['kind' => 'adult', 'record_status' => 'active', 'record_version' => 1];

    protected function casts(): array
    {
        return ['display_label' => 'encrypted', 'record_version' => 'integer', 'erased_at' => 'immutable_datetime'];
    }

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(BureaucracyWorkspace::class, 'workspace_id');
    }

    public function workspaces(): BelongsToMany
    {
        return $this->belongsToMany(BureaucracyWorkspace::class, 'bureaucracy_workspace_people', 'person_id', 'workspace_id')->withTimestamps();
    }

    public function dossier(): HasOne
    {
        return $this->hasOne(BureaucracyCase::class, 'person_id');
    }

    public function grants(): HasMany
    {
        return $this->hasMany(BureaucracyAccessGrant::class, 'person_id');
    }
}

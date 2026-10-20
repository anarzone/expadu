<?php

namespace App\Models;

use App\Models\Concerns\UsesUtcTimestamps;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class BureaucracyWorkspace extends Model
{
    use UsesUtcTimestamps;

    protected $fillable = ['owner_user_id'];

    public function people(): BelongsToMany
    {
        return $this->belongsToMany(BureaucracyPerson::class, 'bureaucracy_workspace_people', 'workspace_id', 'person_id')->withTimestamps();
    }
}

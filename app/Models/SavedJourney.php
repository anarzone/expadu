<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** A reusable request. Opening it always asks the journey provider for fresh routes. */
class SavedJourney extends Model
{
    protected $fillable = ['user_id', 'fingerprint', 'title', 'request'];

    protected function casts(): array
    {
        return ['request' => 'array'];
    }

    public function toShared(): array
    {
        return ['id' => $this->id, 'title' => $this->title, 'request' => $this->request];
    }
}

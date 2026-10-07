<?php

namespace App\Models;

use App\Models\Concerns\UsesUtcTimestamps;
use Illuminate\Database\Eloquent\Model;
use LogicException;

class BureaucracyCatalogueRelease extends Model
{
    use UsesUtcTimestamps;

    protected $fillable = ['content_hash', 'schema_version', 'artifact'];

    protected function casts(): array
    {
        return ['artifact' => 'array'];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Catalogue releases are immutable. Stage a new release.'));
        static::deleting(fn () => throw new LogicException('Retain catalogue releases referenced by history.'));
    }
}

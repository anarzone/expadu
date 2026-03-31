<?php

namespace App\Models;

use Database\Factories\CityNewsFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['title', 'summary', 'source', 'source_url', 'category', 'relevance', 'affected_lines', 'severity', 'published_at', 'expires_at'])]
class CityNews extends Model
{
    /** @use HasFactory<CityNewsFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'published_at' => 'datetime',
            'expires_at' => 'datetime',
            'affected_lines' => 'array',
        ];
    }
}

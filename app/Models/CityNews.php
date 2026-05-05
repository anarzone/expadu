<?php

namespace App\Models;

use App\Models\Concerns\HasEmbedding;
use Database\Factories\CityNewsFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['title', 'summary', 'source', 'source_url', 'category', 'relevance', 'affected_lines', 'severity', 'published_at', 'expires_at'])]
class CityNews extends Model
{
    /** @use HasFactory<CityNewsFactory> */
    use HasEmbedding, HasFactory;

    public function embeddingText(): string
    {
        return trim(implode(' ', array_filter([
            $this->title,
            $this->category,
            $this->relevance,
            $this->summary,
        ])));
    }

    protected function casts(): array
    {
        return [
            'published_at' => 'datetime',
            'expires_at' => 'datetime',
            'affected_lines' => 'array',
        ];
    }
}

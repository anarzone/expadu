<?php

namespace App\Models;

use App\Models\Concerns\HasEmbedding;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable([
    'name', 'category', 'subcategory', 'emoji', 'description',
    'address', 'lat', 'lng', 'phone', 'website',
    'languages', 'insurance_accepted', 'opening_hours',
    'source', 'osm_id', 'is_verified', 'accepts_new',
    'rating', 'review_count', 'quality_score',
])]
class Service extends Model
{
    use HasEmbedding;

    public function embeddingText(): string
    {
        $langs = is_array($this->languages) ? implode(' ', $this->languages) : '';

        return trim(implode(' ', array_filter([
            $this->name,
            $this->category,
            $this->subcategory,
            $langs,
            $this->description,
        ])));
    }

    protected function casts(): array
    {
        return [
            'lat' => 'float',
            'lng' => 'float',
            'languages' => 'array',
            'insurance_accepted' => 'array',
            'opening_hours' => 'array',
            'is_verified' => 'boolean',
            'accepts_new' => 'boolean',
            'rating' => 'float',
            'quality_score' => 'float',
        ];
    }
}

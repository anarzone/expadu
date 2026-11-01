<?php

namespace App\Models;

use App\Personalisation\Pseudonym;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * A person's taste in a few plain sentences plus tags, written nightly from
 * computed facts (taste:profiles). Keyed by the personalisation pseudonym.
 *
 * @property string $subject_key
 * @property array<string, mixed> $facts
 * @property string $facts_hash
 * @property list<string> $sentences
 * @property list<string> $tags
 * @property list<string>|null $edited_sentences
 * @property list<string> $hidden_tags
 */
#[Fillable(['subject_key', 'facts', 'facts_hash', 'sentences', 'tags', 'edited_sentences', 'hidden_tags', 'model', 'prompt_version', 'generated_at'])]
class TasteProfile extends Model
{
    protected $attributes = ['hidden_tags' => '[]'];

    protected function casts(): array
    {
        return [
            'facts' => 'array',
            'sentences' => 'array',
            'tags' => 'array',
            'edited_sentences' => 'array',
            'hidden_tags' => 'array',
            'generated_at' => 'immutable_datetime',
        ];
    }

    public static function for(User $user): ?self
    {
        return self::query()->where('subject_key', Pseudonym::for($user))->first();
    }

    /** The person's own sentences win over the model's. @return list<string> */
    public function shownSentences(): array
    {
        return $this->edited_sentences !== null ? array_values($this->edited_sentences) : array_values($this->sentences ?? []);
    }

    /** @return list<string> */
    public function visibleTags(): array
    {
        return array_values(array_diff($this->tags ?? [], $this->hidden_tags ?? []));
    }

    /** @return array{sentences: list<string>, tags: list<string>, hidden_tags: list<string>, edited: bool, generated_at: string|null} */
    public function toApi(): array
    {
        return [
            'sentences' => $this->shownSentences(),
            'tags' => $this->visibleTags(),
            'hidden_tags' => array_values($this->hidden_tags ?? []),
            'edited' => $this->edited_sentences !== null,
            'generated_at' => $this->generated_at?->toIso8601String(),
        ];
    }
}

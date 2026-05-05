<?php

namespace App\Observers;

use App\Models\Concerns\HasEmbedding;
use App\Services\EmbeddingService;
use Illuminate\Database\Eloquent\Model;

/**
 * Re-embeds a content row whenever its source text changes.
 *
 * The trait's `refreshEmbedding()` short-circuits when `embedding_hash`
 * already matches sha256(text), so this observer can fire on every save
 * without doing redundant sidecar work.
 *
 * Sidecar errors (network, model overload) leave embedding as-is and do
 * NOT block the save — see EmbeddingService docblock.
 */
class EmbeddableObserver
{
    public function __construct(private EmbeddingService $service) {}

    public function saved(Model $model): void
    {
        if (! in_array(HasEmbedding::class, class_uses_recursive($model), true)) {
            return;
        }

        /** @var Model&HasEmbedding $model */
        $model->refreshEmbedding($this->service);
    }
}

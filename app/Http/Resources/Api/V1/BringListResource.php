<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * What to bring to a Paperwork appointment (App\Home\BringList): the process's required
 * documents, how many are ready, and each one's reviewed note.
 */
class BringListResource extends JsonResource
{
    public static $wrap = null;

    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            /** @var string|null */
            'title' => $this->resource['title'] ?? null,
            'ready' => (int) $this->resource['ready'],
            'total' => (int) $this->resource['total'],
            /** @var array<int, array{id: string, title: string, ready: bool, note: string|null}> */
            'documents' => array_map(fn (array $document) => [
                'id' => (string) $document['id'],
                'title' => (string) $document['title'],
                'ready' => (bool) $document['ready'],
                'note' => $document['note'],
            ], $this->resource['documents']),
        ];
    }
}

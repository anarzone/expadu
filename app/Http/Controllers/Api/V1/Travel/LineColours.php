<?php

namespace App\Http\Controllers\Api\V1\Travel;

use App\Services\KvbLineColors;
use Illuminate\Http\JsonResponse;

/** A list of stations with each one's lines in KVB's own colours, for the app's line badges. */
final class LineColours
{
    public static function add(JsonResponse $response, string $key): JsonResponse
    {
        $data = $response->getData(true);
        if (! is_array($data[$key] ?? null)) {
            return $response;
        }
        $data[$key] = array_map(fn (array $station) => [
            ...$station,
            'colors' => collect($station['lines'] ?? [])->mapWithKeys(fn ($line) => [(string) $line => KvbLineColors::for((string) $line)])->all(),
        ], $data[$key]);

        return $response->setData($data);
    }
}

<?php

namespace App\Casts;

use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Crypt;
use JsonException;

/**
 * Keeps the queryable JSONB column opaque while storing the real fact value
 * as authenticated ciphertext. The legacy fallback is used only while a
 * deployment migration is backfilling existing rows.
 *
 * @implements CastsAttributes<mixed, mixed>
 */
class EncryptedCaseFactValue implements CastsAttributes
{
    /**
     * @param  array<string, mixed>  $attributes
     *
     * @throws JsonException
     */
    public function get(Model $model, string $key, mixed $value, array $attributes): mixed
    {
        $ciphertext = $attributes['encrypted_value'] ?? null;

        if (is_string($ciphertext) && $ciphertext !== '') {
            return json_decode(Crypt::decryptString($ciphertext), true, 512, JSON_THROW_ON_ERROR);
        }

        if (! is_string($value)) {
            return $value;
        }

        return json_decode($value, true, 512, JSON_THROW_ON_ERROR);
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @return array{value: string, encrypted_value: string}
     *
     * @throws JsonException
     */
    public function set(Model $model, string $key, mixed $value, array $attributes): array
    {
        return [
            'value' => json_encode(['protected' => true], JSON_THROW_ON_ERROR),
            'encrypted_value' => Crypt::encryptString(json_encode($value, JSON_THROW_ON_ERROR)),
        ];
    }
}

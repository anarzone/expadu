<?php

namespace App\Bureaucracy\Catalogue;

use DomainException;

final class CatalogueHash
{
    public static function of(array $value): string
    {
        return hash('sha256', json_encode(self::canonical($value), JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION));
    }

    private static function canonical(array $value): array
    {
        if (! array_is_list($value)) {
            ksort($value);
        }
        foreach ($value as $key => $item) {
            if (is_array($item)) {
                $value[$key] = self::canonical($item);
            } elseif (! is_scalar($item) && $item !== null) {
                throw new DomainException('Catalogue artifacts contain only scalar values and arrays.');
            }
        }

        return $value;
    }
}

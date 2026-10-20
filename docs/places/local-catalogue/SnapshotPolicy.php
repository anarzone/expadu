<?php

declare(strict_types=1);

final class SnapshotPolicy
{
    public static function schema(): array
    {
        return json_decode(file_get_contents(__DIR__.'/schema.json'), true, flags: JSON_THROW_ON_ERROR);
    }

    public static function allowsTable(string $table): bool
    {
        return array_key_exists($table, self::schema());
    }

    public static function columns(string $table, array $columns): array
    {
        $schema = self::schema();
        if (! isset($schema[$table]) || array_diff($columns, array_column($schema[$table], 'column_name')) !== []) {
            throw new DomainException('Table or column is outside the catalogue snapshot policy.');
        }

        return $columns;
    }

    public static function isPlaceAttachment(array $row, array $placeIds): bool
    {
        return ($row['mediable_type'] ?? null) === 'App\\Models\\Spot'
            && isset($placeIds[$row['mediable_id'] ?? 0]);
    }

    public static function sanitize(string $table, array $row): array
    {
        if (! self::allowsTable($table)) {
            throw new DomainException('Private table cannot be sanitized into scope.');
        }
        if (array_key_exists('actor', $row) && $row['actor'] !== null) {
            $row['actor'] = 'local-catalogue-review';
        }
        foreach (['snapshot', 'match_evidence', 'metadata'] as $field) {
            if (isset($row[$field])) {
                $decoded = json_decode($row[$field], false, flags: JSON_THROW_ON_ERROR);
                $cleaned = self::scrubIdentities($decoded);
                if ($cleaned !== $decoded) {
                    $row[$field] = json_encode($cleaned, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
                }
            }
        }

        return $row;
    }

    private static function scrubIdentities(mixed $value): mixed
    {
        if (is_object($value)) {
            $result = clone $value;
            foreach (get_object_vars($result) as $key => $nested) {
                $key = (string) $key;
                if (in_array(strtolower($key), ['actor', 'reviewer', 'reviewed_by', 'created_by', 'updated_by'], true)) {
                    $result->$key = $nested === null ? null : 'local-catalogue-review';
                } elseif (in_array(strtolower($key), ['user_id', 'reviewer_id', 'actor_id'], true)) {
                    $result->$key = null;
                } elseif (preg_match('/^(password|secret|api_key|access_token|refresh_token|authorization)$/i', $key)) {
                    unset($result->$key);
                } else {
                    $result->$key = self::scrubIdentities($nested);
                }
            }

            return $result == $value ? $value : $result;
        }

        return is_array($value) ? array_map(self::scrubIdentities(...), $value) : $value;
    }
}

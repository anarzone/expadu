<?php

declare(strict_types=1);

final class HistoricalCatalogueSnapshot
{
    public const SHA256 = 'e46d38199007add85a73e4901015cad8e7ae1b1282909055194435dd4dfe947d';

    public static function read(string $path): array
    {
        if (! hash_equals(self::SHA256, hash_file('sha256', $path))) {
            throw new DomainException('Historical snapshot checksum differs.');
        }
        $doc = json_decode(file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
        $schema = json_decode(file_get_contents(__DIR__.'/historical-schema.json'), true, flags: JSON_THROW_ON_ERROR);
        if ($doc['columns'] !== $schema || ! $doc['transaction_read_only'] || $doc['staging_changed'] || $doc['production_changed']) {
            throw new DomainException('Historical snapshot scope differs.');
        }

        return $doc;
    }

    public static function remap(array $tables, int $offset): array
    {
        $fields = [
            'veedels' => [],
            'spots' => ['id', 'parent_spot_id', 'canonical_spot_id', 'destination_spot_id', 'destination_reviewed_parent_id'],
            'park_areas' => ['parent_spot_id'],
            'place_fact_observations' => ['spot_id'],
            'place_fact_corrections' => ['spot_id'],
            'place_fact_revisions' => [],
            'place_reconciliations' => ['alias_spot_id', 'canonical_spot_id'],
            'place_destination_reviews' => ['spot_id', 'destination_spot_id'],
            'media_assets' => [],
            'media_attachments' => ['mediable_id'],
        ];
        if ($offset < 1 || array_diff(array_keys($tables), array_keys($fields)) !== []) {
            throw new DomainException('Private or unknown table, or unsafe ID offset.');
        }
        $ids = [];
        foreach ($tables['spots'] ?? [] as $row) {
            if (! ctype_digit((string) $row['id']) || (int) $row['id'] < 1 || isset($ids[$row['id']])) {
                throw new DomainException('Duplicate or invalid place identity.');
            }
            $ids[$row['id']] = (string) ((int) $row['id'] + $offset);
        }
        foreach ($tables as $table => &$rows) {
            foreach ($rows as &$row) {
                if ($table === 'media_attachments' && $row['mediable_type'] !== 'App\\Models\\Spot') {
                    throw new DomainException('Only original place attachments are allowed.');
                }
                foreach ($fields[$table] as $field) {
                    if (isset($row[$field])) {
                        if (! isset($ids[$row[$field]])) {
                            throw new DomainException('A place relationship points outside the captured catalogue.');
                        }
                        $row[$field] = $ids[$row[$field]];
                    }
                }
            }
            unset($row);
        }
        unset($rows);

        return $tables;
    }
}

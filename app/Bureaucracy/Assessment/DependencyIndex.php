<?php

namespace App\Bureaucracy\Assessment;

final class DependencyIndex
{
    private array $entries = [];

    public function add(string $key, string $processId, string $variantId, int $priority): void
    {
        $this->entries[$key] ??= ['fact_key' => $key, 'process_ids' => [], 'variant_ids' => [], 'priority' => $priority];
        $entry = &$this->entries[$key];
        $entry['process_ids'] = array_values(array_unique([...$entry['process_ids'], $processId]));
        $entry['variant_ids'] = array_values(array_unique([...$entry['variant_ids'], $variantId]));
        $entry['priority'] = max($entry['priority'], $priority);
    }

    public function toArray(): array
    {
        $result = array_values($this->entries);
        usort($result, fn ($one, $two) => $two['priority'] <=> $one['priority'] ?: count($two['process_ids']) <=> count($one['process_ids']) ?: strcmp($one['fact_key'], $two['fact_key']));

        return $result;
    }
}

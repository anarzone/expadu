<?php

namespace App\Bureaucracy\ReadModel;

/**
 * Display order and titles taken from the reviewed catalogue release. Order is the reviewed
 * `position` from the structural process map, never an alphabetical sort of identifiers.
 * Releases compiled before positions existed keep their stored order and report null.
 */
final readonly class CatalogueOrder
{
    /** @var array<string, int|null> */
    private array $positions;

    /** @var array<string, list<array>> */
    private array $variantsByDefinition;

    public function __construct(array $catalogue)
    {
        $positions = [];
        $variants = [];
        foreach ($catalogue['definitions'] ?? [] as $definition) {
            $variants[$definition['id']] = $definition['variants'];
            foreach ($definition['variants'] as $variant) {
                $positions[$variant['id']] = is_int($variant['position'] ?? null) ? $variant['position'] : null;
            }
        }
        $this->positions = $positions;
        $this->variantsByDefinition = $variants;
    }

    public function position(string $unitId): ?int
    {
        return $this->positions[$unitId] ?? null;
    }

    /** The reviewed title of the first step (by reviewed position) among the given variants or guidance rows. */
    public function primaryTitle(array $variants): ?string
    {
        $primary = null;
        foreach ($variants as $variant) {
            if (! is_string($variant['title'] ?? null) || trim($variant['title']) === '') {
                continue;
            }
            if ($primary === null || $this->compare($variant['id'], $primary['id']) < 0) {
                $primary = $variant;
            }
        }

        return $primary['title'] ?? null;
    }

    /** Title for a retained occurrence: only the current release's reviewed units, otherwise unknown. */
    public function definitionTitle(string $definitionId): ?string
    {
        return $this->primaryTitle($this->variantsByDefinition[$definitionId] ?? []);
    }

    /** @return array<string, array> step_id => current reviewed variant */
    public function variantsByStep(string $definitionId): array
    {
        return array_column($this->variantsByDefinition[$definitionId] ?? [], null, 'step_id');
    }

    public function compare(string $one, string $two): int
    {
        return ($this->position($one) ?? PHP_INT_MAX) <=> ($this->position($two) ?? PHP_INT_MAX);
    }
}

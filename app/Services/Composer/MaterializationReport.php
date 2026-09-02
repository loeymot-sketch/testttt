<?php

namespace App\Services\Composer;

/**
 * Compte rendu d'une matérialisation : ce qui a été créé, mis à jour, désactivé — par produit.
 * Sert la commande Artisan (sortie lisible), l'API (JSON) et les tests (assertions exactes).
 */
final class MaterializationReport
{
    public bool $dryRun = false;

    public int $itemsTouched = 0;

    public int $stepsBound = 0;

    /** @var array<string, int> */
    public array $counts = [
        'variations_created' => 0,
        'variations_updated' => 0,
        'variations_deactivated' => 0,
        'extras_created' => 0,
        'extras_updated' => 0,
        'extras_deactivated' => 0,
        'addons_created' => 0,
        'addons_removed' => 0,
        'attributes_created' => 0,
    ];

    /** @var array<int, string> */
    public array $lines = [];

    /** @var array<int, string> */
    public array $warnings = [];

    public function bump(string $key, int $by = 1): void
    {
        $this->counts[$key] = ($this->counts[$key] ?? 0) + $by;
    }

    public function line(string $text): void
    {
        $this->lines[] = $text;
    }

    public function warn(string $text): void
    {
        $this->warnings[] = $text;
    }

    public function changes(): int
    {
        return array_sum($this->counts) + $this->stepsBound;
    }

    public function hasChanges(): bool
    {
        return $this->changes() > 0;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'dry_run' => $this->dryRun,
            'items_touched' => $this->itemsTouched,
            'steps_bound' => $this->stepsBound,
            'counts' => $this->counts,
            'changes' => $this->changes(),
            'lines' => $this->lines,
            'warnings' => $this->warnings,
        ];
    }
}

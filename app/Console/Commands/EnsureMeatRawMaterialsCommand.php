<?php

namespace App\Console\Commands;

use App\Models\RawMaterial;
use App\Services\Kitchen\MeatMaterialResolver;
use Illuminate\Console\Command;

/**
 * [STOCK-VIANDE 2026-08-06 owner] Crée les matières premières des viandes qui étaient vendues
 * sans exister en stock.
 *
 * Mesuré sur 30 jours avant correction : 176 pièces de viande vendues n'avaient AUCUNE matière
 * où être décomptées — Mexicanos, Tenders, Nuggets, Fricadelle, chicken burger et poisson pané
 * étaient absents de `raw_materials`.
 *
 * COMPTÉES EN PIÈCES, volontairement : une pièce se compte sans connaître son poids. La
 * question de l'owner (« combien on a vraiment sorti ») trouve donc une réponse exacte tout de
 * suite ; le poids unitaire, lui, ne sert qu'à valoriser en grammes et pourra être saisi plus
 * tard sans rien changer au code.
 *
 * Idempotente : relancer ne crée aucun doublon et ne touche pas les matières existantes.
 */
class EnsureMeatRawMaterialsCommand extends Command
{
    protected $signature = 'stock:ensure-meat-materials {--branch=1} {--dry-run}';

    protected $description = 'Crée les matières premières des viandes vendues sans stock (comptées en pièces)';

    public function handle(): int
    {
        $branchId = (int) $this->option('branch');
        $dry = (bool) $this->option('dry-run');

        $existantes = RawMaterial::query()
            ->where('branch_id', $branchId)
            ->pluck('name')
            ->map(static fn ($n) => mb_strtolower(trim((string) $n)))
            ->all();

        $creees = 0;
        foreach (MeatMaterialResolver::MATIERES_EN_PIECES as $nom) {
            if (in_array(mb_strtolower($nom), $existantes, true)) {
                $this->line("  = {$nom} — existe déjà");

                continue;
            }

            if ($dry) {
                $this->line("  + {$nom} — SERAIT créée (unité : pièce)");
                $creees++;

                continue;
            }

            RawMaterial::query()->create([
                'branch_id' => $branchId,
                'name' => $nom,
                'unit' => 'piece',
                'piece_weight_g' => null,   // à saisir par l'owner ; inutile pour COMPTER
                'is_active' => true,
            ]);
            $this->info("  + {$nom} — créée (unité : pièce)");
            $creees++;
        }

        $this->newLine();
        $this->line($dry
            ? "À BLANC : {$creees} matière(s) seraient créées."
            : "{$creees} matière(s) créée(s).");

        // Ce qui reste à saisir pour valoriser en grammes — dit explicitement plutôt que laissé
        // à découvrir dans un rapport de coût qui afficherait zéro.
        $sansPoids = RawMaterial::query()
            ->where('branch_id', $branchId)
            ->whereIn('name', array_values(MeatMaterialResolver::SYMBOLE_VERS_MATIERE))
            ->get()
            ->filter(static fn ($m) => (float) ($m->piece_weight_g ?? 0) <= 0)
            ->pluck('name');

        if ($sansPoids->isNotEmpty()) {
            $this->newLine();
            $this->warn('Poids unitaire encore absent (comptage en pièces OK, valorisation en grammes impossible) :');
            foreach ($sansPoids as $n) {
                $this->line("  · {$n}");
            }
        }

        return self::SUCCESS;
    }
}

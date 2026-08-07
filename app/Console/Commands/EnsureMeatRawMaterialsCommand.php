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
        $ajustees = 0;
        foreach (MeatMaterialResolver::MATIERES_A_CREER as $nom => [$unit, $poids]) {
            $libelle = $unit === 'g' ? "{$poids} g l'unité comptée" : 'unité : pièce';

            $existante = RawMaterial::query()->where('branch_id', $branchId)
                ->whereRaw('LOWER(name) = ?', [mb_strtolower($nom)])->first();

            if ($existante !== null) {
                // Idempotente MAIS pas inerte : si l'unité ou le poids a été précisé depuis
                // (le poulet est passé en portions de 200 g), on aligne la matière existante —
                // sinon la conversion resterait impossible et la consommation muette.
                $aligner = mb_strtolower((string) $existante->unit) !== $unit
                    || (float) ($existante->piece_weight_g ?? 0) !== (float) ($poids ?? 0);

                if (! $aligner) {
                    $this->line("  = {$nom} — déjà conforme ({$libelle})");

                    continue;
                }

                if ($dry) {
                    $this->line("  ~ {$nom} — SERAIT alignée sur {$libelle}");
                } else {
                    $existante->update(['unit' => $unit, 'piece_weight_g' => $poids]);
                    $this->info("  ~ {$nom} — alignée sur {$libelle}");
                }
                $ajustees++;

                continue;
            }

            if ($dry) {
                $this->line("  + {$nom} — SERAIT créée ({$libelle})");
                $creees++;

                continue;
            }

            RawMaterial::query()->create([
                'branch_id' => $branchId,
                'name' => $nom,
                'unit' => $unit,
                'piece_weight_g' => $poids,
                'is_active' => true,
            ]);
            $this->info("  + {$nom} — créée ({$libelle})");
            $creees++;
        }

        $this->newLine();
        $this->line($dry
            ? "À BLANC : {$creees} création(s), {$ajustees} alignement(s)."
            : "{$creees} matière(s) créée(s), {$ajustees} alignée(s).");

        // Ce qui reste à saisir pour valoriser en grammes — dit explicitement plutôt que laissé
        // à découvrir dans un rapport de coût qui afficherait zéro.
        $sansPoids = RawMaterial::query()
            ->where('branch_id', $branchId)
            ->whereIn('name', array_values(MeatMaterialResolver::SYMBOLE_VERS_MATIERE))
            ->get()
            // Seules les matières en GRAMMES ont besoin d'un poids : une pièce se compte telle
            // quelle, et l'alerter serait un faux signal répété à chaque exécution.
            ->filter(static fn ($m) => mb_strtolower((string) $m->unit) === 'g' && (float) ($m->piece_weight_g ?? 0) <= 0)
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

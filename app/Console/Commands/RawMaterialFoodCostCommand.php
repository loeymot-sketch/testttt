<?php

namespace App\Console\Commands;

use App\Enums\Status;
use App\Support\GeneratedReportPath;
use App\Models\Item;
use App\Services\RawMaterials\FoodCostService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

/**
 * [ARCH_STOCK_INTELLIGENT_BOM_2026-07-23 / P2b — B5] Rapport food cost owner.
 *
 * Pour chaque produit ACTIF : prix de vente vs coût matière (Σ recette.qty ×
 * avg_cost), marge, marge %, statut. Écrit un Markdown lisible owner dans
 * reports/goal-mega-2026-07-22/FOOD_COST_REPORT.md.
 *
 * ─── Coût inconnu = ATTENDU ────────────────────────────────────────────────
 *  Tant que l'owner n'a pas saisi les prix d'achat (factures P3), `avg_cost` est
 *  NULL → le produit s'affiche « ⏳ en attente prix d'achat (factures P3) », coût
 *  partiel visible, marge non calculée. Ce n'est PAS une erreur — c'est l'état
 *  normal avant la saisie des factures (délégué au service, NULL-safe).
 *
 * NF525 : lecture seule (aucune écriture fiscale). Hard-scope branch_id=1 côté
 * service. Idempotent : réécrit le même rapport à chaque run.
 */
class RawMaterialFoodCostCommand extends Command
{
    protected $signature = 'raw-materials:food-cost
                            {--dry-run : Calculer et afficher sans écrire le fichier}
                            {--out= : Écrire le rapport ailleurs (chemin absolu, ou relatif à la racine du dépôt)}';

    protected $description = 'Génère le rapport food cost (prix vente vs coût matière, marge) par produit. Les coûts inconnus (avg_cost NULL) = « en attente prix d\'achat », attendu tant que les factures ne sont pas saisies.';

    public const REPORT_PATH = 'reports/goal-mega-2026-07-22/FOOD_COST_REPORT.md';

    /** Libellé partagé pour un coût non encore connu (avg_cost NULL). */
    private const PENDING_LABEL = '⏳ en attente prix d\'achat (factures P3)';

    public function handle(FoodCostService $service): int
    {
        $dry = (bool) $this->option('dry-run');

        $items = Item::query()
            ->where('status', Status::ACTIVE)
            ->with('category:id,name')
            ->orderBy('item_category_id')
            ->orderBy('name')
            ->get(['id', 'name', 'price', 'item_category_id']);

        $groups = [];   // catName => [row, ...]
        $complete = 0;
        $pending = 0;
        $noRecipe = 0;

        foreach ($items as $item) {
            $cost = $service->costForProduct($item);
            $catName = $item->category->name ?? 'Sans catégorie';

            if (! $cost['has_recipe']) {
                $noRecipe++;
            } elseif ($cost['has_unknown_cost']) {
                $pending++;
            } else {
                $complete++;
            }

            $groups[$catName][] = $cost;
        }

        $md = $this->render($groups, $complete, $pending, $noRecipe, $items->count());

        // [SUPERVISION 2026-08-22] En `testing`, le rapport atterrit sous storage/ : la suite
        // de tests réécrivait ce fichier du dépôt avec des données de FIXTURE
        // (« 1 produits actifs — Cayenne 10,00 € »). Voir App\Support\GeneratedReportPath.
        $path = GeneratedReportPath::resolve(self::REPORT_PATH, $this->option('out'));
        if (! $dry) {
            File::ensureDirectoryExists(dirname($path));
            File::put($path, $md);
        }

        $this->info(($dry ? '[dry-run] ' : '')."Food cost — {$items->count()} produits : "
            ."{$complete} complets, {$pending} en attente prix, {$noRecipe} sans recette."
            .($dry ? '' : " Rapport : ".$path));

        return self::SUCCESS;
    }

    /**
     * @param  array<string, array<int, array<string, mixed>>>  $groups
     */
    private function render(array $groups, int $complete, int $pending, int $noRecipe, int $total): string
    {
        $date = now()->format('Y-m-d H:i');
        $md = [];
        $md[] = '# Rapport Food Cost — coût matière & marge par produit (Le Cayenne)';
        $md[] = '';
        $md[] = "> Généré le {$date} par `php artisan raw-materials:food-cost`.";
        $md[] = "> {$total} produits actifs — **{$complete} complets**, **{$pending} en attente prix d'achat**, "
            ."**{$noRecipe} sans recette paramétrée**.";
        $md[] = '>';
        $md[] = '> **Coût matière** = Σ (quantité recette × prix d\'achat moyen de la matière). Les produits';
        $md[] = '> marqués « '.self::PENDING_LABEL.' » ont au moins une matière dont le prix d\'achat n\'est';
        $md[] = '> pas encore saisi (avg_cost NULL) — **c\'est attendu** tant que les factures (P3) ne sont pas';
        $md[] = '> entrées. Leur marge n\'est PAS calculée pour ne pas afficher un coût faux.';
        $md[] = '';
        $md[] = 'Légende statut : ✅ complet · ⏳ en attente prix d\'achat (coût partiel) · — recette non paramétrée.';
        $md[] = '';

        if ($groups === []) {
            $md[] = '_Aucun produit actif à coûter._';
            $md[] = '';

            return implode("\n", $md);
        }

        foreach ($groups as $catName => $rows) {
            $md[] = "## {$catName}";
            $md[] = '';
            $md[] = '| Produit | Prix vente | Coût matière | Marge | Marge % | Statut |';
            $md[] = '|---|---|---|---|---|---|';
            foreach ($rows as $r) {
                $md[] = '| '.implode(' | ', [
                    $r['item_name'],
                    $this->money($r['sale_price']),
                    $this->materialCostCell($r),
                    $r['margin'] === null ? '—' : $this->money($r['margin']),
                    $r['margin_pct'] === null ? '—' : number_format($r['margin_pct'], 1, ',', ' ').' %',
                    $this->statusCell($r),
                ]).' |';
            }
            $md[] = '';
        }

        $md[] = '---';
        $md[] = 'Les coûts « en attente » se rempliront automatiquement dès la saisie des prix d\'achat';
        $md[] = '(factures P3). NF525 : ce rapport LIT les recettes et le prix de vente, n\'écrit rien de fiscal.';
        $md[] = '';

        return implode("\n", $md);
    }

    /** @param array<string, mixed> $r */
    private function materialCostCell(array $r): string
    {
        if (! $r['has_recipe']) {
            return '—';
        }

        if ($r['has_unknown_cost']) {
            // Coût partiel : on montre ce qu'on sait, avec ≈ pour dire « incomplet ».
            return $r['material_cost'] > 0.0
                ? '≈ '.$this->money($r['material_cost']).' (partiel)'
                : '? (prix non saisi)';
        }

        return $this->money($r['material_cost']);
    }

    /** @param array<string, mixed> $r */
    private function statusCell(array $r): string
    {
        if (! $r['has_recipe']) {
            return '— recette non paramétrée';
        }

        return $r['has_unknown_cost'] ? self::PENDING_LABEL : '✅ complet';
    }

    private function money(float $value): string
    {
        return number_format($value, 2, ',', ' ').' €';
    }
}

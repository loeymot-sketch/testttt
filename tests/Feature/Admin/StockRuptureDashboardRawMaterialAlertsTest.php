<?php

namespace Tests\Feature\Admin;

use App\Models\Branch;
use App\Models\ItemVariation;
use App\Models\RawMaterial;
use App\Models\RawMaterialStock;
use App\Models\StockLevel;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * [QA 2026-09-28 — RAPPORT_DEV_CAISSE_2026-09-24 P0-15 / P0-14 · triage A11]
 *
 * Défaut reproduit : le bloc « Alertes Stock Bas » du tableau de bord et l'écran
 * « Conso & Stock » ne lisaient PAS LA MÊME TABLE. Le gérant pouvait donc
 * conclure qu'aucune action stock n'était requise pendant que l'autre écran
 * annonçait 20 ruptures avec des stocks théoriques négatifs
 * (Poulet mariné, Cheddar, Portion frites, Pain…).
 *
 * Cause structurelle mesurée, et c'est une cause que le rapport n'avait pas
 * nommée : `StockRuptureDashboardController::lowAlerts()` n'interrogeait que
 * `stock_levels` (stockables Item / ItemVariation / ItemExtra). `RawMaterial`
 * n'apparaissait NULLE PART dans ce contrôleur — les matières premières étaient
 * donc structurellement invisibles à ce panneau, même avec tous les seuils
 * renseignés. `UnifiedStockViewService`, lui, lit bien
 * `raw_materials` + `raw_material_stocks` et déclare `out` dès `on_hand <= 0`,
 * SANS exiger de seuil.
 *
 * Correctif volontairement BORNÉ à cet angle mort : on ajoute la source
 * matières premières, avec la même règle de rupture que l'écran de référence
 * (`on_hand <= 0` = rupture, sans seuil requis ; sinon `on_hand <= threshold_low`).
 *
 * ⛔ CE QUI N'EST PAS CHANGÉ, À DESSEIN : la sémantique des lignes `stock_levels`
 * reste intacte. Un stock d'article à 0 avec `threshold_low` NULL continue de ne
 * PAS produire d'alerte — c'est une décision explicite et testée du dépôt
 * (`StockRuptureDashboardEndpointsTest` : « sans seuil, aucune alerte ne peut
 * sortir — c'est le piège »), déjà compensée depuis 2026-09-02 par les
 * compteurs `tracked_rows`/`thresholds_configured` et le bandeau ambre « ce
 * panneau ne surveille rien ». La rendre alertante serait inverser une décision
 * propriétaire : c'est signalé au propriétaire, pas décidé ici.
 * Le dernier test de ce fichier VERROUILLE cette non-régression.
 */
class StockRuptureDashboardRawMaterialAlertsTest extends TestCase
{
    use RefreshDatabase;

    private function actingAdmin(): void
    {
        $admin = User::factory()->create(['branch_id' => 0]);
        $admin->assignRole('Admin');
        $admin->givePermissionTo(['items_show', 'items_create']);
        Sanctum::actingAs($admin, ['*']);
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedSpatieRoles();
        $this->seedMinimalSettings();
    }

    private function matiere(Branch $branch, string $name, float $onHand, ?float $threshold = null, bool $active = true): RawMaterial
    {
        $material = RawMaterial::query()->create([
            'branch_id'     => $branch->id,
            'name'          => $name,
            'unit'          => 'g',
            'threshold_low' => $threshold,
            'is_active'     => $active,
        ]);

        RawMaterialStock::query()->create([
            'raw_material_id' => $material->id,
            'branch_id'       => $branch->id,
            'on_hand'         => $onHand,
        ]);

        return $material;
    }

    private function alertes(): array
    {
        return $this->getJson('/api/admin/stock/low-alerts')->assertOk()->json('alerts');
    }

    private function noms(array $alerts): array
    {
        return array_values(array_map(
            fn (array $a): string => (string) ($a['label'] ?? $a['stockable_name'] ?? ''),
            $alerts
        ));
    }

    /** Le cas exact du rapport : stock théorique NÉGATIF, invisible au tableau de bord. */
    public function test_une_matiere_au_stock_negatif_apparait_enfin_dans_les_alertes(): void
    {
        $branch = Branch::factory()->create();
        $this->actingAdmin();

        $this->matiere($branch, 'Poulet mariné', -5000.0);

        $this->assertContains('Poulet mariné', $this->noms($this->alertes()));
    }

    /** Rupture franche : zéro est une rupture, seuil ou pas — règle de l'écran de référence. */
    public function test_une_matiere_a_zero_est_une_rupture_meme_sans_seuil(): void
    {
        $branch = Branch::factory()->create();
        $this->actingAdmin();

        $this->matiere($branch, 'Pain', 0.0, null);

        $this->assertContains('Pain', $this->noms($this->alertes()));
    }

    /** Sous le seuil renseigné : alerte classique de stock bas. */
    public function test_une_matiere_sous_son_seuil_declenche_l_alerte(): void
    {
        $branch = Branch::factory()->create();
        $this->actingAdmin();

        $this->matiere($branch, 'Cheddar', 400.0, 500.0);

        $this->assertContains('Cheddar', $this->noms($this->alertes()));
    }

    /** Stock sain : aucun bruit. Sinon le panneau redevient illisible. */
    public function test_une_matiere_saine_ne_produit_aucune_alerte(): void
    {
        $branch = Branch::factory()->create();
        $this->actingAdmin();

        $this->matiere($branch, 'Sauce maison', 8000.0, 500.0);
        $this->matiere($branch, 'Huile', 3000.0, null);

        $this->assertSame([], $this->noms($this->alertes()));
    }

    /** Une matière désactivée n'est plus exploitée : elle ne doit pas alerter. */
    public function test_une_matiere_desactivee_est_ignoree(): void
    {
        $branch = Branch::factory()->create();
        $this->actingAdmin();

        $this->matiere($branch, 'Ancien produit', -900.0, null, false);

        $this->assertSame([], $this->noms($this->alertes()));
    }

    /** Isolation de branche : la matière d'une autre succursale ne doit jamais fuiter. */
    public function test_la_matiere_d_une_autre_branche_ne_fuite_pas(): void
    {
        $branch = Branch::factory()->create();
        $autre = Branch::factory()->create();

        $materiel = RawMaterial::query()->create([
            'branch_id' => $autre->id,
            'name'      => 'Matière autre branche',
            'unit'      => 'g',
            'is_active' => true,
        ]);
        RawMaterialStock::query()->create([
            'raw_material_id' => $materiel->id,
            'branch_id'       => $autre->id,
            'on_hand'         => -4000.0,
        ]);

        // Un responsable épinglé sur `branch` ne doit voir que sa branche.
        $manager = User::factory()->create(['branch_id' => $branch->id]);
        $manager->assignRole('Branch Manager');
        $manager->givePermissionTo(['items_show', 'items_create']);
        Sanctum::actingAs($manager, ['*']);

        $this->assertNotContains('Matière autre branche', $this->noms($this->alertes()));
    }

    /**
     * NON-RÉGRESSION VERROUILLÉE : la sémantique des lignes d'ARTICLES ne bouge
     * pas. Un stock article à 0 sans seuil reste non alertant — décision
     * explicite du dépôt, que ce correctif ne renverse pas.
     */
    public function test_la_semantique_des_stocks_d_articles_reste_inchangee(): void
    {
        $branch = Branch::factory()->create();
        $this->actingAdmin();

        // L'identité du stockable n'importe pas pour ce contrôle : la requête
        // d'alerte filtre sur branche + seuil, et le libellé retombe sur « #id »
        // quand la cible n'est pas résolue. On évite ainsi de dépendre d'un
        // catalogue seedé, pour que ce garde morde toujours.
        StockLevel::query()->create([
            'branch_id'      => $branch->id,
            'stockable_type' => ItemVariation::class,
            'stockable_id'   => 999999,
            'on_hand'        => 0,
            'reserved'       => 0,
            'threshold_low'  => null,
        ]);

        $reponse = $this->getJson('/api/admin/stock/low-alerts')->assertOk()->json();

        $this->assertSame([], $reponse['alerts'],
            'Un stock ARTICLE à 0 sans seuil doit rester non alertant — décision du dépôt, non renversée ici.');
        $this->assertSame(1, $reponse['tracked_rows']);
        $this->assertSame(0, $reponse['thresholds_configured']);
    }
}

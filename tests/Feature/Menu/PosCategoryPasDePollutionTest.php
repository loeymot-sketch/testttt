<?php

namespace Tests\Feature\Menu;

use App\Enums\Status;
use App\Models\Branch;
use App\Models\Item;
use App\Models\ItemCategory;
use App\Models\Tax;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * [CHEF 2026-09-02] Les rayons de campagne ne doivent pas atteindre le caissier.
 *
 * Mesuré au navigateur le 2026-09-02 sur la caisse servie : la bande de rayons
 * affichait « E2E_PLAYWRIGHT_STUDIO_CATEGORY » à côté de Burgers et Sandwichs.
 *
 * Le garde-fou `ItemCategoryService::isAuditPollutionName()` existait déjà, mais
 * n'était appliqué que dans le catalogue ADMIN. `PosCategoryController` construit
 * sa PROPRE requête et ne le consultait pas. Deux rayons pollués sur trois étaient
 * masqués par accident, par le `whereHas('items')` (ils n'avaient aucun article) —
 * ce qui rendait le défaut discret : seul le rayon polluant AVEC un article passait.
 *
 * Ce test attaque l'ENDPOINT HTTP réellement appelé par la caisse
 * (`GET /api/admin/pos-category`, cf. `resources/js/store/modules/posCategory.js`),
 * et non une couche de projection que cet écran ne consomme pas.
 *
 * Portée assumée : seule la voie « caissier avec branche POS courante » est
 * authentifiable dans ce socle de test (la voie « configuration », sans branche
 * courante, renvoie 403 ici). Le filtre corrigé est posé APRÈS la requête, sur le
 * chemin COMMUN aux deux voies — ce test prouve donc que ce chemin s'exécute ; il
 * ne prouve pas à lui seul la seconde voie.
 */
class PosCategoryPasDePollutionTest extends TestCase
{
    use RefreshDatabase;

    private Tax $tax;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedMinimalSettings();
        $this->seedSpatieRoles();
        $this->tax = Tax::factory()->create(['status' => Status::ACTIVE]);
    }

    /** @return array{0:ItemCategory,1:ItemCategory} */
    private function seedRayonSainEtPollue(): array
    {
        $sain = ItemCategory::factory()->create([
            'name' => 'Burgers', 'status' => Status::ACTIVE, 'channels' => ['pos'],
        ]);
        // Nom réellement observé sur la caisse servie le 2026-09-02.
        $pollue = ItemCategory::factory()->create([
            'name' => 'E2E_PLAYWRIGHT_STUDIO_CATEGORY', 'status' => Status::ACTIVE, 'channels' => ['pos'],
        ]);

        // Chaque rayon porte un article vendable : sans cela `whereHas('items')`
        // masquerait le rayon pollué par accident et le test serait vacant.
        foreach ([$sain, $pollue] as $cat) {
            Item::factory()->create([
                'name' => 'Article ' . $cat->id,
                'item_category_id' => $cat->id,
                'tax_id' => $this->tax->id,
                'status' => Status::ACTIVE,
                'channels' => ['pos'],
            ]);
        }

        return [$sain, $pollue];
    }

    private function noms(User $user): array
    {
        $data = $this->actingAs($user, 'sanctum')
            ->getJson('/api/admin/pos-category?paginate=0&status=' . Status::ACTIVE)
            ->assertOk()
            ->json('data');

        return collect($data)->pluck('name')->filter()->values()->all();
    }

    public function test_le_caissier_ne_voit_aucun_rayon_de_campagne(): void
    {
        $branche = Branch::factory()->create(['status' => Status::ACTIVE]);
        [$sain, $pollue] = $this->seedRayonSainEtPollue();

        $caissier = User::factory()->create(['branch_id' => $branche->id]);
        $caissier->assignRole('POS Operator');

        $noms = $this->noms($caissier);

        $this->assertNotContains(
            $pollue->name,
            $noms,
            'Rayon de campagne servi au caissier : ' . implode(' | ', $noms)
        );
        $this->assertNotEmpty($noms, 'Le filtre ne doit pas vider la bande de rayons.');
        unset($sain);
    }
}

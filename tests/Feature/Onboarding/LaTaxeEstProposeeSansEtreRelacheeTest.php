<?php

namespace Tests\Feature\Onboarding;

use App\Enums\Status;
use App\Models\Item;
use App\Models\ItemCategory;
use App\Models\Tax;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * [ONB-02 C1 2026-08-28] La taxe est PROPOSÉE, sans que l'API soit relâchée.
 *
 * ═══ LE CRITÈRE, ET LA TENSION ═══
 *
 * `plans/GOAL_ONB02 §0.5 C1` demande : « un article neuf naît avec la TVA
 * restauration — `POST item` sans `tax_id` → taxe par défaut réglée (10 %) ».
 *
 * Or `ItemRequest:90` porte `tax_id => required` depuis le 2026-08-27, et c'est
 * délibéré : la règle était `nullable`, et `PricingService` faisait
 * `(int) ($dbItem->tax_id ?? 0)` puis `$taxes[0] ?? null` — un article sans taxe
 * était donc **facturé à 0 % en silence**, à la borne comme à la caisse.
 *
 * **Refuser vaut mieux que détaxer sans le dire.** Le critère et cette sévérité se
 * concilient autrement : sévérité à l'API, **confort à l'écran**.
 *
 * ═══ CE QUI MANQUAIT ═══
 *
 * `config/menu.php:80` porte `default_tax_id` depuis toujours — et il n'était lu
 * **que par les semoirs**. Jamais à l'exécution, jamais par l'écran. Le commerçant
 * devait donc choisir parmi six taxes, dont deux **GST étrangères** et un
 * **« No-VAT 0 % »** qui détaxerait sa carte, pour créer son premier produit.
 *
 * Le défaut existait et ne servait à rien.
 */
class LaTaxeEstProposeeSansEtreRelacheeTest extends TestCase
{
    use RefreshDatabase;

    private User $patron;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedSpatieRoles();
        $this->seedMinimalSettings();

        $this->patron = User::factory()->create(['branch_id' => 0]);
        $this->patron->assignRole('Admin');

        foreach (['items', 'items_show', 'items_create', 'items_edit'] as $droit) {
            Permission::findOrCreate($droit, 'sanctum');
        }
        $this->patron->givePermissionTo(['items', 'items_show', 'items_create', 'items_edit']);
        app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();

        $this->actingAs($this->patron, 'sanctum');
    }

    public function test_l_api_refuse_toujours_un_article_sans_taxe(): void
    {
        // ═══ LE CONTRÔLE QUI COMPTE LE PLUS ═══
        //
        // Poser un défaut à l'écran ne doit RIEN assouplir côté serveur. Si un jour
        // quelqu'un rend `tax_id` nullable « puisque l'écran le remplit », tout
        // chemin d'écriture qui contourne le formulaire — import, API, assistant —
        // rouvre la facturation à 0 % en silence.
        $categorie = ItemCategory::factory()->create(['status' => Status::ACTIVE]);

        $reponse = $this->postJson('/api/admin/item', [
            'name'             => 'Tacos sans taxe',
            'price'            => '8.50',
            'item_category_id' => $categorie->id,
            'item_type'        => 1,
            'is_featured'      => 10,
            'status'           => Status::ACTIVE,
            'order'            => 1,
            'description'      => '',
            'caution'          => '',
            // pas de tax_id
        ]);

        $reponse->assertStatus(422);
        $reponse->assertJsonValidationErrors(['tax_id']);

        $this->assertSame(
            0,
            Item::query()->count(),
            "Un article sans taxe a été créé. `PricingService` le facturera à 0 %\n"
            . "en silence, à la borne comme à la caisse."
        );
    }

    public function test_le_defaut_configure_existe_et_designe_une_taxe_reelle(): void
    {
        $defaut = (int) config('menu.settings.default_tax_id', 0);

        $this->assertGreaterThan(
            0,
            $defaut,
            "`menu.settings.default_tax_id` n'est plus configuré : l'écran ne peut\n"
            . 'plus rien proposer, et le commerçant redevient seul devant six taxes.'
        );
    }

    public function test_l_ecran_propose_le_defaut_au_lieu_de_laisser_le_champ_vide(): void
    {
        $formulaire = file_get_contents(
            resource_path('js/components/admin/items/ItemCreateComponent.vue')
        );

        $sansCommentaires = preg_replace('#/\*[\s\S]*?\*/#', '', $formulaire);
        $sansCommentaires = preg_replace('#^\s*//.*$#m', '', $sansCommentaires);

        $this->assertStringNotContainsString(
            'tax_id: null',
            $sansCommentaires,
            "Le formulaire repart d'un champ vide : le commerçant doit choisir parmi\n"
            . "six taxes, dont deux GST étrangères et un « No-VAT 0 % » qui détaxerait\n"
            . 'sa carte, pour créer son premier produit.'
        );

        $this->assertStringContainsString(
            'taxeParDefaut()',
            $sansCommentaires,
            "Le formulaire ne propose aucune taxe par défaut."
        );

        // Et le défaut doit venir de la CONFIGURATION, pas d'un identifiant écrit
        // en dur — sinon on remplace un défaut absent par un défaut de Le Cayenne.
        $this->assertStringContainsString(
            'foodkingConfig?.catalogue?.defaultTaxId',
            $sansCommentaires,
            "La taxe proposée est écrite en dur au lieu d'être lue dans la\n"
            . 'configuration : ce serait le choix fiscal d\'un établissement imposé aux autres.'
        );

        $gabarit = file_get_contents(resource_path('views/master.blade.php'));

        $this->assertStringContainsString(
            "config('menu.settings.default_tax_id'",
            $gabarit,
            "La configuration n'atteint pas le navigateur : `taxeParDefaut()` rendra\n"
            . 'toujours `null`, et le correctif serait inerte.'
        );
    }
}

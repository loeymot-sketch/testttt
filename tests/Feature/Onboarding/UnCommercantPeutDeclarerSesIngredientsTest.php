<?php

namespace Tests\Feature\Onboarding;

use App\Models\Branch;
use App\Models\RawMaterial;
use App\Models\RawMaterialStock;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * [ONB-08 2026-08-28] Un commerçant peut enfin déclarer ses matières premières.
 *
 * ═══ LE BLOCAGE ═══
 *
 * `RawMaterial` n'avait AUCUN CRUD. `routes/api.php:436-441` n'exposait que
 * `movements` (lecture) et `adjust` (correction de quantité). Les seules sources de
 * création étaient `RawMaterialBaselineSeeder` et une commande console.
 *
 * **Un nouveau commerçant ne pouvait déclarer aucun ingrédient.** Tout le domaine
 * matières lui arrivait pré-rempli avec celui de Le Cayenne, sans moyen d'en
 * ajouter, d'en retirer, ni de corriger une unité.
 *
 * C'est le blocage le plus lourd de la mission « depuis zéro », et il n'apparaissait
 * dans AUCUN constat de reconnaissance — trouvé par un audit adverse à qui on avait
 * demandé « qu'est-ce qui empêche un commerçant de partir de rien ».
 *
 * ═══ CE QUE ÇA FERME AU PASSAGE ═══
 *
 * `threshold_low` n'avait aucun chemin d'écriture : **55/55** `stock_levels` et
 * **20/20** `raw_materials` à NULL — pas à 0. Or `StockRuptureDashboardController:99`
 * et `NotifyStockLowOnStockLevelChanged:50` filtrent tous deux
 * `whereNotNull('threshold_low')` : **100 % des lignes étaient exclues**. Le widget
 * stock-bas, le listener et l'écran d'alertes étaient trois instruments branchés sur
 * une colonne que rien ne remplissait.
 */
class UnCommercantPeutDeclarerSesIngredientsTest extends TestCase
{
    use RefreshDatabase;

    private User $karim;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedSpatieRoles();
        $this->seedMinimalSettings();

        if (! Branch::query()->whereKey(1)->exists()) {
            Branch::factory()->create(['id' => 1]);
        }

        $this->karim = User::factory()->create(['branch_id' => 1]);
        $this->karim->assignRole('Admin');
        foreach (['items_show', 'items_create'] as $droit) {
            Permission::findOrCreate($droit, 'sanctum');
        }
        $this->karim->givePermissionTo(['items_show', 'items_create']);
        $this->actingAs($this->karim, 'sanctum');

    }

    /** @param array<string, mixed> $extra */
    private function declarer(array $extra = []): \Illuminate\Testing\TestResponse
    {
        return $this->postJson('/api/admin/raw-materials', array_merge([
            'name'          => 'Poulet frais',
            'unit'          => 'g',
            'threshold_low' => 2000,
            'is_active'     => true,
        ], $extra));
    }

    // ══════════════════════════════════════════════ le geste qui manquait

    public function test_un_commercant_peut_declarer_une_matiere_depuis_rien(): void
    {
        $this->assertSame(0, RawMaterial::query()->count(), 'On part bien de zéro.');

        $this->declarer()->assertStatus(201);

        $matiere = RawMaterial::query()->firstOrFail();

        $this->assertSame('Poulet frais', $matiere->name);
        $this->assertSame('g', $matiere->unit);
        $this->assertSame(1, (int) $matiere->branch_id);
        $this->assertTrue((bool) $matiere->is_active);
    }

    public function test_le_seuil_d_alerte_a_ENFIN_un_chemin_d_ecriture(): void
    {
        $this->declarer(['threshold_low' => 2000])->assertStatus(201);

        $this->assertEqualsWithDelta(
            2000.0,
            (float) RawMaterial::query()->value('threshold_low'),
            0.001,
            "`threshold_low` n'avait AUCUN chemin d'écriture : 20/20 matières à NULL,\n"
            . "et le tableau de rupture comme le listener filtrent `whereNotNull` —\n"
            . "donc 100 % des lignes exclues, alerte de stock bas structurellement muette."
        );
    }

    public function test_le_seuil_peut_rester_vide_sans_forcer_un_chiffre_invente(): void
    {
        // `null` signifie « pas d'alerte sur cette matière ». Forcer un chiffre
        // pousserait le commerçant à en inventer un, et une alerte fausse est pire
        // qu'une alerte absente.
        $this->declarer(['threshold_low' => null])->assertStatus(201);

        $this->assertNull(RawMaterial::query()->value('threshold_low'));
    }

    public function test_la_liste_rend_les_matieres_avec_leur_stock(): void
    {
        $this->declarer()->assertStatus(201);
        $matiere = RawMaterial::query()->firstOrFail();

        RawMaterialStock::create([
            'raw_material_id' => $matiere->id,
            'branch_id'       => 1,
            'on_hand'         => 4500,
        ]);

        $reponse = $this->getJson('/api/admin/raw-materials');

        $reponse->assertOk();

        $ligne = $reponse->json('data.0');

        $this->assertSame('Poulet frais', $ligne['name']);
        $this->assertEqualsWithDelta(4500.0, (float) $ligne['on_hand'], 0.001);
        $this->assertEqualsWithDelta(2000.0, (float) $ligne['threshold_low'], 0.001);

        // L'écran doit pouvoir proposer les unités que la conversion sait traiter,
        // sans les deviner.
        $this->assertContains('kg', $reponse->json('unites_acceptees'));
        $this->assertContains('piece', $reponse->json('unites_acceptees'));
    }

    // ══════════════════════════════════════════════ ce qu'on refuse, et pourquoi

    public function test_une_unite_hors_conversion_est_refusee_en_nommant_les_bonnes(): void
    {
        // La colonne est un `string(16)` LIBRE. La laisser libre a déjà coûté : une
        // facture « 3 kg » créditait 3 grammes. Le formulaire propose donc une forme
        // canonique, et le serveur ne l'accepte que là.
        $reponse = $this->declarer(['unit' => 'cageot']);

        $reponse->assertStatus(422);
        $reponse->assertJsonValidationErrors(['unit']);

        $message = (string) $reponse->json('errors.unit.0');
        $this->assertStringContainsString('kg', $message, 'Le refus doit NOMMER les unités acceptées.');
    }

    public function test_un_doublon_est_refuse_sans_erreur_SQL_brute(): void
    {
        $this->declarer()->assertStatus(201);

        $reponse = $this->declarer();

        $reponse->assertStatus(422);

        // La table porte `unique(['branch_id','name'])`. Sans règle, le doublon
        // remonterait en « SQLSTATE[23000] » — le défaut exact qu'ONB-02 a corrigé
        // sur `kds_station`.
        $this->assertStringNotContainsString('SQLSTATE', (string) $reponse->getContent());
        $this->assertSame(1, RawMaterial::query()->count());
    }

    public function test_changer_l_unite_d_une_matiere_qui_a_du_stock_est_refuse(): void
    {
        $this->declarer(['unit' => 'kg'])->assertStatus(201);
        $matiere = RawMaterial::query()->firstOrFail();

        RawMaterialStock::create([
            'raw_material_id' => $matiere->id,
            'branch_id'       => 1,
            'on_hand'         => 3,
        ]);

        /*
         * LE PIÈGE. Le stock est un NOMBRE, sans son unité. Passer « kg » à « g » ne
         * convertit rien : 3 kilos deviendraient 3 grammes. C'est exactement le
         * facteur mille qui a mis onze matières sur quatorze en négatif via les
         * factures d'achat.
         *
         * Convertir automatiquement serait pire que refuser : le commerçant ne
         * verrait pas que ses chiffres ont bougé.
         */
        $reponse = $this->putJson('/api/admin/raw-materials/' . $matiere->id, [
            'name' => 'Poulet frais',
            'unit' => 'g',
        ]);

        $reponse->assertStatus(422);
        $this->assertStringContainsString('3', (string) $reponse->json('message'));

        $this->assertSame('kg', $matiere->fresh()->unit, "L'unité ne doit pas avoir bougé.");
    }

    public function test_changer_l_unite_reste_possible_quand_le_stock_est_a_zero(): void
    {
        // Le contrôle positif : sans lui, un refus systématique passerait le banc
        // précédent tout en rendant l'unité non corrigeable à vie.
        $this->declarer(['unit' => 'kg'])->assertStatus(201);
        $matiere = RawMaterial::query()->firstOrFail();

        $this->putJson('/api/admin/raw-materials/' . $matiere->id, [
            'name' => 'Poulet frais',
            'unit' => 'g',
        ])->assertOk();

        $this->assertSame('g', $matiere->fresh()->unit);
    }

    public function test_une_matiere_utilisee_par_une_recette_ne_peut_pas_etre_retiree(): void
    {
        $this->declarer()->assertStatus(201);
        $matiere = RawMaterial::query()->firstOrFail();

        DB::table('raw_material_recipe_lines')->insert([
            'branch_id'       => 1,
            'subject_type'    => 'item',
            'subject_id'      => 1,
            'raw_material_id' => $matiere->id,
            'qty'             => 150,
            'created_at'      => now(),
            'updated_at'      => now(),
        ]);

        $reponse = $this->deleteJson('/api/admin/raw-materials/' . $matiere->id);

        $reponse->assertStatus(422);

        /*
         * Laisser une recette pointer dans le vide, c'est le motif du `tax_id`
         * orphelin corrigé le même jour : la déduction de stock cesserait, sans que
         * rien ne le signale.
         *
         * ⚠️ On mesure DEUX choses distinctes, parce que le middleware `localization`
         * fixe la locale par requête : `app()->setLocale()` dans le montage ne
         * survit pas à l'appel HTTP. Confondre les deux ferait croire à un défaut de
         * traduction là où il n'y a qu'un réglage de banc.
         */

        // 1. Le message rendu NOMME le nombre de recettes — sinon le commerçant
        //    apprend qu'il ne peut pas, sans savoir combien ni où regarder.
        $this->assertStringContainsString('1', (string) $reponse->json('message'));
        $this->assertStringNotContainsString(
            'all.message',
            (string) $reponse->json('message'),
            'La clé brute est rendue : la traduction manque.'
        );

        // 2. La version FRANÇAISE existe — c'est celle que lit le commerçant, le
        //    produit étant à locale FR figée (ADR-007).
        $this->assertStringContainsString(
            'recette',
            trans('all.message.matiere_encore_dans_une_recette', ['n' => 1], 'fr')
        );

        $this->assertSame(1, RawMaterial::query()->count());
    }

    public function test_une_matiere_libre_peut_etre_retiree(): void
    {
        $this->declarer()->assertStatus(201);
        $matiere = RawMaterial::query()->firstOrFail();

        $this->deleteJson('/api/admin/raw-materials/' . $matiere->id)->assertOk();

        $this->assertSame(0, RawMaterial::query()->count());
        $this->assertSame(1, RawMaterial::withTrashed()->count(), 'La suppression est douce.');
    }

    public function test_un_compte_sans_droit_d_ecriture_ne_peut_rien_declarer(): void
    {
        // ⚠️ Le role `Admin` porte TOUTES les permissions (`tests/TestCase.php:111-158`).
        // Un utilisateur « sans droit d'ecriture » ne peut donc pas l'avoir — sinon ce
        // banc mesurerait le contraire de ce qu'il annonce, et resterait vert avec la
        // garde retiree.
        $serveur = User::factory()->create(['branch_id' => 1]);
        $serveur->assignRole('Stuff');
        Permission::findOrCreate('items_show', 'sanctum');
        $serveur->givePermissionTo(['items_show']);

        $this->assertFalse(
            $serveur->can('items_create'),
            "Le montage du banc est faux : cet utilisateur a le droit d'ecrire."
        );

        $this->actingAs($serveur, 'sanctum')
            ->postJson('/api/admin/raw-materials', ['name' => 'Test', 'unit' => 'g'])
            ->assertStatus(403);

        $this->assertSame(0, RawMaterial::query()->count());
    }
}

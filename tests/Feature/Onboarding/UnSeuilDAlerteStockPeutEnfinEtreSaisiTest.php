<?php

namespace Tests\Feature\Onboarding;

use App\Models\Branch;
use App\Models\Item;
use App\Models\StockLevel;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * [ONB-08 2026-08-28] Le seuil d'alerte de stock devient saisissable.
 *
 * ═══ LE DÉFAUT ═══
 *
 * `stock_levels.threshold_low` était **lu** à deux endroits :
 *
 *   - `StockRuptureDashboardController::lowAlerts()` filtre
 *     `whereNotNull('threshold_low')` puis `whereColumn('on_hand','<=','threshold_low')`
 *   - `NotifyStockLowOnStockLevelChanged` déclenche la notification de stock bas
 *
 * Et **écrit par personne**. Aucune route, aucun écran, aucune commande. Mesuré en
 * lecture sur la base en service le 2026-08-28 : **55 lignes, 0 seuil.**
 *
 * La section « alertes stock bas » du tableau de bord ne pouvait donc
 * STRUCTURELLEMENT rien afficher, et l'alerte était muette — non pas parce que tout
 * allait bien, mais parce que **personne ne pouvait dire à partir de quand ça
 * n'allait plus**.
 *
 * C'est le pire genre de silence : celui qui ressemble à une bonne nouvelle.
 *
 * ═══ LE JUMEAU ═══
 *
 * Exactement le même défaut que le seuil des matières premières, corrigé la veille.
 * Le motif — *une chaîne complète sauf l'écran où un humain saisit la vérité* — en
 * est à son sixième exemplaire cette semaine : allergènes, poste de cuisine, matières
 * premières, seuil matière, tampon halal, et maintenant seuil de stock.
 *
 * ═══ CE QUE CE BANC PROUVE ═══
 *
 * Pas seulement que l'écriture marche : que **l'alerte s'allume ensuite**. Un banc
 * qui vérifierait la seule écriture laisserait passer le cas où la valeur est
 * enregistrée dans une colonne que le tableau de bord ne regarde pas.
 */
class UnSeuilDAlerteStockPeutEnfinEtreSaisiTest extends TestCase
{
    use RefreshDatabase;

    private User $patron;
    private Branch $branche;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedSpatieRoles();
        $this->seedMinimalSettings();

        $this->branche = Branch::factory()->create();

        $this->patron = User::factory()->create(['branch_id' => $this->branche->id]);
        $this->patron->assignRole('Admin');

        foreach (['items_show', 'items_create', 'items_edit'] as $droit) {
            Permission::findOrCreate($droit, 'sanctum');
        }
        $this->patron->givePermissionTo(['items_show', 'items_create', 'items_edit']);
        app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();

        $this->actingAs($this->patron, 'sanctum');
    }

    private function ligneDeStock(int $enStock): StockLevel
    {
        $produit = Item::factory()->create();

        return StockLevel::factory()->create([
            'branch_id'      => $this->branche->id,
            'stockable_type' => Item::class,
            'stockable_id'   => $produit->id,
            'on_hand'        => $enStock,
            'threshold_low'  => null,
        ]);
    }

    public function test_le_seuil_est_enregistre_et_l_alerte_s_allume(): void
    {
        $ligne = $this->ligneDeStock(enStock: 3);

        // AVANT : le tableau de bord ne peut rien voir, quel que soit le stock.
        $avant = $this->getJson('/api/admin/stock/low-alerts');
        $avant->assertOk();

        $this->assertCount(
            0,
            $avant->json('alerts'),
            "Départ incohérent : une alerte existe alors qu'aucun seuil n'est posé."
        );

        // LE COMMERÇANT DIT À PARTIR DE QUAND ÇA NE VA PLUS.
        $ecriture = $this->putJson("/api/admin/stock/levels/{$ligne->id}/seuil", [
            'threshold_low' => 5,
        ]);

        $ecriture->assertOk();
        $this->assertSame(5, $ecriture->json('data.threshold_low'));

        // Ce que le commerçant veut savoir tout de suite : suis-je déjà dessous ?
        $this->assertTrue(
            $ecriture->json('data.en_alerte'),
            "3 en stock pour un seuil de 5 : le produit est déjà sous le seuil, et\n"
            . "l'enregistrement doit le dire immédiatement."
        );

        // APRÈS : ET C'EST LÀ QUE TOUT SE JOUE — l'alerte apparaît vraiment.
        $apres = $this->getJson('/api/admin/stock/low-alerts');
        $apres->assertOk();

        $alertes = $apres->json('alerts');

        $this->assertCount(
            1,
            $alertes,
            "Le seuil est enregistré mais le tableau de bord reste muet. C'est le cas\n"
            . "qu'un banc d'écriture seule laisserait passer : la valeur atterrit dans\n"
            . 'une colonne que personne ne regarde.'
        );

        $this->assertSame(3, $alertes[0]['on_hand']);
        $this->assertSame(5, $alertes[0]['threshold_low']);
    }

    public function test_un_seuil_peut_etre_retire(): void
    {
        // Un seuil qu'on ne peut plus enlever n'est pas un réglage. Et `null` est
        // précisément la valeur qu'une règle mal écrite refuse en silence.
        $ligne = $this->ligneDeStock(enStock: 2);

        $this->putJson("/api/admin/stock/levels/{$ligne->id}/seuil", ['threshold_low' => 5])
            ->assertOk();

        $retrait = $this->putJson("/api/admin/stock/levels/{$ligne->id}/seuil", [
            'threshold_low' => null,
        ]);

        $retrait->assertOk();
        $this->assertNull($retrait->json('data.threshold_low'));
        $this->assertFalse($retrait->json('data.en_alerte'));

        $this->assertCount(
            0,
            $this->getJson('/api/admin/stock/low-alerts')->json('alerts'),
            "Le produit reste en alerte alors que le commerçant a retiré sa surveillance."
        );
    }

    public function test_un_seuil_absurde_est_refuse_avec_une_raison(): void
    {
        $ligne = $this->ligneDeStock(enStock: 10);

        $negatif = $this->putJson("/api/admin/stock/levels/{$ligne->id}/seuil", [
            'threshold_low' => -1,
        ]);
        $negatif->assertStatus(422);
        $negatif->assertJsonValidationErrors(['threshold_low']);

        // Le plafond n'est pas cosmétique : un seuil absurde mettrait toute la carte
        // en alerte en permanence, ce qui revient exactement à n'avoir aucune alerte.
        $enorme = $this->putJson("/api/admin/stock/levels/{$ligne->id}/seuil", [
            'threshold_low' => 999999999,
        ]);
        $enorme->assertStatus(422);

        $this->assertStringNotContainsString(
            'SQLSTATE',
            (string) $enorme->getContent(),
            'Le refus arrive sous forme de trace de base de données.'
        );
    }

    public function test_un_compte_sans_droit_d_ecriture_ne_peut_pas_poser_de_seuil(): void
    {
        // Contrôle de périmètre : le rôle `Admin` du banc porte TOUS les droits,
        // donc un test d'autorisation écrit avec lui ne prouverait rien. On prend
        // un rôle qui n'en a pas, et on le vérifie explicitement avant de conclure.
        $ligne = $this->ligneDeStock(enStock: 4);

        $serveur = User::factory()->create(['branch_id' => $this->branche->id]);
        $serveur->assignRole('Stuff');
        app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();

        $this->assertFalse(
            $serveur->fresh()->can('items_create'),
            "Le rôle témoin porte déjà le droit d'écriture : ce banc ne prouverait rien."
        );

        $refus = $this->actingAs($serveur, 'sanctum')
            ->putJson("/api/admin/stock/levels/{$ligne->id}/seuil", ['threshold_low' => 5]);

        $this->assertContains(
            $refus->status(),
            [401, 403],
            'Un compte sans droit peut modifier les seuils de stock.'
        );
    }

    public function test_l_ecran_porte_le_champ_et_montre_le_refus(): void
    {
        // ═══ SANS ÉCRAN, L'API EST INERTE ═══
        //
        // Une route d'écriture que personne n'appelle ne vaut pas mieux qu'une
        // colonne que personne n'écrit. La vue conso unifiée AFFICHAIT déjà une
        // colonne « Seuil » — qui ne pouvait montrer qu'un tiret sur les 55 lignes,
        // puisque rien ne l'écrivait. Une colonne qui ne peut afficher qu'un tiret
        // est une promesse non tenue.
        $ecran = file_get_contents(
            resource_path('js/components/admin/stock/UnifiedStockViewComponent.vue')
        );

        $this->assertStringContainsString(
            "'usv-seuil-' + row.id",
            $ecran,
            "L'écran n'a pas de champ pour saisir le seuil : la route d'écriture\n"
            . "n'est appelée par personne, et la colonne « Seuil » reste un tiret."
        );

        $this->assertStringContainsString(
            'data-testid="usv-seuil-erreur"',
            $ecran,
            "Un refus d'enregistrement resterait INVISIBLE : le patron croirait son\n"
            . 'seuil posé. C\'est l\'invariant que ce chantier applique partout ailleurs.'
        );

        // Et la ligne doit porter l'identifiant du NIVEAU DE STOCK, distinct de
        // celui de l'article : sans lui, l'écran ne sait pas quelle ligne viser.
        $service = file_get_contents(app_path('Services/Stock/UnifiedStockViewService.php'));

        $this->assertStringContainsString(
            "'stock_level_id' => (int) \$level->id",
            $service,
            "La ligne ne porte pas l'identifiant du niveau de stock : l'écran enverrait\n"
            . "l'identifiant de l'article, et modifierait la mauvaise ligne — ou aucune."
        );
    }
}

<?php

namespace Tests\Feature\Labels;

use App\Models\Branch;
use App\Models\DlcLabelProduct;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * [VG-2026-10-21 M7-001] Deux tablettes ouvrent la même fiche DLC. B ajoute un allergène et raccourcit la durée ;
 * A enregistre ensuite la copie qu'elle avait ouverte. Avant : 200, Gluten effacé et 48 h rétabli, sans un mot.
 * Maintenant : la fiche porte une `version` (empreinte de son contenu) ; l'éditeur la renvoie (`expected_version`)
 * et le serveur refuse (409) une copie périmée — rien n'est écrasé.
 */
class DlcProductConcurrencyTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedSpatieRoles();
        $this->seedMinimalSettings();
        Branch::factory()->create(['id' => 1]);
        $user = User::factory()->create(['branch_id' => 1, 'name' => 'Karim Test']);
        $user->givePermissionTo(['pos-orders']);
        Sanctum::actingAs($user, ['*']);
    }

    private function fiche(): DlcLabelProduct
    {
        $p = new DlcLabelProduct([
            'name' => 'Sauce course',
            'shelf_life_value' => 48,
            'shelf_life_unit' => 'hours',
            'actions' => [['key' => 'prepared', 'value' => 48, 'unit' => 'hours']],
            'storage' => '0 à +3 °C',
            'allergens' => ['lait'],
        ]);
        $p->branch_id = 1;
        $p->save();

        return $p;
    }

    /** La copie que l'éditeur envoie : la fiche ENTIÈRE, telle qu'elle a été ouverte. */
    private function copieEditeur(array $lue, array $changes = []): array
    {
        return array_merge([
            'name' => $lue['name'],
            'category' => $lue['category'],
            'storage' => $lue['storage'],
            'thawed_storage' => $lue['thawed_storage'],
            'note' => $lue['note'],
            'allergens' => $lue['allergens'],
            'no_allergens' => $lue['no_allergens'],
            'needs_parent' => $lue['needs_parent'],
            'actions' => $lue['actions'],
            'ask_original_dlc' => $lue['ask_original_dlc'],
            'print_operator' => $lue['print_operator'],
            'default_copies' => $lue['default_copies'],
            'expected_version' => $lue['version'] ?? null,
        ], $changes);
    }

    private function lire(int $id): array
    {
        return collect($this->getJson('/api/admin/labels/products')->assertOk()->json('data'))->firstWhere('id', $id);
    }

    public function test_une_copie_perimee_est_refusee_et_n_efface_ni_allergene_ni_duree(): void
    {
        $p = $this->fiche();
        $tabletteA = $this->lire($p->id);
        $tabletteB = $this->lire($p->id);

        // B : ajoute Gluten et raccourcit 48 h → 24 h
        $this->putJson('/api/admin/labels/products/'.$p->id, $this->copieEditeur($tabletteB, [
            'allergens' => ['lait', 'gluten'],
            'actions' => [['key' => 'prepared', 'value' => 24, 'unit' => 'hours']],
        ]))->assertOk();

        // A : n'a changé que la mention, mais renvoie sa copie périmée
        $res = $this->putJson('/api/admin/labels/products/'.$p->id, $this->copieEditeur($tabletteA, ['note' => 'Maison']));
        $res->assertStatus(409);
        $this->assertStringContainsString('modifiée sur un autre appareil', (string) $res->json('message'));
        // la fiche À JOUR revient avec le refus : l'écran la recharge sans deviner
        $this->assertSame(['lait', 'gluten'], $res->json('data.allergens'));

        $fresh = $p->fresh();
        $this->assertSame(['lait', 'gluten'], $fresh->allergens, 'Gluten jamais perdu');
        $this->assertSame(24, $fresh->actionList()[0]['value'], 'la durée raccourcie n\'est jamais rallongée en silence');
        $this->assertNull($fresh->note);
        $this->assertNotEmpty($tabletteA['version'] ?? null, 'la fiche dit sa version');
    }

    public function test_la_version_lue_juste_avant_permet_d_enregistrer_et_change_a_chaque_modification(): void
    {
        $p = $this->fiche();
        $lue = $this->lire($p->id);
        $res = $this->putJson('/api/admin/labels/products/'.$p->id, $this->copieEditeur($lue, ['note' => 'Maison']))->assertOk();
        $v2 = $res->json('data.version');
        $this->assertNotSame($lue['version'], $v2);

        // deux enregistrements de suite par le même éditeur : le second porte la version renvoyée par le premier
        $this->putJson('/api/admin/labels/products/'.$p->id, $this->copieEditeur($res->json('data'), ['note' => 'Maison 2']))->assertOk();
        $this->assertSame('Maison 2', $p->fresh()->note);
    }

    public function test_sans_version_attendue_une_modification_partielle_reste_permise(): void
    {
        $p = $this->fiche();
        // masquer / réactiver (et tout autre appelant qui n'envoie que quelques champs) : inchangé
        $this->putJson('/api/admin/labels/products/'.$p->id, ['name' => 'Sauce course', 'is_active' => false])->assertOk();
        $fresh = $p->fresh();
        $this->assertFalse($fresh->is_active);
        $this->assertSame(['lait'], $fresh->allergens);
    }

    public function test_mettre_en_favori_ne_rend_pas_la_copie_de_l_editeur_perimee(): void
    {
        $p = $this->fiche();
        $lue = $this->lire($p->id);
        $this->putJson('/api/admin/labels/products/'.$p->id.'/favorite', ['favorite' => true])->assertOk();
        $this->putJson('/api/admin/labels/products/'.$p->id, $this->copieEditeur($lue, ['note' => 'Maison']))->assertOk();
    }

    public function test_une_version_illisible_est_refusee_sans_rien_ecrire(): void
    {
        $p = $this->fiche();
        $lue = $this->lire($p->id);
        $this->putJson('/api/admin/labels/products/'.$p->id, $this->copieEditeur($lue, ['expected_version' => ['x'], 'note' => 'X']))->assertStatus(422);
        $this->assertNull($p->fresh()->note);
    }
}

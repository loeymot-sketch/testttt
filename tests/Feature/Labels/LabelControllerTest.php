<?php

namespace Tests\Feature\Labels;

use App\Enums\OrderStatus;
use App\Enums\OrderType;
use App\Enums\PaymentStatus;
use App\Models\Branch;
use App\Models\DlcLabelProduct;
use App\Models\LabelPrintLog;
use App\Models\Order;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * [ETIQUETTES 2026-10-16] Le serveur des étiquettes : catalogue DLC, horloge unique, journal d'hygiène et
 * verrou d'impression automatique. Le serveur ne parle jamais à l'imprimante (elle est en Bluetooth sur la
 * tablette) : ce qu'on prouve ici, c'est ce dont la tablette dépend.
 */
class LabelControllerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedSpatieRoles();
        $this->seedMinimalSettings();
        Branch::factory()->create(['id' => 1]);
        Branch::factory()->create(['id' => 2]);
    }

    private function caisse(int $branch = 1, array $perms = ['pos-orders']): User
    {
        $user = User::factory()->create(['branch_id' => $branch, 'name' => 'Karim Test']);
        $user->givePermissionTo($perms);
        Sanctum::actingAs($user, ['*']);

        return $user;
    }

    private function product(array $attrs = []): DlcLabelProduct
    {
        $p = new DlcLabelProduct($attrs + [
            'name' => 'Oignons émincés',
            'shelf_life_value' => 2,
            'shelf_life_unit' => 'days',
            'storage' => '+4 °C',
        ]);
        $p->branch_id = $attrs['branch_id'] ?? 1;
        $p->save();

        return $p;
    }

    // ───────────── accès ─────────────

    public function test_sans_authentification_tout_est_refuse(): void
    {
        $this->getJson('/api/admin/labels/products')->assertUnauthorized();
        $this->postJson('/api/admin/labels/dlc/print', ['product_id' => 1])->assertUnauthorized();
        $this->getJson('/api/admin/labels/orders')->assertUnauthorized();
    }

    public function test_sans_permission_caisse_cuisine_c_est_interdit(): void
    {
        $this->caisse(1, []);
        $this->getJson('/api/admin/labels/products')->assertForbidden();
        $this->getJson('/api/admin/labels/orders')->assertForbidden();
    }

    public function test_les_trois_permissions_du_personnel_ouvrent_l_ecran(): void
    {
        foreach (['pos-orders', 'pos', 'kitchen-display-system'] as $perm) {
            $this->caisse(1, [$perm]);
            $this->getJson('/api/admin/labels/products')->assertOk();
        }
    }

    // ───────────── catalogue DLC ─────────────

    public function test_creer_lister_modifier_supprimer_un_produit(): void
    {
        $this->caisse();

        $created = $this->postJson('/api/admin/labels/products', [
            'name' => '  Tomates tranchées ', 'shelf_life_value' => 24, 'shelf_life_unit' => 'hours', 'storage' => ' +4 °C ',
        ])->assertCreated()->json('data');
        $this->assertSame('Tomates tranchées', $created['name']);
        $this->assertSame('+4 °C', $created['storage']);
        $this->assertTrue($created['is_active']);

        $this->getJson('/api/admin/labels/products')
            ->assertOk()
            ->assertJsonPath('data.0.name', 'Tomates tranchées')
            ->assertJsonStructure(['data', 'meta' => ['server_now', 'timezone']]);

        $this->putJson('/api/admin/labels/products/'.$created['id'], [
            'name' => 'Tomates', 'shelf_life_value' => 2, 'shelf_life_unit' => 'days', 'is_active' => false,
        ])->assertOk()->assertJsonPath('data.is_active', false)->assertJsonPath('data.storage', null);

        $this->deleteJson('/api/admin/labels/products/'.$created['id'])->assertOk();
        $this->getJson('/api/admin/labels/products')->assertJsonCount(0, 'data');
        $this->assertSoftDeleted('dlc_label_products', ['id' => $created['id']]);
    }

    public function test_un_nom_deja_pris_est_refuse_sans_tenir_compte_de_la_casse(): void
    {
        $this->caisse();
        $this->product(['name' => 'Olives']);

        $this->postJson('/api/admin/labels/products', ['name' => 'OLIVES', 'shelf_life_value' => 3, 'shelf_life_unit' => 'days'])
            ->assertStatus(422)
            ->assertJsonPath('errors.name.0', 'Un produit porte déjà ce nom.');
    }

    public function test_renommer_un_produit_sur_lui_meme_est_permis(): void
    {
        $this->caisse();
        $p = $this->product(['name' => 'Olives']);
        $this->putJson('/api/admin/labels/products/'.$p->id, ['name' => 'Olives', 'shelf_life_value' => 5, 'shelf_life_unit' => 'days'])
            ->assertOk()->assertJsonPath('data.shelf_life_value', 5);
    }

    public function test_les_durees_absurdes_sont_refusees_avec_un_message_clair(): void
    {
        $this->caisse();
        $base = ['name' => 'X', 'shelf_life_unit' => 'hours'];
        $this->postJson('/api/admin/labels/products', $base + ['shelf_life_value' => 721])
            ->assertStatus(422)->assertJsonPath('errors.shelf_life_value.0', 'La durée ne peut pas dépasser 720 heures (30 jours).');
        $this->postJson('/api/admin/labels/products', ['name' => 'X', 'shelf_life_unit' => 'days', 'shelf_life_value' => 366])->assertStatus(422);
        $this->postJson('/api/admin/labels/products', $base + ['shelf_life_value' => 0])->assertStatus(422);
        $this->postJson('/api/admin/labels/products', ['name' => 'X', 'shelf_life_unit' => 'semaines', 'shelf_life_value' => 1])->assertStatus(422);
        $this->postJson('/api/admin/labels/products', ['name' => '', 'shelf_life_unit' => 'days', 'shelf_life_value' => 1])->assertStatus(422);
    }

    public function test_isolation_de_branche_un_produit_d_une_autre_branche_est_invisible_et_intouchable(): void
    {
        $autre = $this->product(['name' => 'Autre branche', 'branch_id' => 2]);
        $this->caisse(1);

        $this->getJson('/api/admin/labels/products')->assertJsonCount(0, 'data');
        $this->putJson('/api/admin/labels/products/'.$autre->id, ['name' => 'Pirate', 'shelf_life_value' => 1, 'shelf_life_unit' => 'days'])->assertNotFound();
        $this->deleteJson('/api/admin/labels/products/'.$autre->id)->assertNotFound();
        $this->postJson('/api/admin/labels/dlc/print', ['product_id' => $autre->id])->assertNotFound();
    }

    // ───────────── impression DLC ─────────────

    public function test_la_dlc_est_calculee_par_le_serveur_en_jours_a_la_meme_heure(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-10-16 14:32:10', config('app.timezone')));
        $this->caisse();
        $p = $this->product(['shelf_life_value' => 3, 'shelf_life_unit' => 'days']);

        $res = $this->postJson('/api/admin/labels/dlc/print', ['product_id' => $p->id, 'copies' => 2])
            ->assertOk()->json();

        $this->assertSame('Oignons émincés', $res['name']);
        $this->assertSame(2, $res['copies']);
        $this->assertSame('Karim', $res['by']);
        $this->assertSame('2026-10-16', Carbon::parse($res['opened_at'])->toDateString());
        $this->assertSame('2026-10-19 14:32:10', Carbon::parse($res['expires_at'])->format('Y-m-d H:i:s'));

        $log = LabelPrintLog::query()->findOrFail($res['log_id']);
        $this->assertSame('dlc', $log->kind);
        $this->assertSame('claimed', $log->status);
        $this->assertSame('Oignons émincés', $log->product_name);
        $this->assertSame(3, $log->shelf_life_value);
        $this->assertTrue($log->expires_at->equalTo($log->opened_at->copy()->addDays(3)));
        Carbon::setTestNow();
    }

    public function test_la_dlc_en_heures(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-10-16 23:00:00', config('app.timezone')));
        $this->caisse();
        $p = $this->product(['shelf_life_value' => 36, 'shelf_life_unit' => 'hours']);
        $res = $this->postJson('/api/admin/labels/dlc/print', ['product_id' => $p->id])->assertOk()->json();
        $this->assertSame('2026-10-18 11:00:00', Carbon::parse($res['expires_at'])->format('Y-m-d H:i:s'));
        $this->assertSame(1, $res['copies']);
        Carbon::setTestNow();
    }

    public function test_un_produit_desactive_ou_inconnu_n_imprime_pas(): void
    {
        $this->caisse();
        $p = $this->product(['is_active' => false]);
        $this->postJson('/api/admin/labels/dlc/print', ['product_id' => $p->id])->assertNotFound();
        $this->postJson('/api/admin/labels/dlc/print', ['product_id' => 999999])->assertNotFound();
        $this->assertSame(0, LabelPrintLog::query()->count());
    }

    public function test_le_nombre_d_etiquettes_est_borne(): void
    {
        $this->caisse();
        $p = $this->product();
        $this->postJson('/api/admin/labels/dlc/print', ['product_id' => $p->id, 'copies' => 21])->assertStatus(422);
        $this->postJson('/api/admin/labels/dlc/print', ['product_id' => $p->id, 'copies' => 0])->assertStatus(422);
    }

    public function test_renommer_ou_supprimer_un_produit_ne_reecrit_pas_le_journal_d_hygiene(): void
    {
        $this->caisse();
        $p = $this->product(['name' => 'Olives', 'shelf_life_value' => 3]);
        $logId = $this->postJson('/api/admin/labels/dlc/print', ['product_id' => $p->id])->json('log_id');

        $this->putJson('/api/admin/labels/products/'.$p->id, ['name' => 'Olives noires', 'shelf_life_value' => 9, 'shelf_life_unit' => 'days'])->assertOk();
        $this->deleteJson('/api/admin/labels/products/'.$p->id)->assertOk();

        $log = LabelPrintLog::query()->findOrFail($logId);
        $this->assertSame('Olives', $log->product_name);
        $this->assertSame(3, $log->shelf_life_value);
    }

    public function test_le_verdict_d_impression_est_enregistre_une_seule_fois(): void
    {
        $this->caisse();
        $p = $this->product();
        $logId = $this->postJson('/api/admin/labels/dlc/print', ['product_id' => $p->id])->json('log_id');

        $this->postJson("/api/admin/labels/prints/{$logId}/result", ['status' => 'printed'])->assertOk()->assertJsonPath('log_status', 'printed');
        // une réponse tardive d'un autre onglet ne transforme pas « imprimé » en « échec »
        $this->postJson("/api/admin/labels/prints/{$logId}/result", ['status' => 'failed', 'message' => 'tard'])
            ->assertOk()->assertJsonPath('log_status', 'printed');

        $log = LabelPrintLog::query()->findOrFail($logId);
        $this->assertSame('printed', $log->status);
        $this->assertNotNull($log->finished_at);
        $this->assertNull($log->message);
    }

    public function test_un_verdict_inconnu_est_refuse(): void
    {
        $this->caisse();
        $p = $this->product();
        $logId = $this->postJson('/api/admin/labels/dlc/print', ['product_id' => $p->id])->json('log_id');
        $this->postJson("/api/admin/labels/prints/{$logId}/result", ['status' => 'bizarre'])->assertStatus(422);
    }

    public function test_l_historique_liste_les_dernieres_impressions_avec_leur_auteur(): void
    {
        $this->caisse();
        $p = $this->product();
        $id = $this->postJson('/api/admin/labels/dlc/print', ['product_id' => $p->id])->json('log_id');
        $this->postJson("/api/admin/labels/prints/{$id}/result", ['status' => 'failed', 'message' => 'Capot ouvert'])->assertOk();

        $this->getJson('/api/admin/labels/history')
            ->assertOk()
            ->assertJsonPath('data.0.product_name', 'Oignons émincés')
            ->assertJsonPath('data.0.status', 'failed')
            ->assertJsonPath('data.0.message', 'Capot ouvert')
            ->assertJsonPath('data.0.by', 'Karim');
    }

    // ───────────── commandes ─────────────

    private function orderOnBoard(array $attrs = []): Order
    {
        return Order::factory()->create($attrs + [
            'branch_id' => 1,
            'order_type' => OrderType::POS,
            'payment_status' => PaymentStatus::PAID,
            'status' => OrderStatus::PREPARING,
        ]);
    }

    public function test_les_commandes_a_etiqueter_sont_celles_de_l_ecran_cuisine(): void
    {
        $this->caisse();
        $on = $this->orderOnBoard();
        $off = $this->orderOnBoard(['status' => OrderStatus::DELIVERED]);

        $json = $this->getJson('/api/admin/labels/orders')->assertOk()->json();
        $ids = collect($json['data'])->pluck('id')->all();
        $this->assertContains($on->id, $ids);
        $this->assertNotContains($off->id, $ids);
        $this->assertArrayHasKey('order_items', $json['data'][0]);
        $this->assertArrayHasKey('server_now', $json['meta']);
        $this->assertEmpty((array) $json['meta']['states']);
    }

    public function test_l_etat_des_etiquettes_remonte_dans_la_liste(): void
    {
        $this->caisse();
        $o = $this->orderOnBoard();

        $log = $this->postJson("/api/admin/labels/orders/{$o->id}/claim", ['mode' => 'manual', 'labels_count' => 3])->json('log_id');
        $state = fn () => $this->getJson('/api/admin/labels/orders')->json('meta.states.'.$o->id);

        $this->assertTrue($state()['in_progress']);
        $this->assertFalse($state()['printed']);

        $this->postJson("/api/admin/labels/prints/{$log}/result", ['status' => 'printed', 'labels_count' => 3])->assertOk();
        $this->assertTrue($state()['printed']);
        $this->assertFalse($state()['in_progress']);
        $this->assertSame(1, $state()['print_count']);
    }

    public function test_un_echec_est_signale_avec_son_message_tant_que_rien_n_est_sorti(): void
    {
        $this->caisse();
        $o = $this->orderOnBoard();
        $log = $this->postJson("/api/admin/labels/orders/{$o->id}/claim", ['mode' => 'manual'])->json('log_id');
        $this->postJson("/api/admin/labels/prints/{$log}/result", ['status' => 'failed', 'message' => 'Fin de rouleau'])->assertOk();

        $s = $this->getJson('/api/admin/labels/orders')->json('meta.states.'.$o->id);
        $this->assertTrue($s['failed']);
        $this->assertSame('Fin de rouleau', $s['last_message']);
        $this->assertFalse($s['printed']);
    }

    // ───────────── verrou d'impression automatique ─────────────

    public function test_l_automatique_n_est_accorde_qu_une_fois_par_commande(): void
    {
        $this->caisse();
        $o = $this->orderOnBoard();

        $this->postJson("/api/admin/labels/orders/{$o->id}/claim", ['mode' => 'auto'])->assertOk()->assertJsonPath('claimed', true);
        $this->postJson("/api/admin/labels/orders/{$o->id}/claim", ['mode' => 'auto'])->assertOk()->assertJsonPath('claimed', false);

        // une AUTRE tablette (autre utilisateur) n'est pas accordée non plus
        $this->caisse();
        $this->postJson("/api/admin/labels/orders/{$o->id}/claim", ['mode' => 'auto'])->assertOk()->assertJsonPath('claimed', false);
        $this->assertSame(1, LabelPrintLog::query()->where('order_id', $o->id)->count());
    }

    public function test_reimprimer_a_la_main_est_toujours_permis(): void
    {
        $this->caisse();
        $o = $this->orderOnBoard();
        $this->postJson("/api/admin/labels/orders/{$o->id}/claim", ['mode' => 'auto'])->assertJsonPath('claimed', true);
        $this->postJson("/api/admin/labels/orders/{$o->id}/claim", ['mode' => 'manual'])->assertJsonPath('claimed', true);
        $this->postJson("/api/admin/labels/orders/{$o->id}/claim", ['mode' => 'manual'])->assertJsonPath('claimed', true);
        $this->assertSame(3, LabelPrintLog::query()->where('order_id', $o->id)->count());
    }

    public function test_une_reclamation_restee_sans_verdict_est_rendue_a_la_file(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-10-16 12:00:00'));
        $this->caisse();
        $o = $this->orderOnBoard();
        $first = $this->postJson("/api/admin/labels/orders/{$o->id}/claim", ['mode' => 'auto'])->json('log_id');

        Carbon::setTestNow(Carbon::parse('2026-10-16 12:01:00')); // 60 s : trop tôt
        $this->postJson("/api/admin/labels/orders/{$o->id}/claim", ['mode' => 'auto'])->assertJsonPath('claimed', false);

        Carbon::setTestNow(Carbon::parse('2026-10-16 12:03:00')); // 180 s > 120 s : rendue
        $this->postJson("/api/admin/labels/orders/{$o->id}/claim", ['mode' => 'auto'])
            ->assertJsonPath('claimed', true)->assertJsonPath('log_id', $first)->assertJsonPath('reclaimed', true);
        Carbon::setTestNow();
    }

    public function test_une_commande_imprimee_n_est_plus_jamais_reclamee_en_auto(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-10-16 12:00:00'));
        $this->caisse();
        $o = $this->orderOnBoard();
        $log = $this->postJson("/api/admin/labels/orders/{$o->id}/claim", ['mode' => 'auto', 'labels_count' => 2])->json('log_id');
        $this->postJson("/api/admin/labels/prints/{$log}/result", ['status' => 'printed', 'labels_count' => 2])->assertOk();

        Carbon::setTestNow(Carbon::parse('2026-10-16 18:00:00')); // longtemps après
        $this->postJson("/api/admin/labels/orders/{$o->id}/claim", ['mode' => 'auto'])->assertJsonPath('claimed', false);
        Carbon::setTestNow();
    }

    public function test_un_echec_sans_aucune_etiquette_sortie_peut_etre_retente_apres_un_delai_et_dans_la_limite(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-10-16 12:00:00'));
        $this->caisse();
        $o = $this->orderOnBoard();
        $claim = fn () => $this->postJson("/api/admin/labels/orders/{$o->id}/claim", ['mode' => 'auto']);
        $fail = fn ($log) => $this->postJson("/api/admin/labels/prints/{$log}/result", ['status' => 'failed', 'message' => 'Capot ouvert', 'printed_pages' => 0]);

        $log = $claim()->json('log_id');
        $fail($log)->assertOk();

        Carbon::setTestNow(Carbon::parse('2026-10-16 12:00:10')); // 10 s : trop tôt
        $claim()->assertJsonPath('claimed', false);

        Carbon::setTestNow(Carbon::parse('2026-10-16 12:01:00')); // 60 s : permis (tentative 2)
        $claim()->assertJsonPath('claimed', true)->assertJsonPath('log_id', $log)->assertJsonPath('reclaimed', true);
        $fail($log)->assertOk();

        Carbon::setTestNow(Carbon::parse('2026-10-16 12:02:00')); // tentative 3
        $claim()->assertJsonPath('claimed', true);
        $fail($log)->assertOk();

        Carbon::setTestNow(Carbon::parse('2026-10-16 12:30:00')); // plafond de 3 tentatives atteint
        $claim()->assertJsonPath('claimed', false);
        $this->assertSame(3, LabelPrintLog::query()->findOrFail($log)->attempts);
        Carbon::setTestNow();
    }

    public function test_un_echec_apres_sortie_d_etiquettes_n_est_jamais_retente_seul_pas_de_doublon(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-10-16 12:00:00'));
        $this->caisse();
        $o = $this->orderOnBoard();
        $log = $this->postJson("/api/admin/labels/orders/{$o->id}/claim", ['mode' => 'auto', 'labels_count' => 4])->json('log_id');
        $this->postJson("/api/admin/labels/prints/{$log}/result", ['status' => 'failed', 'message' => 'Fin de rouleau', 'printed_pages' => 2])->assertOk();

        Carbon::setTestNow(Carbon::parse('2026-10-16 15:00:00'));
        $this->postJson("/api/admin/labels/orders/{$o->id}/claim", ['mode' => 'auto'])->assertJsonPath('claimed', false);

        $state = $this->getJson('/api/admin/labels/orders')->json('meta.states.'.$o->id);
        $this->assertTrue($state['failed']);
        $this->assertSame(2, $state['printed_pages']);
        Carbon::setTestNow();
    }

    public function test_une_commande_d_une_autre_branche_ne_se_reclame_pas(): void
    {
        $this->caisse(1);
        $other = $this->orderOnBoard(['branch_id' => 2]);
        $this->postJson("/api/admin/labels/orders/{$other->id}/claim", ['mode' => 'manual'])->assertNotFound();
        $this->postJson('/api/admin/labels/orders/999999/claim', ['mode' => 'manual'])->assertNotFound();
    }

    public function test_le_mode_de_reclamation_est_obligatoire(): void
    {
        $this->caisse();
        $o = $this->orderOnBoard();
        $this->postJson("/api/admin/labels/orders/{$o->id}/claim", [])->assertStatus(422);
        $this->postJson("/api/admin/labels/orders/{$o->id}/claim", ['mode' => 'nimporte'])->assertStatus(422);
    }

    // ───────────── revue adversariale (2026-10-16) ─────────────

    public function test_la_reponse_tardive_d_une_tablette_perimee_ne_clot_pas_la_tentative_de_l_autre(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-10-16 12:00:00'));
        $this->caisse();
        $o = $this->orderOnBoard();
        $a = $this->postJson("/api/admin/labels/orders/{$o->id}/claim", ['mode' => 'auto'])->json();
        $this->assertSame(1, $a['attempt']);

        Carbon::setTestNow(Carbon::parse('2026-10-16 12:03:00')); // la réclamation de A est rendue à la file
        $b = $this->postJson("/api/admin/labels/orders/{$o->id}/claim", ['mode' => 'auto'])->json();
        $this->assertTrue($b['claimed']);
        $this->assertSame($a['log_id'], $b['log_id']);
        $this->assertSame(2, $b['attempt']);

        // A, lente, répond « échec » avec SA tentative (1) : ignoré
        $this->postJson("/api/admin/labels/prints/{$a['log_id']}/result", ['status' => 'failed', 'printed_pages' => 0, 'attempt' => 1])
            ->assertOk()->assertJsonPath('ignored', true)->assertJsonPath('log_status', 'claimed');

        // B répond « imprimé » avec la sienne (2) : appliqué
        $this->postJson("/api/admin/labels/prints/{$b['log_id']}/result", ['status' => 'printed', 'labels_count' => 2, 'attempt' => 2])
            ->assertOk()->assertJsonPath('log_status', 'printed')->assertJsonPath('ignored', false);

        Carbon::setTestNow(Carbon::parse('2026-10-16 12:30:00'));
        $this->postJson("/api/admin/labels/orders/{$o->id}/claim", ['mode' => 'auto'])->assertJsonPath('claimed', false);
        Carbon::setTestNow();
    }

    public function test_l_automatique_ne_reimprime_pas_une_commande_deja_reimprimee_a_la_main(): void
    {
        $this->caisse();
        $o = $this->orderOnBoard();
        $m = $this->postJson("/api/admin/labels/orders/{$o->id}/claim", ['mode' => 'manual'])->json('log_id');
        // impression manuelle en cours : l'automatique attend
        $this->postJson("/api/admin/labels/orders/{$o->id}/claim", ['mode' => 'auto'])->assertJsonPath('claimed', false);
        $this->postJson("/api/admin/labels/prints/{$m}/result", ['status' => 'printed', 'labels_count' => 1])->assertOk();
        // imprimée à la main : l'automatique ne passe jamais
        $this->postJson("/api/admin/labels/orders/{$o->id}/claim", ['mode' => 'auto'])->assertJsonPath('claimed', false);
        $this->assertSame(1, LabelPrintLog::query()->where('order_id', $o->id)->count());
    }

    public function test_une_unite_en_tableau_donne_422_pas_500(): void
    {
        $this->caisse();
        $this->postJson('/api/admin/labels/products', ['name' => 'X', 'shelf_life_value' => 1, 'shelf_life_unit' => ['x']])->assertStatus(422);
    }

    public function test_un_identifiant_geant_donne_404_pas_500(): void
    {
        $this->caisse();
        $big = '99999999999999999999';
        // jamais 500 : le routeur refuse l'identifiant avant le contrôleur (404, ou 405 si une autre route couvre l'URL)
        $codes = [
            $this->putJson("/api/admin/labels/products/{$big}", ['name' => 'X', 'shelf_life_value' => 1, 'shelf_life_unit' => 'days'])->status(),
            $this->deleteJson("/api/admin/labels/products/{$big}")->status(),
            $this->postJson("/api/admin/labels/prints/{$big}/result", ['status' => 'printed'])->status(),
            $this->postJson("/api/admin/labels/orders/{$big}/claim", ['mode' => 'manual'])->status(),
        ];
        foreach ($codes as $code) {
            $this->assertContains($code, [404, 405]);
        }
    }

    public function test_le_journal_dlc_porte_la_branche_du_produit_meme_pour_un_administrateur_sans_branche(): void
    {
        $p = $this->product(['branch_id' => 2, 'name' => 'Branche deux']);
        $admin = User::factory()->create(['branch_id' => 0, 'name' => 'Admin Test']);
        $admin->givePermissionTo('pos-orders');
        Sanctum::actingAs($admin, ['*']);

        $logId = $this->postJson('/api/admin/labels/dlc/print', ['product_id' => $p->id])->assertOk()->json('log_id');
        $this->assertSame(2, (int) LabelPrintLog::withoutGlobalScopes()->findOrFail($logId)->branch_id);
    }

    public function test_les_produits_les_plus_utilises_sont_comptes_sur_30_jours(): void
    {
        $this->caisse();
        $a = $this->product(['name' => 'Rare']);
        $b = $this->product(['name' => 'Souvent']);
        foreach ([[$b, 3], [$b, 2], [$a, 1]] as [$p, $copies]) {
            $id = $this->postJson('/api/admin/labels/dlc/print', ['product_id' => $p->id, 'copies' => $copies])->json('log_id');
            $this->postJson("/api/admin/labels/prints/{$id}/result", ['status' => 'printed'])->assertOk();
        }
        // une impression en échec ne compte pas
        $fail = $this->postJson('/api/admin/labels/dlc/print', ['product_id' => $a->id, 'copies' => 9])->json('log_id');
        $this->postJson("/api/admin/labels/prints/{$fail}/result", ['status' => 'failed'])->assertOk();

        $rows = collect($this->getJson('/api/admin/labels/products')->json('data'))->keyBy('name');
        $this->assertSame(5, $rows['Souvent']['uses_30d']);
        $this->assertSame(1, $rows['Rare']['uses_30d']);
    }

    public function test_reimprimer_une_seule_etiquette_ne_marque_pas_la_commande_imprimee(): void
    {
        $this->caisse();
        $o = $this->orderOnBoard();
        $log = $this->postJson("/api/admin/labels/orders/{$o->id}/claim", ['mode' => 'manual', 'labels_count' => 1, 'partial' => true])->json('log_id');
        $this->postJson("/api/admin/labels/prints/{$log}/result", ['status' => 'printed', 'labels_count' => 1])->assertOk();

        $state = $this->getJson('/api/admin/labels/orders')->json('meta.states.'.$o->id);
        $this->assertFalse($state['printed'], 'une étiquette sur quatre ne vaut pas « commande imprimée »');
        // et l'automatique n'est pas verrouillé par une réimpression partielle
        $this->postJson("/api/admin/labels/orders/{$o->id}/claim", ['mode' => 'auto'])->assertJsonPath('claimed', true);
    }

    public function test_une_impression_complete_apres_une_partielle_marque_la_commande_imprimee(): void
    {
        $this->caisse();
        $o = $this->orderOnBoard();
        $p = $this->postJson("/api/admin/labels/orders/{$o->id}/claim", ['mode' => 'manual', 'labels_count' => 1, 'partial' => true])->json('log_id');
        $this->postJson("/api/admin/labels/prints/{$p}/result", ['status' => 'printed'])->assertOk();
        $f = $this->postJson("/api/admin/labels/orders/{$o->id}/claim", ['mode' => 'manual', 'labels_count' => 4])->json('log_id');
        $this->postJson("/api/admin/labels/prints/{$f}/result", ['status' => 'printed', 'labels_count' => 4])->assertOk();

        $state = $this->getJson('/api/admin/labels/orders')->json('meta.states.'.$o->id);
        $this->assertTrue($state['printed']);
        $this->assertSame(1, $state['print_count']);
    }
}

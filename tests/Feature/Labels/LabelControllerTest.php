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
        ])->assertOk()->assertJsonPath('data.is_active', false)
            // un champ ABSENT n'est pas effacé (masquer un produit ne doit pas lui retirer sa conservation)…
            ->assertJsonPath('data.storage', '+4 °C');
        // …et la conservation, imprimée sur toute étiquette d'hygiène, ne peut pas être vidée.
        $this->putJson('/api/admin/labels/products/'.$created['id'], [
            'name' => 'Tomates', 'shelf_life_value' => 2, 'shelf_life_unit' => 'days', 'storage' => '',
        ])->assertStatus(422)->assertJsonPath('errors.storage.0', 'Indiquez la température de conservation (elle est imprimée sur l’étiquette).');
        $this->assertSame('+4 °C', DlcLabelProduct::query()->findOrFail($created['id'])->storage);

        $this->deleteJson('/api/admin/labels/products/'.$created['id'])->assertOk();
        $this->getJson('/api/admin/labels/products')->assertJsonCount(0, 'data');
        $this->assertSoftDeleted('dlc_label_products', ['id' => $created['id']]);
    }

    public function test_un_nom_deja_pris_est_refuse_sans_tenir_compte_de_la_casse(): void
    {
        $this->caisse();
        $this->product(['name' => 'Olives']);

        $this->postJson('/api/admin/labels/products', ['name' => 'OLIVES', 'shelf_life_value' => 3, 'shelf_life_unit' => 'days', 'storage' => '0 à +4 °C'])
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

    public function test_jeter_un_lot_pose_la_date_sans_supprimer_la_ligne_et_reste_dans_la_branche(): void
    {
        $this->caisse();
        $p = $this->product(['name' => 'Olives']);
        $id = $this->postJson('/api/admin/labels/dlc/print', ['product_id' => $p->id])->json('log_id');
        $this->postJson("/api/admin/labels/prints/{$id}/result", ['status' => 'printed'])->assertOk();

        $this->postJson('/api/admin/labels/prints/discard', ['ids' => [$id]])->assertOk()->assertJsonPath('discarded', 1);
        $this->postJson('/api/admin/labels/prints/discard', ['ids' => [$id]])->assertOk()->assertJsonPath('discarded', 0); // idempotent

        $row = collect($this->getJson('/api/admin/labels/history')->json('data'))->firstWhere('id', $id);
        $this->assertNotNull($row['discarded_at']);
        $this->assertSame('printed', $row['status']); // la ligne reste, intacte

        // annuler un « Jeté » posé par erreur remet le lot dans la liste
        $this->postJson('/api/admin/labels/prints/discard', ['ids' => [$id], 'restore' => true])->assertOk()->assertJsonPath('restored', 1);
        $row = collect($this->getJson('/api/admin/labels/history')->json('data'))->firstWhere('id', $id);
        $this->assertNull($row['discarded_at']);
        $this->postJson('/api/admin/labels/prints/discard', ['ids' => [$id]])->assertOk()->assertJsonPath('discarded', 1);

        // une autre branche ne peut pas jeter ce lot
        $this->caisse(2);
        $this->postJson('/api/admin/labels/prints/discard', ['ids' => [$id]])->assertOk()->assertJsonPath('discarded', 0);
    }

    public function test_jeter_refuse_une_liste_vide_ou_invalide(): void
    {
        $this->caisse();
        $this->postJson('/api/admin/labels/prints/discard', ['ids' => []])->assertStatus(422);
        $this->postJson('/api/admin/labels/prints/discard', [])->assertStatus(422);
        $this->postJson('/api/admin/labels/prints/discard', ['ids' => ['x']])->assertStatus(422);
    }

    // ───────────── configuration détaillée (niveau logiciels HACCP du marché) ─────────────

    private function expertProduct(array $over = []): array
    {
        return $this->postJson('/api/admin/labels/products', $over + [
            'name' => 'Poulet mariné', 'category' => 'Viandes & volailles',
            'actions' => [
                ['key' => 'thawed', 'value' => 3, 'unit' => 'days'],
                ['key' => 'opened', 'value' => 48, 'unit' => 'hours'],
            ],
            'allergens' => ['moutarde', 'lait'], 'storage' => '0 à +3 °C', 'note' => 'Bac n°2',
            'print_operator' => true, 'default_copies' => 2,
        ])->assertCreated()->json('data');
    }

    public function test_un_produit_peut_porter_plusieurs_evenements_chacun_avec_sa_duree(): void
    {
        $this->caisse();
        $p = $this->expertProduct();
        $this->assertSame('Viandes & volailles', $p['category']);
        $this->assertSame([['key' => 'thawed', 'value' => 3, 'unit' => 'days'], ['key' => 'opened', 'value' => 48, 'unit' => 'hours']], $p['actions']);
        $this->assertSame(['moutarde', 'lait'], $p['allergens']);
        $this->assertSame(2, $p['default_copies']);
        // la durée « historique » suit le premier événement
        $this->assertSame(3, $p['shelf_life_value']);
        $this->assertSame('days', $p['shelf_life_unit']);
    }

    public function test_un_produit_ancien_sans_evenements_garde_sa_duree_comme_ouverture(): void
    {
        $this->caisse();
        $old = $this->product(['shelf_life_value' => 2, 'shelf_life_unit' => 'days']);
        $row = collect($this->getJson('/api/admin/labels/products')->json('data'))->firstWhere('id', $old->id);
        $this->assertSame([['key' => 'opened', 'value' => 2, 'unit' => 'days']], $row['actions']);
        $this->assertSame(1, $row['default_copies']);
        $this->assertTrue($row['print_operator']);
    }

    public function test_configuration_invalide_refusee(): void
    {
        $this->caisse();
        // sans conservation : refus, avec la raison
        $this->postJson('/api/admin/labels/products', ['name' => 'X', 'actions' => [['key' => 'opened', 'value' => 1, 'unit' => 'days']]])
            ->assertStatus(422)->assertJsonPath('errors.storage.0', 'Indiquez la température de conservation (elle est imprimée sur l’étiquette).');
        $base = ['name' => 'X', 'storage' => '0 à +4 °C'];
        $this->postJson('/api/admin/labels/products', $base + ['actions' => [['key' => 'grille', 'value' => 1, 'unit' => 'days']]])->assertStatus(422);
        $dup = $this->postJson('/api/admin/labels/products', $base + ['actions' => [['key' => 'opened', 'value' => 1, 'unit' => 'days'], ['key' => 'opened', 'value' => 2, 'unit' => 'days']]])
            ->assertStatus(422)->json('errors');
        $this->assertSame('Chaque événement (ouverture, décongélation…) ne peut figurer qu’une fois.', $dup['actions.1.key'][0]);
        $long = $this->postJson('/api/admin/labels/products', $base + ['actions' => [['key' => 'opened', 'value' => 400, 'unit' => 'days']]])
            ->assertStatus(422)->json('errors');
        $this->assertSame('La durée ne peut pas dépasser 365 jours.', $long['actions.0.value'][0]);
        $this->postJson('/api/admin/labels/products', $base + ['actions' => [['key' => 'opened', 'value' => 2, 'unit' => 'days']], 'allergens' => ['chocolat']])->assertStatus(422);
        $this->postJson('/api/admin/labels/products', $base + ['actions' => [['key' => 'opened', 'value' => 2, 'unit' => 'days']], 'default_copies' => 50])->assertStatus(422);
    }

    public function test_impression_par_evenement_lot_et_operateur(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-10-16 10:00:00', config('app.timezone')));
        $this->caisse();
        $p = $this->expertProduct();

        $thawed = $this->postJson('/api/admin/labels/dlc/print', ['product_id' => $p['id']])->assertOk()->json();
        $this->assertSame('thawed', $thawed['action']); // événement par défaut = le premier
        $this->assertSame('2026-10-19 10:00', Carbon::parse($thawed['expires_at'])->format('Y-m-d H:i'));
        $this->assertSame(2, $thawed['copies']); // nombre proposé par le produit
        $this->assertMatchesRegularExpression('/^L261016-\d{4}$/', $thawed['lot']);
        $this->assertSame('Karim', $thawed['by']);
        $this->assertSame(['moutarde', 'lait'], $thawed['allergens']);
        $this->assertSame('Bac n°2', $thawed['note']);

        $opened = $this->postJson('/api/admin/labels/dlc/print', ['product_id' => $p['id'], 'action' => 'opened', 'copies' => 1])->assertOk()->json();
        $this->assertSame('opened', $opened['action']);
        $this->assertSame('2026-10-18 10:00', Carbon::parse($opened['expires_at'])->format('Y-m-d H:i'));
        $this->assertNotSame($thawed['lot'], $opened['lot']);

        $log = LabelPrintLog::query()->findOrFail($opened['log_id']);
        $this->assertSame('opened', $log->action);
        $this->assertSame($opened['lot'], $log->lot);
        Carbon::setTestNow();
    }

    public function test_sans_prenom_si_le_produit_ne_le_demande_pas(): void
    {
        $this->caisse();
        $p = $this->expertProduct(['name' => 'Anonyme', 'print_operator' => false]);
        $this->assertNull($this->postJson('/api/admin/labels/dlc/print', ['product_id' => $p['id']])->json('by'));
    }

    public function test_la_dlc_secondaire_ne_depasse_jamais_la_dlc_du_fabricant(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-10-16 10:00:00', config('app.timezone')));
        $this->caisse();
        $p = $this->expertProduct(['name' => 'Crème', 'ask_original_dlc' => true, 'actions' => [['key' => 'opened', 'value' => 5, 'unit' => 'days']]]);

        // DLC demandée : obligatoire
        $this->postJson('/api/admin/labels/dlc/print', ['product_id' => $p['id']])
            ->assertStatus(422)->assertJsonPath('errors.original_dlc.0', 'Indiquez la DLC du fabricant (sur l’emballage d’origine).');

        // fabricant au 18/10 : la DLC calculée (21/10) est LIMITÉE au 18/10 23:59
        $r = $this->postJson('/api/admin/labels/dlc/print', ['product_id' => $p['id'], 'original_dlc' => '2026-10-18'])->assertOk()->json();
        $this->assertTrue($r['capped']);
        $this->assertSame('2026-10-18 23:59', Carbon::parse($r['expires_at'])->format('Y-m-d H:i'));

        // fabricant au 30/10 : la DLC calculée (21/10) s'applique
        $r2 = $this->postJson('/api/admin/labels/dlc/print', ['product_id' => $p['id'], 'original_dlc' => '2026-10-30'])->assertOk()->json();
        $this->assertFalse($r2['capped']);
        $this->assertSame('2026-10-21 10:00', Carbon::parse($r2['expires_at'])->format('Y-m-d H:i'));

        // fabricant déjà dépassé : refus net, rien n'est journalisé
        $before = LabelPrintLog::query()->count();
        $this->postJson('/api/admin/labels/dlc/print', ['product_id' => $p['id'], 'original_dlc' => '2026-10-15'])
            ->assertStatus(422)->assertJsonPath('status', 'original_expired');
        $this->assertSame($before, LabelPrintLog::query()->count());
        Carbon::setTestNow();
    }

    public function test_un_evenement_non_prevu_est_refuse_jamais_remplace_par_un_autre(): void
    {
        $this->caisse();
        $p = $this->expertProduct(['name' => 'Seul', 'actions' => [['key' => 'prepared', 'value' => 24, 'unit' => 'hours']]]);
        $before = LabelPrintLog::query()->count();
        // « Mise sous vide » demandée, la fiche ne prévoit que « Préparation » : refus explicite, rien n'est journalisé
        $this->postJson('/api/admin/labels/dlc/print', ['product_id' => $p['id'], 'action' => 'vacuum'])
            ->assertStatus(422)->assertJsonPath('status', 'unknown_action')
            ->assertJsonPath('message', '« Mise sous vide » n’est pas prévu pour « Seul » : ajoutez-le à sa fiche, ou choisissez un autre événement.');
        $this->assertSame($before, LabelPrintLog::query()->count());
        $this->postJson('/api/admin/labels/dlc/print', ['product_id' => $p['id'], 'action' => 'inconnu'])->assertStatus(422);
        // sans événement demandé : celui de la fiche
        $this->assertSame('prepared', $this->postJson('/api/admin/labels/dlc/print', ['product_id' => $p['id']])->assertOk()->json('action'));
    }

    // ───────────── revue HACCP : conservation, chaîne des étiquettes, hors ligne, surveillance ─────────────

    private function printed(int $productId, array $extra = []): array
    {
        $r = $this->postJson('/api/admin/labels/dlc/print', ['product_id' => $productId] + $extra)->assertOk()->json();
        $this->postJson("/api/admin/labels/prints/{$r['log_id']}/result", ['status' => 'printed'])->assertOk();

        return $r;
    }

    public function test_une_etiquette_de_decongelation_ne_dit_jamais_de_remettre_au_congelateur(): void
    {
        $this->caisse();
        // fiche ANCIENNE (antérieure à la conservation après décongélation) : surgelée, sans valeur choisie → repli froid positif
        $legacy = $this->product(['name' => 'Steaks hachés', 'storage' => '≤ −18 °C', 'actions' => [
            ['key' => 'thawed', 'value' => 3, 'unit' => 'days'], ['key' => 'opened', 'value' => 48, 'unit' => 'hours'],
        ]]);
        $p = ['id' => $legacy->id];

        $thawed = $this->postJson('/api/admin/labels/dlc/print', ['product_id' => $p['id'], 'action' => 'thawed'])->assertOk()->json();
        $this->assertSame('0 à +4 °C', $thawed['storage']);
        $this->assertSame('0 à +4 °C', LabelPrintLog::query()->findOrFail($thawed['log_id'])->storage, 'le journal dit ce que dit le papier');

        // le même produit simplement OUVERT (sachet de surgelés entamé) reste au congélateur
        $opened = $this->postJson('/api/admin/labels/dlc/print', ['product_id' => $p['id'], 'action' => 'opened'])->assertOk()->json();
        $this->assertSame('≤ −18 °C', $opened['storage']);

        // « Ambiante » ou « -18 » écrit à la main : même règle ; un froid positif est gardé tel quel
        $this->assertSame('0 à +4 °C', (new DlcLabelProduct(['storage' => 'Ambiante']))->storageFor('thawed'));
        $this->assertSame('0 à +4 °C', (new DlcLabelProduct(['storage' => '-18°C']))->storageFor('thawed'));
        $this->assertSame('0 à +3 °C', (new DlcLabelProduct(['storage' => '0 à +3 °C']))->storageFor('thawed'));
        $this->assertSame('2-4 °C', (new DlcLabelProduct(['storage' => '2-4 °C']))->storageFor('thawed'), 'plage positive : le tiret n’est pas un signe moins');
        $this->assertSame('0 à +4 °C', (new DlcLabelProduct(['storage' => '0 à -2 °C']))->storageFor('thawed'));
    }

    public function test_un_produit_fait_a_partir_d_un_autre_ne_depasse_jamais_sa_dlc_sauf_cuisson(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-10-16 10:00:00', config('app.timezone')));
        $this->caisse();
        $source = $this->expertProduct(['name' => 'Poulet', 'actions' => [['key' => 'thawed', 'value' => 48, 'unit' => 'hours']]]);
        $made = $this->expertProduct(['name' => 'Poulet mariné maison', 'actions' => [
            ['key' => 'prepared', 'value' => 3, 'unit' => 'days'], ['key' => 'cooked', 'value' => 3, 'unit' => 'days'],
        ]]);
        $parent = $this->printed($source['id']); // DLC 18/10 10:00

        $child = $this->postJson('/api/admin/labels/dlc/print', ['product_id' => $made['id'], 'parent_log_id' => $parent['log_id']])->assertOk()->json();
        $this->assertTrue($child['capped']);
        $this->assertSame('parent', $child['cap_source']);
        $this->assertSame('2026-10-18 10:00', Carbon::parse($child['expires_at'])->format('Y-m-d H:i'), 'la DLC suit celle du poulet');
        $this->assertSame(['lot' => $parent['lot'], 'name' => 'Poulet'], $child['parent']);
        $this->assertSame($parent['log_id'], LabelPrintLog::query()->findOrFail($child['log_id'])->parent_log_id);

        // la cuisson est un traitement assainissant : nouvelle durée propre
        $cooked = $this->postJson('/api/admin/labels/dlc/print', ['product_id' => $made['id'], 'action' => 'cooked', 'parent_log_id' => $parent['log_id']])->assertOk()->json();
        $this->assertFalse($cooked['capped']);
        $this->assertSame('2026-10-19 10:00', Carbon::parse($cooked['expires_at'])->format('Y-m-d H:i'));

        // produit d'origine périmé : refus, même pour cuire
        Carbon::setTestNow(Carbon::parse('2026-10-18 10:01:00', config('app.timezone')));
        $this->postJson('/api/admin/labels/dlc/print', ['product_id' => $made['id'], 'action' => 'cooked', 'parent_log_id' => $parent['log_id']])
            ->assertStatus(422)->assertJsonPath('status', 'parent_expired');
        Carbon::setTestNow(Carbon::parse('2026-10-16 11:00:00', config('app.timezone')));

        // produit d'origine JETÉ : refus (« utilisé » à l'instant reste accepté 2 h — voir le test dédié)
        $this->postJson('/api/admin/labels/prints/discard', ['ids' => [$parent['log_id']], 'reason' => 'discarded'])->assertOk();
        $this->postJson('/api/admin/labels/dlc/print', ['product_id' => $made['id'], 'parent_log_id' => $parent['log_id']])
            ->assertStatus(422)->assertJsonPath('status', 'parent_closed');

        // une étiquette jamais sortie de l'imprimante, ou d'une autre branche, n'est pas une origine
        $failed = $this->postJson('/api/admin/labels/dlc/print', ['product_id' => $source['id']])->json('log_id');
        $this->postJson("/api/admin/labels/prints/{$failed}/result", ['status' => 'failed'])->assertOk();
        $this->postJson('/api/admin/labels/dlc/print', ['product_id' => $made['id'], 'parent_log_id' => $failed])
            ->assertStatus(422)->assertJsonPath('status', 'parent_unknown');
        $other = $this->product(['branch_id' => 2, 'name' => 'Ailleurs']);
        $this->caisse(2);
        $foreign = $this->printed($other->id);
        $this->caisse(1);
        $this->postJson('/api/admin/labels/dlc/print', ['product_id' => $made['id'], 'parent_log_id' => $foreign['log_id']])
            ->assertStatus(422)->assertJsonPath('status', 'parent_unknown');
        Carbon::setTestNow();
    }

    public function test_la_dlc_et_le_lot_du_fabricant_sont_imprimes_et_journalises(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-10-16 10:00:00', config('app.timezone')));
        $this->caisse();
        $p = $this->expertProduct(['name' => 'Crème', 'ask_original_dlc' => true, 'actions' => [['key' => 'opened', 'value' => 2, 'unit' => 'days']]]);
        $r = $this->postJson('/api/admin/labels/dlc/print', ['product_id' => $p['id'], 'original_dlc' => '2026-10-30', 'supplier_lot' => ' 2345A '])->assertOk()->json();
        $this->assertFalse($r['capped']);
        $this->assertSame('2026-10-30', $r['original_dlc']); // imprimée même quand elle ne limite pas
        $this->assertSame('2345A', $r['supplier_lot']);
        $this->assertSame('2345A', LabelPrintLog::query()->findOrFail($r['log_id'])->supplier_lot);
        $this->assertSame('original', $this->postJson('/api/admin/labels/dlc/print', ['product_id' => $p['id'], 'original_dlc' => '2026-10-17'])->json('cap_source'));
        Carbon::setTestNow();
    }

    public function test_une_etiquette_imprimee_hors_ligne_est_journalisee_au_retour_du_reseau_sans_doublon(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-10-16 10:00:00', config('app.timezone')));
        $this->caisse();
        $p = $this->expertProduct(['name' => 'Tomates', 'actions' => [['key' => 'prepared', 'value' => 24, 'unit' => 'hours']]]);
        // imprimée à 09:30 heure de Paris, envoyée en UTC (« Z ») par la tablette
        $body = [
            'client_ref' => 'tab-1-abcdef12', 'product_id' => $p['id'], 'action' => 'prepared', 'copies' => 2,
            'opened_at' => '2026-10-16T07:30:00Z', 'expires_at' => '2026-10-17T07:30:00Z', 'status' => 'printed',
        ];
        $r = $this->postJson('/api/admin/labels/dlc/offline', $body)->assertOk()->json();
        $this->assertFalse($r['replayed']);
        $log = LabelPrintLog::query()->findOrFail($r['log_id']);
        $this->assertTrue($log->offline);
        $this->assertSame('2026-10-16 09:30', $log->opened_at->format('Y-m-d H:i'), 'heure de Paris, pas l’heure UTC');
        $this->assertSame('2026-10-17 09:30', $log->expires_at->format('Y-m-d H:i'));
        $this->assertSame('printed', $log->status);
        $this->assertMatchesRegularExpression('/^L261016-\d{4}$/', $log->lot);
        $this->assertSame(2, $log->printed_pages);

        // la file de la tablette rejoue l'envoi : jamais de doublon
        $again = $this->postJson('/api/admin/labels/dlc/offline', $body)->assertOk()->json();
        $this->assertTrue($again['replayed']);
        $this->assertSame($r['log_id'], $again['log_id']);
        $this->assertSame(1, LabelPrintLog::query()->where('client_ref', 'tab-1-abcdef12')->count());

        // Ce que le serveur n'aurait pas imprimé (DLC plus longue que la fiche, heure absurde, événement retiré) est
        // QUAND MÊME journalisé — le papier existe, il est sur un bac — et signalé « à vérifier », jamais rejeté en silence.
        foreach ([
            ['tab-1-long0001', ['expires_at' => '2026-10-18T07:30:00Z'], 'DLC imprimée plus longue que la fiche'],
            ['tab-1-old00001', ['opened_at' => '2026-10-01T07:30:00Z', 'expires_at' => '2026-10-02T07:30:00Z'], 'heure de la tablette incohérente'],
            ['tab-1-act00001', ['action' => 'vacuum'], 'non prévu par la fiche'],
        ] as [$ref, $over, $why]) {
            $res = $this->postJson('/api/admin/labels/dlc/offline', ['client_ref' => $ref] + $over + $body)->assertOk()->json();
            $this->assertTrue($res['anomaly'], $why);
            $row = LabelPrintLog::query()->findOrFail($res['log_id']);
            $this->assertTrue($row->anomaly);
            $this->assertStringContainsString($why, $row->message);
        }
        // produit supprimé depuis : journalisé sous le nom envoyé par la tablette
        $gone = $this->postJson('/api/admin/labels/dlc/offline', ['client_ref' => 'tab-1-gone0001', 'product_id' => 999999, 'product_name' => 'Ancien produit'] + $body)->assertOk()->json();
        $this->assertSame('Ancien produit', LabelPrintLog::query()->findOrFail($gone['log_id'])->product_name);
        $this->assertTrue($gone['anomaly']);
        // la conservation IMPRIMÉE est celle que la tablette a mise sur le papier
        $kept = $this->postJson('/api/admin/labels/dlc/offline', ['client_ref' => 'tab-1-stor0001', 'storage' => '0 à +2 °C'] + $body)->assertOk()->json();
        $this->assertSame('0 à +2 °C', LabelPrintLog::query()->findOrFail($kept['log_id'])->storage);

        // elle entre dans la surveillance comme les autres
        $this->assertContains($r['log_id'], collect($this->getJson('/api/admin/labels/watch')->json('data'))->pluck('id')->all());
        Carbon::setTestNow();
    }

    public function test_la_surveillance_garde_les_lots_echus_tant_que_personne_ne_les_a_sortis(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-10-10 10:00:00', config('app.timezone')));
        $this->caisse();
        $p = $this->expertProduct(['name' => 'Olives', 'actions' => [['key' => 'opened', 'value' => 24, 'unit' => 'hours']]]);
        $old = $this->printed($p['id']);            // échue le 11/10 10:00
        $veryOld = null;
        Carbon::setTestNow(Carbon::parse('2026-10-02 10:00:00', config('app.timezone')));
        $veryOld = $this->printed($p['id']);        // échue le 03/10 : plus de 7 jours → journal seul
        Carbon::setTestNow(Carbon::parse('2026-10-12 21:00:00', config('app.timezone')));
        // 60 impressions plus récentes : l'historique (50 lignes) n'a plus le lot échu, la surveillance si
        $q = $this->expertProduct(['name' => 'Long', 'actions' => [['key' => 'opened', 'value' => 10, 'unit' => 'days']]]);
        // (écrites directement : 60 appels HTTP dépasseraient la limite de débit de l'API)
        for ($i = 0; $i < 60; $i++) {
            $row = new LabelPrintLog([
                'kind' => LabelPrintLog::KIND_DLC, 'dlc_product_id' => $q['id'], 'product_name' => 'Long', 'action' => 'opened',
                'shelf_life_value' => 10, 'shelf_life_unit' => 'days', 'opened_at' => now(), 'expires_at' => now()->addDays(10),
                'copies' => 1, 'status' => LabelPrintLog::STATUS_PRINTED,
            ]);
            $row->branch_id = 1;
            $row->save();
            $id = $row->id;
        }
        $this->assertNotContains($old['log_id'], collect($this->getJson('/api/admin/labels/history')->json('data'))->pluck('id')->all());

        $watch = collect($this->getJson('/api/admin/labels/watch')->assertOk()->json('data'));
        $this->assertContains($old['log_id'], $watch->pluck('id')->all(), 'échu depuis 35 h, jamais sorti : toujours signalé');
        $this->assertNotContains($veryOld['log_id'], $watch->pluck('id')->all());
        $this->assertNotContains($id, $watch->pluck('id')->all(), 'à échoir dans 10 jours : pas encore à surveiller');

        // sorti comme « utilisé » : quitte la surveillance, reste au journal avec sa raison et son auteur
        $this->postJson('/api/admin/labels/prints/discard', ['ids' => [$old['log_id']], 'reason' => 'used'])->assertOk()->assertJsonPath('discarded', 1);
        $this->assertNotContains($old['log_id'], collect($this->getJson('/api/admin/labels/watch')->json('data'))->pluck('id')->all());
        $row = LabelPrintLog::query()->findOrFail($old['log_id']);
        $this->assertSame('used', $row->discard_reason);
        $this->assertNotNull($row->discarded_by);
        $this->postJson('/api/admin/labels/prints/discard', ['ids' => [$old['log_id']], 'reason' => 'perdu'])->assertStatus(422);
        Carbon::setTestNow();
    }

    public function test_conservation_apres_decongelation_choisie_par_produit(): void
    {
        $this->caisse();
        // surgelé + décongélation sans conservation après décongélation : refus, avec la raison
        $this->postJson('/api/admin/labels/products', [
            'name' => 'Steak haché', 'storage' => '≤ −18 °C', 'actions' => [['key' => 'thawed', 'value' => 24, 'unit' => 'hours']],
        ])->assertStatus(422)->assertJsonPath('errors.thawed_storage.0', 'Indiquez la conservation après décongélation (ex. 0 à +2 °C pour une viande hachée, 0 à +4 °C, ambiante pour un pain).');

        // viande hachée : +2 °C au plus (arrêté du 21/12/2009, annexe I) — imprimé et journalisé tel quel
        $steak = $this->postJson('/api/admin/labels/products', [
            'name' => 'Steak haché', 'storage' => '≤ −18 °C', 'thawed_storage' => '0 à +2 °C', 'actions' => [['key' => 'thawed', 'value' => 24, 'unit' => 'hours']],
        ])->assertCreated()->json('data');
        $this->assertSame('0 à +2 °C', $steak['thawed_storage']);
        $r = $this->postJson('/api/admin/labels/dlc/print', ['product_id' => $steak['id']])->assertOk()->json();
        $this->assertSame('0 à +2 °C', $r['storage']);
        $this->assertSame('0 à +2 °C', LabelPrintLog::query()->findOrFail($r['log_id'])->storage);

        // pain décongelé à température ambiante : « Ambiante » n'est plus écrasé
        $pain = $this->postJson('/api/admin/labels/products', [
            'name' => 'Pains burger', 'storage' => '≤ −18 °C', 'thawed_storage' => 'Ambiante', 'actions' => [['key' => 'thawed', 'value' => 48, 'unit' => 'hours']],
        ])->assertCreated()->json('data');
        $this->assertSame('Ambiante', $this->postJson('/api/admin/labels/dlc/print', ['product_id' => $pain['id']])->json('storage'));

        // masquer la fiche ne redemande rien (la conservation n'est pas touchée)
        $this->putJson('/api/admin/labels/products/'.$steak['id'], ['name' => 'Steak haché', 'actions' => $steak['actions'], 'is_active' => false])->assertOk();

        // froid positif reconnu par liste blanche : tiret demi-cadratin, « surgelé », « froid négatif » ne le sont pas
        foreach (['–18 °C', 'Surgelé', 'Froid négatif', 'Congélateur', 'Ambiante', '-18', 'Chambre froide'] as $notChilled) {
            $this->assertFalse(DlcLabelProduct::isChilled($notChilled), $notChilled);
        }
        foreach (['0 à +4 °C', '2-4 °C', '+3 °C', '0 à +2 °C'] as $chilled) {
            $this->assertTrue(DlcLabelProduct::isChilled($chilled), $chilled);
        }
    }

    public function test_un_produit_fait_a_partir_d_un_autre_exige_son_origine_ou_produit_neuf(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-10-16 10:00:00', config('app.timezone')));
        $this->caisse();
        $source = $this->expertProduct(['name' => 'Poulet', 'actions' => [['key' => 'thawed', 'value' => 48, 'unit' => 'hours']]]);
        $made = $this->expertProduct(['name' => 'Poulet mariné', 'needs_parent' => true, 'actions' => [['key' => 'prepared', 'value' => 3, 'unit' => 'days']]]);
        $this->assertTrue($made['needs_parent']);

        // un appui sans origine : refus explicite — l'horloge ne repart jamais à zéro par oubli
        $this->postJson('/api/admin/labels/dlc/print', ['product_id' => $made['id']])
            ->assertStatus(422)->assertJsonPath('status', 'parent_required');
        // « produit neuf » déclaré : accepté
        $this->postJson('/api/admin/labels/dlc/print', ['product_id' => $made['id'], 'parent_none' => true])->assertOk();

        // bac d'origine marqué « utilisé » il y a 30 min : encore une origine valable (on vide, puis on étiquette)
        $parent = $this->printed($source['id']);
        Carbon::setTestNow(Carbon::parse('2026-10-16 10:30:00', config('app.timezone')));
        $this->postJson('/api/admin/labels/prints/discard', ['ids' => [$parent['log_id']], 'reason' => 'used'])->assertOk();
        Carbon::setTestNow(Carbon::parse('2026-10-16 11:00:00', config('app.timezone')));
        $ok = $this->postJson('/api/admin/labels/dlc/print', ['product_id' => $made['id'], 'parent_log_id' => $parent['log_id']])->assertOk()->json();
        $this->assertSame('parent', $ok['cap_source']);
        $this->assertContains($parent['log_id'], collect($this->getJson('/api/admin/labels/lots')->json('data'))->pluck('id')->all());
        // … au-delà de 2 h, non
        Carbon::setTestNow(Carbon::parse('2026-10-16 12:31:00', config('app.timezone')));
        $this->postJson('/api/admin/labels/dlc/print', ['product_id' => $made['id'], 'parent_log_id' => $parent['log_id']])
            ->assertStatus(422)->assertJsonPath('status', 'parent_closed');
        $this->assertNotContains($parent['log_id'], collect($this->getJson('/api/admin/labels/lots')->json('data'))->pluck('id')->all());
        Carbon::setTestNow();
    }

    public function test_les_lots_en_cours_couvrent_tout_le_journal_pas_seulement_72_h(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-10-16 10:00:00', config('app.timezone')));
        $this->caisse();
        $long = $this->expertProduct(['name' => 'Sauce longue', 'actions' => [['key' => 'prepared', 'value' => 6, 'unit' => 'days']]]);
        $a = $this->printed($long['id']);                    // échéance dans 6 jours : hors surveillance, mais en cours
        $b = $this->printed($long['id']);
        $this->postJson('/api/admin/labels/prints/discard', ['ids' => [$b['log_id']], 'reason' => 'discarded'])->assertOk();
        $ids = collect($this->getJson('/api/admin/labels/lots')->assertOk()->json('data'))->pluck('id')->all();
        $this->assertContains($a['log_id'], $ids);
        $this->assertNotContains($b['log_id'], $ids, 'jeté : plus une origine');
        $this->assertNotContains($a['log_id'], collect($this->getJson('/api/admin/labels/watch')->json('data'))->pluck('id')->all());
        Carbon::setTestNow();
    }

    public function test_une_fiche_sans_conservation_n_imprime_pas_et_dit_quoi_faire(): void
    {
        $this->caisse();
        $old = $this->product(['name' => 'Ancienne fiche', 'storage' => null]);
        $this->postJson('/api/admin/labels/dlc/print', ['product_id' => $old->id])
            ->assertStatus(422)->assertJsonPath('status', 'storage_missing')
            ->assertJsonPath('message', 'Renseignez la conservation de « Ancienne fiche » (Modifier la fiche) : elle est imprimée sur l’étiquette.');
    }

    public function test_aucun_allergene_declare_et_auteur_du_jete(): void
    {
        $this->caisse();
        $p = $this->expertProduct(['name' => 'Tomates', 'allergens' => [], 'no_allergens' => true]);
        $this->assertTrue($p['no_allergens']);
        $this->assertTrue($this->postJson('/api/admin/labels/dlc/print', ['product_id' => $p['id']])->json('no_allergens'));
        // des allergènes cochés l'emportent : jamais « aucun » et « lait » à la fois
        $q = $this->expertProduct(['name' => 'Crème', 'allergens' => ['lait'], 'no_allergens' => true]);
        $this->assertFalse($q['no_allergens']);

        $r = $this->printed($p['id']);
        $this->postJson('/api/admin/labels/prints/discard', ['ids' => [$r['log_id']]])->assertOk();
        $row = collect($this->getJson('/api/admin/labels/history')->json('data'))->firstWhere('id', $r['log_id']);
        $this->assertSame('Karim', $row['discarded_by']);
        $this->assertSame('discarded', $row['discard_reason']);
    }

    public function test_une_etiquette_annulee_ne_compte_pas_dans_les_plus_utilises(): void
    {
        $this->caisse();
        $p = $this->product(['name' => 'Erreur']);
        $r = $this->printed($p->id, ['copies' => 4]);
        $this->postJson('/api/admin/labels/prints/discard', ['ids' => [$r['log_id']], 'reason' => 'cancelled'])->assertOk();
        $row = collect($this->getJson('/api/admin/labels/products')->json('data'))->firstWhere('id', $p->id);
        $this->assertSame(0, $row['uses_30d']);
        $this->postJson('/api/admin/labels/prints/discard', ['ids' => [$r['log_id']], 'restore' => true])->assertOk();
        $restored = LabelPrintLog::query()->findOrFail($r['log_id']);
        $this->assertNull($restored->discard_reason);
        $this->assertNull($restored->discarded_by);
    }
}

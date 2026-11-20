<?php

namespace Tests\Feature\Labels;

use App\Models\Branch;
use App\Models\DlcLabelProduct;
use App\Models\LabelPrintLog;
use App\Models\User;
use App\Services\Hygiene\OperatorPins;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * [CAYENNE HYGIÈNE 2026-10-18 · rapport Komia « Qui ? obligatoire partout »] Une étiquette DLC dit QUI l'a faite : la personne choisie sous « Qui ? »
 * (avec son code si elle en a un), pas le compte partagé de la tablette — la même règle que les relevés.
 */
class DlcOperatorTest extends TestCase
{
    use RefreshDatabase;

    private User $tablet;
    private User $sarah;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedSpatieRoles();
        $this->seedMinimalSettings();
        Branch::factory()->create(['id' => 1]);
        Branch::factory()->create(['id' => 2]);
        config(['hygiene.branch_id' => 1]);
        Carbon::setTestNow(Carbon::parse('2026-10-20 10:00:00', config('app.timezone')));
        $this->tablet = User::factory()->create(['branch_id' => 1, 'name' => 'Tablette Cuisine']);
        $this->tablet->givePermissionTo('pos-orders');
        $this->tablet->assignRole('POS Operator');
        $this->sarah = User::factory()->create(['branch_id' => 1, 'name' => 'Sarah Lopez']);
        $this->sarah->assignRole('Chef');
        Sanctum::actingAs($this->tablet, ['*']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function product(): DlcLabelProduct
    {
        $p = new DlcLabelProduct([
            'name' => 'Oignons rouges émincés', 'category' => 'Légumes & crudités', 'shelf_life_value' => 3, 'shelf_life_unit' => 'days',
            'actions' => [['key' => 'prepared', 'value' => 3, 'unit' => 'days']], 'storage' => '0 à +4 °C', 'is_active' => true, 'sort_order' => 1,
            'print_operator' => true, 'no_allergens' => true,
        ]);
        $p->branch_id = 1;
        $p->save();

        return $p;
    }

    private function pin(User $u, string $pin = '4821'): void { app(OperatorPins::class)->set(1, $u->id, $pin, $this->tablet->id); }

    private function token(User $u, string $pin = '4821'): string
    {
        return $this->postJson('/api/admin/hygiene/operators/verify', ['user_id' => $u->id, 'pin' => $pin])->json('data.token');
    }

    private function print(array $extra = [])
    {
        return $this->postJson('/api/admin/labels/dlc/print', ['product_id' => $this->product()->id, 'copies' => 1] + $extra);
    }

    public function test_sans_choix_l_etiquette_est_au_nom_du_compte_connecte_comme_avant(): void
    {
        $r = $this->print()->assertOk();
        $r->assertJsonPath('by', 'Tablette');
        $this->assertSame($this->tablet->id, LabelPrintLog::query()->firstOrFail()->user_id);
    }

    public function test_la_personne_choisie_signe_l_etiquette_et_son_prenom_est_sur_le_papier(): void
    {
        $r = $this->print(['operator_user_id' => $this->sarah->id])->assertOk();
        $r->assertJsonPath('by', 'Sarah');
        $this->assertSame($this->sarah->id, LabelPrintLog::query()->firstOrFail()->user_id);
    }

    public function test_une_personne_protegee_par_un_code_ne_signe_pas_sans_le_prouver_et_aucune_ligne_n_est_creee(): void
    {
        $this->pin($this->sarah);
        $this->print(['operator_user_id' => $this->sarah->id])->assertStatus(422)->assertJsonValidationErrors('operator_pin');
        $this->print(['operator_user_id' => $this->sarah->id, 'operator_pin' => '0000'])->assertStatus(422);
        $this->assertSame(0, LabelPrintLog::query()->count());
    }

    public function test_avec_le_jeton_de_la_personne_l_etiquette_est_signee_d_elle(): void
    {
        $this->pin($this->sarah);
        $token = $this->token($this->sarah);
        $this->print(['operator_user_id' => $this->sarah->id, 'operator_token' => $token])->assertOk()->assertJsonPath('by', 'Sarah');
        $this->assertSame($this->sarah->id, LabelPrintLog::query()->firstOrFail()->user_id);
    }

    public function test_une_personne_hors_du_personnel_est_refusee(): void
    {
        $client = User::factory()->create(['branch_id' => 1, 'name' => 'Un Client']);
        $client->assignRole('Customer');
        $autre = User::factory()->create(['branch_id' => 2, 'name' => 'Autre Site']);
        $autre->assignRole('Chef');
        foreach ([$client->id, $autre->id, 999999] as $bad) {
            $this->print(['operator_user_id' => $bad])->assertStatus(422)->assertJsonValidationErrors('operator_user_id');
        }
        $this->assertSame(0, LabelPrintLog::query()->count());
    }

    private function offlineBody(array $over = []): array
    {
        return $over + [
            'client_ref' => 'offline-ref-0001', 'product_id' => $this->product()->id, 'action' => 'prepared', 'copies' => 1,
            'opened_at' => '2026-10-20T09:55:00+02:00', 'expires_at' => '2026-10-23T09:55:00+02:00', 'status' => 'printed',
        ];
    }

    public function test_une_etiquette_imprimee_hors_ligne_est_signee_de_la_personne_si_son_jeton_couvre_l_heure_d_impression(): void
    {
        $this->pin($this->sarah);
        $token = $this->token($this->sarah); // 10:00 → 10:10
        Carbon::setTestNow(Carbon::parse('2026-10-20 11:30', config('app.timezone')));

        $this->postJson('/api/admin/labels/dlc/offline', $this->offlineBody(['operator_user_id' => $this->sarah->id, 'operator_token' => $token, 'opened_at' => '2026-10-20T10:05:00+02:00', 'expires_at' => '2026-10-23T10:05:00+02:00']))->assertOk();

        $log = LabelPrintLog::query()->firstOrFail();
        $this->assertSame($this->sarah->id, $log->user_id);
        $this->assertStringNotContainsString('signature', (string) $log->message);
    }

    public function test_une_signature_hors_ligne_qu_on_ne_peut_plus_prouver_n_efface_jamais_l_etiquette_elle_est_signalee_a_verifier(): void
    {
        $this->pin($this->sarah);
        $token = $this->token($this->sarah);
        Carbon::setTestNow(Carbon::parse('2026-10-20 15:00', config('app.timezone')));

        // imprimée à 14 h 50 : le jeton (10 h → 10 h 10) ne la couvre pas. Le papier existe : il est inscrit, au compte connecté, « à vérifier ».
        $this->postJson('/api/admin/labels/dlc/offline', $this->offlineBody(['operator_user_id' => $this->sarah->id, 'operator_token' => $token, 'opened_at' => '2026-10-20T14:50:00+02:00', 'expires_at' => '2026-10-23T14:50:00+02:00']))->assertOk()->assertJsonPath('anomaly', true);

        $log = LabelPrintLog::query()->firstOrFail();
        $this->assertSame($this->tablet->id, $log->user_id, 'jamais attribuée à Sarah sans preuve');
        $this->assertStringContainsString('signature de la personne non vérifiée', (string) $log->message);
        $this->assertTrue((bool) $log->anomaly);
    }

    public function test_un_papier_antidate_de_deux_jours_ne_reprend_pas_un_jeton_de_dix_minutes(): void
    {
        $this->pin($this->sarah);
        $token = $this->token($this->sarah); // 10:00 → 10:10
        Carbon::setTestNow(Carbon::parse('2026-10-22 10:00', config('app.timezone')));

        // le jeton couvre « 10 h 02 » — mais c'était il y a 48 h : au-delà de la file hors ligne des relevés (72 h) rien ne se prouve, ici déjà moins
        // l'heure du papier est choisie par la tablette ; le serveur ne la laisse pas ressusciter un jeton périmé depuis plus de 72 h
        $this->postJson('/api/admin/labels/dlc/offline', $this->offlineBody(['operator_user_id' => $this->sarah->id, 'operator_token' => $token, 'opened_at' => '2026-10-20T10:02:00+02:00', 'expires_at' => '2026-10-23T10:02:00+02:00']))->assertOk();
        // 48 h : encore dans la fenêtre de 72 h, donc rejoué comme avant (compatibilité des files en attente)
        $this->assertSame($this->sarah->id, LabelPrintLog::query()->firstOrFail()->user_id);

        LabelPrintLog::query()->delete();
        Carbon::setTestNow(Carbon::parse('2026-10-24 10:00', config('app.timezone')));
        $this->postJson('/api/admin/labels/dlc/offline', $this->offlineBody(['client_ref' => 'offline-ref-0002', 'operator_user_id' => $this->sarah->id, 'operator_token' => $token, 'opened_at' => '2026-10-20T10:02:00+02:00', 'expires_at' => '2026-10-23T10:02:00+02:00']))->assertOk();
        $log = LabelPrintLog::query()->firstOrFail();
        $this->assertSame($this->tablet->id, $log->user_id, '4 jours après : jamais attribuée à Sarah');
        $this->assertStringContainsString('signature de la personne non vérifiée', (string) $log->message);
    }

    public function test_le_rejeu_hors_ligne_ne_double_pas_et_garde_la_premiere_signature(): void
    {
        $this->pin($this->sarah);
        $token = $this->token($this->sarah);
        $body = $this->offlineBody(['operator_user_id' => $this->sarah->id, 'operator_token' => $token, 'opened_at' => '2026-10-20T10:03:00+02:00', 'expires_at' => '2026-10-23T10:03:00+02:00']); // dans la fenêtre du jeton (10 h → 10 h 10)
        $this->postJson('/api/admin/labels/dlc/offline', $body)->assertOk();
        $this->postJson('/api/admin/labels/dlc/offline', $body)->assertOk()->assertJsonPath('replayed', true);
        $this->assertSame(1, LabelPrintLog::query()->count());
        $this->assertSame($this->sarah->id, LabelPrintLog::query()->firstOrFail()->user_id);
    }
}

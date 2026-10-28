<?php

namespace Tests\Feature\Hygiene;

use App\Models\Branch;
use App\Models\HygieneEquipment;
use App\Models\HygieneOperatorPin;
use App\Models\HygieneTemperatureReading;
use App\Models\User;
use App\Services\Hygiene\OperatorPins;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * [CAYENNE HYGIÈNE 2026-10-18 · rapport Komia P1 « Qui ? sans code »] Sur la tablette partagée, personne ne signe au nom d'un collègue qui a un
 * code sans le connaître. Facultatif par personne ; essais limités ; le code n'est jamais gardé (jeton signé, y compris hors ligne).
 */
class OperatorPinTest extends TestCase
{
    use RefreshDatabase;

    private const TEMP = '/api/admin/hygiene/temperature';
    private const OPS = '/api/admin/hygiene/operators';
    private const TEAM = '/api/admin/hygiene/team';

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
        $this->tablet = User::factory()->create(['branch_id' => 1, 'name' => 'Compte Tablette']);
        $this->tablet->givePermissionTo('pos-orders');
        $this->tablet->assignRole('POS Operator');
        $this->sarah = User::factory()->create(['branch_id' => 1, 'name' => 'Sarah Lopez']);
        $this->sarah->assignRole('Chef');
        Sanctum::actingAs($this->tablet, ['*']);
        $this->postJson(self::TEMP.'/equipment/quick-setup', ['fridge' => 2, 'freezer' => 0])->assertCreated();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function pins(): OperatorPins { return app(OperatorPins::class); }

    private function givePin(User $u, string $pin = '4821'): void { $this->pins()->set(1, $u->id, $pin, $this->tablet->id); }

    private function reading(array $extra = [], string $name = 'Frigo 1'): array
    {
        $id = HygieneEquipment::query()->where('name', $name)->value('id');

        return ['readings' => [['equipment_id' => $id, 'value' => 3.0] + (isset($extra['_reading']) ? $extra['_reading'] : [])]] + array_diff_key($extra, ['_reading' => 1]);
    }

    private function sign(array $extra = [], string $name = 'Frigo 1')
    {
        return $this->postJson(self::TEMP.'/readings', $this->reading(['operator_user_id' => $this->sarah->id] + $extra, $name));
    }

    // ───────────── sans code : comme avant ─────────────

    public function test_sans_code_on_signe_au_nom_d_un_collegue_comme_avant(): void
    {
        $this->sign()->assertCreated()->assertJsonPath('data.0.operator_name', 'Sarah Lopez');
    }

    // ───────────── avec code ─────────────

    public function test_avec_code_signer_au_nom_de_la_personne_sans_preuve_est_refuse_et_rien_n_est_enregistre(): void
    {
        $this->givePin($this->sarah);
        $this->sign()->assertStatus(422)->assertJsonValidationErrors('operator_pin');
        $this->assertSame(0, HygieneTemperatureReading::query()->count());
    }

    public function test_un_faux_code_est_refuse_avec_les_essais_restants_et_le_bon_code_passe(): void
    {
        $this->givePin($this->sarah);
        $this->sign(['operator_pin' => '0000'])->assertStatus(422)->assertJsonPath('errors.operator_pin.0', fn ($m) => str_contains($m, '4 essais restants'));
        $this->assertSame(0, HygieneTemperatureReading::query()->count());
        $this->sign(['operator_pin' => '4821'])->assertCreated()->assertJsonPath('data.0.operator_name', 'Sarah Lopez');
        $this->assertSame($this->sarah->id, HygieneTemperatureReading::query()->firstOrFail()->user_id);
    }

    public function test_cinq_faux_codes_bloquent_la_personne_meme_pour_le_bon_code(): void
    {
        $this->givePin($this->sarah);
        for ($i = 0; $i < 4; $i++) { $this->sign(['operator_pin' => '1111'], 'Frigo 1')->assertStatus(422); }
        $this->sign(['operator_pin' => '1111'])->assertStatus(423);
        $this->sign(['operator_pin' => '4821'])->assertStatus(423)->assertJsonPath('errors.operator_pin.0', fn ($m) => str_contains($m, 'bloqué'));
        $this->assertSame(0, HygieneTemperatureReading::query()->count());

        Carbon::setTestNow(Carbon::now()->addMinutes(11)); // le verrou tombe
        $this->sign(['operator_pin' => '4821'])->assertCreated();
    }

    public function test_choisir_son_propre_compte_ne_demande_aucun_code(): void
    {
        $this->givePin($this->tablet);
        $id = HygieneEquipment::query()->where('name', 'Frigo 1')->value('id');
        $this->postJson(self::TEMP.'/readings', ['operator_user_id' => $this->tablet->id, 'readings' => [['equipment_id' => $id, 'value' => 3.0]]])->assertCreated();
    }

    public function test_le_code_est_stocke_hache_et_jamais_rendu(): void
    {
        $this->givePin($this->sarah, '4821');
        $row = HygieneOperatorPin::withoutGlobalScopes()->firstOrFail();
        $this->assertNotSame('4821', $row->pin_hash);
        $this->assertStringNotContainsString('4821', json_encode($row->toArray()));
        $this->assertTrue(\Hash::check(hash_hmac('sha256', '4821', 'hygiene-pin|'.config('app.key')), $row->pin_hash));
    }

    // ───────────── vérification et jeton ─────────────

    public function test_verify_bon_code_rend_un_jeton_et_la_signature_passe_sans_renvoyer_le_code(): void
    {
        $this->givePin($this->sarah);
        $r = $this->postJson(self::OPS.'/verify', ['user_id' => $this->sarah->id, 'pin' => '4821'])->assertOk();
        $token = $r->json('data.token');
        $this->assertNotEmpty($token);

        $this->sign(['operator_token' => $token])->assertCreated()->assertJsonPath('data.0.operator_name', 'Sarah Lopez');
    }

    public function test_verify_mauvais_code_compte_les_essais_puis_bloque(): void
    {
        $this->givePin($this->sarah);
        $this->postJson(self::OPS.'/verify', ['user_id' => $this->sarah->id, 'pin' => '1234'])->assertStatus(422)->assertJsonPath('data.remaining', 4);
        for ($i = 0; $i < 3; $i++) { $this->postJson(self::OPS.'/verify', ['user_id' => $this->sarah->id, 'pin' => '1234'])->assertStatus(422); }
        $this->postJson(self::OPS.'/verify', ['user_id' => $this->sarah->id, 'pin' => '1234'])->assertStatus(423)->assertJsonPath('data.locked_minutes', 10);
        $this->postJson(self::OPS.'/verify', ['user_id' => $this->sarah->id, 'pin' => '4821'])->assertStatus(423);
    }

    public function test_verify_valide_le_format_et_la_personne(): void
    {
        $this->postJson(self::OPS.'/verify', ['user_id' => $this->sarah->id, 'pin' => '12'])->assertStatus(422)->assertJsonValidationErrors('pin');
        $this->postJson(self::OPS.'/verify', ['user_id' => $this->sarah->id, 'pin' => 'abcd'])->assertStatus(422);
        $this->postJson(self::OPS.'/verify', ['user_id' => 999999, 'pin' => '4821'])->assertStatus(422)->assertJsonValidationErrors('user_id');
        $client = User::factory()->create(['branch_id' => 1]); $client->assignRole('Customer');
        $this->postJson(self::OPS.'/verify', ['user_id' => $client->id, 'pin' => '4821'])->assertStatus(422);
    }

    public function test_verify_d_une_personne_sans_code_ne_rend_pas_de_jeton(): void
    {
        $this->postJson(self::OPS.'/verify', ['user_id' => $this->sarah->id, 'pin' => '4821'])->assertOk()->assertJsonPath('data.token', null);
    }

    public function test_un_jeton_ne_vaut_que_pour_sa_personne(): void
    {
        $this->givePin($this->sarah);
        $bob = User::factory()->create(['branch_id' => 1, 'name' => 'Bob Martin']); $bob->assignRole('Chef');
        $this->givePin($bob, '7777');
        $token = $this->postJson(self::OPS.'/verify', ['user_id' => $this->sarah->id, 'pin' => '4821'])->json('data.token');

        $id = HygieneEquipment::query()->where('name', 'Frigo 1')->value('id');
        $this->postJson(self::TEMP.'/readings', ['operator_user_id' => $bob->id, 'operator_token' => $token, 'readings' => [['equipment_id' => $id, 'value' => 3.0]]])
            ->assertStatus(422)->assertJsonValidationErrors('operator_token');
        $this->assertSame(0, HygieneTemperatureReading::query()->count());
    }

    public function test_un_jeton_forge_ou_modifie_est_refuse(): void
    {
        $this->givePin($this->sarah);
        $token = $this->postJson(self::OPS.'/verify', ['user_id' => $this->sarah->id, 'pin' => '4821'])->json('data.token');
        [$body, $sig] = explode('.', $token);
        $this->sign(['operator_token' => $body.'.'.strrev($sig)])->assertStatus(422);
        $this->sign(['operator_token' => 'n-importe-quoi'])->assertStatus(422);
        $this->sign(['operator_token' => $body])->assertStatus(422);
        $forged = rtrim(strtr(base64_encode(json_encode(['b' => 1, 'u' => $this->sarah->id, 'v' => 0, 'i' => 0, 'e' => 9999999999])), '+/', '-_'), '=');
        $this->sign(['operator_token' => $forged.'.'.$sig])->assertStatus(422);
        $this->assertSame(0, HygieneTemperatureReading::query()->count());
    }

    public function test_un_jeton_expire_apres_dix_minutes(): void
    {
        $this->givePin($this->sarah);
        $token = $this->postJson(self::OPS.'/verify', ['user_id' => $this->sarah->id, 'pin' => '4821'])->json('data.token');
        Carbon::setTestNow(Carbon::now()->addMinutes(9));
        $this->sign(['operator_token' => $token])->assertCreated();
        Carbon::setTestNow(Carbon::now()->addMinutes(2)); // 11 minutes après l'émission
        $this->sign(['operator_token' => $token], 'Frigo 2')->assertStatus(422)->assertJsonPath('errors.operator_token.0', fn ($m) => str_contains($m, 'expiré'));
    }

    public function test_changer_ou_retirer_le_code_tue_les_jetons_deja_emis(): void
    {
        $this->givePin($this->sarah);
        $token = $this->postJson(self::OPS.'/verify', ['user_id' => $this->sarah->id, 'pin' => '4821'])->json('data.token');

        Carbon::setTestNow(Carbon::now()->addSeconds(5));
        $this->givePin($this->sarah, '9090'); // le responsable change le code
        $this->sign(['operator_token' => $token])->assertStatus(422);

        $this->pins()->remove(1, $this->sarah->id); // puis le retire : la personne est libre, le jeton n'a plus d'objet
        $this->sign()->assertCreated();
    }

    public function test_le_mauvais_essai_d_un_tiers_ne_tue_pas_le_jeton_de_la_personne(): void
    {
        $this->givePin($this->sarah);
        $token = $this->postJson(self::OPS.'/verify', ['user_id' => $this->sarah->id, 'pin' => '4821'])->json('data.token');
        Carbon::setTestNow(Carbon::now()->addSeconds(30));
        $this->postJson(self::OPS.'/verify', ['user_id' => $this->sarah->id, 'pin' => '0000'])->assertStatus(422); // quelqu'un devine
        $this->sign(['operator_token' => $token])->assertCreated();
    }

    // ───────────── hors ligne : la fenêtre du jeton se juge sur l'heure de la MESURE ─────────────

    public function test_un_releve_garde_hors_ligne_est_accepte_si_la_mesure_est_dans_la_fenetre_du_jeton(): void
    {
        $this->givePin($this->sarah);
        $token = $this->postJson(self::OPS.'/verify', ['user_id' => $this->sarah->id, 'pin' => '4821'])->json('data.token'); // émis 10:00
        Carbon::setTestNow(Carbon::parse('2026-10-20 11:30', config('app.timezone'))); // rejeu 1 h 30 plus tard

        $this->sign(['operator_token' => $token, '_reading' => ['measured_at' => '2026-10-20T10:03:00+02:00']])->assertCreated()->assertJsonPath('data.0.operator_name', 'Sarah Lopez');
    }

    public function test_un_releve_garde_hors_ligne_mesure_apres_l_expiration_du_jeton_est_refuse(): void
    {
        $this->givePin($this->sarah);
        $token = $this->postJson(self::OPS.'/verify', ['user_id' => $this->sarah->id, 'pin' => '4821'])->json('data.token'); // 10:00 → 10:10
        Carbon::setTestNow(Carbon::parse('2026-10-20 11:30', config('app.timezone')));

        $this->sign(['operator_token' => $token, '_reading' => ['measured_at' => '2026-10-20T10:25:00+02:00']])->assertStatus(422)
            ->assertJsonPath('errors.operator_token.0', fn ($m) => str_contains($m, 'expiré'));
        $this->assertSame(0, HygieneTemperatureReading::query()->count());
    }

    public function test_un_jeton_ne_peut_pas_signer_une_mesure_anterieure_a_son_emission(): void
    {
        $this->givePin($this->sarah);
        $token = $this->postJson(self::OPS.'/verify', ['user_id' => $this->sarah->id, 'pin' => '4821'])->json('data.token'); // émis 10:00
        Carbon::setTestNow(Carbon::parse('2026-10-20 10:05', config('app.timezone')));

        $this->sign(['operator_token' => $token, '_reading' => ['measured_at' => '2026-10-20T09:30:00+02:00']])->assertStatus(422);
    }

    public function test_chaque_geste_du_lot_doit_tomber_dans_la_fenetre_du_jeton_pas_seulement_le_plus_ancien(): void
    {
        $this->givePin($this->sarah);
        $token = $this->postJson(self::OPS.'/verify', ['user_id' => $this->sarah->id, 'pin' => '4821'])->json('data.token'); // 10:00 → 10:10
        Carbon::setTestNow(Carbon::parse('2026-10-20 16:00', config('app.timezone')));
        $ids = HygieneEquipment::query()->whereIn('name', ['Frigo 1', 'Frigo 2'])->pluck('id', 'name');

        // A mesuré dans la fenêtre, B mesuré à 15 h 59 (hors fenêtre) : le lot entier est refusé
        $this->postJson(self::TEMP.'/readings', ['operator_user_id' => $this->sarah->id, 'operator_token' => $token, 'readings' => [
            ['equipment_id' => $ids['Frigo 1'], 'value' => 3.0, 'measured_at' => '2026-10-20T10:05:00+02:00'],
            ['equipment_id' => $ids['Frigo 2'], 'value' => 3.0, 'measured_at' => '2026-10-20T15:59:00+02:00'],
        ]])->assertStatus(422)->assertJsonValidationErrors('operator_token');

        // A dans la fenêtre, B SANS heure de mesure (= maintenant, 16 h) : refusé aussi
        $this->postJson(self::TEMP.'/readings', ['operator_user_id' => $this->sarah->id, 'operator_token' => $token, 'readings' => [
            ['equipment_id' => $ids['Frigo 1'], 'value' => 3.0, 'measured_at' => '2026-10-20T10:05:00+02:00'],
            ['equipment_id' => $ids['Frigo 2'], 'value' => 3.0],
        ]])->assertStatus(422)->assertJsonValidationErrors('operator_token');
        $this->assertSame(0, HygieneTemperatureReading::query()->count());

        // tous dans la fenêtre : accepté
        $this->postJson(self::TEMP.'/readings', ['operator_user_id' => $this->sarah->id, 'operator_token' => $token, 'readings' => [
            ['equipment_id' => $ids['Frigo 1'], 'value' => 3.0, 'measured_at' => '2026-10-20T10:02:00+02:00'],
            ['equipment_id' => $ids['Frigo 2'], 'value' => 3.0, 'measured_at' => '2026-10-20T10:08:00+02:00'],
        ]])->assertCreated();
    }

    public function test_annuler_un_releve_au_nom_d_une_personne_protegee_exige_son_code_et_est_signe_d_elle(): void
    {
        $this->givePin($this->sarah);
        $this->postJson(self::TEMP.'/readings', $this->reading([], 'Frigo 1'))->assertCreated();
        $id = HygieneTemperatureReading::query()->firstOrFail()->id;

        // sans preuve : refusé, rien n'est annulé
        $this->postJson(self::TEMP."/readings/{$id}/void", ['reason' => 'faute de frappe', 'operator_user_id' => $this->sarah->id])->assertStatus(422)->assertJsonValidationErrors('operator_pin');
        $this->assertNull(HygieneTemperatureReading::query()->find($id)->voided_at);

        $token = $this->postJson(self::OPS.'/verify', ['user_id' => $this->sarah->id, 'pin' => '4821'])->json('data.token');
        $this->postJson(self::TEMP."/readings/{$id}/void", ['reason' => 'faute de frappe', 'operator_user_id' => $this->sarah->id, 'operator_token' => $token])->assertOk();
        $row = HygieneTemperatureReading::query()->find($id);
        $this->assertNotNull($row->voided_at);
        $this->assertSame($this->sarah->id, $row->voided_by, 'annulé PAR Sarah, pas par le compte partagé de la tablette');
    }

    public function test_annuler_sans_designer_personne_reste_signe_du_compte_connecte(): void
    {
        $this->postJson(self::TEMP.'/readings', $this->reading([], 'Frigo 1'))->assertCreated();
        $id = HygieneTemperatureReading::query()->firstOrFail()->id;
        $this->postJson(self::TEMP."/readings/{$id}/void", ['reason' => 'faute de frappe'])->assertOk();
        $this->assertSame($this->tablet->id, HygieneTemperatureReading::query()->find($id)->voided_by);
    }

    // ───────────── le responsable ─────────────

    private function manager(): User
    {
        $m = User::factory()->create(['branch_id' => 1, 'name' => 'Responsable']);
        $m->givePermissionTo(['pos-orders', 'settings']);
        $m->assignRole('Branch Manager');
        Sanctum::actingAs($m, ['*']);

        return $m;
    }

    public function test_la_gestion_des_codes_est_reservee_au_responsable(): void
    {
        $this->getJson(self::TEAM)->assertForbidden();
        $this->putJson(self::TEAM."/{$this->sarah->id}/pin", ['pin' => '4821'])->assertForbidden();
        $this->deleteJson(self::TEAM."/{$this->sarah->id}/pin")->assertForbidden();
        $this->assertSame(0, HygieneOperatorPin::withoutGlobalScopes()->count());
    }

    public function test_le_responsable_definit_liste_et_retire_les_codes_sans_jamais_les_lire(): void
    {
        $this->manager();
        $this->putJson(self::TEAM."/{$this->sarah->id}/pin", ['pin' => '4821'])->assertOk()->assertJsonPath('data.has_pin', true);

        $r = $this->getJson(self::TEAM)->assertOk();
        $row = collect($r->json('data'))->firstWhere('id', $this->sarah->id);
        $this->assertTrue($row['has_pin']);
        $this->assertStringNotContainsString('4821', json_encode($r->json()));
        $this->assertStringNotContainsString('pin_hash', json_encode($r->json()));
        $this->assertGreaterThanOrEqual(1, $r->json('meta.without_pin'));

        $this->deleteJson(self::TEAM."/{$this->sarah->id}/pin")->assertOk()->assertJsonPath('data.has_pin', false);
        $this->assertSame(0, HygieneOperatorPin::withoutGlobalScopes()->count());
    }

    public function test_redefinir_un_code_debloque_la_personne(): void
    {
        $this->givePin($this->sarah);
        for ($i = 0; $i < 5; $i++) { $this->postJson(self::OPS.'/verify', ['user_id' => $this->sarah->id, 'pin' => '0000']); }
        $this->postJson(self::OPS.'/verify', ['user_id' => $this->sarah->id, 'pin' => '4821'])->assertStatus(423);

        $this->manager();
        $this->getJson(self::TEAM)->assertJsonPath('data.'.collect($this->getJson(self::TEAM)->json('data'))->search(fn ($u) => $u['id'] === $this->sarah->id).'.locked', true);
        $this->putJson(self::TEAM."/{$this->sarah->id}/pin", ['pin' => '5826'])->assertOk();
        $this->postJson(self::OPS.'/verify', ['user_id' => $this->sarah->id, 'pin' => '5826'])->assertOk();
    }

    public function test_les_codes_trop_faciles_sont_refuses_a_la_definition(): void
    {
        $this->manager();
        foreach (['0000', '1111', '9999', '1234', '4321', '2345', '123456', '654321', '000000'] as $weak) {
            $this->putJson(self::TEAM."/{$this->sarah->id}/pin", ['pin' => $weak])->assertStatus(422)->assertJsonValidationErrors('pin');
        }
        foreach (['4821', '1357', '2468', '908172'] as $ok) {
            $this->putJson(self::TEAM."/{$this->sarah->id}/pin", ['pin' => $ok])->assertOk();
        }
    }

    public function test_le_responsable_debloque_une_personne_sans_changer_son_code(): void
    {
        $this->givePin($this->sarah);
        $token = $this->postJson(self::OPS.'/verify', ['user_id' => $this->sarah->id, 'pin' => '4821'])->json('data.token');
        for ($i = 0; $i < 5; $i++) { $this->postJson(self::OPS.'/verify', ['user_id' => $this->sarah->id, 'pin' => '0000']); }
        $this->postJson(self::OPS.'/verify', ['user_id' => $this->sarah->id, 'pin' => '4821'])->assertStatus(423);

        $this->manager();
        $this->postJson(self::TEAM."/{$this->sarah->id}/unlock")->assertOk()->assertJsonPath('data.locked', false);
        $this->postJson(self::OPS.'/verify', ['user_id' => $this->sarah->id, 'pin' => '4821'])->assertOk(); // même code, de nouveau accepté
        // et un jeton émis AVANT n'a pas été tué par le déblocage (le code n'a pas changé)
        $this->sign(['operator_token' => $token])->assertCreated();
    }

    public function test_debloquer_demande_la_permission_et_une_personne_qui_a_un_code(): void
    {
        $this->givePin($this->sarah);
        $this->postJson(self::TEAM."/{$this->sarah->id}/unlock")->assertForbidden();
        $this->manager();
        $this->postJson(self::TEAM."/{$this->tablet->id}/unlock")->assertNotFound(); // aucun code à débloquer
        $this->postJson(self::TEAM.'/999999/unlock')->assertNotFound();
    }

    public function test_le_code_est_poivre_une_base_volee_ne_permet_aucune_attaque_hors_ligne(): void
    {
        $this->givePin($this->sarah, '4821');
        $hash = HygieneOperatorPin::withoutGlobalScopes()->firstOrFail()->pin_hash;
        $this->assertFalse(\Hash::check('4821', $hash), 'bcrypt du seul code : cassable hors ligne en quelques minutes');
        $this->assertTrue(\Hash::check(hash_hmac('sha256', '4821', 'hygiene-pin|'.config('app.key')), $hash));
    }

    public function test_definir_deux_fois_le_code_d_une_personne_ne_cree_qu_une_ligne(): void
    {
        $this->givePin($this->sarah, '4821');
        $this->givePin($this->sarah, '1357');
        $this->assertSame(1, HygieneOperatorPin::withoutGlobalScopes()->where('user_id', $this->sarah->id)->count());
        $this->postJson(self::OPS.'/verify', ['user_id' => $this->sarah->id, 'pin' => '1357'])->assertOk();
    }

    public function test_le_format_du_code_est_controle_et_la_personne_doit_etre_du_personnel(): void
    {
        $this->manager();
        foreach (['123', '1234567', 'abcd', '12 34', ''] as $bad) {
            $this->putJson(self::TEAM."/{$this->sarah->id}/pin", ['pin' => $bad])->assertStatus(422)->assertJsonValidationErrors('pin');
        }
        $this->putJson(self::TEAM.'/999999/pin', ['pin' => '4821'])->assertNotFound();
        $outsider = User::factory()->create(['branch_id' => 2, 'name' => 'Autre Site']); $outsider->assignRole('Chef');
        $this->putJson(self::TEAM."/{$outsider->id}/pin", ['pin' => '4821'])->assertNotFound();
        $this->assertSame(0, HygieneOperatorPin::withoutGlobalScopes()->count());
    }

    public function test_la_liste_des_operateurs_dit_qui_a_un_code(): void
    {
        $this->givePin($this->sarah);
        $r = $this->getJson(self::OPS)->assertOk();
        $by = collect($r->json('data'))->keyBy('id');
        $this->assertTrue($by[$this->sarah->id]['has_pin']);
        $this->assertFalse($by[$this->tablet->id]['has_pin']);
        $this->assertSame([4, 6], $r->json('meta.pin_digits'));
    }

    // ───────────── toutes les écritures passent par le même contrôle ─────────────

    public function test_aucune_ecriture_d_hygiene_ne_signe_sans_passer_par_le_controle_du_code(): void
    {
        $files = array_merge(glob(app_path('Http/Controllers/Admin/Hygiene/*Controller.php')), [app_path('Http/Controllers/Admin/HygieneTemperatureController.php')]);
        foreach ($files as $f) {
            $src = file_get_contents($f);
            // une ligne qui écrit « operator_name » depuis l'utilisateur connecté (au lieu de $op[...]) contournerait le code personnel
            $this->assertDoesNotMatchRegularExpression('/[\'"]operator_name[\'"]\s*=>\s*\$(?:request->user|user)\b/', $src, basename($f).' signe sans passer par operator()');
            // idem pour l'annulation : « annulé par » est la personne désignée (avec son code si elle en a un), pas le compte partagé de la tablette
            $this->assertDoesNotMatchRegularExpression('/[\'"]voided_by[\'"]\s*=>\s*\$request->user/', $src, basename($f).' annule sans passer par operator()');
        }
    }
}

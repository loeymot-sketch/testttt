<?php

namespace Tests\Feature\Wheel;

use App\Models\Branch;
use App\Models\Scopes\BranchScope;
use App\Models\User;
use App\Models\WheelSpin;
use App\Services\Wheel\WheelUnlockService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Tests\TestCase;

/**
 * ROUE — la surface HTTP, c'est-à-dire la porte par laquelle on attaquerait.
 *
 * Le service est déjà couvert (`WheelDrawSecurityTest`). Ici on vérifie ce que le service ne peut
 * pas garantir seul : que le contrôleur ne laisse pas entrer ce qu'il ne doit pas.
 *
 *   1. la porte est FERMÉE au public tant que le propriétaire ne l'a pas ouverte — et fermée en
 *      404, pas en 403 : un 403 dit « ça existe » et donne envie de chercher ;
 *   2. pas de jeton de validation = pas de tour, JAMAIS de repli déclaratif ;
 *   3. un jeton forgé, expiré, rejoué ou venu d'un autre comptoir est refusé ;
 *   4. RIEN dans le corps de la requête ne peut influencer le lot ;
 *   5. l'émission d'un jeton est réservée aux comptes caisse, et la branche vient du COMPTE.
 */
class WheelEndpointSecurityTest extends TestCase
{
    use RefreshDatabase;

    private int $branchId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedSpatieRoles();
        $this->seedMinimalSettings();
        if (! file_exists(storage_path('installed'))) {
            touch(storage_path('installed'));
        }

        $this->branchId = Branch::factory()->create()->id;

        Config::set('wheel.enabled', true); // ouverte, sauf test dédié
        Config::set('wheel.campaign_key', 'test-http');
        Config::set('wheel.daily_total_cap', 500);
        Config::set('wheel.unlock_methods', ['staff' => true, 'order' => true, 'declaratif' => false]);
        // Étapes DÉSACTIVÉES ici : ce banc éprouve la sécurité du JETON. Les mêler au parcours
        // rendrait chaque échec ambigu. Le parcours a son propre banc (WheelStepsTest).
        Config::set('wheel.steps', [
            'review' => ['required' => false, 'url' => '', 'dwell_seconds' => 0],
            'follow' => ['required' => false, 'instagram' => '', 'snapchat' => '', 'dwell_seconds' => 0],
        ]);
        Config::set('wheel.segments', [
            ['key' => 'a', 'label' => '-10%', 'type' => 'coupon_percent', 'value' => 10, 'weight' => 1, 'daily_cap' => 0],
            ['key' => 'b', 'label' => 'Menu offert', 'type' => 'free_item', 'value' => 0, 'weight' => 1, 'daily_cap' => 0],
        ]);
    }

    private function cle(): array
    {
        // `app.api_key`, PAS `app.key` : le middleware compare à la clé d'API publiée dans le
        // meta du site, pas à la clé de chiffrement de l'application. S'y tromper rend 400 sur
        // TOUT, et onze tests échouent pour une raison qui n'a rien à voir avec la roue.
        return ['x-api-key' => (string) config('app.api_key')];
    }

    private function jeton(?int $branchId = null): string
    {
        return app(WheelUnlockService::class)->issue($branchId ?? $this->branchId, 1)['token'];
    }

    /**
     * [MISE À JOUR 2026-08-09] Le tour exige désormais une ADRESSE (seconde clé d'identité, et canal
     * des conditions du lot) et le FRANCHISSEMENT DES ÉTAPES, horodaté par le serveur.
     *
     * Ce banc-ci teste la SÉCURITÉ DU JETON, pas les étapes : on les désactive donc dans son
     * `setUp`. Les tester ici mêlerait deux sujets, et un échec ne dirait plus lequel a cassé.
     * Une adresse dérivée du numéro garde chaque cas indépendant : sans ça, l'unicité de l'adresse
     * ferait échouer des tests qui parlent d'autre chose.
     */
    private function tourner(array $corps)
    {
        $tel = (string) ($corps['phone'] ?? '0600000000');
        $corps += ['email' => 'banc-' . preg_replace('/\D+/', '', $tel) . '@exemple.fr'];

        return $this->withHeaders($this->cle())
            ->postJson('/api/frontend/wheel/spin', $corps + ['branch_id' => $this->branchId]);
    }

    // ── 1. LA PORTE ──────────────────────────────────────────────────────────────────────────

    public function test_fermee_au_public_la_roue_repond_404_et_pas_403(): void
    {
        Config::set('wheel.enabled', false);

        $this->withHeaders($this->cle())
            ->getJson('/api/frontend/wheel/config?branch_id=' . $this->branchId)
            ->assertStatus(404);

        $this->tourner(['phone' => '0612345678', 'unlock_token' => $this->jeton()])
            ->assertStatus(404);
    }

    public function test_le_proprietaire_peut_tester_pendant_que_la_porte_est_fermee(): void
    {
        Config::set('wheel.enabled', false);
        Config::set('wheel.preview_role', 'Admin');

        $admin = User::factory()->create(['branch_id' => 0]);
        $admin->assignRole('Admin');

        $r = $this->actingAs($admin)->withHeaders($this->cle())
            ->getJson('/api/frontend/wheel/config?branch_id=' . $this->branchId);

        $r->assertOk()->assertJsonPath('preview', true);
    }

    public function test_la_configuration_publique_n_expose_ni_poids_ni_plafond(): void
    {
        $brut = $this->withHeaders($this->cle())
            ->getJson('/api/frontend/wheel/config?branch_id=' . $this->branchId)
            ->assertOk()->getContent();

        $this->assertStringContainsString('-10%', $brut, 'la roue doit rester dessinable');
        $this->assertStringNotContainsString('weight', $brut);
        $this->assertStringNotContainsString('daily_cap', $brut);
    }

    // ── 2. PAS DE JETON, PAS DE TOUR ─────────────────────────────────────────────────────────

    public function test_sans_jeton_le_tour_est_refuse_et_le_message_dit_quoi_faire(): void
    {
        $r = $this->tourner(['phone' => '0612345678'])->assertStatus(403);

        $this->assertMatchesRegularExpression('/quipe/i', (string) $r->json('message'),
            'le refus doit renvoyer vers l\'équipe : un refus incompris est vécu comme une panne');
        $this->assertSame(0, WheelSpin::withoutGlobalScope(BranchScope::class)->count(),
            'un tour a été enregistré sans validation');
    }

    public function test_un_jeton_FORGE_est_refuse(): void
    {
        foreach (['nimportequoi', 'aaa.bbb', base64_encode('{"b":1,"e":9999999999}') . '.deadbeef'] as $faux) {
            $this->tourner(['phone' => '0612345678', 'unlock_token' => $faux])->assertStatus(403);
        }
        $this->assertSame(0, WheelSpin::withoutGlobalScope(BranchScope::class)->count());
    }

    public function test_un_jeton_EXPIRE_est_refuse(): void
    {
        Config::set('wheel.unlock_token_ttl_minutes', 1);
        $jeton = $this->jeton();

        $this->travel(2)->minutes();

        $this->tourner(['phone' => '0612345678', 'unlock_token' => $jeton])->assertStatus(403);
        $this->assertSame(0, WheelSpin::withoutGlobalScope(BranchScope::class)->count());
    }

    public function test_un_jeton_d_un_AUTRE_comptoir_est_refuse(): void
    {
        $autre = Branch::factory()->create();

        $this->tourner(['phone' => '0612345678', 'unlock_token' => $this->jeton($autre->id)])
            ->assertStatus(403);
        $this->assertSame(0, WheelSpin::withoutGlobalScope(BranchScope::class)->count());
    }

    /** Le rejeu est la fraude la plus simple : un jeton photographié et partagé. */
    public function test_le_MEME_jeton_ne_sert_pas_deux_fois(): void
    {
        $jeton = $this->jeton();

        $this->tourner(['phone' => '0611111111', 'unlock_token' => $jeton])->assertOk();

        // Autre numéro, donc l'unicité « un tour par téléphone » ne peut pas expliquer un refus :
        // seul l'usage unique du jeton peut. C'est ce qu'on veut prouver ici.
        $r = $this->tourner(['phone' => '0622222222', 'unlock_token' => $jeton]);
        $this->assertNotEquals(200, $r->status(),
            'le même jeton a servi deux fois : une validation photographiée ferait tourner tout le monde');
        $this->assertSame(1, WheelSpin::withoutGlobalScope(BranchScope::class)->count());
    }

    // ── 3. LE CORPS DE LA REQUÊTE N'A AUCUN POUVOIR ──────────────────────────────────────────

    /**
     * On envoie tout ce qu'un attaquant tenterait. Le lot doit rester celui du serveur.
     * La roue n'a que deux segments ici, alors on vérifie la chose qui compte vraiment : ce que le
     * serveur RENVOIE correspond à ce qu'il a ÉCRIT en base, et pas à ce qui a été demandé.
     */
    public function test_aucun_champ_envoye_ne_peut_choisir_le_lot(): void
    {
        $r = $this->tourner([
            'phone'         => '0612345678',
            'unlock_token'  => $this->jeton(),
            // Tentatives d'injection : toutes doivent être ignorées.
            'prize_key'     => 'b',
            'prize'         => 'Menu offert',
            'segment_index' => 1,
            'prize_value'   => 999,
            'points'        => 99999,
        ])->assertOk();

        $spin = WheelSpin::withoutGlobalScope(BranchScope::class)->firstOrFail();

        $this->assertSame($spin->prize_key === 'a' ? 0 : 1, (int) $r->json('segment_index'),
            'l\'index renvoyé ne correspond pas au lot réellement enregistré');
        $this->assertSame($spin->prize_label, $r->json('prize_label'));
        $this->assertNotSame(999.0, (float) $spin->prize_value, 'une valeur envoyée a été retenue');
        $this->assertNotSame(99999, (int) $spin->points_awarded, 'des points envoyés ont été retenus');
    }

    public function test_un_second_tour_du_meme_numero_est_refuse_en_409(): void
    {
        $this->tourner(['phone' => '0612345678', 'unlock_token' => $this->jeton()])->assertOk();
        $this->tourner(['phone' => '06 12 34 56 78', 'unlock_token' => $this->jeton()])->assertStatus(409);
    }

    // ── 4. L'ÉMISSION DU JETON ───────────────────────────────────────────────────────────────

    public function test_seul_un_compte_caisse_peut_valider_un_tour(): void
    {
        $this->postJson('/api/admin/wheel/unlock-token')->assertStatus(401);

        $quidam = User::factory()->create(['branch_id' => $this->branchId]);
        $this->actingAs($quidam)->postJson('/api/admin/wheel/unlock-token')->assertStatus(403);
    }

    public function test_un_compte_caisse_emet_un_jeton_utilisable_UNE_fois(): void
    {
        $caissier = User::factory()->create(['branch_id' => $this->branchId]);
        $caissier->givePermissionTo('pos');

        $r = $this->actingAs($caissier)->postJson('/api/admin/wheel/unlock-token')->assertOk();
        $jeton = (string) $r->json('token');

        $this->assertNotSame('', $jeton);
        $this->assertNotNull($r->json('expires_at'), 'un jeton sans expiration circulerait toute la soirée');

        $this->tourner(['phone' => '0612345678', 'unlock_token' => $jeton])->assertOk();
        $this->tourner(['phone' => '0633333333', 'unlock_token' => $jeton])->assertStatus(409);
    }
}

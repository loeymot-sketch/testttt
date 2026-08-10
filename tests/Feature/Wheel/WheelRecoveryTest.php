<?php

namespace Tests\Feature\Wheel;

use App\Models\Branch;
use App\Services\Wheel\WheelUnlockService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Tests\TestCase;

/**
 * LA REPRISE APRÈS COUPURE RÉSEAU — le chemin de secours, et le défaut qu'il avait réintroduit.
 *
 * ── LE SCÉNARIO ──────────────────────────────────────────────────────────────────────────────
 * Le client tourne, remplit ses coordonnées, appuie — et le réseau tombe pendant la réclamation. Le
 * lot est peut-être déjà enregistré côté serveur. Sans reprise, il verrait « tu as déjà joué » au
 * second essai et ne reverrait JAMAIS son lot : deux messages contradictoires et une impasse.
 *
 * La page va donc rechercher son lot avec son jeton. C'est ce chemin-là qu'on éprouve ici.
 *
 * ── LE DÉFAUT [P0 2026-08-10] ────────────────────────────────────────────────────────────────
 * La réponse ne portait pas le TYPE du lot. La page de secours retombait alors sur le cas « remise »
 * et affichait « saisis ce code dans ton panier sur le site » — pour un lot en points ou un produit
 * offert, qui n'ont pas de code. Prouvé en réel : lot « 100 points », consigne de code affichée,
 * aucun code. Ces deux types pèsent 60 % de la roue.
 *
 * C'est exactement le défaut d'honnêteté corrigé le 9 août sur le chemin normal, revenu par la porte
 * de derrière. Une leçon qui revient : un correctif est complet sur la surface regardée, pas sur ses
 * jumelles.
 */
class WheelRecoveryTest extends TestCase
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

        Config::set('wheel.enabled', true);
        // [ROUE × CAISSE 2026-08-10] La roue ne tire plus de lot en REMISE quand la caisse
        // refuse les codes — c'est une garde neuve, et elle est juste. Ce banc parle d'autre
        // chose : on accepte donc les codes ici, pour qu'un interrupteur de la caisse ne
        // décide pas de ce qu'il éprouve.
        Config::set('pos.coupon_codes_enabled', true);
        Config::set('wheel.campaign_key', 'test-reprise');
        Config::set('wheel.daily_total_cap', 500);
        Config::set('wheel.unlock_methods', ['staff' => true, 'order' => true, 'declaratif' => false]);
        Config::set('wheel.steps', [
            'review' => ['required' => false, 'url' => '', 'dwell_seconds' => 0, 'derive_fallback' => false],
            'follow' => ['required' => false, 'instagram' => '', 'snapchat' => '', 'facebook' => '', 'dwell_seconds' => 0],
        ]);
    }

    private function cle(): array
    {
        return ['x-api-key' => (string) config('app.api_key')];
    }

    private function jeton(): string
    {
        return app(WheelUnlockService::class)->issue($this->branchId, 1)['token'];
    }

    /** Joue le parcours complet et rend le jeton employé. */
    private function parcours(string $tel, string $mail): string
    {
        $jeton = $this->jeton();
        $this->withHeaders($this->cle())->postJson('/api/frontend/wheel/spin', [
            'branch_id' => $this->branchId, 'unlock_token' => $jeton,
        ])->assertOk();
        $this->withHeaders($this->cle())->postJson('/api/frontend/wheel/claim', [
            'branch_id' => $this->branchId, 'unlock_token' => $jeton,
            'phone' => $tel, 'email' => $mail,
        ])->assertOk();

        return $jeton;
    }

    private function reprise(string $jeton, string $tel)
    {
        return $this->withHeaders($this->cle())->getJson(
            '/api/frontend/wheel/config?branch_id=' . $this->branchId
            . '&phone=' . urlencode($tel) . '&t=' . urlencode($jeton)
        );
    }

    /**
     * LE CŒUR : la reprise doit dire de quel TYPE de lot il s'agit. Sans ça, la page ne peut pas
     * choisir le bon message, et elle choisit le mauvais.
     */
    public function test_la_reprise_porte_le_TYPE_du_lot_pour_un_lot_SANS_code(): void
    {
        Config::set('wheel.segments', [
            ['key' => 'p', 'label' => '100 points', 'type' => 'points', 'value' => 100,
             'weight' => 1, 'daily_cap' => 0],
        ]);

        $jeton = $this->parcours('0611000401', 'reprise-points@exemple.fr');

        $r = $this->reprise($jeton, '0611000401')->assertOk();

        $this->assertTrue((bool) $r->json('already_spun'));
        $this->assertSame('points', $r->json('previous_prize_type'),
            'sans le type, la page affiche « saisis ce code dans ton panier » pour un lot en points '
            . '— et le client cherche un code qui n\'existe pas');
        $this->assertNull($r->json('previous_code'), 'un lot en points n\'a pas de code');
        $this->assertSame(100, (int) $r->json('previous_points'));
        $this->assertNotNull($r->json('previous_valid_until'),
            'l\'échéance disparaissait sur le chemin de secours : un lot sans date se remet à plus tard');
    }

    public function test_la_reprise_porte_le_code_ET_le_type_pour_une_remise(): void
    {
        Config::set('wheel.segments', [
            ['key' => 'a', 'label' => '-10%', 'type' => 'coupon_percent', 'value' => 10,
             'weight' => 1, 'daily_cap' => 0, 'max_discount' => 5],
        ]);

        $jeton = $this->parcours('0611000402', 'reprise-remise@exemple.fr');

        $r = $this->reprise($jeton, '0611000402')->assertOk();

        $this->assertSame('coupon_percent', $r->json('previous_prize_type'));
        $this->assertNotNull($r->json('previous_code'), 'le client doit RETROUVER son code');
        $this->assertMatchesRegularExpression('/^ROUE-/', (string) $r->json('previous_code'));
        $this->assertNotNull($r->json('previous_valid_until'));
    }

    /**
     * ET LA PORTE RESTE FERMÉE. Cette consultation reste un annuaire potentiel — « ce numéro a-t-il
     * joué, et qu'a-t-il gagné » — donc elle exige le jeton du client. Sans jeton, rien ne sort, y
     * compris le type. Un refus silencieux vaut mieux qu'un oracle poli.
     */
    public function test_sans_jeton_la_reprise_ne_dit_RIEN_du_lot(): void
    {
        Config::set('wheel.segments', [
            ['key' => 'p', 'label' => '100 points', 'type' => 'points', 'value' => 100,
             'weight' => 1, 'daily_cap' => 0],
        ]);

        $this->parcours('0611000403', 'reprise-sansjeton@exemple.fr');

        $r = $this->withHeaders($this->cle())->getJson(
            '/api/frontend/wheel/config?branch_id=' . $this->branchId . '&phone=0611000403'
        )->assertOk();

        $this->assertFalse((bool) $r->json('already_spun'));
        $this->assertNull($r->json('previous_prize_type'),
            'ORACLE : le type fuite sans jeton — on peut énumérer des numéros et lire les lots');
        $this->assertNull($r->json('previous_code'));
        $this->assertNull($r->json('previous_valid_until'));
    }

    /** Un jeton d'un autre comptoir ne consulte rien non plus. */
    public function test_un_jeton_d_un_AUTRE_comptoir_ne_consulte_rien(): void
    {
        Config::set('wheel.segments', [
            ['key' => 'p', 'label' => '100 points', 'type' => 'points', 'value' => 100,
             'weight' => 1, 'daily_cap' => 0],
        ]);

        $this->parcours('0611000404', 'reprise-autre@exemple.fr');

        $autre = Branch::factory()->create();
        $jetonVoisin = app(WheelUnlockService::class)->issue($autre->id, 1)['token'];

        $r = $this->reprise($jetonVoisin, '0611000404')->assertOk();

        $this->assertFalse((bool) $r->json('already_spun'));
        $this->assertNull($r->json('previous_prize_type'));
    }
}

<?php

namespace Tests\Feature\Loyalty;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Smartisan\Settings\Facades\Settings;
use Tests\TestCase;

/**
 * [FIDÉLITÉ 2026-08-19] LES PALIERS ANNONCÉS AU CLIENT DOIVENT ÊTRE ATTEIGNABLES.
 *
 * ── LE DÉFAUT, MESURÉ SUR L'API RÉELLE ───────────────────────────────────────────────────────
 * Avec le réglage de production (gain 10 pts/€, taux 100 pts = 1 €, plancher 1000),
 * `GET /api/frontend/loyalty/config` renvoyait `tiers: [100, 250, 500, 1000, 2000]` à côté de
 * `min_redeem_points: 1000`. La borne en tire une barre de progression « encore N points » : un
 * client à 60 points lisait donc « encore 40 points » pour une récompense qui n'existe pas —
 * RIEN n'est utilisable sous 1000. Il atteint 100 points, il ne se passe rien.
 *
 * ── POURQUOI CE FICHIER ─────────────────────────────────────────────────────────────────────
 * C'est le JUMEAU OUBLIÉ du correctif du 2026-08-05, qui avait redressé `min_redeem_points`
 * (plancher effectif au lieu du réglage brut, sentinelle LoyaltyConfigEffectiveFloorTest) sans
 * toucher aux paliers — alors que ce sont les paliers, et pas le plancher, que le client VOIT.
 * Cette sentinelle ferme l'autre moitié de la porte.
 */
class LoyaltyConfigTiersHonestTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedMinimalSettings();
    }

    private function config(): array
    {
        return $this->withHeader('x-api-key', (string) config('app.api_key'))
            ->getJson('/api/frontend/loyalty/config')
            ->assertOk()
            ->json('data');
    }

    public function test_aucun_palier_publie_ne_passe_sous_le_plancher_reel(): void
    {
        // Réglage RÉEL de production, mesuré le 2026-08-19.
        Settings::group('loyalty_setup')->set([
            'loyalty_points_per_euro' => 10,
            'loyalty_points_for_1_euro_discount' => 100,
            'loyalty_min_redeem_points' => 1000,
            'loyalty_tiers' => '100,250,500,1000,2000',
        ]);

        $data = $this->config();

        $this->assertSame(1000, (int) $data['min_redeem_points']);
        $this->assertNotEmpty($data['tiers']);

        foreach ($data['tiers'] as $palier) {
            $this->assertGreaterThanOrEqual(
                1000,
                (int) $palier,
                "Le palier {$palier} promet une récompense sous le plancher : elle n'arrivera jamais."
            );
        }

        // Le plancher lui-même DOIT être un jalon : c'est le seul chiffre qui change quelque chose.
        $this->assertContains(1000, array_map('intval', $data['tiers']));
        // Et les paliers au-dessus survivent — on nettoie, on n'ampute pas.
        $this->assertContains(2000, array_map('intval', $data['tiers']));
    }

    public function test_avec_un_plancher_bas_les_paliers_configures_restent_intacts(): void
    {
        // Le nettoyage ne doit pas se transformer en appauvrissement quand tout est atteignable.
        Settings::group('loyalty_setup')->set([
            'loyalty_points_per_euro' => 10,
            'loyalty_points_for_1_euro_discount' => 100,
            'loyalty_min_redeem_points' => 100,
            'loyalty_tiers' => '100,250,500,1000,2000',
        ]);

        $tiers = array_map('intval', $this->config()['tiers']);

        $this->assertSame([100, 250, 500, 1000, 2000], $tiers);
    }
}

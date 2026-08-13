<?php

namespace Tests\Feature\Pos;

use App\Enums\OrderStatus;
use App\Enums\OrderType;
use App\Enums\PaymentGateway;
use App\Enums\PaymentStatus;
use App\Enums\PosPaymentMethod;
use App\Models\Branch;
use App\Models\Order;
use App\Models\User;
use App\Services\Loyalty\LoyaltyRules;
use App\Services\Loyalty\PosCustomerLookupService;
use App\Services\Loyalty\PosRedemptionException;
use App\Services\Loyalty\PosRedemptionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Smartisan\Settings\Facades\Settings;
use Tests\TestCase;

/**
 * « LE PLANCHER ANNONCÉ AU COMPTOIR EST CELUI QUI SERA APPLIQUÉ » — une seule définition.
 *
 * ── LE DÉFAUT DE STRUCTURE QUE CE BANC FERME ─────────────────────────────────────────────────
 * Deux endroits répondaient à la question « à partir de combien de points peut-on payer ? » :
 *   · la fenêtre de caisse ANNONÇAIT `LoyaltyRules::effectiveFloor()` (premier multiple du taux
 *     au-dessus du réglage) ;
 *   · l'encaissement APPLIQUAIT le réglage BRUT `loyalty_min_redeem_points`.
 * Le comptoir pouvait donc annoncer 1000 pendant que l'encaissement acceptait 950.
 *
 * ── HONNÊTETÉ SUR LA GRAVITÉ : LA DIVERGENCE ÉTAIT NOMINALE ──────────────────────────────────
 * Mesuré avant de corriger : les deux ne pouvaient PAS produire un résultat différent, parce que la
 * garde du multiple s'exécute AVANT le plancher et élimine toute valeur entre le réglage et le
 * plancher effectif — un réglage à 950 refuse 950 avec `POINTS_NOT_MULTIPLE`, jamais avec
 * `BELOW_MIN_REDEEM`. Aucun client n'a jamais vu deux chiffres différents. Ce n'était pas un bogue
 * vivant ; c'était une définition dupliquée, donc une divergence programmée pour le jour où l'ordre
 * des gardes changerait.
 *
 * Ce banc éprouve donc l'ÉGALITÉ des deux, pas un symptôme. C'est ce qui le rend utile : il casse
 * si quelqu'un redonne une définition propre à l'un des deux côtés.
 */
class PosLoyaltyFloorAnnonceEgaleAppliqueTest extends TestCase
{
    use RefreshDatabase;

    private Branch $branche;

    private User $client;

    private const CODE = 'FLOORAGR';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedSpatieRoles();
        $this->seedMinimalSettings();
        Config::set('fiscal.audit_secret', 'test-fiscal-secret-'.str_repeat('p', 40));

        $this->branche = Branch::factory()->create();
        $this->client = User::factory()->create(['phone' => '0699887766']);
        DB::table('users')->where('id', $this->client->id)
            ->update(['loyalty_code' => self::CODE, 'loyalty_points' => 5000]);
    }

    private function reglages(int $plancher, int $tauxRemise = 100): void
    {
        Settings::group('loyalty_setup')->set([
            'loyalty_points_per_euro'            => 10,
            'loyalty_points_for_1_euro_discount' => $tauxRemise,
            'loyalty_min_redeem_points'          => $plancher,
        ]);
    }

    private function venteOuverte(): Order
    {
        return Order::factory()->create([
            'branch_id' => $this->branche->id,
            'subtotal' => 80.00, 'discount' => 0.00, 'total_tax' => 0.00,
            'delivery_charge' => 0.00, 'total' => 80.00,
            'status'             => OrderStatus::PENDING,
            'payment_status'     => PaymentStatus::UNPAID,
            'payment_method'     => PaymentGateway::CASH_ON_DELIVERY,
            'pos_payment_method' => PosPaymentMethod::CASH,
            'order_type'         => OrderType::TAKEAWAY,
            'source_surface'     => 'pos',
        ]);
    }

    /**
     * LE CŒUR : pour un réglage qui n'est PAS un multiple du taux, le chiffre annoncé au comptoir et
     * le chiffre appliqué à l'encaissement sont le MÊME.
     *
     * ⚠️ LE CAS `0` M'A CORRIGÉ : j'attendais 0 (« pas de plancher »), la règle répond 100. Elle a
     * raison et mon attente était fausse : le `max(1, …)` de `effectiveFloor()` est délibéré — on ne
     * peut jamais racheter moins d'UN euro de points, donc le plus petit seuil réel est un multiple
     * complet du taux. Annoncer « 0 » au comptoir serait un mensonge de plus, pas une souplesse.
     */
    public function test_le_plancher_annonce_est_exactement_celui_applique(): void
    {
        foreach ([[950, 1000], [1000, 1000], [1001, 1100], [50, 100], [0, 100]] as [$reglage, $attendu]) {
            $this->reglages($reglage);

            $annonce = ($this->app->make(PosCustomerLookupService::class)->byCode(self::CODE))
                ['customer']['effective_floor'] ?? null;
            $regle = $this->app->make(LoyaltyRules::class)->effectiveFloor();

            $this->assertSame($attendu, $regle,
                "réglage {$reglage} : le plancher effectif calculé n'est pas celui attendu");
            $this->assertSame($regle, $annonce,
                "réglage {$reglage} : le comptoir annonce {$annonce} mais la règle dit {$regle}");
        }
    }

    /**
     * ET L'ENCAISSEMENT REFUSE SUR LE MÊME CHIFFRE. Avec un réglage à 950, un rachat de 900 points
     * (multiple valide du taux) doit être refusé POUR LE PLANCHER — et le message doit nommer 1000,
     * le chiffre que le client a vu à l'écran, pas 950.
     */
    public function test_l_encaissement_refuse_en_nommant_le_chiffre_annonce(): void
    {
        $this->reglages(950);

        try {
            $this->app->make(PosRedemptionService::class)
                ->applyToOrder($this->venteOuverte(), 900, self::CODE, null);
            $this->fail('900 points ont été acceptés alors que le plancher annoncé est 1000');
        } catch (PosRedemptionException $e) {
            $this->assertSame('BELOW_MIN_REDEEM', $e->errorCode,
                'le refus doit venir du plancher, pas d\'une autre garde');
            $this->assertStringContainsString('1000', $e->getMessage(),
                'le message nomme un chiffre différent de celui affiché au client');
            $this->assertStringNotContainsString('950', $e->getMessage(),
                'le message nomme le réglage brut au lieu du plancher réellement appliqué');
        }
    }

    /** Et au-dessus du plancher, l'encaissement passe : le banc ne prouverait rien s'il refusait tout. */
    public function test_au_dessus_du_plancher_le_rachat_passe(): void
    {
        $this->reglages(950);

        $r = $this->app->make(PosRedemptionService::class)
            ->applyToOrder($this->venteOuverte(), 1000, self::CODE, null);

        $this->assertTrue((bool) ($r['ok'] ?? $r['applied'] ?? true),
            'un rachat conforme au plancher a été refusé');
        $this->assertSame(4000, (int) DB::table('users')->where('id', $this->client->id)->value('loyalty_points'),
            '5000 − 1000 : le solde n\'a pas été débité correctement');
    }
}

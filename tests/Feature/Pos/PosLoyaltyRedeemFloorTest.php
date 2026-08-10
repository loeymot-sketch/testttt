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
use App\Services\Loyalty\PosRedemptionException;
use App\Services\Loyalty\PosRedemptionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * LE PLANCHER D'UTILISATION DES POINTS — le trou qui n'a pas encore mordu.
 *
 * ── LA DEMANDE DU PROPRIÉTAIRE ───────────────────────────────────────────────────────────────
 * « Une limite utilisable à partir de 1000 points par exemple, l'équivalent de 10 €. »
 * Barème du logiciel : 100 points = 1 € de remise. Donc 1000 points = 10 €. Son chiffre est exact.
 *
 * ── CE QUI SE PASSE AUJOURD'HUI ──────────────────────────────────────────────────────────────
 * Le plancher `loyalty_min_redeem_points` est appliqué sur le site et à la borne
 * (`DiscountCalculator:58`, `LoyaltyController:402` et `:526`) et **nulle part à la caisse** :
 * `grep min_redeem` dans `PosRedemptionService` ne donne AUCUNE occurrence.
 *
 * Le défaut est masqué tant que le seuil vaut son défaut de 50, parce que la règle « multiple de
 * 100 » impose déjà 100 points minimum. Il mord le jour où le propriétaire règle 1000, ce qu'il
 * demande explicitement : la borne et le site refuseront sous 1000, et **la caisse accepterait
 * encore 100 points pour 1 €**. Le client apprendrait à venir dépenser ses points au comptoir.
 *
 * ── CE QUE CE BANC VERROUILLE ────────────────────────────────────────────────────────────────
 * Le même plancher partout, avec un refus qui DIT le chiffre — un caissier doit pouvoir expliquer
 * au client, pas subir un rejet muet.
 */
class PosLoyaltyRedeemFloorTest extends TestCase
{
    use RefreshDatabase;

    protected Branch $branch;

    protected User $cashier;

    protected User $customer;

    protected Order $order;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedSpatieRoles();
        $this->seedMinimalSettings();

        Config::set('fiscal.audit_secret', 'test-fiscal-secret-'.str_repeat('a', 40));
        Config::set('pos.loyalty_enabled', true);
        Config::set('pos.manual_discount_enabled', true);

        $this->reglage('loyalty_points_for_1_euro_discount', 100);

        $this->branch = Branch::factory()->create();

        $this->cashier = User::factory()->create([
            'branch_id' => $this->branch->id, 'phone' => '0102030905',
        ]);
        $this->cashier->assignRole('POS Operator');

        $this->customer = User::factory()->create([
            'branch_id' => $this->branch->id, 'phone' => '0102030906',
        ]);
        DB::table('users')->where('id', $this->customer->id)->update([
            'loyalty_code' => 'PLANCHER', 'loyalty_points' => 5000,
        ]);
        $this->customer->refresh();

        $this->order = Order::factory()->create([
            'branch_id' => $this->branch->id,
            'user_id' => $this->customer->id,
            'subtotal' => 40.00, 'discount' => 0.00, 'total_tax' => 0.00,
            'delivery_charge' => 0.00, 'total' => 40.00,
            'status' => OrderStatus::PENDING,
            'payment_status' => PaymentStatus::UNPAID,
            'payment_method' => PaymentGateway::CASH_ON_DELIVERY,
            'pos_payment_method' => PosPaymentMethod::CASH,
            'order_type' => OrderType::TAKEAWAY,
            'source_surface' => 'pos',
        ]);
    }

    private function reglage(string $cle, int $valeur): void
    {
        // Le paquet enveloppe la valeur (`{"$cast":null,"$value":50}`) : passer par lui, pas par SQL.
        \Smartisan\Settings\Facades\Settings::group('loyalty_setup')->set([$cle => $valeur]);
    }

    /**
     * Effacer un réglage, c'est `forget()` — PAS `set(null)`.
     *
     * `set(null)` écrit une ligne `{"$cast":null,"$value":null}` que `get($cle, 50)` renvoie telle
     * quelle : le défaut ne s'applique plus. Or cet état est INATTEIGNABLE par le logiciel —
     * `LoyaltySetupRequest:19` impose `required|integer|min:0|max:10000`, l'écran admin ne peut donc
     * pas enregistrer un vide. Éprouver `set(null)`, c'est éprouver un cas impossible et se croire
     * en défaut. L'état réel « pas encore réglé », c'est la ligne ABSENTE — une installation neuve.
     */
    private function oublier(string $cle): void
    {
        \Smartisan\Settings\Facades\Settings::group('loyalty_setup')->forget($cle);
        DB::table('settings')->where('group', 'loyalty_setup')->where('key', $cle)->delete();
    }

    private function racheter(int $points): array
    {
        return app(PosRedemptionService::class)
            ->applyToOrder($this->order->fresh(), $points, 'PLANCHER', $this->cashier->id);
    }

    // ── LE PLANCHER ──────────────────────────────────────────────────────────────────────────

    /**
     * LE CŒUR. Seuil à 1000 — la valeur que le propriétaire veut — et un rachat de 100 points doit
     * être refusé à la caisse, comme il l'est déjà sur le site et à la borne.
     */
    public function test_sous_le_plancher_la_caisse_REFUSE_comme_le_site(): void
    {
        $this->reglage('loyalty_min_redeem_points', 1000);

        try {
            $this->racheter(100);
            $this->fail('la caisse a accepté 100 points alors que le seuil est à 1000 : le client '
                . 'apprendra à venir dépenser ses points au comptoir plutôt que sur le site');
        } catch (PosRedemptionException $e) {
            $this->assertSame(422, $e->httpStatus);
            $this->assertSame('BELOW_MIN_REDEEM', $e->errorCode,
                'la fenêtre de caisse s\'appuie sur un code STABLE, pas sur le texte du message');
            $this->assertMatchesRegularExpression('/1000/', $e->getMessage(),
                'le refus doit DIRE le chiffre : un caissier doit pouvoir l\'expliquer au client');
        }

        $this->assertSame(5000, (int) $this->customer->fresh()->loyalty_points,
            'le solde a bougé malgré le refus');
        $this->assertSame(0.0, (float) $this->order->fresh()->discount);
    }

    /** Au-dessus du plancher, le rachat passe : la garde ne doit pas bloquer un client légitime. */
    public function test_au_dessus_du_plancher_le_rachat_passe(): void
    {
        $this->reglage('loyalty_min_redeem_points', 1000);

        $r = $this->racheter(1000);

        $this->assertTrue((bool) ($r['ok'] ?? true));
        $this->assertSame(4000, (int) $this->customer->fresh()->loyalty_points);
        $this->assertSame(10.00, (float) $this->order->fresh()->discount,
            '1000 points valent 10 € au barème de la maison');
    }

    /** Pile sur le plancher, ça passe : un seuil est un minimum, pas une exclusion. */
    public function test_pile_sur_le_plancher_ca_passe(): void
    {
        $this->reglage('loyalty_min_redeem_points', 200);

        $this->racheter(200);

        $this->assertSame(4800, (int) $this->customer->fresh()->loyalty_points);
    }

    /** Sans plancher réglé, on garde le défaut du reste du logiciel — pas un comportement à part. */
    public function test_sans_plancher_regle_le_defaut_du_logiciel_s_applique(): void
    {
        $this->oublier('loyalty_min_redeem_points');

        // Le défaut lu partout ailleurs est 50 (LoyaltyController:402, DiscountCalculator:58).
        // 100 points le dépassent, donc le rachat passe — le défaut ne doit rien casser.
        $this->racheter(100);

        $this->assertSame(4900, (int) $this->customer->fresh()->loyalty_points);
    }

    /**
     * LE DÉFAUT DE 50 ÉPINGLÉ POUR DE VRAI. Au barème de 100 points/€, le défaut est INVISIBLE :
     * la règle du multiple impose déjà 100 points, donc aucun rachat ne peut tomber sous 50. Un
     * réglage qu'aucun test ne peut voir est un réglage qu'on peut changer par accident.
     *
     * On l'observe en baissant le barème à 10 points/€ — une valeur qu'un exploitant peut vouloir.
     * 20 points sont alors un multiple valide, et c'est le PLANCHER qui doit refuser.
     */
    public function test_le_defaut_de_50_est_bien_celui_du_reste_du_logiciel(): void
    {
        $this->reglage('loyalty_points_for_1_euro_discount', 10);
        $this->oublier('loyalty_min_redeem_points');

        try {
            $this->racheter(20);
            $this->fail('20 points acceptes alors que le defaut du logiciel est 50 (LoyaltyController:402)');
        } catch (PosRedemptionException $e) {
            $this->assertSame('BELOW_MIN_REDEEM', $e->errorCode);
            $this->assertMatchesRegularExpression('/50/', $e->getMessage());
        }

        // Et 50 pile passe : 50 points valent 5 € au barème de 10 points/€.
        $this->racheter(50);
        $this->assertSame(4950, (int) $this->customer->fresh()->loyalty_points);
        $this->assertSame(5.00, (float) $this->order->fresh()->discount);
    }

    /**
     * ET LE PLANCHER NE REMPLACE PAS LA GARDE DU MULTIPLE. Les deux protègent des choses
     * différentes : le multiple évite une remise à centimes, le plancher évite les micro-rachats.
     */
    public function test_le_multiple_reste_exige_au_dessus_du_plancher(): void
    {
        $this->reglage('loyalty_min_redeem_points', 100);

        try {
            $this->racheter(150);
            $this->fail('150 points ne sont pas un multiple de 100 : la remise tomberait sur des centimes');
        } catch (PosRedemptionException $e) {
            $this->assertSame(422, $e->httpStatus);
            $this->assertSame('POINTS_NOT_MULTIPLE', $e->errorCode);
        }
    }
}

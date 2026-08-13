<?php

namespace Tests\Feature\Fiscal;

use App\Enums\OrderType;
use App\Enums\PaymentGateway;
use App\Enums\PaymentStatus;
use App\Enums\PosPaymentMethod;
use App\Models\Branch;
use App\Models\Order;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * UNE VENTE PAYÉE EN LIGNE PAR CARTE DOIT COMPTER COMME « CARTE » DANS LE Z, PAS COMME « AUTRE ».
 *
 * ── LA COLLISION DE NOMBRES, ET POURQUOI ELLE EST INVISIBLE ──────────────────────────────────
 * Le rapport Z ventile les règlements ainsi (`ZReportService::applyOrderToTotals`) :
 *
 *     $method = $order->pos_payment_method ?: ($order->payment_method ?: 'unknown');
 *
 * Un repli parfaitement raisonnable… sauf que les DEUX énumérations utilisent les MÊMES NOMBRES
 * pour des sens DIFFÉRENTS :
 *
 *     PaymentGateway::CARD    = 4        PosPaymentMethod::OTHER  = 4
 *     PaymentGateway::E_WALLET= 2        PosPaymentMethod::CARD   = 2
 *     PaymentGateway::PAYPAL  = 3        PosPaymentMethod::MOBILE_BANKING = 3
 *
 * Une vente web réglée par CARTE en ligne arrive donc dans le Z avec la clé « 4 », que la
 * ventilation lit comme « Autre ». L'argent total reste juste — c'est la RÉPARTITION qui ment,
 * dans un document signé et archivé six ans.
 *
 * Rien ne pouvait le signaler : aucune erreur, aucun total faux, juste une colonne qui reçoit ce
 * qui appartient à une autre. C'est exactement le genre de défaut qu'on ne trouve qu'en comparant
 * deux énumérations qu'on n'a aucune raison de regarder ensemble.
 *
 * ── MESURÉ EN PRODUCTION AVANT DE CORRIGER ───────────────────────────────────────────────────
 * 3 ventes, 49,20 € — les seules concernées à ce jour, toutes du site. Petit, réel, et qui grossit
 * à chaque commande web payée en ligne.
 *
 * ── OÙ EST LE CORRECTIF, ET POURQUOI PAS DANS LE Z ───────────────────────────────────────────
 * `ZReportService` est en ZONE GELÉE (CLAUDE.md §7). On ne touche donc pas au lecteur : on corrige
 * la SOURCE, au moment où la passerelle confirme le paiement. C'est aussi le bon endroit sur le
 * fond — c'est là qu'on SAIT comment le client a payé.
 *
 * ⛔ La traduction est volontairement PARTIELLE : seules les passerelles dont l'équivalent caisse
 * est certain sont converties. Inventer une correspondance pour une passerelle inutilisée
 * introduirait un chiffre faux dans un document fiscal — exactement ce qu'on répare.
 */
class OnlinePaymentPosMethodTest extends TestCase
{
    use RefreshDatabase;

    private Branch $branche;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedSpatieRoles();
        $this->seedMinimalSettings();
        Config::set('fiscal.audit_secret', 'test-fiscal-secret-'.str_repeat('z', 40));
        $this->branche = Branch::factory()->create();
    }

    private function venteWeb(int $passerelle): Order
    {
        return Order::factory()->create([
            'branch_id' => $this->branche->id,
            'subtotal' => 24.00, 'discount' => 0.00, 'total_tax' => 0.00,
            'delivery_charge' => 0.00, 'total' => 24.00,
            'payment_status'     => PaymentStatus::UNPAID,
            'payment_method'     => $passerelle,
            'pos_payment_method' => null,
            'order_type'         => OrderType::DELIVERY,
            'source_surface'     => 'web',
        ]);
    }

    /**
     * LE CŒUR : une carte en ligne se retrouve sous « Carte » dans la ventilation, pas sous « Autre ».
     */
    public function test_une_carte_en_ligne_est_ventilee_en_CARTE(): void
    {
        $o = $this->venteWeb(PaymentGateway::CARD);

        app(\App\Services\Payments\PosMethodFromGateway::class)->appliquer($o);

        $this->assertSame(PosPaymentMethod::CARD, (int) $o->fresh()->pos_payment_method,
            'une vente par carte tombe encore dans la colonne « Autre » du Z');
    }

    /** Le titre-restaurant a le même sens des deux côtés : la traduction est sûre. */
    public function test_le_titre_restaurant_garde_son_sens(): void
    {
        $o = $this->venteWeb(PaymentGateway::TICKET_RESTAURANT);

        app(\App\Services\Payments\PosMethodFromGateway::class)->appliquer($o);

        $this->assertSame(PosPaymentMethod::TICKET_RESTAURANT, (int) $o->fresh()->pos_payment_method);
    }

    /**
     * ⛔ LA GARDE ESSENTIELLE : une passerelle dont l'équivalent caisse n'est PAS certain n'est pas
     * traduite. Mieux vaut une case vide qu'un chiffre inventé dans un document fiscal — une case
     * vide se voit, un chiffre faux se recopie.
     */
    public function test_une_passerelle_ambigue_n_est_PAS_traduite(): void
    {
        $o = $this->venteWeb(PaymentGateway::PAYPAL);

        app(\App\Services\Payments\PosMethodFromGateway::class)->appliquer($o);

        $this->assertNull($o->fresh()->pos_payment_method,
            'une correspondance a été inventée pour une passerelle dont le sens caisse est incertain');
    }

    /**
     * ET ON N'ÉCRASE JAMAIS UNE VALEUR EXISTANTE. Une vente encaissée au comptoir a déjà son mode,
     * posé par la personne qui a encaissé ; la passerelle n'a pas à le corriger après coup.
     */
    public function test_un_mode_deja_pose_par_le_comptoir_n_est_pas_ecrase(): void
    {
        $o = $this->venteWeb(PaymentGateway::CARD);
        DB::table('orders')->where('id', $o->id)
            ->update(['pos_payment_method' => PosPaymentMethod::CASH]);

        app(\App\Services\Payments\PosMethodFromGateway::class)->appliquer($o->fresh());

        $this->assertSame(PosPaymentMethod::CASH, (int) $o->fresh()->pos_payment_method,
            'le mode saisi au comptoir a été écrasé par la passerelle');
    }
}

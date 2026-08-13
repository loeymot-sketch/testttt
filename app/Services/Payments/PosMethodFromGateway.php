<?php

namespace App\Services\Payments;

use App\Enums\PaymentGateway;
use App\Enums\PosPaymentMethod;
use App\Models\Order;
use Illuminate\Support\Facades\DB;

/**
 * TRADUIT LA PASSERELLE DE PAIEMENT EN MODE D'ENCAISSEMENT, POUR LA VENTILATION DU RAPPORT Z.
 *
 * ── LE DÉFAUT QUE CETTE CLASSE RÉPARE ────────────────────────────────────────────────────────
 * Le rapport Z ventile les règlements ainsi (`ZReportService::applyOrderToTotals`) :
 *
 *     $method = $order->pos_payment_method ?: ($order->payment_method ?: 'unknown');
 *
 * Le repli est raisonnable, mais les DEUX énumérations utilisent les MÊMES NOMBRES pour des sens
 * DIFFÉRENTS :
 *
 *     PaymentGateway::CARD     = 4   ⟷   PosPaymentMethod::OTHER           = 4
 *     PaymentGateway::E_WALLET = 2   ⟷   PosPaymentMethod::CARD            = 2
 *     PaymentGateway::PAYPAL   = 3   ⟷   PosPaymentMethod::MOBILE_BANKING  = 3
 *
 * Une vente réglée par CARTE en ligne arrivait donc dans le Z sous la clé « 4 », que la ventilation
 * lit comme « Autre ». Le total restait juste — c'est la RÉPARTITION qui mentait, dans un document
 * signé et archivé six ans. Aucune erreur, aucun total faux : juste une colonne qui reçoit ce qui
 * appartient à une autre. On ne trouve ça qu'en comparant deux énumérations qu'on n'a aucune raison
 * de regarder ensemble.
 *
 * Mesuré en production avant correction : **3 ventes, 49,20 €**, toutes venues du site.
 *
 * ── POURQUOI ICI ET PAS DANS LE RAPPORT Z ────────────────────────────────────────────────────
 * `ZReportService` est en ZONE GELÉE (CLAUDE.md §7). On ne touche donc pas au lecteur, on corrige
 * la SOURCE. C'est aussi le bon endroit sur le fond : c'est au moment où la passerelle confirme le
 * règlement qu'on SAIT comment le client a payé.
 *
 * ── POURQUOI LA TRADUCTION EST VOLONTAIREMENT INCOMPLÈTE ─────────────────────────────────────
 * Seules les passerelles dont l'équivalent caisse est CERTAIN sont converties. Pour les autres, on
 * ne pose rien. Inventer une correspondance (« PayPal, c'est un peu comme du mobile ») écrirait un
 * chiffre faux dans un document fiscal — exactement ce qu'on répare. Une case vide se voit et se
 * corrige ; un chiffre faux se recopie d'année en année.
 *
 * ⛔ N'ajouter une ligne à cette table que si l'équivalence est évidente pour un contrôleur fiscal,
 * pas seulement pour un développeur.
 */
final class PosMethodFromGateway
{
    /**
     * Les seules équivalences dont le sens est identique des deux côtés.
     *
     * @var array<int, int>
     */
    private const CORRESPONDANCES = [
        PaymentGateway::CARD              => PosPaymentMethod::CARD,
        PaymentGateway::TICKET_RESTAURANT => PosPaymentMethod::TICKET_RESTAURANT,
    ];

    /**
     * Pose le mode d'encaissement d'après la passerelle, si et seulement si :
     *   · la vente n'en a pas déjà un — une valeur posée par la personne qui a encaissé fait foi,
     *     la passerelle n'a pas à la corriger après coup ;
     *   · la correspondance est certaine.
     *
     * Écriture directe en base : on ne déclenche aucun événement de modèle, car cette valeur n'est
     * pas un changement d'état commercial — c'est une précision d'écriture comptable sur une vente
     * qui vient d'être payée.
     */
    public function appliquer(Order $order): void
    {
        if ($order->pos_payment_method !== null) {
            return;
        }

        $passerelle = (int) ($order->payment_method ?? 0);
        if (! array_key_exists($passerelle, self::CORRESPONDANCES)) {
            return;
        }

        $mode = self::CORRESPONDANCES[$passerelle];

        DB::table('orders')->where('id', $order->id)->update([
            'pos_payment_method' => $mode,
            'updated_at'         => now(),
        ]);

        $order->pos_payment_method = $mode;
    }
}

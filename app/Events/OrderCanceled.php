<?php

namespace App\Events;

use App\Events\Concerns\DispatchableAfterCommit;
use Illuminate\Database\Eloquent\Model;

/**
 * [F-01] Internal domain event — fired after an order is canceled (any source:
 * customer self-cancel, admin cancel, refund-on-cancel). Triggers the
 * compensating release of stock previously decremented by
 * {@see \App\Listeners\DecrementItemAvailabilityOnOrder}.
 *
 * NOT broadcast (internal only). Always dispatched after-commit at call sites
 * (see {@see DispatchableAfterCommit} trait).
 */
class OrderCanceled
{
    use DispatchableAfterCommit;

    /**
     * @param  int|null  $fromStatus  Statut QUITTÉ par la commande. Facultatif : les
     *                                sites d'appel qui ne le connaissent pas gardent
     *                                le comportement historique (restitution du stock).
     */
    public function __construct(
        public readonly Model $order,
        public readonly ?int $fromStatus = null,
    ) {
    }

    /**
     * [LOCK-OSM-CANCEL-AFTER-READY 2026-08-19] La marchandise a-t-elle DÉJÀ été
     * transformée quand la commande a été annulée ?
     *
     * Le stock part à la CRÉATION de la commande (`DecrementStockOnOrderCreated`), et
     * l'annulation le restituait jusqu'ici sans jamais regarder d'où l'on venait. Tant
     * qu'on ne pouvait annuler qu'AVANT « prêt », c'était juste : rien n'était encore
     * transformé. Depuis l'ouverture de PREPARED→CANCELED et OUT_FOR_DELIVERY→CANCELED,
     * ça ne l'est plus : le pain, la viande et la sauce d'un plat que la cuisine a
     * déclaré PRÊT sont dans la poubelle, pas dans le frigo. Les restituer ferait
     * remonter `on_hand` et RÉ-OUVRIRAIT la disponibilité — la caisse et la borne
     * proposeraient un produit qui n'existe plus (le « faux disponible » que ce projet
     * a déjà chassé dans l'autre sens).
     *
     * Prouvé sur la commande réelle #6598 : `delta=-1 order_created` à 08:50, puis
     * `delta=+1 order_canceled` à 09:41, 51 minutes après le bip « Prêt ».
     *
     * Le seuil est PREPARED, pas PREPARING : il correspond exactement aux deux arêtes
     * nouvellement ouvertes. Annuler depuis ACCEPT ou PREPARING restitue le stock comme
     * avant — comportement historique, volontairement inchangé.
     */
    public function materialAlreadyCommitted(): bool
    {
        return $this->fromStatus !== null
            && $this->fromStatus >= \App\Enums\OrderStatus::PREPARED
            && $this->fromStatus !== \App\Enums\OrderStatus::CANCELED
            && $this->fromStatus !== \App\Enums\OrderStatus::REJECTED
            && $this->fromStatus !== \App\Enums\OrderStatus::RETURNED;
    }
}

<?php

namespace App\Listeners;

use App\Enums\OrderStatus;
use App\Events\OrderStatusChanged;
use App\Services\Kitchen\KitchenTicketAutoPrinter;

/**
 * [KITCHEN-AUTOPRINT 2026-08-07 owner] Le ticket cuisine s'imprime SEUL dès que la commande
 * entre en cuisine — sans que personne ne clique.
 *
 * L'owner : « chaque commande qui vient de la borne ou bien de la caisse, ou chaque commande
 * qui rentre sur l'écran de cuisine, ça s'imprime automatiquement ».
 *
 * POURQUOI SE BRANCHER SUR LE STATUT PLUTÔT QUE SUR LA SOURCE
 * -----------------------------------------------------------
 * L'ancien déclencheur filtrait par surface (borne / web / livraison) et laissait donc la
 * CAISSE de côté : le caissier devait appuyer sur un bouton. Or la règle que l'owner énonce
 * n'est pas « selon d'où ça vient » mais « dès que ça arrive en cuisine ». Le statut ACCEPTÉ
 * est exactement ce moment, et il est le même pour toutes les surfaces — une seule règle, donc
 * aucune surface ne peut être oubliée le jour où on en ajoute une.
 *
 * Le passage à EN PRÉPARATION est couvert aussi : l'écran de cuisine promeut automatiquement
 * la première commande de la file, et une commande peut donc atteindre la cuisine par ce
 * chemin sans jamais transiter visiblement par ACCEPTÉ.
 *
 * Le doublon est impossible : {@see KitchenTicketAutoPrinter::printOnce()} réclame la commande
 * de façon atomique en base, et l'ancien déclencheur à la création passe par la même garde.
 */
class AutoPrintKitchenTicketOnKitchenEntry
{
    /** Statuts qui font entrer une commande sur l'écran de cuisine. */
    private const ENTREE_CUISINE = [OrderStatus::ACCEPT, OrderStatus::PREPARING];

    public function __construct(
        private readonly KitchenTicketAutoPrinter $printer = new KitchenTicketAutoPrinter,
    ) {
    }

    public function handle(OrderStatusChanged $event): void
    {
        if (! in_array((int) $event->newStatus, self::ENTREE_CUISINE, true)) {
            return;
        }

        // Aucune exception ne remonte : une imprimante muette ne doit jamais empêcher une
        // commande de changer de statut. printOnce journalise déjà sa propre cause d'échec.
        $this->printer->printOnce($event->order, 'kitchen_entry');
    }
}

<?php

namespace Tests\Unit\Events;

use App\Enums\OrderStatus;
use App\Events\OrderCanceled;
use Illuminate\Database\Eloquent\Model;
use PHPUnit\Framework\TestCase;

/**
 * [LOCK-OSM-CANCEL-AFTER-READY 2026-08-19] Le stock d'un plat DÉJÀ cuisiné ne doit
 * pas revenir en stock quand la commande est annulée.
 *
 * CE QUE LE RED-TEAM A PROUVÉ (commande réelle #6598, mouvements en base) :
 *     delta=-1  reason=order_created   08:50:33
 *     delta=+1  reason=order_canceled  09:41:19   ← 51 min APRÈS le bip « Prêt »
 * Le pain, la viande et la sauce sont à la poubelle ; les rendre fait remonter
 * `on_hand` et RÉ-OUVRE la disponibilité — la caisse et la borne proposent alors un
 * produit qui n'existe plus. Sur les 109 commandes PRÊTES en base au diagnostic,
 * cela représentait 252 unités fantômes.
 *
 * Ce défaut est une CONSÉQUENCE DIRECTE de l'ouverture de PREPARED→CANCELED : tant
 * que l'annulation n'était possible qu'avant « prêt », rien n'était encore transformé
 * et la restitution était juste.
 *
 * SEUIL : PREPARED, pas PREPARING. Il correspond exactement aux deux arêtes
 * nouvellement ouvertes. Annuler depuis ACCEPT ou PREPARING restitue le stock comme
 * avant — comportement historique, volontairement inchangé.
 */
class OrderCanceledMaterialCommittedTest extends TestCase
{
    private function evenement(?int $fromStatus): OrderCanceled
    {
        $order = new class extends Model
        {
            protected $table = 'orders';
        };

        return new OrderCanceled($order, $fromStatus);
    }

    public function test_annuler_avant_preparation_restitue_le_stock_comme_avant(): void
    {
        foreach ([OrderStatus::PENDING, OrderStatus::ACCEPT, OrderStatus::PREPARING] as $from) {
            $this->assertFalse(
                $this->evenement($from)->materialAlreadyCommitted(),
                "Depuis le statut $from, rien n'est encore transformé : le stock DOIT être restitué."
            );
        }
    }

    public function test_annuler_apres_pret_ne_restitue_pas_le_stock(): void
    {
        $this->assertTrue(
            $this->evenement(OrderStatus::PREPARED)->materialAlreadyCommitted(),
            'La cuisine a déclaré le plat prêt : la marchandise est consommée.'
        );
        $this->assertTrue(
            $this->evenement(OrderStatus::OUT_FOR_DELIVERY)->materialAlreadyCommitted(),
            'Le plat est parti en livraison : la marchandise est consommée.'
        );
    }

    public function test_un_statut_terminal_ne_compte_pas_comme_marchandise_engagee(): void
    {
        // Ces statuts ont une valeur numérique supérieure à PREPARED mais ne décrivent
        // pas une commande cuisinée — un simple `>= PREPARED` les capterait à tort.
        foreach ([OrderStatus::CANCELED, OrderStatus::REJECTED, OrderStatus::RETURNED] as $from) {
            $this->assertFalse(
                $this->evenement($from)->materialAlreadyCommitted(),
                "Le statut terminal $from ne décrit pas une marchandise transformée."
            );
        }
    }

    public function test_sans_statut_d_origine_le_comportement_historique_est_conserve(): void
    {
        // Les 8 autres sites de dispatch d'OrderCanceled ne transmettent pas le statut
        // quitté. Ils DOIVENT continuer à restituer exactement comme avant : ce
        // correctif ne doit rien changer en dehors des deux arêtes nouvellement ouvertes.
        $this->assertFalse(
            $this->evenement(null)->materialAlreadyCommitted(),
            "Statut d'origine inconnu ⇒ comportement historique (restitution)."
        );
    }
}

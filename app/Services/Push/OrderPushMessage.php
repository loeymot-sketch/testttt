<?php

namespace App\Services\Push;

use App\Enums\OrderStatus;
use App\Enums\OrderType;
use App\Models\Order;
use App\Models\User;

/**
 * [NFC / NOTIFICATIONS 2026-11-20] Texte des notifications de commande envoyées au client (table figée, §G6 du GOAL).
 *
 *   4 validée      « Commande n° X validée, on la prépare. » (+ « +N points · solde M » si des points sont crédités)
 *   7 préparation  aucun envoi (trop bavard)
 *   8 prête        à emporter / sur place : « Votre commande n° X est prête, venez la chercher. » · livraison : aucun envoi
 *   10 en route    « Votre commande n° X est en route. »
 *   13 remise      « Bon appétit ! Merci pour votre commande. »
 *   16 / 19        « Votre commande n° X a été annulée. »
 *   1, 22, autres  aucun envoi
 * Jamais de montant dépensé : des POINTS seulement (décision propriétaire 2026-11-06).
 * Construit AU MOMENT DE L'ENVOI (lecture fraîche) : le crédit de points posé juste après la validation est pris en compte.
 */
final class OrderPushMessage
{
    /** Statuts qui déclenchent un envoi (les autres n'en déclenchent jamais). */
    public const STATUTS = [
        OrderStatus::ACCEPT, OrderStatus::PREPARED, OrderStatus::OUT_FOR_DELIVERY,
        OrderStatus::DELIVERED, OrderStatus::CANCELED, OrderStatus::REJECTED,
    ];

    /** Rang d'avancement des statuts qui notifient (plus grand = plus avancé). */
    private const RANG = [
        // ACCEPT et PREPARING ont le MÊME rang : une commande payée en ligne passe directement en préparation (AutoPrepareOnPaidPolicy)
        // avant que « validée » (retardée de 5 s) soit envoyée — « en préparation » ne doit pas l'abandonner (audit adverse P1).
        OrderStatus::ACCEPT => 1, OrderStatus::PREPARING => 1, OrderStatus::PREPARED => 3,
        OrderStatus::OUT_FOR_DELIVERY => 4, OrderStatus::DELIVERED => 5,
    ];

    /**
     * Vrai quand la notification du statut `$voulu` est PÉRIMÉE parce que la commande a déjà dépassé ce statut :
     * « validée » (retardée de 5 s) ne doit JAMAIS arriver après « prête » — avec le même `tag` elle la REMPLACERAIT à l'écran du
     * client et lui dirait qu'on prépare une commande déjà prête. Une commande annulée / refusée / retournée ne reçoit plus
     * aucune notification d'avancement. Même statut = pas périmée.
     */
    public static function estPerimee(int $actuel, int $voulu): bool
    {
        if ($actuel === $voulu || ! isset(self::RANG[$voulu])) {
            return false; // « annulée » (16/19) n'est jamais périmée ; un statut inconnu ne bloque rien
        }
        if (in_array($actuel, [OrderStatus::CANCELED, OrderStatus::REJECTED, OrderStatus::RETURNED], true)) {
            return true;
        }

        return isset(self::RANG[$actuel]) && self::RANG[$actuel] > self::RANG[$voulu];
    }

    /** @return array<string,mixed>|null null = ne rien envoyer */
    public static function build(Order $order, int $status): ?array
    {
        if (! in_array($status, self::STATUTS, true)) {
            return null;
        }
        $livraison = (int) $order->order_type === OrderType::DELIVERY;
        if ($status === OrderStatus::PREPARED && $livraison) {
            return null; // le client de livraison est prévenu au départ du livreur, pas à la sortie de cuisine
        }
        if ($status === OrderStatus::OUT_FOR_DELIVERY && ! $livraison) {
            return null;
        }
        $numero = trim((string) ($order->queue_number ?: $order->order_serial_no ?: $order->id));
        $points = null;
        $solde = null;
        switch ($status) {
            case OrderStatus::ACCEPT:
                $corps = 'Commande n° '.$numero.' validée, on la prépare.';
                $gagnes = (int) ($order->loyalty_points_awarded ?? 0);
                if ($gagnes > 0) {
                    $user = User::query()->withoutGlobalScope(\App\Models\Scopes\BranchScope::class)->find((int) $order->user_id);
                    $points = $gagnes;
                    $solde = $user ? (int) $user->loyalty_points : null;
                    $corps .= ' +'.$gagnes.' points'.($solde !== null ? ' · solde '.$solde : '').'.';
                }
                break;
            case OrderStatus::PREPARED:
                $corps = 'Votre commande n° '.$numero.' est prête, venez la chercher.';
                break;
            case OrderStatus::OUT_FOR_DELIVERY:
                $corps = 'Votre commande n° '.$numero.' est en route.';
                break;
            case OrderStatus::DELIVERED:
                $corps = 'Bon appétit ! Merci pour votre commande.';
                break;
            default:
                $corps = 'Votre commande n° '.$numero.' a été annulée.';
        }

        $charge = [
            'v' => 1,
            'type' => 'order',
            'order_id' => (int) $order->id,
            'status' => $status,
            'title' => 'Le Cayenne',
            'body' => $corps,
            'tag' => 'order-'.(int) $order->id,
            // « Mes commandes » : le statut y est en direct et chaque commande y propose « Suivre » (le site n'a pas de lien direct vers un suivi).
            'url' => '/#orders',
        ];
        if ($points !== null) {
            $charge['points_awarded'] = $points;
        }
        if ($solde !== null) {
            $charge['points_balance'] = $solde;
        }

        return $charge;
    }
}

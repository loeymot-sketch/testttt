<?php

namespace App\Services\Push;

use App\Enums\OrderStatus;
use App\Enums\OrderType;
use App\Models\Order;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * [NFC / NOTIFICATIONS 2026-11-20] Texte des notifications de commande envoyées au client (table figée, §G6 du GOAL ; textes
 * réécrits le 2026-11-20 : un TITRE qui dit l'état, un CORPS qui dit ce qui suit).
 *
 *   4 validée      « Commande n° X validée » · « On s'en occupe : vous recevrez une alerte dès qu'elle est prête / au départ du livreur. »
 *                  (+ « N points gagnés, solde : M points. » SEULEMENT si ces points sont bien crédités AU DESTINATAIRE)
 *   7 préparation  aucun envoi (trop bavard)
 *   8 prête        à emporter / sur place : « Commande n° X prête ! » · livraison : aucun envoi
 *   10 en route    « Votre commande est en route »
 *   13 remise      « Bon appétit ! »
 *   16 annulée     « Commande n° X annulée » · 19 refusée : « Commande n° X non acceptée »
 *   1, 22, autres  aucun envoi
 * Jamais de montant dépensé : des POINTS seulement (décision propriétaire 2026-11-06). Aucune promesse invérifiable (remboursement, délai).
 * Urgence (en-tête Web Push : elle règle les CONDITIONS DE LIVRAISON, pas le son) : « high » partout où le client attend ou doit corriger
 * ce qu'il croit (validée, prête, en route, annulée, refusée : une annulation « normale » pourrait arriver après « prête ») ; « normal »
 * pour la remise seule, que personne n'attend (audit adverse tour 2, S2-01).
 * Construit AU MOMENT DE L'ENVOI (lecture fraîche) : le crédit de points posé juste après la validation est pris en compte.
 */
final class OrderPushMessage
{
    /** Espace insécable : « n° 17 » et « 1 250 points » ne se coupent jamais en fin de ligne. */
    private const ESPACE = "\u{00A0}";

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

    /** Typographie française : espace INSÉCABLE avant « : », « ! », « ? », « ; » (jamais un point d'exclamation seul en début de ligne). */
    private static function fr(string $texte): string
    {
        return (string) preg_replace('/ ([!?:;])/u', self::ESPACE.'$1', $texte);
    }

    /** « 1 point », « 120 points », « 1 250 points » (espace insécable entre milliers). */
    private static function nbPoints(int $n): string
    {
        return number_format($n, 0, ',', self::ESPACE).self::ESPACE.($n > 1 ? 'points' : 'point');
    }

    /**
     * Les points de la commande sont-ils bien allés AU DESTINATAIRE ? Ils sont crédités au propriétaire du code fidélité saisi sur la
     * commande (qui peut être quelqu'un d'autre que son titulaire) : on ne dit « N points gagnés » que si le grand livre le confirme
     * (audit adverse S-03).
     */
    private static function pointsCreditesAuClient(Order $order): bool
    {
        try {
            return DB::table('loyalty_transactions')
                ->where('order_id', (int) $order->id)
                ->where('type', 'earn')
                ->where('user_id', (int) $order->user_id)
                ->exists();
        } catch (\Throwable) {
            return false;
        }
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
        $num = 'n°'.self::ESPACE.$numero;
        $points = null;
        $solde = null;
        $urgence = 'high';
        switch ($status) {
            case OrderStatus::ACCEPT:
                $titre = 'Commande '.$num.' validée';
                $corps = $livraison
                    ? 'On s’en occupe : vous recevrez une alerte au départ du livreur.'
                    : 'On s’en occupe : vous recevrez une alerte dès qu’elle est prête.';
                $gagnes = (int) ($order->loyalty_points_awarded ?? 0);
                if ($gagnes > 0 && self::pointsCreditesAuClient($order)) {
                    $user = User::query()->withoutGlobalScope(\App\Models\Scopes\BranchScope::class)->find((int) $order->user_id);
                    $points = $gagnes;
                    $solde = $user ? (int) $user->loyalty_points : null;
                    $corps .= ' '.self::nbPoints($gagnes).($gagnes > 1 ? ' gagnés' : ' gagné').($solde !== null ? ', solde : '.self::nbPoints($solde) : '').'.';
                }
                break;
            case OrderStatus::PREPARED:
                $titre = 'Commande '.$num.' prête !';
                $corps = (int) $order->order_type === OrderType::TAKEAWAY
                    ? 'Elle vous attend au comptoir.'
                    : 'Elle vous attend : venez la récupérer.';
                break;
            case OrderStatus::OUT_FOR_DELIVERY:
                $titre = 'Votre commande est en route';
                $corps = 'Le livreur est parti avec la commande '.$num.'. Gardez votre téléphone à portée de main.';
                break;
            case OrderStatus::DELIVERED:
                $titre = 'Bon appétit !';
                $corps = 'Votre commande '.$num.' vous a été remise. Merci et à très vite au Cayenne !';
                $urgence = 'normal';
                break;
            case OrderStatus::REJECTED:
                $titre = 'Commande '.$num.' non acceptée';
                $corps = 'Elle n’a pas pu être acceptée. Le détail est dans Mes commandes.';
                break;
            default:
                $titre = 'Commande '.$num.' annulée';
                $corps = 'Votre commande a été annulée. Le détail est dans Mes commandes.';
        }

        $charge = [
            'v' => 1,
            'type' => 'order',
            'order_id' => (int) $order->id,
            'status' => $status,
            'title' => self::fr($titre),
            'body' => self::fr($corps),
            'urgency' => $urgence,   // lu (et retiré) par WebPushSender : jamais dans le message chiffré
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

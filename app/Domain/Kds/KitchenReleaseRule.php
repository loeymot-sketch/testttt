<?php

namespace App\Domain\Kds;

use App\Enums\OrderStatus;
use App\Enums\OrderType;
use App\Enums\PaymentStatus;
use App\Enums\PosPaymentMethod;
use App\Models\Order;

final class KitchenReleaseRule
{
    /**
     * @return int[]
     */
    public static function visibleStatuses(): array
    {
        return [
            OrderStatus::ACCEPT,
            OrderStatus::PREPARING,
            OrderStatus::PREPARED,
        ];
    }

    /**
     * @return int[]
     */
    public static function itemBoardStatuses(): array
    {
        return [
            OrderStatus::ACCEPT,
            OrderStatus::PREPARING,
        ];
    }

    public static function isVisibleStatus(int $status): bool
    {
        return in_array($status, self::visibleStatuses(), true);
    }

    public static function canTransition(int $from, int $to): bool
    {
        if ($from === $to) {
            return self::isVisibleStatus($from);
        }

        return ($from === OrderStatus::ACCEPT && $to === OrderStatus::PREPARING)
            || ($from === OrderStatus::PREPARING && $to === OrderStatus::PREPARED);
    }

    public static function shouldDispatchStatusChanged(int $from, int $to): bool
    {
        return $from !== $to && self::canTransition($from, $to);
    }

    public static function isReleasedToKitchen(
        int $status,
        int $paymentStatus,
        ?int $orderType = null,
        ?int $posPaymentMethod = null
    ): bool {
        if ($status < OrderStatus::ACCEPT) {
            return false;
        }

        if ($paymentStatus === PaymentStatus::PAID) {
            return true;
        }

        return $orderType === OrderType::POS
            && $posPaymentMethod === PosPaymentMethod::CASH;
    }

    public static function orderIsReleased(Order $order): bool
    {
        return self::isReleasedToKitchen(
            (int) $order->status,
            (int) $order->payment_status,
            $order->order_type === null ? null : (int) $order->order_type,
            $order->pos_payment_method === null ? null : (int) $order->pos_payment_method
        );
    }

    /**
     * Payment-release predicate for the KITCHEN BOARD (what list() surfaces and
     * what changeStatus() lets a chef bump). BROADER than isReleasedToKitchen():
     * it additionally admits PENDING_COUNTER orders — the Plan B kiosk→counter
     * encashment flow (config kiosk.payment_route_all_to_counter), where the
     * kitchen starts preparing while the customer pays at the till. Status is
     * enforced separately via visibleStatuses() / canTransition(), so this
     * predicate is intentionally payment-dimension only.
     *
     * SSOT: applyBoardReleaseFilter() is the SQL mirror of this boolean.
     * list() and changeStatus() MUST share one definition so "visible on the
     * board" and "bumpable" can never diverge again — that divergence was the
     * root cause of the unreleased-order bump defect (an UNPAID order, invisible
     * in list(), was still bumpable via the change-status endpoint, firing
     * customer notifications before payment).
     */
    public static function isReleasedForBoard(
        int $paymentStatus,
        ?int $orderType = null,
        ?int $posPaymentMethod = null
    ): bool {
        if ($paymentStatus === PaymentStatus::PAID
            || $paymentStatus === PaymentStatus::PENDING_COUNTER) {
            return true;
        }

        return $orderType === OrderType::POS
            && $posPaymentMethod === PosPaymentMethod::CASH;
    }

    public static function orderIsReleasedForBoard(Order $order): bool
    {
        return self::isReleasedForBoard(
            (int) $order->payment_status,
            $order->order_type === null ? null : (int) $order->order_type,
            $order->pos_payment_method === null ? null : (int) $order->pos_payment_method
        );
    }

    /**
     * SQL mirror of isReleasedForBoard() — applies the board payment-release
     * filter to a query builder so list()'s server-side filter and
     * changeStatus()'s in-memory guard share one definition.
     *
     * @param  \Illuminate\Database\Eloquent\Builder|\Illuminate\Database\Query\Builder  $query
     */
    public static function applyBoardReleaseFilter($query)
    {
        return $query->where(function ($q) {
            $q->where('payment_status', PaymentStatus::PAID)
                ->orWhere('payment_status', PaymentStatus::PENDING_COUNTER)
                ->orWhere(function ($cashQuery) {
                    $cashQuery->where('order_type', OrderType::POS)
                        ->where('pos_payment_method', PosPaymentMethod::CASH);
                });
        });
    }

    /*
     * ─── [GOAL ULTRA-SYNC W4 2026-07-20] Commandes programmées (scheduled_at) ───
     * Mandat owner : une commande pour DANS 1 H ne doit PAS s'afficher en cuisine ;
     * elle apparaît `lead` minutes avant l'heure cible (défaut 20), avec avant ça un
     * bandeau « ⏰ programmée pour HH:MM ». NULL = ASAP (tout l'existant inchangé).
     * Même discipline SSOT que le release-filter : le SQL (applyScheduledBoardFilter)
     * et le booléen (orderIsWithinScheduledWindow) partagent UNE définition pour que
     * « visible sur le board » et « bumpable » ne divergent jamais (leçon du défaut
     * unreleased-order-bump). NF525 : zéro impact — visibilité SELECT-only.
     */

    public static function scheduledLeadMinutes(): int
    {
        return max(1, (int) config('kds.scheduled_lead_minutes', 20));
    }

    /**
     * [KDS-SCHEDULED-NO-UPPER-BOUND 2026-07-22] Grâce (heures) après l'heure cible
     * au-delà de laquelle une programmée no-show sort du board actif.
     */
    public static function scheduledGraceHours(): int
    {
        return max(1, (int) config('kds.scheduled_grace_hours', 2));
    }

    /**
     * Board (KDS + OSS, les 5 chemins jumeaux) : ne montrer que l'ASAP (NULL) et
     * les programmées DANS leur fenêtre active — de `grace` heures avant l'heure
     * cible dépassée jusqu'à `lead` minutes avant : now - grace <= scheduled_at
     * <= now + lead.
     *
     * [KDS-SCHEDULED-NO-UPPER-BOUND 2026-07-22] La borne HAUTE (plancher
     * `scheduled_at >= now - grace`) est nouvelle : sans elle une programmée
     * no-show (jamais bumpée/livrée/annulée) satisfaisait `scheduled_at <= horizon`
     * à VIE et squattait le board. Elle en sort désormais `grace` heures après sa
     * cible. On NE touche PAS le jumeau booléen orderIsWithinScheduledWindow (guard
     * de bump) : la moitié porteuse de la doctrine « visible ⟹ bumpable » reste
     * vraie (tout ce que le board montre est dans la fenêtre du guard) ; la seule
     * divergence introduite est bénigne (invisible mais techniquement bumpable — un
     * abandonné hors board que le chef ne peut plus atteindre), JAMAIS le sens
     * dangereux « visible mais non bumpable ». NULL = ASAP (borne inapplicable).
     *
     * @param  \Illuminate\Database\Eloquent\Builder|\Illuminate\Database\Query\Builder  $query
     * @param  \DateTimeInterface|null  $now  horloge injectable (tests) — défaut now()
     */
    public static function applyScheduledBoardFilter($query, $now = null)
    {
        $base = $now ? \Illuminate\Support\Carbon::instance(
            $now instanceof \Illuminate\Support\Carbon ? $now : \Illuminate\Support\Carbon::parse($now)
        ) : now();
        $horizon = $base->copy()->addMinutes(self::scheduledLeadMinutes());
        $floor = $base->copy()->subHours(self::scheduledGraceHours());
        $deliveryHorizon = $base->copy()->addMinutes(self::deliveryLeadMinutes());

        return $query->where(function ($q) use ($horizon, $floor, $deliveryHorizon) {
            // [VG-2026-10-21 M2-101] Sans heure programmée, une LIVRAISON a son heure réelle dans
            // `delivery_slot_at` (départ de la tournée) : retenue tant que ce départ est au-delà de
            // now + deliveryLeadMinutes(). Pas de plancher ici : l'existant (ASAP) reste intact.
            $q->where(function ($asap) use ($deliveryHorizon) {
                $asap->whereNull('scheduled_at')
                    ->where(function ($d) use ($deliveryHorizon) {
                        $d->whereNull('delivery_slot_at')
                            ->orWhere('delivery_slot_at', '<=', $deliveryHorizon);
                    });
            })
                ->orWhere(function ($qq) use ($horizon, $floor) {
                    $qq->where('scheduled_at', '<=', $horizon)
                        ->where('scheduled_at', '>=', $floor);
                });
        });
    }

    /*
     * ─── [VG-2026-10-21 M2-101 / M1-103] Livraisons : l'heure réelle est `delivery_slot_at` ───
     * Le site envoie toujours `delivery_time = 'ASAP'` et `scheduled_at = NULL`, même pour une
     * livraison du lendemain ; la caisse aussi (« tout de suite » qui part 2 h à 28 h plus tard).
     * La seule heure vraie est le DÉPART de la tournée (`delivery_slot_at`, DeliveryWaveService,
     * docs/LIVRAISON.md §2). Sans elle, la cuisine recevait, imprimait et comptait dans l'attente
     * une livraison de demain dès son acceptation (commande 8100). Une heure programmée explicite
     * (`scheduled_at`) garde la priorité.
     */

    /**
     * Marge de libération cuisine d'une livraison : délai de préparation + UNE tournée.
     * La tournée de plus vient de la règle du « regroupement tardif » (docs/LIVRAISON.md §2) :
     * le départ d'une commande DÉJÀ libérée peut reculer d'un cran (30 min). Avec cette marge,
     * elle ne disparaît jamais du board après y être entrée ; et une livraison « au plus tôt »
     * (départ ≤ now + 20 min arrondi au cran suivant) entre en cuisine tout de suite, comme avant.
     */
    public static function deliveryLeadMinutes(): int
    {
        return self::scheduledLeadMinutes() + max(5, (int) config('delivery.wave_minutes', 30));
    }

    /**
     * Instant d'entrée en cuisine d'une commande RETENUE (programmée ou livraison différée),
     * ou null si elle est entrée en cuisine dès sa création (ASAP).
     */
    public static function kitchenReleaseAt(Order $order): ?\Illuminate\Support\Carbon
    {
        if ($order->scheduled_at !== null) {
            return \Illuminate\Support\Carbon::instance($order->scheduled_at)->subMinutes(self::scheduledLeadMinutes());
        }
        if ($order->delivery_slot_at === null) {
            return null;
        }
        $release = \Illuminate\Support\Carbon::instance($order->delivery_slot_at)->subMinutes(self::deliveryLeadMinutes());

        return ($order->created_at === null || $release->greaterThan($order->created_at)) ? $release : null;
    }

    /**
     * Échappement de la fenêtre glissante legacy (`order_datetime >= now − 8 h`, chemins jumeaux
     * KDS list / orderItems / sync, OSS list / listForBranch) pour une livraison créée bien avant
     * son départ : sans lui, libérée à départ − marge, elle aurait un `order_datetime` trop vieux
     * et serait éjectée du board au moment même où elle doit y entrer (jamais cuisinée).
     * Borné à `scheduledGraceHours()` après le départ (pas de zombie éternel). À appeler DANS
     * le groupe OR de la fenêtre legacy, à côté de `orWhereNotNull('scheduled_at')`.
     */
    public static function orWhereDeliverySlotActive($query, $now = null)
    {
        $base = $now ? \Illuminate\Support\Carbon::instance(
            $now instanceof \Illuminate\Support\Carbon ? $now : \Illuminate\Support\Carbon::parse($now)
        ) : now();

        return $query->orWhere('delivery_slot_at', '>=', $base->copy()->subHours(self::scheduledGraceHours()));
    }

    /**
     * File du pont d'impression : « entrée en cuisine depuis `since` ». L'instant d'entrée est
     * max(created_at, heure cible − marge) : une commande retenue, libérée des heures après sa
     * création, doit sortir sur papier À SA LIBÉRATION — la seule fenêtre `created_at` la
     * condamnait à ne jamais sortir. La réclamation (`kitchen_ticket_claims`) empêche le doublon.
     */
    public static function applyKitchenEntrySince($query, $since)
    {
        $since = \Illuminate\Support\Carbon::instance(
            $since instanceof \Illuminate\Support\Carbon ? $since : \Illuminate\Support\Carbon::parse($since)
        );

        return $query->where(function ($q) use ($since) {
            $q->where('created_at', '>=', $since)
                ->orWhere('scheduled_at', '>=', $since->copy()->addMinutes(self::scheduledLeadMinutes()))
                ->orWhere(function ($d) use ($since) {
                    $d->whereNull('scheduled_at')
                        ->where('delivery_slot_at', '>=', $since->copy()->addMinutes(self::deliveryLeadMinutes()));
                });
        });
    }

    /**
     * Bandeau « à venir » : les programmées ENCORE HORS fenêtre (strictement après
     * now + lead). Complément exact du board filter — une commande est toujours dans
     * exactement un des deux ensembles.
     */
    public static function applyScheduledUpcomingFilter($query, $now = null)
    {
        $base = $now ? \Illuminate\Support\Carbon::instance(
            $now instanceof \Illuminate\Support\Carbon ? $now : \Illuminate\Support\Carbon::parse($now)
        ) : now();
        $horizon = $base->copy()->addMinutes(self::scheduledLeadMinutes());
        $deliveryHorizon = $base->copy()->addMinutes(self::deliveryLeadMinutes());

        // [VG-2026-10-21 M2-101] Complément exact du board filter : une livraison retenue
        // (départ > now + marge livraison) est annoncée au bandeau au lieu de disparaître.
        return $query->where(function ($q) use ($horizon, $deliveryHorizon) {
            $q->where(function ($s) use ($horizon) {
                $s->whereNotNull('scheduled_at')
                    ->where('scheduled_at', '>', $horizon);
            })->orWhere(function ($d) use ($deliveryHorizon) {
                $d->whereNull('scheduled_at')
                    ->where('delivery_slot_at', '>', $deliveryHorizon);
            });
        });
    }

    /**
     * Jumeau booléen du board filter — pour le guard changeStatus() (bump) : une
     * programmée hors fenêtre n'est PAS bumpable (422), miroir de son invisibilité.
     */
    public static function orderIsWithinScheduledWindow(Order $order, $now = null): bool
    {
        $base = $now ? \Illuminate\Support\Carbon::instance(
            $now instanceof \Illuminate\Support\Carbon ? $now : \Illuminate\Support\Carbon::parse($now)
        ) : now();

        if ($order->scheduled_at === null) {
            // [VG-2026-10-21 M2-101] Jumeau du board filter : une livraison retenue (départ au-delà
            // de now + marge livraison) n'est pas bumpable, miroir de son invisibilité.
            if ($order->delivery_slot_at !== null) {
                return $order->delivery_slot_at->lessThanOrEqualTo($base->copy()->addMinutes(self::deliveryLeadMinutes()));
            }

            return true; // ASAP — comportement historique intact
        }

        $horizon = $base->copy()->addMinutes(self::scheduledLeadMinutes());

        return $order->scheduled_at->lessThanOrEqualTo($horizon);
    }
}

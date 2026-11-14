<?php

namespace App\Services\Pos;

use App\Enums\OrderStatus;
use App\Enums\OrderType;
use App\Enums\PaymentStatus;
use App\Enums\PosPaymentMethod;
use App\Models\Order;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;

/**
 * [GOAL CAISSE/CUISINE #3 2026-10-02] SOURCE UNIQUE de la file « en attente d'encaissement ».
 *
 * Avant : la requête vivait en ligne dans la route `counter-collect/pending`, sans aucun filtre de
 * date → des commandes jamais encaissées d'hier, d'avant-hier et plus ancien occupaient la tête de
 * la file (tri FIFO) et pouvaient masquer les récentes derrière le plafond de 200.
 *
 * Maintenant : la route d'affichage ET la purge des anciennes lisent CETTE définition. Une commande
 * n'est purgeable que si elle est exactement une ligne de la file — jamais une commande que
 * l'écran n'aurait pas montrée (ex. livraison web encaissée à la porte).
 *
 * Journée de service : MIROIR de `PosOrderController::fenetreDuService()` et du helper front
 * `resources/js/helpers/posServiceDay.js` — jour civil, et tant que 5 h ne sont pas passées la
 * veille reste dans « aujourd'hui » (le service du soir n'est pas coupé à minuit).
 */
class CounterCollectQueue
{
    public const HEURE_BASCULE_SERVICE = 5;

    public const SCOPE_TODAY = 'today';

    public const SCOPE_PREVIOUS = 'previous';

    public const SCOPE_ALL = 'all';

    /** Début (inclus) de la journée de service courante. */
    public static function serviceDayStart(): Carbon
    {
        $now = Carbon::now(config('app.timezone'));
        $start = $now->copy()->startOfDay();
        if ($now->hour < self::HEURE_BASCULE_SERVICE) {
            $start->subDay();
        }

        return $start;
    }

    /**
     * La file telle que l'écran d'encaissement la définit (toutes dates), non limitée.
     * Byte-identique à l'ancienne requête inline de la route, sans le limit().
     */
    public static function query(int $branchId = 0, array $with = []): Builder
    {
        $query = Order::with($with)
            ->where('payment_status', PaymentStatus::PENDING_COUNTER)
            // Une commande ANNULÉE/REJETÉE/RETOURNÉE ne reste jamais dans la file (fantôme incaissable,
            // confirmCounterPayment la refuse) — set terminal aligné sur PaymentService.
            ->whereNotIn('status', [OrderStatus::CANCELED, OrderStatus::REJECTED, OrderStatus::RETURNED])
            ->where(function ($q) {
                $q->where(function ($k) {
                    $k->where('source_surface', 'kiosk')
                        ->whereIn('order_type', [OrderType::KIOSK, OrderType::TAKEAWAY]);
                })->orWhere(function ($p) {
                    $p->where('source_surface', 'pos')
                        ->where('pos_payment_method', PosPaymentMethod::COUNTER_DEFERRED);
                })->orWhere(function ($tel) {
                    // Commande téléphone caisse (paiement différé) : reste dans la file.
                    $tel->where('source_surface', 'phone')
                        ->where('pos_payment_method', PosPaymentMethod::COUNTER_DEFERRED);
                })->orWhere(function ($web) {
                    // Web à emporter acceptée sans paiement en ligne (marqueur COUNTER_DEFERRED).
                    // La livraison web (encaissée à la porte) n'a pas ce marqueur → exclue.
                    $web->where('source_surface', 'web')
                        ->where('pos_payment_method', PosPaymentMethod::COUNTER_DEFERRED);
                })->orWhere(function ($n) {
                    // Filet anti-NULL : commande borne PENDING_COUNTER sans source_surface (donnée héritée).
                    $n->whereNull('source_surface')
                        ->whereIn('order_type', [OrderType::KIOSK, OrderType::TAKEAWAY]);
                });
            })
            ->orderBy('created_at');

        if ($branchId > 0) {
            $query->where('branch_id', $branchId);
        }

        return $query;
    }

    /**
     * Restreint à la journée de service courante ('today'), aux jours PRÉCÉDENTS ('previous'),
     * ou ne restreint pas ('all').
     *
     * Une commande est « d'un jour précédent » si SA date est antérieure au début de la journée de
     * service. La date = order_datetime, repli created_at. GARDE : une commande À L'AVANCE dont le
     * créneau (scheduled_at) est encore à venir / dans la journée courante n'est JAMAIS rangée dans
     * les anciennes — sinon un repas pré-commandé pour ce soir disparaîtrait de la file.
     */
    public static function applyScope(Builder $query, string $scope): Builder
    {
        if ($scope === self::SCOPE_ALL) {
            return $query;
        }

        $start = self::serviceDayStart();

        $isPrevious = function (Builder $q) use ($start): void {
            $q->where(function ($d) use ($start) {
                $d->where('order_datetime', '<', $start)
                    ->orWhere(function ($c) use ($start) {
                        $c->whereNull('order_datetime')->where('created_at', '<', $start);
                    });
            })->where(function ($s) use ($start) {
                $s->whereNull('scheduled_at')->orWhere('scheduled_at', '<', $start);
            });
        };

        if ($scope === self::SCOPE_PREVIOUS) {
            return $query->where(fn ($q) => $isPrevious($q));
        }

        return $query->whereNot(fn ($q) => $isPrevious($q));
    }

    public static function normalizeScope(?string $scope): string
    {
        return in_array($scope, [self::SCOPE_PREVIOUS, self::SCOPE_ALL], true) ? $scope : self::SCOPE_TODAY;
    }
}

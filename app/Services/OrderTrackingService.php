<?php

namespace App\Services;

use App\Domain\Kds\KitchenReleaseRule;
use App\Enums\OrderStatus;
use App\Enums\OrderType;
use App\Models\FrontendOrder;
use App\Models\Order;
use App\Models\Scopes\BranchScope;

/**
 * [T-C SUIVI-CLIENT 2026-08-16 · GOAL owner] Suivi de commande temps réel
 * public, depuis le téléphone du client — "puisque la commande était rentrée
 * sur notre système, en cours, jusqu'à la dernière étape ; presque prête
 * lorsqu'elle reste entre les deux dernières commandes dans la liste".
 *
 * Identité = `tracking_token` (opaque, généré Order::boot(), PAS `token`/
 * `order_serial_no` qui sont séquentiels et devinables — voir migration
 * 2026_08_16_090000).
 *
 * File « devant » = même sémantique SSOT KitchenReleaseRule que
 * WaitEstimateService/KDS (ne jamais re-définir la file).
 *
 * SELECT-only : zéro impact NF525, zéro écriture.
 */
class OrderTrackingService
{
    /** Nombre de commandes encore devant, à partir duquel on affiche "bientôt prête". */
    public const ALMOST_READY_THRESHOLD = 2;

    public function findByToken(string $trackingToken): ?Order
    {
        return Order::withoutGlobalScope(BranchScope::class)
            ->where('tracking_token', $trackingToken)
            ->first();
    }

    /**
     * @return array{
     *   found: bool,
     *   queue_number: ?string,
     *   status: int,
     *   status_label: string,
     *   step: int,
     *   position_ahead: ?int,
     *   almost_ready: bool,
     *   ready: bool,
     *   wait_low: ?int,
     *   wait_high: ?int,
     *   server_time: string,
     * }
     */
    public function track(string $trackingToken): array
    {
        $now = now(config('app.timezone'));
        $order = $this->findByToken($trackingToken);

        if (! $order) {
            return [
                'found' => false,
                'server_time' => $now->toIso8601String(),
            ];
        }

        return $this->forOrder($order, $now);
    }

    /**
     * Même calcul que track(), mais à partir d'une commande DÉJÀ résolue par
     * un appelant authentifié (kiosk `frontend/order/show/{id}`) — pas besoin
     * du tracking_token dans ce contexte, l'appelant possède déjà la commande.
     * Réutilisé par OrderController::show()/store() pour que la borne affiche
     * la même position de file / fourchette de temps que la page de suivi
     * publique (une seule définition, jamais deux calculs qui divergent).
     *
     * @return array{
     *   found: bool,
     *   queue_number: ?string,
     *   status: int,
     *   status_label: string,
     *   step: int,
     *   position_ahead: ?int,
     *   almost_ready: bool,
     *   ready: bool,
     *   wait_low: ?int,
     *   wait_high: ?int,
     *   server_time: string,
     * }
     */
    public function forOrder(Order|FrontendOrder $order, ?\Illuminate\Support\Carbon $now = null): array
    {
        $now ??= now(config('app.timezone'));

        $status = (int) $order->status;
        [$step, $label] = $this->stepAndLabel($status);
        $ready = $status === OrderStatus::PREPARED
            || $status === OrderStatus::OUT_FOR_DELIVERY
            || $status === OrderStatus::DELIVERED;

        $positionAhead = null;
        $almostReady = false;

        // Position dans la file uniquement pendant la phase cuisine active
        // (avant PREPARED) — une fois prête, la notion de "devant" n'a plus de sens.
        if (! $ready && in_array($status, KitchenReleaseRule::visibleStatuses(), true)) {
            $query = Order::withoutGlobalScope(BranchScope::class)
                ->where('branch_id', $order->branch_id)
                ->where('id', '!=', $order->id)
                ->whereIn('status', KitchenReleaseRule::visibleStatuses())
                ->where('order_datetime', '<', $order->order_datetime)
                // [test-e2e fix C-001/D-001 round-1 2026-08-16] garde-fou d'ancienneté
                // miroir WaitEstimateService::QUEUE_WINDOW_MINUTES (SSOT file active) —
                // sans elle les ACCEPT/PREPARING fantômes jamais bumpés (454 en dev,
                // 431 > 7j, 411 > 30j) gonflaient position_ahead sans borne alors que
                // wait_low/wait_high restaient bornés par ce même filtre côté
                // WaitEstimateService, produisant "465 commandes avant vous" affiché
                // en même temps que "20-25 min" — contradiction interne visible client.
                ->where('order_datetime', '>=', $now->copy()->subMinutes(WaitEstimateService::QUEUE_WINDOW_MINUTES));

            KitchenReleaseRule::applyBoardReleaseFilter($query);
            KitchenReleaseRule::applyScheduledBoardFilter($query, $now);

            $positionAhead = $query->count();
            $almostReady = $positionAhead <= self::ALMOST_READY_THRESHOLD;
        }

        $estimate = $ready ? null : $this->estimateFor($order, $status, $now);

        return [
            'found' => true,
            'queue_number' => $order->queue_number ?: $order->order_serial_no,
            'status' => $status,
            'status_label' => $label,
            'step' => $step,
            'position_ahead' => $positionAhead,
            'almost_ready' => $almostReady,
            'ready' => $ready,
            'wait_low' => $estimate['wait_low'] ?? null,
            'wait_high' => $estimate['wait_high'] ?? null,
            // [GOAL STORES 2026-10-01] Heure de retrait PROGRAMMÉE (HH:MM), si elle est à venir.
            // Sans elle, une commande passée à 3 h pour 18 h 20 affichait « prête dans ~10-15
            // min » sur sa page de suivi — et le rappel de l'application sonnait dans 10 min.
            'prevue_pour' => $this->heureProgrammee($order, $now),
            // [VG-2026-10-21 M2-102] Jour de cette heure (Y-m-d, fuseau du restaurant) : une livraison
            // de DEMAIN 20:30 ne doit pas se lire « 20:30 » tout court. null si aucune heure à venir.
            'prevue_le' => $this->heureCible($order, $now)?->copy()->timezone(config('app.timezone'))->format('Y-m-d'),
            // [E2E UBER 2026-10-24 · U1-001] Un coursier Uber livre : l'heure d'ARRIVÉE qu'Uber estime (HH:MM), à la place du départ
            // de notre tournée, qui ne concerne plus cette commande. null quand ce sont nos livreurs.
            'arrivee_estimee' => $this->arriveeUber($order),
            'server_time' => $now->toIso8601String(),
        ];
    }

    /**
     * [2026-09-23 owner] Avant l'accept caisse (PENDING), le client voit la
     * fourchette générique constante (WaitEstimateService). Une fois la
     * commande ACCEPT/PREPARING, le caissier a fixé `preparation_time` à
     * l'accept (PosOrdersTrackerComponent, "CAISSE-WEB-INTEL 2026-08-06") —
     * c'est CETTE valeur précise qui prime, en décompte depuis `accepted_at`
     * (jamais négatif). `preparation_time` au défaut migration (0) = jamais
     * fixé explicitement → on retombe sur la fourchette générique.
     *
     * @return array{wait_low:int,wait_high:int}
     */
    private function estimateFor(Order|FrontendOrder $order, int $status, \Illuminate\Support\Carbon $now): array
    {
        // [VG-2026-10-21 M2-102] Livraison : l'heure réelle est le départ de sa tournée
        // (`delivery_slot_at`). Elle ne peut pas arriver avant : ni la fourchette générique
        // « 10–15 min », ni le temps de préparation du caissier ne la concernent.
        $depart = $this->departLivraison($order, $now);
        if ($depart !== null) {
            $minutes = (int) ceil(($depart->getTimestamp() - $now->getTimestamp()) / 60);

            return ['wait_low' => $minutes, 'wait_high' => $minutes];
        }

        $inCashierReviewedFlow = in_array($status, [OrderStatus::ACCEPT, OrderStatus::PREPARING], true);
        $preparationTime = (int) ($order->preparation_time ?? 0);

        // [E2E stores · vague B · 2026-10-01 · P0] `FrontendOrder` — le modèle de la route du client —
        // ne convertit PAS `accepted_at` en date (`Order` le fait) : on recevait une chaîne, et
        // `getTimestamp()` sur une chaîne rendait 500 au suivi de toute commande web acceptée
        // (53 erreurs en production du 25 au 28/09). On lit la date quelle que soit sa forme, ici,
        // plutôt que d'ajouter le cast au modèle : cela changerait le JSON qu'il sert ailleurs.
        $accepteeLe = $this->instant($order->accepted_at ?? null);
        // [E2E stores · revue adverse B2-R2-02 · 2026-10-01] `preparation_time` porte DÈS LA CRÉATION
        // le défaut des réglages (30 min en production) : il ne vaut temps du caissier que si celui-ci
        // l'a réellement choisi à l'acceptation (`preparation_time_confirmed_at`). Sinon, la
        // fourchette générique (décision propriétaire du 2026-09-23) reste affichée.
        $confirmeParLaCaisse = $this->instant($order->preparation_time_confirmed_at ?? null) !== null;

        if ($inCashierReviewedFlow && $preparationTime > 0 && $accepteeLe && $confirmeParLaCaisse) {
            // Timestamps bruts (jamais diffInSeconds signé — sens ambigu selon
            // l'appelant/l'objet receveur, source de bugs de sens ailleurs dans
            // ce dépôt) : elapsed > 0 si `now` est après `accepted_at`.
            $elapsedSeconds = $now->getTimestamp() - $accepteeLe->getTimestamp();
            $elapsedMinutes = (int) floor($elapsedSeconds / 60);
            $remaining = max(0, $preparationTime - max(0, $elapsedMinutes));

            return ['wait_low' => $remaining, 'wait_high' => $remaining];
        }

        // [GOAL STORES 2026-10-01] Commande programmée à venir : le temps restant va jusqu'à
        // l'heure choisie — jamais la fourchette générique « dès que prêt », qui ne la concerne
        // pas. (Le rappel natif de l'application lit ces minutes : il sonne donc à l'heure.)
        // [VG-2026-10-21 M2-102] Même heure cible que `prevue_pour` (jamais `scheduled_at` nu : null pour une livraison).
        if (($cible = $this->heureCible($order, $now)) !== null) {
            $minutes = (int) ceil(($cible->getTimestamp() - $now->getTimestamp()) / 60);

            return ['wait_low' => $minutes, 'wait_high' => $minutes];
        }

        return app(WaitEstimateService::class)->estimate((int) $order->branch_id);
    }

    /** HH:MM de l'heure de retrait programmée si elle est STRICTEMENT à venir, sinon null. */
    /**
     * Une date de la base, quelle que soit la forme sous laquelle le modèle la rend : objet date
     * (attribut converti) ou chaîne brute (attribut non converti). Illisible ⇒ null.
     */
    private function instant(mixed $valeur): ?\Carbon\CarbonInterface
    {
        if ($valeur instanceof \Carbon\CarbonInterface) {
            return $valeur;
        }
        if (! is_string($valeur) || trim($valeur) === '') {
            return null;
        }
        try {
            return \Illuminate\Support\Carbon::parse($valeur, config('app.timezone'));
        } catch (\Throwable) {
            return null;
        }
    }

    private function courseUber(Order|FrontendOrder $order): ?\App\Models\UberDirectDelivery
    {
        // [E2E UBER 2026-10-24 round 2 · U1-001] `FrontendOrder` AUSSI : c'est lui que sert `/api/frontend/order/show/{id}`,
        // la route que le site interroge toutes les 20 s. En n'acceptant que `Order`, l'arrivée estimée n'existait que sur la
        // route publique par jeton — le client, lui, lisait encore « Départ de la livraison prévu à 18:30 » (mauvaise couche mesurée).
        if ((int) $order->order_type !== \App\Enums\OrderType::DELIVERY || ! $order->id) {
            return null;
        }
        try {
            return app(\App\Services\UberDirect\UberDirectDispatchService::class)->activeCourse((int) $order->id);
        } catch (\Throwable $e) {
            return null; // table absente / base indisponible : le suivi ordinaire ne doit jamais en dépendre
        }
    }

    private function arriveeUber(Order|FrontendOrder $order): ?string
    {
        $course = $this->courseUber($order);
        $eta = $course ? (data_get($course->delivery_payload, 'dropoff_eta') ?? data_get($course->quote_payload, 'dropoff_eta')) : null;
        if (! $eta || (string) $course->status === \App\Models\UberDirectDelivery::STATUS_DELIVERED) {
            return null;
        }
        try {
            $at = \Carbon\Carbon::parse($eta);
            // [E2E UBER 2026-10-24 · U2-013] Une arrivée estimée DÉJÀ PASSÉE n'est plus une information : on ne l'affiche pas.
            return $at->greaterThan(now()) ? $at->setTimezone(config('app.timezone'))->format('H:i') : null;
        } catch (\Throwable $e) {
            return null;
        }
    }

    private function heureProgrammee(Order|FrontendOrder $order, \Illuminate\Support\Carbon $now): ?string
    {
        // [VG-2026-10-21 M2-102] Programmée, sinon départ de tournée d'une livraison.
        return $this->heureCible($order, $now)?->copy()->timezone(config('app.timezone'))->format('H:i');
    }

    /**
     * [VG-2026-10-21 M2-102] Heure cible STRICTEMENT à venir : `scheduled_at` (retrait programmé),
     * à défaut le départ de la tournée d'une livraison (`delivery_slot_at`). null sinon.
     */
    private function heureCible(Order|FrontendOrder $order, \Illuminate\Support\Carbon $now): ?\Carbon\CarbonInterface
    {
        $prevue = $order->scheduled_at ?? null;
        if ($prevue instanceof \Carbon\CarbonInterface) {
            return $prevue->greaterThan($now) ? $prevue : null;
        }

        return $this->departLivraison($order, $now);
    }

    /** [VG-2026-10-21 M2-102] Départ de tournée à venir d'une livraison sans heure programmée, sinon null. */
    private function departLivraison(Order|FrontendOrder $order, \Illuminate\Support\Carbon $now): ?\Carbon\CarbonInterface
    {
        if (($order->scheduled_at ?? null) !== null) {
            return null;
        }
        // [E2E UBER 2026-10-24 · U1-001] Confiée à un coursier Uber : le départ de NOTRE tournée n'a plus de sens pour cette commande.
        if ($this->courseUber($order) !== null) {
            return null;
        }
        // [VG-2026-10-21 M4-004] Le livreur est PARTI (en route / remise) : le départ théorique de la tournée
        // n'est plus une heure à venir — l'annoncer après un départ anticipé serait faux.
        if (in_array((int) $order->status, [OrderStatus::OUT_FOR_DELIVERY, OrderStatus::DELIVERED], true)) {
            return null;
        }
        $slot = $order->delivery_slot_at ?? null;
        // [VG-2026-10-21 R2-001-cl] Relu en BASE : à la création, la tournée est écrite par l'écouteur
        // (AssignDeliveryWaveOnOrderCreated) sur une AUTRE instance ; la réponse de création lisait NULL et
        // annonçait « 10–15 min » quand le suivi, lui, donnait le départ. Une seule définition pour les deux.
        if (($order->exists ?? false) && (int) ($order->order_type ?? 0) === OrderType::DELIVERY) {
            $slot = Order::withoutGlobalScope(BranchScope::class)->whereKey($order->id)->value('delivery_slot_at');
        }
        $depart = $this->instant($slot);

        return ($depart !== null && $depart->greaterThan($now)) ? $depart : null;
    }

    /**
     * @return array{0:int,1:string}
     */
    private function stepAndLabel(int $status): array
    {
        return match (true) {
            $status === OrderStatus::PENDING => [1, 'Commande reçue'],
            $status === OrderStatus::ACCEPT => [2, 'Commande acceptée'],
            $status === OrderStatus::PREPARING => [3, 'En préparation'],
            $status === OrderStatus::PREPARED => [4, 'Prête'],
            $status === OrderStatus::OUT_FOR_DELIVERY => [4, 'En livraison'],
            $status === OrderStatus::DELIVERED => [5, 'Livrée / Récupérée'],
            $status === OrderStatus::CANCELED => [0, 'Annulée'],
            $status === OrderStatus::REJECTED => [0, 'Refusée'],
            default => [1, 'Commande reçue'],
        };
    }
}

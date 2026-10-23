<?php

namespace App\Services\Delivery;

use App\Models\Branch;
use App\Models\DeliveryStop;
use App\Models\Order;
use App\Enums\PaymentGateway;
use App\Enums\PaymentStatus;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Tournées de livraison de 30 minutes.
 *
 * Une TOURNÉE (wave) = un départ du restaurant sur la grille :00 / :30 ; tous ses arrêts sont
 * livrés par un seul livreur en un seul trajet. Règles (décision propriétaire 2026-10-20) :
 *  · deux commandes dont le départ tombe dans la même fenêtre partent ensemble ;
 *  · une 2ᵉ commande arrivée dans les 30 minutes de la 1ʳᵉ, trop tard pour son départ, peut le
 *    décaler d'un cran de grille pour partir avec elle — jamais si le livreur est déjà parti, si
 *    la tournée est pleine, ou si un client a choisi lui-même son créneau ;
 *  · au-delà d'une fenêtre d'écart : deux départs, chaque client reçoit sa commande sans attendre.
 *
 * Hors machine à états de la commande (gelée) : tout vit dans `delivery_stops`.
 */
class DeliveryWaveService
{
    public function __construct(private readonly DeliveryQuoteService $quotes, private readonly RoadRouteService $routes)
    {
    }

    public function waveMinutes(): int
    {
        return max(5, (int) config('delivery.wave_minutes', 30));
    }

    /** Premier départ possible : maintenant + délai de préparation, arrondi au cran de grille supérieur. */
    public function earliestWave(?CarbonInterface $now = null): Carbon
    {
        $now = $now ? Carbon::instance($now) : Carbon::now();
        $target = $now->copy()->addMinutes((int) config('delivery.min_lead_minutes', 20))->startOfMinute();
        // Une borne exacte (19:30:00) est acceptée ; la moindre seconde de plus saute au cran suivant.
        $target = $this->ceilToGrid($target);

        return $this->nextInServiceWindow($target);
    }

    /**
     * Créneaux proposés au client (« choix d'horaire »).
     *
     * @return array<int, array{wave_at: string, time: string, label: string, stops: int, full: bool}>
     */
    public function availableWaves(int $branchId, ?CarbonInterface $now = null, int $limit = 12): array
    {
        $out = [];
        $cursor = $this->earliestWave($now);
        $horizon = ($now ? Carbon::instance($now) : Carbon::now())->copy()->addDays((int) config('delivery.horizon_days', 2));
        $max = (int) config('delivery.max_stops_per_wave', 6);

        while (count($out) < $limit && $cursor->lte($horizon)) {
            if ($this->inServiceWindow($cursor)) {
                $stops = DeliveryStop::query()->where('branch_id', $branchId)->where('wave_at', $cursor)->whereNull('left_at')->count();
                $closed = $this->waveHasLeft($branchId, $cursor);
                $out[] = [
                    'wave_at' => $cursor->toDateTimeString(),
                    'time'    => $cursor->format('H:i'),
                    'label'   => $cursor->isSameDay(Carbon::now()) ? $cursor->format('H:i') : $cursor->translatedFormat('D H:i'),
                    'stops'   => $stops,
                    // Une tournée dont le livreur est déjà parti n'accepte plus personne : même traitement qu'une tournée pleine.
                    'full'    => $closed || $stops >= $max,
                ];
            }
            $cursor = $cursor->copy()->addMinutes($this->waveMinutes());
        }

        return $out;
    }

    /**
     * Rattache une commande de livraison à une tournée. Idempotent (une commande = un arrêt).
     *
     * @throws ValidationException si le créneau choisi par le client est invalide
     */
    public function assign(Order $order, ?CarbonInterface $chosenWave = null): DeliveryStop
    {
        return DB::transaction(function () use ($order, $chosenWave) {
            if ($existing = DeliveryStop::query()->where('order_id', $order->id)->lockForUpdate()->first()) {
                return $existing;
            }

            // Une livraison sans adresse n'a ni point d'arrivée ni prix : jamais de tournée pour elle.
            if (! $order->address) {
                throw new \DomainException('Commande de livraison sans adresse : aucune tournée.');
            }

            $branchId = (int) $order->branch_id;
            $now = Carbon::now();
            $max = (int) config('delivery.max_stops_per_wave', 6);
            $fixed = false;

            if ($chosenWave !== null) {
                $wave = $this->validatedChosenWave($branchId, Carbon::instance($chosenWave), $now);
                $fixed = true;
            } else {
                $wave = $this->pickAsapWave($branchId, $now, $max);
            }

            $stop = DeliveryStop::query()->create([
                'branch_id'       => $branchId,
                'order_id'        => $order->id,
                'wave_at'         => $wave,
                'sequence'        => 99,
                'slot_fixed'      => $fixed,
                'delivery_boy_id' => $this->defaultCourierId($branchId),
                'distance_km'     => $order->delivery_distance_km,
                ...$this->collectionFor($order),
            ]);

            $order->forceFill(['delivery_slot_at' => $wave] + ($stop->delivery_boy_id ? ['delivery_boy_id' => $stop->delivery_boy_id] : []))->saveQuietly();
            $this->resequence($branchId, $wave);

            // Livreur attribué d'office (livreur unique) : on le prévient comme le fait l'attribution historique,
            // une fois la commande bien enregistrée (jamais pour une transaction annulée).
            if ($stop->delivery_boy_id) {
                $orderId = (int) $order->id;
                DB::afterCommit(fn () => $this->notifyCourier($orderId));
            }

            return $stop->fresh();
        });
    }

    /** Ordre de passage au plus proche voisin depuis le restaurant + ETA cumulée (estimation, pas Waze). */
    public function resequence(int $branchId, CarbonInterface $wave): void
    {
        $stops = DeliveryStop::query()->where('branch_id', $branchId)->where('wave_at', $wave)->whereNull('left_at')
            ->with('order.address')->get();
        if ($stops->isEmpty()) {
            return;
        }

        $branch = Branch::query()->find($branchId);
        $cursor = [(float) $branch?->latitude, (float) $branch?->longitude];
        $remaining = $stops->all();
        $ordered = [];

        while ($remaining) {
            $bestIdx = null;
            $bestDist = INF;
            foreach ($remaining as $i => $stop) {
                $p = $this->coords($stop);
                $d = $p ? $this->km($cursor, $p) : 9999.0;
                if ($d < $bestDist) {
                    $bestDist = $d;
                    $bestIdx = $i;
                }
            }
            $stop = $remaining[$bestIdx];
            unset($remaining[$bestIdx]);
            $ordered[] = $stop;
            $p = $this->coords($stop);
            if ($p) {
                $cursor = $p;
            }
        }

        // [2026-10-23] ETA d'ARRIVÉE (aller simple, ce que voit le client) sur itinéraire routier réel quand il est disponible.
        // Chemin de CRÉATION de commande (appelé dans la transaction d'assign(), verrous tenus) : AUCUN appel réseau ici —
        // itinéraire déjà en mémoire s'il existe, sinon estimation. Le réseau n'est sollicité qu'à la lecture (tourSummary).
        $plan = $this->plan($branch, $ordered, false);
        foreach ($ordered as $idx => $stop) {
            $stop->forceFill(['sequence' => $idx + 1, 'eta_minutes' => $plan['arrivals'][$idx]])->save();
        }
    }

    /**
     * [2026-10-23 · décision propriétaire] Durée RÉELLE d'une tournée ALLER-RETOUR pour la gestion des livreurs :
     * restaurant → arrêts (dans l'ordre de passage) → restaurant, remises comprises. Ce n'est PAS ce que voit le client
     * (aller simple jusqu'à SON adresse) et le prix n'en dépend jamais.
     *
     * @param  iterable<DeliveryStop>  $stops  avec `order.address` chargés
     * @return array{tour_minutes: int, out_minutes: int, return_minutes: int, stops: int, distance_km: float, back_at: string, source: string}|null
     *         null si aucun arrêt n'a de point GPS exploitable (on n'invente pas une durée).
     */
    public function tourSummary(int $branchId, iterable $stops, ?CarbonInterface $start = null): ?array
    {
        $list = collect($stops)->sortBy(fn (DeliveryStop $s) => $s->sequence ?? PHP_INT_MAX)->values()->all();
        $withPoint = array_values(array_filter($list, fn (DeliveryStop $s) => $this->coords($s) !== null));
        $branch = Branch::query()->find($branchId);
        if (! $withPoint || ! $branch || ! is_numeric($branch->latitude) || ! is_numeric($branch->longitude)) {
            return null;
        }

        $plan = $this->plan($branch, $list);
        $handover = (int) config('delivery.handover_minutes', 4);
        $out = (int) end($plan['arrivals']) + $handover; // arrivée au dernier arrêt + sa remise
        $tour = $out + $plan['return_minutes'];
        // Départ réel s'il a eu lieu (le premier arrêt parti) ; sinon l'heure prévue, JAMAIS dans le passé : une tournée en retard
        // (heure dépassée, pas encore partie) partira au plus tôt maintenant, donc le retour ne peut pas s'afficher avant.
        $lefts = collect($list)->pluck('left_at')->filter()->sort()->values();
        $origin = $start
            ? Carbon::instance($start)
            : ($lefts->isNotEmpty() ? Carbon::instance($lefts->first()) : Carbon::instance($list[0]->wave_at)->max(Carbon::now()));

        return [
            'tour_minutes'   => $tour,
            'out_minutes'    => $out,
            'return_minutes' => $plan['return_minutes'],
            'stops'          => count($list),
            'distance_km'    => round($plan['km'], 1),
            'back_at'        => $origin->copy()->addMinutes($tour)->toDateTimeString(),
            'source'         => $plan['source'],
        ];
    }

    /**
     * Trajets du parcours restaurant → arrêts (ordre donné) → restaurant.
     * Minutes d'une étape aller = conduite × facteur de circulation (itinéraire réel seulement) + marge de stationnement,
     * arrondie à la minute par étape (identique à l'ancienne estimation quand le routage est coupé).
     *
     * @param  list<DeliveryStop>  $ordered
     * @param  bool  $network  false = jamais d'appel réseau (création de commande)
     * @return array{arrivals: list<int>, return_minutes: int, km: float, source: string}
     */
    private function plan(?Branch $branch, array $ordered, bool $network = true): array
    {
        $handover = (int) config('delivery.handover_minutes', 4);
        $buffer = (float) config('delivery.travel_buffer_minutes', 3);
        $home = [(float) $branch?->latitude, (float) $branch?->longitude];

        $points = [$home];
        $pointIndex = [];
        foreach ($ordered as $idx => $stop) {
            if ($p = $this->coords($stop)) {
                $points[] = $p;
                $pointIndex[$idx] = count($points) - 1; // l'étape qui ARRIVE à cet arrêt = legs[index - 1]
            }
        }
        $points[] = $home;

        $route = $this->routes->legs($points, $network);
        $factor = $route['source'] === RoadRouteService::SOURCE_ROAD ? max(1.0, (float) config('delivery.routing.traffic_factor', 1.15)) : 1.0;
        $legs = $route['legs'];

        $arrivals = [];
        $elapsed = 0;
        foreach ($ordered as $idx => $stop) {
            $leg = isset($pointIndex[$idx]) ? ($legs[$pointIndex[$idx] - 1] ?? null) : null;
            $elapsed += (int) ceil(($leg ? $leg['seconds'] / 60 * $factor : 0.0) + $buffer) + ($arrivals ? $handover : 0);
            $arrivals[] = $elapsed;
        }

        $returnLeg = $legs ? end($legs) : null;

        return [
            'arrivals'       => $arrivals,
            'return_minutes' => $returnLeg ? (int) ceil($returnLeg['seconds'] / 60 * $factor) : 0,
            'km'             => array_sum(array_column($legs, 'km')),
            'source'         => $route['source'],
        ];
    }

    /**
     * Livreur par défaut : s'il n'y a qu'UN livreur actif sur la branche (cas du Cayenne), c'est lui.
     * Plusieurs livreurs = pas de choix silencieux, la caisse désigne qui prend la tournée.
     */
    public function defaultCourierId(int $branchId): ?int
    {
        try {
            $ids = \App\Models\User::query()
                ->withoutGlobalScope(\App\Models\Scopes\BranchScope::class)
                ->role('Delivery Boy', 'sanctum')
                ->where('status', \App\Enums\Status::ACTIVE)
                ->whereIn('branch_id', [0, $branchId])
                ->limit(2)->pluck('id');
        } catch (\Spatie\Permission\Exceptions\RoleDoesNotExist) {
            // Rôle absent (installation neuve) : la tournée se crée quand même, sans livreur désigné.
            return null;
        }

        return $ids->count() === 1 ? (int) $ids->first() : null;
    }

    /** Désigne le livreur d'une tournée (tous ses arrêts non encore partis). */
    public function assignCourier(int $branchId, CarbonInterface $wave, int $courierId): int
    {
        // [UBER-DIRECT 2026-10-22] Un arrêt confié à Uber n'a plus de livreur à nous : désigner un livreur ne le lui reprend pas.
        $uberOrders = \App\Models\UberDirectDelivery::query()->withoutGlobalScope(\App\Models\Scopes\BranchScope::class)->activeCourse()->pluck('order_id');
        $stopIds = DeliveryStop::query()->where('branch_id', $branchId)->where('wave_at', $wave)->whereNull('left_at')->whereNotIn('order_id', $uberOrders)->pluck('order_id');
        DeliveryStop::query()->where('branch_id', $branchId)->where('wave_at', $wave)->whereNull('left_at')->whereNotIn('order_id', $uberOrders)->update(['delivery_boy_id' => $courierId]);
        Order::query()->withoutGlobalScope(\App\Models\Scopes\BranchScope::class)->whereIn('id', $stopIds)->update(['delivery_boy_id' => $courierId]);

        // Désignation volontaire par la caisse : on prévient le livreur, comme le fait la voie historique
        // (mail / SMS / push, best-effort : une passerelle absente ne doit pas défaire l'attribution).
        foreach ($stopIds as $orderId) {
            $this->notifyCourier((int) $orderId);
        }

        return $stopIds->count();
    }

    private function notifyCourier(int $orderId): void
    {
        try {
            \App\Events\SendOrderDeliveryBoyMail::dispatch(['order_id' => $orderId, 'status' => 101]);
            \App\Events\SendOrderDeliveryBoySms::dispatch(['order_id' => $orderId, 'status' => 101]);
            \App\Events\SendOrderDeliveryBoyPush::dispatch(['order_id' => $orderId, 'status' => 101]);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('delivery.courier.notify_failed', ['order_id' => $orderId, 'error' => $e->getMessage()]);
        }
    }

    // ── choix de la tournée ─────────────────────────────────────────────────────────────────

    private function pickAsapWave(int $branchId, Carbon $now, int $max): Carbon
    {
        $candidate = $this->earliestWave($now);

        // Rejoindre le départ juste avant (qu'on ne peut plus prendre) en le décalant d'un cran ?
        $previous = $candidate->copy()->subMinutes($this->waveMinutes());
        $stops = DeliveryStop::query()->where('branch_id', $branchId)->where('wave_at', $previous)->lockForUpdate()->get();
        if ($stops->isNotEmpty()
            && $stops->every(fn (DeliveryStop $s) => $s->left_at === null && ! $s->slot_fixed)
            && $stops->count() < $max
            && $stops->min('created_at')->gte($now->copy()->subMinutes($this->waveMinutes()))
            && DeliveryStop::query()->where('branch_id', $branchId)->where('wave_at', $candidate)->count() === 0
        ) {
            DeliveryStop::query()->whereIn('id', $stops->pluck('id'))->update(['wave_at' => $candidate]);
            Order::query()->whereIn('id', $stops->pluck('order_id'))->update(['delivery_slot_at' => $candidate]);
            $this->resequence($branchId, $candidate);

            return $candidate;
        }

        // Sinon : premier créneau de la grille qui n'est pas plein.
        for ($i = 0; $i < 48; $i++) {
            $count = DeliveryStop::query()->where('branch_id', $branchId)->where('wave_at', $candidate)->whereNull('left_at')->lockForUpdate()->count();
            if ($count < $max && $this->inServiceWindow($candidate) && ! $this->waveHasLeft($branchId, $candidate)) {
                return $candidate;
            }
            $candidate = $this->nextInServiceWindow($candidate->copy()->addMinutes($this->waveMinutes()));
        }

        throw ValidationException::withMessages(['delivery_slot_at' => 'Plus aucun créneau de livraison disponible.']);
    }

    /** @throws ValidationException */
    public function validatedChosenWave(int $branchId, Carbon $wave, Carbon $now): Carbon
    {
        $fail = fn (string $msg) => throw ValidationException::withMessages(['delivery_slot_at' => $msg]);
        $step = $this->waveMinutes();

        if ($wave->second !== 0 || $wave->minute % $step !== 0) {
            $fail("Créneau invalide : les départs ont lieu toutes les {$step} minutes.");
        }
        if ($wave->lt($this->earliestWave($now))) {
            $fail('Ce créneau est trop proche : choisissez un horaire plus tardif.');
        }
        if ($wave->gt($now->copy()->addDays((int) config('delivery.horizon_days', 2)))) {
            $fail('Ce créneau est trop lointain.');
        }
        if (! $this->inServiceWindow($wave)) {
            $fail('Ce créneau est en dehors des heures de livraison.');
        }
        $count = DeliveryStop::query()->where('branch_id', $branchId)->where('wave_at', $wave)->whereNull('left_at')->lockForUpdate()->count();
        if ($count >= (int) config('delivery.max_stops_per_wave', 6) || $this->waveHasLeft($branchId, $wave)) {
            $fail('Ce créneau est complet : choisissez le suivant.');
        }

        return $wave;
    }

    /**
     * Le livreur est-il DÉJÀ PARTI avec cette tournée ? Il peut partir en avance (commandes prêtes avant l'heure) :
     * une commande qui la rejoindrait ensuite serait injoignable (livreur parti, personne à désigner à la caisse).
     */
    public function waveHasLeft(int $branchId, CarbonInterface $wave): bool
    {
        return DeliveryStop::query()->where('branch_id', $branchId)->where('wave_at', $wave)->whereNotNull('left_at')->exists();
    }

    /**
     * [VG-2026-10-21 M4-004] Heure à partir de laquelle le livreur peut partir avec cette tournée :
     * heure de la tournée − `delivery.depart_max_advance_minutes`. Un départ ferme la tournée (waveHasLeft) :
     * sans borne, la tournée de demain 18:00 partait aujourd'hui à 17:10.
     */
    public function departureOpensAt(CarbonInterface $wave): Carbon
    {
        return Carbon::instance($wave)->copy()->subMinutes(max(0, (int) config('delivery.depart_max_advance_minutes', 45)));
    }

    /**
     * [VG-2026-10-21 M4-005] Le jour d'une tournée, pour les écrans (livreur, planche « Tournées » de la caisse) : « Départ
     * 00:30 » sans date se lisait comme ce soir alors que la tournée partait 24 h plus tard. Date calendaire, jamais devinée.
     *
     * @return array{date: string, is_today: bool, day_label: string}
     */
    public function waveDay(CarbonInterface $wave): array
    {
        $wave = Carbon::instance($wave);
        $today = Carbon::today();
        $jours = ['dim.', 'lun.', 'mar.', 'mer.', 'jeu.', 'ven.', 'sam.'];

        return [
            'date'      => $wave->format('Y-m-d'),
            'is_today'  => $wave->isSameDay($today),
            'day_label' => match (true) {
                $wave->isSameDay($today)              => "aujourd'hui",
                $wave->isSameDay($today->copy()->addDay()) => 'demain',
                $wave->isSameDay($today->copy()->subDay()) => 'hier',
                default                                => $jours[$wave->dayOfWeek] . ' ' . $wave->format('d/m'),
            },
        ];
    }

    /** [VG-2026-10-21 M4-004] Heure d'arrivée estimée d'un arrêt : depuis le départ RÉEL s'il a eu lieu, sinon depuis l'heure de la tournée. */
    public function etaAt(DeliveryStop $stop): Carbon
    {
        // [E2E 2026-10-23 C2-005] Pas encore parti : au plus tôt MAINTENANT (même règle que back_at), jamais une arrivée dans le passé.
        $origin = $stop->left_at ? Carbon::instance($stop->left_at) : Carbon::instance($stop->wave_at)->max(Carbon::now());

        return $origin->copy()->addMinutes((int) $stop->eta_minutes);
    }

    // ── grille et horaires ──────────────────────────────────────────────────────────────────

    private function ceilToGrid(Carbon $t): Carbon
    {
        $step = $this->waveMinutes();
        $t = $t->copy();
        $overflowSeconds = $t->second;
        if ($overflowSeconds > 0) {
            $t->addMinute()->second(0);
        }
        $rem = $t->minute % $step;

        return $rem === 0 ? $t : $t->addMinutes($step - $rem);
    }

    /** Fenêtre de service (enjambe minuit : 18:00 → 00:30). */
    public function inServiceWindow(CarbonInterface $t): bool
    {
        $open = (string) config('kds.scheduled_window_open', '18:00');
        $close = (string) config('kds.scheduled_window_close', '00:30');
        $hm = $t->format('H:i');

        return $open <= $close ? ($hm >= $open && $hm <= $close) : ($hm >= $open || $hm <= $close);
    }

    private function nextInServiceWindow(Carbon $t): Carbon
    {
        for ($i = 0; $i < 96 && ! $this->inServiceWindow($t); $i++) {
            $t = $t->copy()->addMinutes($this->waveMinutes());
        }

        return $t;
    }

    // ── encaissement et géométrie ───────────────────────────────────────────────────────────

    /**
     * [VG-2026-10-21 L2] Mode d'encaissement « titre-restaurant » d'un arrêt (s'ajoute à DeliveryStop::COLLECT_CASH /
     * COLLECT_CARD / COLLECT_PAID_ONLINE ; colonne delivery_stops.collect_method = 16 caractères).
     */
    public const COLLECT_MEAL_VOUCHER = 'meal_voucher';

    /** @return array{collect_method: string, amount_due_cents: int} */
    public function collectionFor(Order $order): array
    {
        if ((int) $order->payment_status === PaymentStatus::PAID) {
            return ['collect_method' => DeliveryStop::COLLECT_PAID_ONLINE, 'amount_due_cents' => 0];
        }

        // [VG-2026-10-21 L2] Titre-restaurant récupéré à la porte (décision propriétaire 2026-10-22) : un vrai mode, pas « carte ».
        $method = match ((int) $order->payment_method) {
            PaymentGateway::CASH_ON_DELIVERY  => DeliveryStop::COLLECT_CASH,
            PaymentGateway::TICKET_RESTAURANT => self::COLLECT_MEAL_VOUCHER,
            default                           => DeliveryStop::COLLECT_CARD,
        };

        return ['collect_method' => $method, 'amount_due_cents' => (int) round(((float) $order->total) * 100)];
    }

    /** @return array{0: float, 1: float}|null */
    private function coords(DeliveryStop $stop): ?array
    {
        $a = $stop->order?->address;
        if (! $a || ! is_numeric($a->latitude) || ! is_numeric($a->longitude)) {
            return null;
        }

        return [(float) $a->latitude, (float) $a->longitude];
    }

    /** @param array{0: float, 1: float} $a @param array{0: float, 1: float} $b */
    private function km(array $a, array $b): float
    {
        $dLat = deg2rad($b[0] - $a[0]);
        $dLng = deg2rad($b[1] - $a[1]);
        $h = sin($dLat / 2) ** 2 + cos(deg2rad($a[0])) * cos(deg2rad($b[0])) * sin($dLng / 2) ** 2;

        return 6371.0 * 2 * atan2(sqrt($h), sqrt(1 - $h));
    }
}

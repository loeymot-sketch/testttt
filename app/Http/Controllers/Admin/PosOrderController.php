<?php

namespace App\Http\Controllers\Admin;

use Exception;
use App\Models\Order;
use App\Exports\OrderExport;
use Illuminate\Http\Request;
use App\Services\OrderService;
use Maatwebsite\Excel\Facades\Excel;
use App\Http\Requests\PaginateRequest;
use App\Http\Requests\OrderStatusRequest;
use App\Http\Requests\PaymentStatusRequest;
use App\Http\Resources\SimpleOrderResource;
use App\Http\Resources\OrderDetailsResource;
use App\Models\Scopes\BranchScope;
use Symfony\Component\HttpKernel\Exception\HttpException;


class PosOrderController extends AdminController
{
    /**
     * [GOAL G1 2026-09-03] Plafonds de SÉCURITÉ du flux « journée de service » (voir serviceDay).
     * Ce ne sont pas des choix d'affichage : ils ne mordent pas sur un service réel — Le Cayenne
     * tourne à quelques centaines de commandes par jour, dont l'immense majorité déjà livrées.
     * S'ils mordent, `meta.truncated` le DIT et l'écran l'annonce.
     */
    private const CAP_ACTIFS = 300;

    private const CAP_LIVREES = 300;

    /** Heure de bascule de la journée de service — miroir du helper front (voir stale()). */
    private const HEURE_BASCULE_SERVICE = 5;

    /** Les états des quatre files du tiroir, hors livrées (traitées à part, tri inverse). */
    private const STATUTS_ACTIFS = [
        \App\Enums\OrderStatus::PENDING,
        \App\Enums\OrderStatus::ACCEPT,
        \App\Enums\OrderStatus::PREPARING,
        \App\Enums\OrderStatus::PREPARED,
        \App\Enums\OrderStatus::OUT_FOR_DELIVERY,
    ];

    /** Terminaux non livrés — hors tiroir, sur demande explicite du tableau de suivi. */
    private const STATUTS_TERMINAUX = [
        \App\Enums\OrderStatus::CANCELED,
        \App\Enums\OrderStatus::REJECTED,
        \App\Enums\OrderStatus::RETURNED,
    ];

    private OrderService $orderService;

    public function __construct(OrderService $order)
    {
        parent::__construct();
        $this->orderService = $order;
        $this->middleware(['permission:pos-orders'])->only(
            'destroy',
            'export',
            'changeStatus',
            'changePaymentStatus',
            'selectDeliveryBoy',
            'reorderItems', // [P2-3 FIX] Explicit permission guard for reorder
            'refundWithCounterEntry' // [P11-FZH] NF525 counter-entry refund
        );
        $this->middleware(['permission:pos-orders|pos'])->only('index', 'show');
    }

    /**
     * [P11-FZH / F-VERIFY-08-02] NF525 counter-entry refund.
     * Creates a mirror order in the current Z window for an order whose
     * created_at is contained in a closed Z report window. The parent
     * stays IMMUTABLE — the mirror carries the negated financial fields
     * + a fresh fiscal_sequence_no + parent_order_id link.
     */
    public function refundWithCounterEntry(
        Order $order,
        Request $request,
        \App\Services\Order\RefundWithCounterEntryService $service
    ): \Illuminate\Http\JsonResponse {
        // [HEAL-4 / PROPOSAL-02 — V101-02 2026-05-26] Permission gate.
        // Granted ONLY to Admin (Permission::all()) + Branch Manager (explicit).
        // POS Operator does NOT get this permission by default (mass-refund
        // vector mitigation per PROPOSAL_POS_REFUND_UI_2026-05-25 §8 risk #1).
        // Owner can grant manually via /admin/role/{id}/edit UI if needed.
        // Fail-fast BEFORE validation to surface the authz error cleanly.
        abort_unless(
            \Illuminate\Support\Facades\Auth::user()?->can('pos-refund') ?? false,
            403,
            'Insufficient permission to issue refund.'
        );

        $validated = $request->validate([
            'reason' => ['required', 'string', 'min:3', 'max:700'],
        ]);

        // Defense-in-depth — middleware already enforces, but cross-branch is fatal.
        $authUser = \Illuminate\Support\Facades\Auth::user();
        if ($authUser && !$authUser->hasRole('Admin')
            && (int) ($authUser->branch_id ?? 0) !== (int) $order->branch_id) {
            abort(403, 'Cross-branch refund denied.');
        }

        // ─────────────────────────────────────────────────────────────────────
        // [WI-REFUND-PREZ 2026-06-04] Single endpoint, server-side path-selection.
        //
        // The "sealed?" predicate is the SSOT — it lives server-side in
        // SealedOrderGuard, never duplicated on the client. We branch here so the
        // owner's "Rembourser" CTA works for BOTH cases through ONE route:
        //
        //   • Sealed (post-Z, parent inside a CLOSED Z window) → NF525 counter-
        //     entry mirror via RefundWithCounterEntryService (parent immutable).
        //     Response carries the mirror (mode='counter_entry').
        //
        //   • NOT sealed (pre-Z, parent still in the open Z) → the EXISTING,
        //     already-working pre-Z refund: OrderService::changeStatus(RETURNED)
        //     with the reason. This sets status=RETURNED, fires cashBack() (money
        //     returned to the drawer/customer via the order's `transaction`),
        //     refunds loyalty points, and appends an `order.returned` audit row.
        //     The parent is captured in the still-open Z (no fiscal gap, no
        //     mirror). Response carries mode='pre_z' + mirror=null.
        //
        // NOTE on payment_status: we deliberately do NOT flip the parent's
        // payment_status to REFUNDED. PaymentStateMachine defines
        // `PAID => []` (app/Domain/Order/PaymentStateMachine.php:17) — a PAID
        // order CANNOT transition to REFUNDED; changePaymentStatus() would throw
        // InvalidArgumentException(422). This matches the post-Z path, where the
        // parent also stays PAID and only the MIRROR carries REFUNDED. The
        // canonical "refunded" representation of a parent in this codebase is
        // status=RETURNED + cashBack + audit — which is exactly what the pre-Z
        // path produces. Widening the PaymentStateMachine is a fiscal-adjacent
        // owner-gated change, intentionally out of scope here.
        // ─────────────────────────────────────────────────────────────────────
        $isSealed = app(\App\Services\Order\SealedOrderGuard::class)->isSealed($order);

        if (!$isSealed) {
            try {
                return $this->refundPreZ($order, (string) $validated['reason']);
            } catch (HttpException $http) {
                // 403 cross-branch / role guards from OrderService::changeStatus
                // must reach the client intact (multi-tenant security signal).
                throw $http;
            } catch (\Illuminate\Validation\ValidationException $ve) {
                return response()->json([
                    'success' => false,
                    'message' => $ve->getMessage(),
                ], 422);
            } catch (\Throwable $t) {
                // OrderService::changeStatus throws Exception(.., 422) for an
                // invalid status transition; InvalidArgumentException(422) is
                // possible from downstream guards. Surface a clean 422 for those,
                // 500 for anything genuinely unexpected.
                $code = (int) $t->getCode();
                if ($code === 422) {
                    return response()->json([
                        'success' => false,
                        'message' => $t->getMessage(),
                    ], 422);
                }
                \Illuminate\Support\Facades\Log::error('refund-pre-z failed', [
                    'order_id' => $order->id,
                    'error'    => $t->getMessage(),
                ]);
                return response()->json([
                    'success' => false,
                    'message' => 'Failed to process pre-Z refund: ' . $t->getMessage(),
                ], 500);
            }
        }

        try {
            $mirror = $service->execute($order, (string) $validated['reason']);

            return response()->json([
                'success' => true,
                'mode'    => 'counter_entry',
                'data'    => new OrderDetailsResource($mirror->load('orderItems')),
                'meta'    => [
                    'parent_order_id'           => (int) $order->id,
                    'mirror_fiscal_sequence_no' => (int) $mirror->fiscal_sequence_no,
                ],
            ], 201);
        } catch (\InvalidArgumentException $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 422);
        } catch (HttpException $http) {
            throw $http;
        } catch (\Illuminate\Database\QueryException $qe) {
            // [HEAL-A.3-bis 2026-05-19 / Z8 P0-1] UNIQUE(parent_order_id) violation.
            // A mirror already exists for this parent — surface as a stable 409
            // with friendly code, NOT a generic 500. The DB-level UNIQUE (heal
            // A.3, migration 2026_05_19_200000) is the primary defense above
            // the dormant RefundWithCounterEntryService:73-78 status-flip guard.
            // Two distinct X-Idempotency-Key values that both pass the
            // idempotency middleware would otherwise produce a double mirror
            // (double Z negative against a single sale) before this catch.
            if (($qe->errorInfo[0] ?? null) === '23000') {
                return response()->json([
                    'success' => false,
                    'code'    => 'MIRROR_ALREADY_EXISTS',
                    'message' => 'Counter-entry already exists for this parent order.',
                ], 409);
            }
            \Illuminate\Support\Facades\Log::error('refund-with-counter-entry db error', [
                'order_id' => $order->id,
                'sqlstate' => $qe->errorInfo[0] ?? null,
                'error'    => $qe->getMessage(),
            ]);
            return response()->json([
                'success' => false,
                'message' => 'Database error during counter-entry refund.',
            ], 500);
        } catch (\Throwable $t) {
            \Illuminate\Support\Facades\Log::error('refund-with-counter-entry failed', [
                'order_id' => $order->id,
                'error'    => $t->getMessage(),
            ]);
            return response()->json([
                'success' => false,
                'message' => 'Failed to create counter-entry refund: ' . $t->getMessage(),
            ], 500);
        }
    }

    /**
     * [WI-REFUND-PREZ 2026-06-04] Pre-Z refund branch of refundWithCounterEntry.
     *
     * Reuses the EXISTING, already-working pre-Z refund path
     * (OrderService::changeStatus → OrderStatus::RETURNED) verbatim — we do NOT
     * reinvent or modify OrderService. The transition DELIVERED→RETURNED (and
     * other terminal→RETURNED edges) is legal in OrderStateMachine; changeStatus
     * validates the reason (required ≤700), runs cashBack() when the order has a
     * `transaction`, refunds loyalty points, and writes the `order.returned`
     * audit row — all guarded by SealedOrderGuard::assertMutable() which permits
     * pre-Z mutation (the inverse of assertSealed). No mirror is produced; the
     * negative is captured in the still-open Z (no fiscal gap).
     *
     * Returns the same envelope shape as the post-Z path so the single frontend
     * handler (PosRefundModal + onRefundCompleted) is tolerant of both modes:
     * `data` = refreshed parent, `meta.mirror_fiscal_sequence_no` = null.
     */
    private function refundPreZ(Order $order, string $reason): \Illuminate\Http\JsonResponse
    {
        // Build a synthetic OrderStatusRequest so OrderService::changeStatus
        // (type-hinted to OrderStatusRequest) resolves $request->status,
        // $request->reason and its inner $request->validate(). authorize() does
        // not run on a manually-created FormRequest — already gated above by
        // abort_unless(can('pos-refund')) + the cross-branch check.
        $statusRequest = OrderStatusRequest::create('/', 'POST', [
            'status' => \App\Enums\OrderStatus::RETURNED,
            'reason' => $reason,
        ]);
        $statusRequest->setContainer(app())->setRedirector(app('redirect'));
        $statusRequest->setUserResolver(fn () => \Illuminate\Support\Facades\Auth::user());

        $refunded = $this->orderService->changeStatus($order, $statusRequest);

        // changeStatus returns Order on success, or array on caught failure.
        if (is_array($refunded)) {
            return response()->json([
                'success' => false,
                'message' => $refunded['message'] ?? 'Pre-Z refund failed.',
            ], 422);
        }

        return response()->json([
            'success' => true,
            'mode'    => 'pre_z',
            'data'    => new OrderDetailsResource($refunded->fresh()?->load('orderItems') ?? $refunded),
            'meta'    => [
                'parent_order_id'           => (int) $order->id,
                'mirror_fiscal_sequence_no' => null,
            ],
        ], 200);
    }

    public function index(
        PaginateRequest $request
    ): \Illuminate\Http\Response|\Illuminate\Http\Resources\Json\AnonymousResourceCollection|\Illuminate\Contracts\Foundation\Application|\Illuminate\Contracts\Routing\ResponseFactory {
        abort_unless(auth()->user()?->can('pos-orders') || auth()->user()?->can('pos'), 403);
        try {
            $orders = $this->orderService->list($request);

            return SimpleOrderResource::collection($this->markSealed($orders));
        } catch (Exception $exception) {
            return response(['status' => false, 'message' => $exception->getMessage()], 422);
        }
    }

    /**
     * [BOUTON SCELLÉ 2026-08-19] Pose `is_sealed` sur chaque ligne avant sérialisation.
     *
     * Le tableau de suivi affichait « Annuler » sur des commandes enfermées dans un Z clos :
     * le clic partait, le serveur refusait (NF525, à raison), et le caissier restait devant un
     * bouton mort. Avec ce drapeau, la ligne propose « Rembourser » — la sortie légitime, qui
     * existe déjà (`refundWithCounterEntry`, contrepartie NF525).
     *
     * UNE seule requête pour tout le lot (SealedOrderGuard::sealedOrderIds) : le prédicat par
     * commande aurait coûté 100 requêtes sur un tableau rafraîchi toutes les 5 secondes.
     * Mesuré : le tableau passe à 9 requêtes / 31 ms au total, dont UNE pour ce marquage.
     * Fail-safe : si le calcul échoue, on rend la liste INTACTE sans drapeau — le tableau
     * s'affiche comme avant plutôt que de tomber.
     *
     * ⛔ NE JAMAIS appeler ceci avant un `->save()`. `is_sealed` n'est PAS une colonne : posée
     * par `setAttribute`, elle devient un attribut sale et Eloquent l'inclurait dans l'UPDATE
     * → « Unknown column 'is_sealed' ». C'est le même piège que `withCount` (`orders_count`
     * n'est pas une colonne non plus). Les deux seuls appelants (`index`, `stale`) lisent puis
     * sérialisent, sans jamais persister — c'est ce qui rend ce marquage sûr ici.
     *
     * @template T
     * @param  T  $orders
     * @return T
     */
    private function markSealed($orders)
    {
        try {
            $rows = $orders instanceof \Illuminate\Contracts\Pagination\Paginator
                || $orders instanceof \Illuminate\Contracts\Pagination\LengthAwarePaginator
                ? $orders->getCollection()
                : $orders;

            if (! $rows instanceof \Illuminate\Support\Collection && ! is_iterable($rows)) {
                return $orders;
            }

            $sealed = app(\App\Services\Order\SealedOrderGuard::class)->sealedOrderIds($rows);
            foreach ($rows as $row) {
                $row->setAttribute('is_sealed', isset($sealed[(int) $row->id]));
            }
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('[PosOrder] marquage scellé ignoré : '.$e->getMessage()); // allow: dégradation d'affichage seulement
        }

        return $orders;
    }

    /**
     * [GOAL G1 2026-09-03] La JOURNÉE DE SERVICE du tiroir de contrôle de la caisse.
     *
     * LE DÉFAUT RÉPARÉ. La caisse demandait `admin/pos-order?paginate=1&per_page=100` et
     * présentait cette page comme la journée entière. `OrderService::list` trie `id desc` par
     * défaut : au-delà de cent commandes, ce sont donc les PLUS ANCIENNES qui tombaient — celles
     * qui traînent, celles qu'il faut voir — et rien ne le signalait. Devenaient faux en silence :
     * les quatre files du tiroir, les deux pastilles de la barre, `activeOrdersStats`,
     * `readyOrders`, et le rang cuisine annoncé au client (« vous êtes le 4ᵉ », sous-estimé).
     * Il n'existait AUCUN plafond serveur : `PaginateRequest` ne borne qu'à `max:1000`. Le cent
     * était un choix purement client, invisible et non signalé.
     *
     * POURQUOI UN ENDPOINT SÉPARÉ plutôt que retirer `per_page`. Sans borne, une journée à 900
     * commandes fait un payload non borné sur l'écran qui doit rester instantané. Celui-ci ne
     * rend que ce que le tiroir AFFICHE : la journée de service, dans les états des quatre files.
     *
     * DEUX FAMILLES, UN SEUL PLAFOND CHACUNE, ET IL EST AVOUÉ.
     *  · ACTIFS (à encaisser / cuisine / prêtes / en livraison) — ce qui exige encore un geste.
     *    Borné par la réalité d'un service, jamais par un choix d'affichage. Tri PLUS ANCIENNE
     *    D'ABORD : si le plafond de sécurité mordait un jour, il tomberait sur les dernières
     *    arrivées, jamais sur les traînardes qui sont la raison d'être de cet écran.
     *  · LIVRÉES — l'archive du service. Tri PLUS RÉCENTE D'ABORD, exactement ce que dit la file
     *    correspondante (`resources/js/support/filesControle.js::fileLivrees`) : « on ouvre cette
     *    file pour vérifier ce qu'on VIENT de servir, pas pour relire le début du service ».
     *
     * `meta.total` est le total RÉEL, `meta.truncated` dit si une borne a mordu. Un compteur
     * silencieusement faux est pire qu'une borne assumée : c'est le défaut d'origine lui-même.
     *
     * Les statuts terminaux non livrés (annulée / rejetée / rendue) restent DEHORS : aucune des
     * quatre files ne les montre, et `meta.total` doit compter ce que l'écran affiche — sinon il
     * annonce des commandes introuvables. Le paramètre `avec_terminales=1` les rajoute pour le
     * tableau de suivi plein écran, dont le compteur « X aujourd'hui » les compte, lui.
     */
    public function serviceDay(\Illuminate\Http\Request $request): \Illuminate\Http\JsonResponse
    {
        abort_unless(auth()->user()?->can('pos-orders') || auth()->user()?->can('pos'), 403);

        try {
            [$debut, $fin] = $this->fenetreDuService();

            // Plafonds de SÉCURITÉ, jamais des choix d'affichage : ils ne mordent pas sur un
            // service réel. `plafond` ne peut que les ABAISSER (jamais les lever) — il sert au
            // banc à prouver que la troncature est avouée quand elle survient.
            $capActifs = self::CAP_ACTIFS;
            $capLivrees = self::CAP_LIVREES;
            if ($request->filled('plafond')) {
                $demande = max(1, (int) $request->input('plafond'));
                $capActifs = min($capActifs, $demande);
                $capLivrees = min($capLivrees, $demande);
            }

            $statutsActifs = self::STATUTS_ACTIFS;
            if ($request->boolean('avec_terminales')) {
                $statutsActifs = array_merge($statutsActifs, self::STATUTS_TERMINAUX);
            }

            $relations = ['transaction', 'orderItems.orderItem', 'user'];

            $fenetre = fn () => Order::query()
                ->where('order_datetime', '>=', $debut)
                ->where('order_datetime', '<', $fin);

            $totalActifs = $fenetre()->whereIn('status', $statutsActifs)->count();
            $actifs = $fenetre()
                ->whereIn('status', $statutsActifs)
                ->with($relations)
                ->orderBy('order_datetime')
                ->orderBy('id')
                ->limit($capActifs)
                ->get();

            $totalLivrees = $fenetre()->where('status', \App\Enums\OrderStatus::DELIVERED)->count();
            $livrees = $fenetre()
                ->where('status', \App\Enums\OrderStatus::DELIVERED)
                ->with($relations)
                ->orderByDesc('order_datetime')
                ->orderByDesc('id')
                ->limit($capLivrees)
                ->get();

            $lignes = $actifs->concat($livrees);
            $total = $totalActifs + $totalLivrees;

            return response()->json([
                'data' => SimpleOrderResource::collection($this->markSealed($lignes))->resolve(),
                'meta' => [
                    'total'     => $total,
                    'shown'     => $lignes->count(),
                    'truncated' => $total > $lignes->count(),
                    'from'      => $debut->toIso8601String(),
                    'to'        => $fin->toIso8601String(),
                    'actifs'    => [
                        'total'     => $totalActifs,
                        'shown'     => $actifs->count(),
                        'truncated' => $totalActifs > $actifs->count(),
                    ],
                    'livrees'   => [
                        'total'     => $totalLivrees,
                        'shown'     => $livrees->count(),
                        'truncated' => $totalLivrees > $livrees->count(),
                    ],
                ],
            ]);
        } catch (Exception $exception) {
            return response()->json(['status' => false, 'message' => $exception->getMessage()], 422);
        }
    }

    /**
     * Bornes de la journée de service, MIROIR EXACT du helper front
     * `resources/js/helpers/posServiceDay.js::serviceDayRange()` : jours civils complets, et tant
     * que l'heure de bascule (5 h) n'est pas franchie, la VEILLE reste affichée avec le jour
     * courant. Les deux doivent bouger ensemble — sinon une commande serait ni dans le tableau,
     * ni en souffrance. Le 5 h est un littéral VOLONTAIRE des deux côtés (voir `stale()`).
     *
     * @return array{0: \Carbon\Carbon, 1: \Carbon\Carbon} [début inclus, fin EXCLUE]
     */
    private function fenetreDuService(): array
    {
        $now = \Carbon\Carbon::now(config('app.timezone'));
        $debut = $now->copy()->startOfDay();
        if ($now->hour < self::HEURE_BASCULE_SERVICE) {
            $debut->subDay();
        }

        return [$debut, $now->copy()->startOfDay()->addDay()];
    }

    /**
     * [COMMANDES EN SOUFFRANCE 2026-08-19] Les non terminées ANTÉRIEURES à la journée de service.
     *
     * LE DÉFAUT RÉPARÉ. Depuis le passage du tableau en « journée de service » (fenêtre glissante
     * qui garde la veille jusqu'à 5 h), toute commande non terminée plus ancienne est devenue
     * INVISIBLE : plus moyen de la suivre, de la livrer, ni de l'annuler. Mesuré en base au
     * 2026-08-19 : 577 commandes non terminées antérieures, dont 486 PAYÉES, la plus ancienne du
     * 2026-05-28. Elles ne disparaissaient pas — on ne les voyait plus.
     *
     * Endpoint SÉPARÉ, jamais fondu dans les voies du tableau : 577 lignes noieraient les 2 vraies
     * commandes du service en cours. La caisse affiche un compteur, et n'ouvre la liste que si on
     * la demande.
     *
     * `count` est le total RÉEL (pas la taille de la page) : une troncature muette ferait croire
     * qu'on a tout vu.
     */
    public function stale(\Illuminate\Http\Request $request): \Illuminate\Http\JsonResponse
    {
        abort_unless(auth()->user()?->can('pos-orders') || auth()->user()?->can('pos'), 403);

        try {
            $appTz = config('app.timezone');
            // 5 h : littéral VOLONTAIRE, pas un réglage. Un clé de config ici serait un piège —
            // le helper front porte la sienne en dur (DEFAULT_SERVICE_DAY_START_HOUR = 5) et ne
            // la lirait pas : la déplacer côté serveur seul créerait une bande horaire où une
            // commande n'est NI dans le tableau NI en souffrance. Les deux valeurs sont épinglées
            // ensemble par tests/Feature/PosStaleOrdersTest.
            $startHour = 5;
            // Plancher = début de la journée de service en cours, MIROIR EXACT du helper front
            // resources/js/helpers/posServiceDay.js : avant l'heure de bascule, le service de la
            // veille court encore, donc le plancher recule d'un jour. Les deux doivent bouger
            // ensemble — sinon une commande serait « ni dans le tableau, ni en souffrance ».
            $now = \Carbon\Carbon::now($appTz);
            $floor = $now->copy()->startOfDay()->setTime($startHour, 0);
            if ($now->hour < $startHour) {
                $floor->subDay();
            }

            $unfinished = [
                \App\Enums\OrderStatus::PENDING,
                \App\Enums\OrderStatus::ACCEPT,
                \App\Enums\OrderStatus::PREPARING,
                \App\Enums\OrderStatus::PREPARED,
                \App\Enums\OrderStatus::OUT_FOR_DELIVERY,
            ];

            $base = Order::query()
                ->whereIn('status', $unfinished)
                ->where('order_datetime', '<', $floor);

            $total = (clone $base)->count();

            $perPage = (int) $request->input('per_page', 50);
            $perPage = max(1, min($perPage, 100));

            $rows = (clone $base)
                ->with(['transaction', 'user', 'orderItems.orderItem'])
                ->orderByDesc('order_datetime')
                ->limit($perPage)
                ->get();

            return response()->json([
                'data' => SimpleOrderResource::collection($this->markSealed($rows))->resolve(),
                'meta' => [
                    'count'      => $total,
                    'shown'      => $rows->count(),
                    'floor'      => $floor->toIso8601String(),
                    'per_page'   => $perPage,
                    // Vrai dès qu'on n'affiche pas tout : la caisse doit pouvoir le DIRE, une
                    // troncature silencieuse se lit comme « il n'y a que ça ».
                    'truncated'  => $total > $rows->count(),
                ],
            ]);
        } catch (Exception $exception) {
            return response()->json(['status' => false, 'message' => $exception->getMessage()], 422);
        }
    }

    public function show(
        int|string $order
    ): \Illuminate\Http\Response|OrderDetailsResource|\Illuminate\Contracts\Foundation\Application|\Illuminate\Contracts\Routing\ResponseFactory {
        try {
            // RED-team Wave 5I A.1 timing-leak fix 2026-05-18: catch ModelNotFoundException
            // and unify with 403 to prevent existence enumeration (foreign-branch order id
            // returns 403, non-existent id also 403 — single response shape, no info leak).
            // BranchScope + sentinel OrderShowBranchGuardSentinelTest:44 expect 403 baseline.
            try {
                $order = Order::withoutGlobalScope(BranchScope::class)->findOrFail($order);
            } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
                abort(403, 'Cross-branch access denied');
            }
            abort_unless(
                auth()->user()?->branch_id === 0 || $order->branch_id === auth()->user()?->branch_id,
                403,
                'Cross-branch access denied'
            );
            return new OrderDetailsResource($this->orderService->show($order, false));
        } catch (HttpException $http) {
            throw $http;
        } catch (Exception $exception) {
            return response(['status' => false, 'message' => $exception->getMessage()], 422);
        }
    }

    public function destroy(
        Order $order
    ): \Illuminate\Http\Response|\Illuminate\Contracts\Foundation\Application|\Illuminate\Contracts\Routing\ResponseFactory {
        try {
            $this->orderService->destroy($order);
            return response('', 202);
        } catch (\Symfony\Component\HttpKernel\Exception\HttpException $http) {
            // [POS-9.1.2] Do NOT mask 403/404 as 422: security-critical
            // HTTP status codes from abort() must reach the client intact.
            throw $http;
        } catch (Exception $exception) {
            return response(['status' => false, 'message' => $exception->getMessage()], 422);
        }
    }

    public function export(
        PaginateRequest $request
    ): \Illuminate\Http\Response|\Symfony\Component\HttpFoundation\BinaryFileResponse|\Illuminate\Contracts\Foundation\Application|\Illuminate\Contracts\Routing\ResponseFactory {
        try {
            return Excel::download(new OrderExport($this->orderService, $request), 'POS-Order.xlsx');
        } catch (Exception $exception) {
            return response(['status' => false, 'message' => $exception->getMessage()], 422);
        }
    }

    public function changeStatus(
        Order $order,
        OrderStatusRequest $request
    ): \Illuminate\Http\Response|OrderDetailsResource|\Illuminate\Contracts\Foundation\Application|\Illuminate\Contracts\Routing\ResponseFactory {
        // [REFUND-BYPASS-GUARD 2026-06-26 / P1] Twin-route authz parity.
        // RETURNED (22) IS the refund transition (fires PaymentService::cashBack
        // + LoyaltyService::refundPoints in OrderService::changeStatus). The
        // dedicated endpoint refundWithCounterEntry:58-62 gates it on
        // can('pos-refund') (Admin/Branch Manager only — mass-refund vector
        // mitigation, PROPOSAL_POS_REFUND_UI_2026-05-25 §8 risk #1). This sibling
        // route was gated only by `permission:pos-orders` (route group), which a
        // POS Operator HAS — re-opening the refund path. Mirror the dedicated
        // endpoint's gate EXACTLY, fail-fast BEFORE delegating. The
        // OrderStateMachine DELIVERED->RETURNED edge stays unconditional (frozen
        // + owner-locked LOCK_ORDERSTATEMACHINE_PREZ_REFUND); authorization lives
        // here at the controller layer, not in the state machine.
        // [F-CANCEL-REFUND-PARITY 2026-07-15 / P1] RETURNED n'était pas la seule
        // transition qui rembourse : OrderService::changeStatus sort AUSSI l'argent
        // du tiroir sur CANCELED (16) et REJECTED (19) d'une commande PAYÉE (cashBack
        // si Transaction, sinon recordCashRefundMovement pour une vente cash directe —
        // OrderService.php:2286-2320). Un POS Operator (pos-orders sans pos-refund)
        // pouvait donc drainer le tiroir du total en « annulant » une vente cash payée
        // = remboursement déguisé sans droit de rembourser. On étend le gate à
        // CANCELED/REJECTED UNIQUEMENT quand la commande est PAYÉE (annuler une commande
        // NON payée ne bouge aucun argent → geste opérationnel légitime, non gardé).
        // RETURNED garde son gate inconditionnel (parité historique REFUND-BYPASS-GUARD).
        $refundLikeStatus = (int) $request->status;
        $movesCashOnStatusChange = $refundLikeStatus === \App\Enums\OrderStatus::RETURNED
            || (in_array($refundLikeStatus, [\App\Enums\OrderStatus::CANCELED, \App\Enums\OrderStatus::REJECTED], true)
                && (int) $order->payment_status === \App\Enums\PaymentStatus::PAID);
        if ($movesCashOnStatusChange) {
            abort_unless(
                auth()->user()?->can('pos-refund') ?? false,
                403,
                'Permission insuffisante pour effectuer un remboursement.'
            );
        }

        try {
            return new OrderDetailsResource($this->orderService->changeStatus($order, $request));
        } catch (HttpException $http) {
            // Security-critical HTTP codes (e.g. 403 cross-branch from
            // OrderService::changeStatus) must reach the client intact — never
            // masked as a generic 422.
            throw $http;
        } catch (Exception $exception) {
            return response(['status' => false, 'message' => $exception->getMessage()], 422);
        }
    }

    public function changePaymentStatus(
        Order $order,
        PaymentStatusRequest $request
    ): \Illuminate\Http\Response|OrderDetailsResource|\Illuminate\Contracts\Foundation\Application|\Illuminate\Contracts\Routing\ResponseFactory {
        // [NUIT-A 2026-07-03 / P2 twin-route authz parity] REFUNDED est la transition de remboursement.
        // La route sœur change-status gate RETURNED sur `pos-refund` (PosOrderController::changeStatus:328,
        // Admin/Branch Manager only). Cette route n'était gardée que par `permission:pos-orders` (groupe),
        // qu'un POS Operator POSSÈDE → il pouvait marquer une commande REMBOURSÉE sans le droit de
        // remboursement (vente off-book / vecteur de remboursements de masse). On miroir EXACTEMENT le
        // gate de la sœur, fail-fast AVANT de déléguer (hors try → le 403 n'est pas masqué en 422).
        if ((int) $request->payment_status === \App\Enums\PaymentStatus::REFUNDED) {
            abort_unless(
                auth()->user()?->can('pos-refund') ?? false,
                403,
                'Permission insuffisante pour effectuer un remboursement.'
            );
        }

        try {
            return new OrderDetailsResource($this->orderService->changePaymentStatus($order, $request));
        } catch (Exception $exception) {
            return response(['status' => false, 'message' => $exception->getMessage()], 422);
        }
    }

    public function selectDeliveryBoy(Order $order, Request $request): \Illuminate\Http\Response|OrderDetailsResource|\Illuminate\Contracts\Foundation\Application|\Illuminate\Contracts\Routing\ResponseFactory
    {
        try {
            return new OrderDetailsResource($this->orderService->selectDeliveryBoy($order, $request));
        } catch (HttpException $http) {
            // [GOAL-2026-05-18 P0-LIV-01] OrderService::selectDeliveryBoy now
            // calls abort(403)/abort(422) for cross-branch + role guards. Let
            // the HttpException reach the client intact — masking it as a
            // generic 422 would defeat the multi-tenant security signal.
            throw $http;
        } catch (Exception $exception) {
            return response(['status' => false, 'message' => $exception->getMessage()], 422);
        }
    }

    // ─────────────────────────────────────────────────────────────────────────
    // GET /api/v1/admin/pos-order/{order}/reorder-items
    // Returns the structured cart payload of a past order for instant re-import
    // by the POS front-end (e.g. Vue/React cart state).
    // ─────────────────────────────────────────────────────────────────────────
    public function reorderItems(Order $order): \Illuminate\Http\JsonResponse|\Illuminate\Http\Response
    {
        try {
            $order->load(['orderItems.orderItem']);

            $cartItems = $order->orderItems->map(function ($orderItem) {
                return [
                    'item_id' => $orderItem->item_id,
                    'item_name' => $orderItem->orderItem?->name,
                    'item_image' => $orderItem->orderItem?->thumb,
                    'quantity' => $orderItem->quantity,
                    'unit_price' => $orderItem->price,
                    'total_price' => $orderItem->total_price,
                    'variations' => $this->reorderVariations($orderItem),
                    'extras' => $this->reorderExtras($orderItem),
                    'note' => $orderItem->note ?? '',
                ];
            });

            return response()->json([
                'status' => true,
                'order_id' => $order->id,
                'order_type' => $order->order_type,
                'note' => $order->note,
                'cart_items' => $cartItems,
            ]);
        } catch (Exception $exception) {
            return response(['status' => false, 'message' => $exception->getMessage()], 422);
        }
    }

    private function reorderVariations(\App\Models\OrderItem $orderItem): array
    {
        $snapshot = $this->decodedOrderItemArray($orderItem->composition_snapshot);
        $lines = isset($snapshot['lines']) && is_array($snapshot['lines']) ? $snapshot['lines'] : [];

        if ($lines !== []) {
            return collect($lines)
                ->map(fn($line) => [
                    'id' => $line['variation_id'] ?? $line['id'] ?? null,
                    'name' => $line['variation_name'] ?? $line['name'] ?? null,
                    'attribute_name' => $line['attribute_name'] ?? null,
                    'quantity' => max(1, (int) ($line['quantity'] ?? 1)),
                    'price' => (float) ($line['unit_price'] ?? $line['price'] ?? 0),
                ])
                ->filter(fn($line) => ! blank($line['id']))
                ->values()
                ->all();
        }

        return collect($this->decodedOrderItemArray($orderItem->item_variations))
            ->map(fn($line) => [
                'id' => $line['variation_id'] ?? $line['id'] ?? null,
                'name' => $line['variation_name'] ?? $line['name'] ?? null,
                'attribute_name' => $line['attribute_name'] ?? null,
                'quantity' => max(1, (int) ($line['quantity'] ?? 1)),
                'price' => (float) ($line['unit_price'] ?? $line['price'] ?? 0),
            ])
            ->filter(fn($line) => ! blank($line['id']))
            ->values()
            ->all();
    }

    private function reorderExtras(\App\Models\OrderItem $orderItem): array
    {
        $snapshot = $this->decodedOrderItemArray($orderItem->composition_snapshot);
        $extras = isset($snapshot['extras']) && is_array($snapshot['extras']) ? $snapshot['extras'] : [];

        if ($extras !== []) {
            return collect($extras)
                ->map(fn($extra) => [
                    'id' => $extra['extra_id'] ?? $extra['id'] ?? null,
                    'name' => $extra['extra_name'] ?? $extra['name'] ?? null,
                    'quantity' => max(1, (int) ($extra['quantity'] ?? 1)),
                    'price' => (float) ($extra['unit_price'] ?? $extra['price'] ?? 0),
                ])
                ->filter(fn($extra) => ! blank($extra['id']))
                ->values()
                ->all();
        }

        return collect($this->decodedOrderItemArray($orderItem->item_extras))
            ->map(fn($extra) => [
                'id' => $extra['extra_id'] ?? $extra['id'] ?? null,
                'name' => $extra['extra_name'] ?? $extra['name'] ?? null,
                'quantity' => max(1, (int) ($extra['quantity'] ?? 1)),
                'price' => (float) ($extra['unit_price'] ?? $extra['price'] ?? 0),
            ])
            ->filter(fn($extra) => ! blank($extra['id']))
            ->values()
            ->all();
    }

    private function decodedOrderItemArray(mixed $value): array
    {
        if (is_array($value)) {
            return $value;
        }

        if (! is_string($value) || trim($value) === '') {
            return [];
        }

        $decoded = json_decode($value, true);

        return is_array($decoded) ? $decoded : [];
    }
}

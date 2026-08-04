<?php

namespace App\Services;

use Exception;
use App\Enums\Ask;
use Carbon\Carbon;
use App\Models\Order;
use App\Models\OrderStatusTransition;
use App\Enums\OrderType;
use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Enums\PosPaymentMethod;
use App\Domain\Kds\KitchenReleaseRule;
use App\Events\KdsOrderRecalled;
use App\Events\SendOrderSms;
use Illuminate\Http\Request;
use App\Events\SendOrderMail;
use App\Events\SendOrderPush;
use App\Domain\Order\OrderStateMachine;
use App\Listeners\DispatchKdsTicket;
use Illuminate\Support\Facades\Log;
use App\Libraries\QueryExceptionLibrary;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;

class KitchenDisplaySystemOrderService
{
    public object $order;
    private bool $lastListOverflow = false;
    private DispatchKdsTicket $kdsTicketDispatcher;

    public function __construct(?DispatchKdsTicket $kdsTicketDispatcher = null)
    {
        $this->kdsTicketDispatcher = $kdsTicketDispatcher ?? app(DispatchKdsTicket::class);
    }

    protected array $orderFilter = [
        'order_serial_no',
        'branch_id',
        'order_type',
        'status',
        'source',
        'payment_method', // [GAP-29-3] Allow filtering by payment method (e.g. cash=1 for kiosk cash panel)
    ];

    protected array $exceptFilter = [
        'excepts'
    ];

    /**
     * @throws Exception
     */
    public function list(Request $request)
    {
        try {
            $requests = $request->all();
            $this->lastListOverflow = false;
            $allowedColumns = ['id', 'order_datetime', 'queue_number', 'order_serial_no', 'status', 'created_at'];
            $requestedColumn = (string) ($request->get('order_column') ?? 'id');
            $orderColumn = in_array($requestedColumn, $allowedColumns, true) ? $requestedColumn : 'id';
            $requestedType = strtolower((string) ($request->get('order_by') ?? 'asc'));
            $orderType = in_array($requestedType, ['asc', 'desc'], true) ? $requestedType : 'desc';

            $userBranchId = auth()->user()->branch_id ?? 0;

            // [Sprint 2A DEL-3 2026-05-16] Eager-load `address` + `user` so
            // KDSOrderDetailsResource can expose order_address + customer for
            // DELIVERY orders (chef + livreur). Order::user() is BranchScope-
            // exempt + withTrashed; Order::address() is hasOne with no scope.
            // No isolation risk — relations join via order_id only.
            // [TERRAIN-HEAL 2026-07-16 · PERF-KDS-N1] Eager-load `orderItems.orderItem` (le produit) en
            // plus d'`orderItems` : KDSOrderDetailsResource:59 faisait `loadMissing('orderItem')` PAR
            // commande → 1 requête/commande sur un board POLLÉ EN CONTINU (N+1 chaud). En le batchant ici,
            // les produits de toutes les lignes de toutes les commandes chargent en UNE requête ; le
            // loadMissing de la resource devient un no-op. OrderItemResource ne lit que id/name/thumb/
            // kds_station de orderItem (aucune sous-relation) → cet eager-load couvre tout.
            $query = Order::with(['orderItems.orderItem', 'address', 'user'])
                ->whereIn('status', KitchenReleaseRule::visibleStatuses());
            // SSOT board-release filter (PAID | PENDING_COUNTER | POS cash) —
            // shared with changeStatus()'s bump guard via KitchenReleaseRule so
            // "visible on the board" and "bumpable" stay identical.
            KitchenReleaseRule::applyBoardReleaseFilter($query);

            // [FIX BUG-KDS-SYNC] Admin users have branch_id=0 → show all branches.
            // Branch-specific staff see only their own branch.
            if ($userBranchId > 0) {
                $query->where('branch_id', $userBranchId);
            }

            // [FIX-FRONT-05] Pagination KDS: limiter à 50 commandes actives maximum
            // [AUDIT-P51-BUG1] Fix: include advance orders scheduled for today OR overdue from yesterday+
            // Previously only showed yesterday's advance orders, causing "zombie" orders to persist unseen.
            // [RED-team P1 perf 2026-05-17] whereDate non-sargable → range query (uses idx_orders_datetime)
            //
            // [Wave T R5 KDS Adversarial P0 2026-05-20 KDS-T-R5-01] CORRECTION
            // of Wave 3b heal (commit 148dbebce, sister of `KdsSyncService`).
            //
            // ROOT CAUSE: the Wave 3b heal asserted "MySQL session TZ defaults
            // to UTC" — empirically FALSE on this deployment. Running
            //   SELECT @@session.time_zone, NOW(), UTC_TIMESTAMP();
            // returns: time_zone='SYSTEM' (Paris local), NOW()=Paris-local,
            // UTC_TIMESTAMP()=NOW()-2h. The `mysql.timezone` config key is
            // NULL so PDO inherits the OS session TZ, which is Europe/Paris.
            //
            // CONSEQUENCE of the bug heal: bounds were converted to UTC PHP
            // strings (e.g. "2026-05-19 22:00:00") and bound as literals.
            // MySQL session_tz=SYSTEM/Paris interpreted those literals as
            // Paris-local datetimes, shifting the effective window backward
            // by 2h. Symptom: last ~2h of every Paris day (22h–minuit)
            // silently dropped from KDS list — empirically validated
            // pre-heal (1 row visible out of 11 DB rows for branch=1
            // status=7 at 23:51 Paris).
            //
            // CORRECT BEHAVIOR: use Paris-local Carbon bounds directly. The
            // bind strings ("2026-05-20 00:00:00" / "2026-05-20 23:59:59")
            // are interpreted by MySQL under session_tz=Paris, matching the
            // semantic intent "all of TODAY in Paris". Stored TIMESTAMP
            // values are likewise displayed/compared as Paris-local under
            // this session_tz. INVARIANT: this heal assumes session_tz is
            // OS local (Paris). If config/database.php gains
            // `connections.mysql.timezone => '+00:00'`, this query must be
            // re-evaluated (consider whereRaw CONVERT_TZ). Sentinel:
            // tests/Feature/Sentinels/KdsTodayWindowTzSentinelTest.php
            // pins the invariant via the full service roundtrip.
            $appTz = config('app.timezone');
            $todayStart = Carbon::today($appTz);
            $todayEnd = Carbon::today($appTz)->endOfDay();
            $tomorrowStart = Carbon::tomorrow($appTz);

            // [ULTRA MINUIT-STRADDLE 2026-07-04] La branche non-advance filtrait par jour CIVIL
            // ([todayStart, todayEnd]) : à 00h00, une commande de 23h30 encore ACCEPT/PREPARING
            // disparaissait du board alors qu'elle n'avait que 30 min — or Le Cayenne opère après
            // minuit (commandes réelles 23h-02h, DB-prouvé). Borne basse = fenêtre GLISSANTE
            // partagée OSS↔KDS (`oss.stale_window_hours`, défaut 8h — même clé que le prune du mur
            // client, pour que « visible cuisine » et « visible client » restent cohérents) ;
            // borne haute = < demain (anti-futur, préservée). La branche advance-overdue reste
            // INCHANGÉE (contrat AUDIT-52-BUG1 « show ALL overdue advance orders »). Même fenêtre
            // appliquée à KdsSyncService::sync + OSS list/listForBranch (parité 4 chemins).
            // Sentinels : OssKdsMidnightStraddleTest + KdsTodayWindowTzSentinelTest (inversé).
            $staleFloor = now($appTz)->subHours((int) config('oss.stale_window_hours', 8));

            // [F-02 AUDIT CUISINIER 2026-08-01 · P1] Plancher d'âge des commandes à l'avance /
            // programmées en retard : sans lui, elles restaient éternellement sur le board et
            // volaient les slots visibles aux vraies commandes du service (cf. branche orWhere
            // plus bas). 48 h par défaut = large pour un retard légitime (J-1 non retiré),
            // court pour un zombie. Réglable : oss.advance_stale_window_hours.
            $advanceFloor = now($appTz)->subHours((int) config('oss.advance_stale_window_hours', 48));

            // [GOAL ULTRA-SYNC W4 2026-07-20] Commandes programmées : le board ne
            // montre que l'ASAP (scheduled_at NULL — 100% de l'existant, inchangé)
            // et les programmées ENTRÉES dans leur fenêtre (scheduled_at <= now +
            // lead, défaut 20 min). SSOT KitchenReleaseRule — même prédicat que le
            // guard de bump changeStatus() (orderIsWithinScheduledWindow) et que
            // orderItems()/sync()/OSS (parité chemins). Hors fenêtre → bandeau
            // upcomingScheduled() (complément exact). now($appTz) = Paris-local,
            // même invariant session_tz que la fenêtre glissante ci-dessus.
            KitchenReleaseRule::applyScheduledBoardFilter($query, now($appTz));

            $query->where(function ($query) use ($staleFloor, $tomorrowStart, $advanceFloor) {
                // Standard orders: sliding active window (midnight-safe, non-advance)
                $query->where(function ($subQuery) use ($staleFloor, $tomorrowStart) {
                    $subQuery->where('order_datetime', '>=', $staleFloor)
                             ->where('order_datetime', '<', $tomorrowStart)
                             ->where('is_advance_order', Ask::NO);
                })
                // Advance orders: scheduled for today OR overdue from yesterday/past
                // [F-02 AUDIT CUISINIER 2026-08-01 · P1] …mais PAS indéfiniment. Cette branche
                // n'avait AUCUN plancher d'âge (contrairement aux commandes standard qui ont
                // $staleFloor) : une programmée jamais livrée restait sur le board POUR
                // TOUJOURS. Constaté : une commande de 9 jours (0 ligne, « ATTENTE 12389:38 »)
                // squattait la tuile n°1 des 3 slots visibles et poussait les vraies commandes
                // en « +N en attente » ; 15 zombies de 49 jours en base. Un cuisinier ne prépare
                // pas une commande vieille de plusieurs jours. Plancher = même fenêtre que les
                // standard, élargie aux retards plausibles (défaut 48 h). Rien n'est supprimé :
                // la commande reste en base, dans l'historique et en admin.
                ->orWhere(function ($subQuery) use ($tomorrowStart, $advanceFloor) {
                    $subQuery->where('is_advance_order', Ask::YES)
                             ->where('order_datetime', '>=', $advanceFloor)  // pas de zombie éternel
                             ->where('order_datetime', '<', $tomorrowStart) // Today or overdue past dates
                             ->whereNotIn('status', [OrderStatus::DELIVERED, OrderStatus::CANCELED]); // Not already completed
                })
                // [FIX SCHEDULED-STALE 2026-07-20] Programmées : échappent à la
                // fenêtre datetime legacy (miroir de l'échappement advance). Une
                // programmée CRÉÉE >8h avant sa cible (10:00 → 20:00, ou J-1..J-7)
                // avait order_datetime < staleFloor à T-lead ET is_advance_order=NO
                // → AND-composée avec le gate scheduled, elle était éjectée du board
                // au moment EXACT où le bandeau la lâchait (complément exact) —
                // jamais cuisinée. Son admission temporelle est DÉJÀ gérée par
                // applyScheduledBoardFilter (scheduled_at <= now+lead, AND top-level)
                // ; statut/release/branch gates inchangés. NULL = ASAP → strictement
                // identique. Sentinel : KdsScheduledOrderGateTest (10h→20h + J-1).
                ->orWhereNotNull('scheduled_at');
            })->where(function ($query) use ($requests) {
                foreach ($requests as $key => $request) {
                    if (in_array($key, $this->orderFilter)) {
                        if ($key === "status" && $request) {
                            $query->where($key, (int) $request);
                        } else if ($key === "payment_method" && $request !== null && $request !== '') {
                            $query->where($key, (int) $request);
                        } else if (in_array($key, ['branch_id', 'order_type', 'source'], true)) {
                            // [POS-9.1.5] LIKE → = on integer-ID columns to prevent
                            // cross-branch substring leakage. Using LIKE '%1%' on branch_id
                            // matched rows 1, 10, 11, 12, 21, 100… a real data leak.
                            if ($request !== null && $request !== '') {
                                $query->where($key, (int) $request);
                            }
                        } else {
                            $query->where($key, 'like', '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], (string) $request) . '%');
                        }
                    }

                    if (in_array($key, $this->exceptFilter)) {
                        // [#3] Guard array/non-string input (?excepts[]=x): explode() on a
                        // non-string raises TypeError, which catch(Exception) does NOT catch
                        // (TypeError extends Error) → uncaught 500. Skip empty tokens so a
                        // bare `excepts=` is a no-op rather than where('order_type','!=','').
                        if (is_string($request) && $request !== '') {
                            foreach (explode('|', $request) as $explode) {
                                if ($explode !== '') {
                                    $query->where('order_type', '!=', $explode);
                                }
                            }
                        }
                    }
                }
            })->orderBy($orderColumn, $orderType);

            // [PERF-KDS-BOARD REVERTÉ 2026-07-07] L'optim FORCE INDEX via
            // `->from(DB::raw('`orders` force index (...)'))` a été RETIRÉE : elle
            // entrait en collision avec BranchScope (frozen §7) qui qualifie la colonne
            // `branch_id` en `sprintf('%s.%s', $builder->getQuery()->from, 'branch_id')`
            // → `` `orders` force index (idx)`.branch_id `` = SQL invalide → le board
            // renvoyait 0 commande en HTTP (BranchScope actif) alors que la DB en a 42.
            // Les tests étaient verts car FORCE INDEX est gaté MySQL et la CI tourne
            // SQLite (green ≠ prod-safe). La correctness du board prime sur la perf.
            // BACKLOG : refaire l'optim SANS toucher l'identifiant FROM (restructurer
            // le prédicat de fenêtre pour qu'il soit sargable, l'optimiseur choisira
            // idx_orders_branch_status naturellement) + un tier CI MySQL réel (le
            // test SQLite ne peut PAS attraper un FROM-raw re-introduit gaté-MySQL).
            // Garde de régression : tests/Feature/KDS/KdsBoardQueryPlanTest.php
            // (test_board_runs_under_branchscope_and_returns_expected_set).

            $orders = $query->limit(51)->get();

            $this->lastListOverflow = $orders->count() > 50;

            return $orders->take(50)->values();
        } catch (Exception $exception) {
            Log::info($exception->getMessage());
            throw new Exception(QueryExceptionLibrary::message($exception), 422);
        }
    }

    public function lastListOverflow(): bool
    {
        return $this->lastListOverflow;
    }

    /**
     * [GOAL ULTRA-SYNC W4 2026-07-20] Bandeau « ⏰ programmées à venir ».
     *
     * Retourne les commandes PROGRAMMÉES encore HORS fenêtre cuisine
     * (scheduled_at > now + lead — applyScheduledUpcomingFilter, complément
     * EXACT du board filter appliqué à list()/orderItems()/sync() : une
     * commande est toujours dans exactement un des deux ensembles). Le chef
     * sait ce qui arrive sans que le board soit occupé des heures en avance.
     *
     * Mêmes gates que list() : release paiement (SSOT applyBoardReleaseFilter
     * — une programmée NON released n'existe pour la cuisine ni sur le board
     * ni dans le bandeau), statuts actifs visibleStatuses(), isolation branch
     * (admin branch_id=0 voit tout, staff sa branche). Payload minimal — le
     * bandeau n'a pas besoin des lignes d'items. Read-only, NF525 zéro impact.
     *
     * [FIX SCHEDULED-STALE P3 2026-07-20] + `scheduled_date` (Y-m-d Paris-local) :
     * le bandeau peut porter des cibles au-delà d'aujourd'hui (J+1..J+7) — la date
     * est calculée SERVEUR (même cast/TZ que scheduled_at) pour que le front
     * préfixe « sam. 26/07 » sans re-dériver la date depuis l'ISO (risque TZ).
     *
     * @return array<int, array{id: int, order_serial_no: mixed, scheduled_at: string|null, scheduled_date: string|null, order_type: int|null, customer_name: string|null}>
     *
     * @throws Exception
     */
    public function upcomingScheduled(): array
    {
        try {
            $userBranchId = auth()->user()->branch_id ?? 0;

            $appTz = config('app.timezone');

            $query = Order::query()
                ->select(['id', 'order_serial_no', 'scheduled_at', 'order_type', 'pos_customer_name', 'user_id', 'branch_id'])
                ->with('user')
                ->whereIn('status', KitchenReleaseRule::visibleStatuses());

            KitchenReleaseRule::applyBoardReleaseFilter($query);
            // now($appTz) = Paris-local — même invariant TZ que list() (Wave T R5).
            KitchenReleaseRule::applyScheduledUpcomingFilter($query, now($appTz));

            // [Mirror list() branch gates] Admin branch_id=0 voit toutes les branches.
            if ($userBranchId > 0) {
                $query->where('branch_id', $userBranchId);
            }

            return $query->orderBy('scheduled_at', 'asc')
                ->limit(20)
                ->get()
                ->map(static function (Order $order): array {
                    return [
                        'id'              => (int) $order->id,
                        'order_serial_no' => $order->order_serial_no,
                        'scheduled_at'    => $order->scheduled_at?->toIso8601String(),
                        // [FIX SCHEDULED-STALE P3] Y-m-d Paris-local (cast datetime,
                        // app TZ) — désambiguïsation multi-jours côté bandeau.
                        'scheduled_date'  => $order->scheduled_at?->format('Y-m-d'),
                        'order_type'      => $order->order_type === null ? null : (int) $order->order_type,
                        'customer_name'   => $order->pos_customer_name ?: $order->user?->name,
                    ];
                })
                ->values()
                ->all();
        } catch (Exception $exception) {
            Log::info($exception->getMessage());
            throw new Exception(QueryExceptionLibrary::message($exception), 422);
        }
    }

    /**
     * [Wave X3 2026-05-21] KDS "Historique du jour" — read-only V1.
     *
     * Returns today's PREPARED / OUT_FOR_DELIVERY / DELIVERED orders for the
     * caller's branch, sorted by status-change recency (`updated_at` desc),
     * capped at 50.
     *
     * Why `updated_at` and not `order_datetime` like list()/orderItems():
     * placement time is irrelevant for a "what was bumped today" view. The
     * last write on the row IS the status transition (validated in
     * `changeStatus()` above — `$locked->status = $newStatus; $locked->save()`
     * touches `updated_at` via Eloquent timestamps), so `updated_at` is the
     * closest proxy to "bump time" without adding a new `bumped_at` column.
     *
     * TZ discipline (Wave T R5 lesson, see list() L92-121): bounds are built
     * with Paris-local Carbon literals. We DO NOT setTimezone('UTC') because
     * MySQL session_tz=SYSTEM=Paris on this deployment, and the prior heal
     * already proved UTC-converted bindings silently drop the last ~2h of
     * every Paris day.
     *
     * Branch isolation: identical to list() — admin (branch_id=0) sees all
     * branches; branch staff (branch_id>0) sees only their own branch.
     *
     * Status list is HARD-CODED (not `KitchenReleaseRule::visibleStatuses()`)
     * because the history view shows POST-bump states (PREPARED+) while the
     * active board shows PRE/IN-progress (ACCEPT+PREPARING) plus PREPARED.
     * The two lists are intentionally distinct.
     *
     * NF525: read-only — chain untouched.
     *
     * @throws Exception
     */
    public function historyToday(): \Illuminate\Database\Eloquent\Collection
    {
        try {
            $userBranchId = auth()->user()->branch_id ?? 0;

            $appTz = config('app.timezone');
            $todayStart = Carbon::today($appTz);
            $tomorrowStart = Carbon::tomorrow($appTz);

            // [TERRAIN-HEAL 2026-07-16 · PERF-KDS-N1 twin] Même N+1 que list() : eager-load
            // orderItems.orderItem pour éviter le loadMissing par commande de la resource.
            $query = Order::with(['orderItems.orderItem', 'address', 'user'])
                ->whereIn('status', [
                    OrderStatus::PREPARED,
                    OrderStatus::OUT_FOR_DELIVERY,
                    OrderStatus::DELIVERED,
                ])
                ->whereBetween('updated_at', [$todayStart, $tomorrowStart]);

            // [Mirror list() L83-85] Admin branch_id=0 sees all; branch staff scoped.
            if ($userBranchId > 0) {
                $query->where('branch_id', $userBranchId);
            }

            return $query->orderByDesc('updated_at')
                ->limit(50)
                ->get();
        } catch (Exception $exception) {
            Log::info($exception->getMessage());
            throw new Exception(QueryExceptionLibrary::message($exception), 422);
        }
    }

    /**
     * [Heal-5 / PROPOSAL KDS Archive Undo 2026-05-25 — Path B compensating action]
     *
     * Chef recalls a PREPARED order within a 60-second grace window after the
     * bump. Mandate verbatim: « j'ai pu valider une commande par erreur avec
     * rapidité, je vais revenir pour la corriger ».
     *
     * INVARIANTS (NF525-safe, append-only):
     *  - `orders.status` is NEVER mutated (stays PREPARED). The state ledger
     *    is frozen-forward by OrderStateMachine §7.
     *  - One `order_status_transitions` row is appended with `from=PREPARED`,
     *    `to=PREPARED`, `reason='kitchen_recall'`. We DO NOT call
     *    `OrderStateMachine::recordTransition()` because it short-circuits
     *    when from==to (silent no-op — exact identity-transition guard at
     *    OrderStateMachine.php:140). We persist directly via the Eloquent
     *    model, which is the documented compensating-action pattern.
     *  - The OSS "Prêt" notification to the customer is NOT downgraded.
     *  - audit_logs HMAC chain is NOT touched (this is a business-events
     *    journal, not the fiscal chain).
     *
     * GUARDS (under lockForUpdate inside DB::transaction):
     *  - Status must be PREPARED (else 422).
     *  - `updated_at` must be within last 60 seconds (TTL — else 422).
     *  - Cap N=1 per order: if a prior `kitchen_recall` row exists for this
     *    order in the window, return 409 (PROPOSAL §7 R2).
     *  - Branch isolation: branch staff (branch_id > 0) may only recall
     *    orders of their own branch (else 403). Admin (branch_id=0) bypass.
     *
     * Returns the OrderStatusTransition row id + recalled_at timestamp so the
     * controller can build a 200 JSON envelope. Broadcasts KdsOrderRecalled
     * after commit (DispatchableAfterCommit) for KDS boards on other stations.
     *
     * @throws HttpException 422 (state, TTL), 403 (branch), 409 (already recalled)
     */
    public function recall(Order $order): array
    {
        $user = auth()->user();
        $actorId = $user ? (int) $user->getAuthIdentifier() : 0;
        $userBranchId = (int) ($user->branch_id ?? 0);
        $correlationId = request()?->header('X-Correlation-ID') ?? (string) Str::uuid();
        $windowSeconds = 60;

        $result = DB::transaction(function () use ($order, $actorId, $userBranchId, $correlationId, $windowSeconds) {
            /** @var Order $locked */
            $locked = Order::query()
                ->whereKey($order->id)
                ->lockForUpdate()
                ->firstOrFail();

            // Branch isolation under lock (defence-in-depth — BranchScope already
            // filters route binding for non-admin, but lock + recheck protects
            // the cross-branch race window).
            if ($userBranchId > 0 && (int) $locked->branch_id !== $userBranchId) {
                abort(403, 'Accès refusé : cette commande appartient à une autre succursale.');
            }

            // State guard — only PREPARED orders are recallable.
            if ((int) $locked->status !== OrderStatus::PREPARED) {
                abort(422, trans('all.message.kds_recall_invalid_state'));
            }

            // TTL guard — 60s window since the last write (updated_at proxy
            // for bump time; see PROPOSAL §A "Why updated_at").
            $bumpedAt = $locked->updated_at instanceof \DateTimeInterface
                ? Carbon::instance($locked->updated_at)
                : Carbon::parse((string) $locked->updated_at);

            $now = Carbon::now();
            if ($bumpedAt->lt($now->copy()->subSeconds($windowSeconds))) {
                abort(422, trans('all.message.kds_recall_window_expired'));
            }

            // Cap N=1 — refuse a second recall for the same order within the recall
            // window (PROPOSAL §7 R2). Lookup is cheap (indexed order_id).
            // [#13] Anchor the dedup to a STABLE sliding window (now - windowSeconds),
            // NOT to $bumpedAt: updated_at can be advanced by an unrelated write while
            // status stays PREPARED, which would push the window past the prior
            // kitchen_recall row and let a 2nd recall slip through. The sliding window
            // is immune to that and matches the documented "in the same window" intent.
            $alreadyRecalled = OrderStatusTransition::query()
                ->where('order_id', $locked->id)
                ->where('reason', 'kitchen_recall')
                ->where('occurred_at', '>=', $now->copy()->subSeconds($windowSeconds))
                ->exists();

            if ($alreadyRecalled) {
                abort(409, trans('all.message.kds_recall_already_recalled'));
            }

            // Append the compensating action row. DO NOT call
            // OrderStateMachine::recordTransition() — its from==to guard
            // (OrderStateMachine.php:140) would silently return without writing.
            $recallRow = OrderStatusTransition::query()->create([
                'order_id'       => (int) $locked->id,
                'order_type'     => Order::class,
                'from_status'    => OrderStatus::PREPARED,
                'to_status'      => OrderStatus::PREPARED,
                'actor_id'       => $actorId ?: null,
                'actor_type'     => $actorId ? 'user' : null,
                'reason'         => 'kitchen_recall',
                'correlation_id' => $correlationId,
                'occurred_at'    => $now,
            ]);

            // CRITICAL invariant assertion: orders.status MUST stay PREPARED.
            // The compensating-action contract is broken if anything mutates
            // this row inside the transaction. A defensive re-read here is
            // cheap and gives the sentinel test a hard pin.
            $statusAfter = (int) Order::query()->whereKey($locked->id)->value('status');
            if ($statusAfter !== OrderStatus::PREPARED) {
                throw new Exception('[KDS recall] Invariant broken: orders.status mutated during recall transaction.');
            }

            return [
                'transition_id' => (int) $recallRow->id,
                'order_id'      => (int) $locked->id,
                'branch_id'     => (int) $locked->branch_id,
                'queue_number'  => $locked->queue_number !== null ? (int) $locked->queue_number : null,
                'recalled_at'   => $now->toIso8601String(),
            ];
        });

        // Broadcast after commit so KDS boards on other stations re-inject
        // the card with a RAPPELÉ badge. DispatchableAfterCommit drops the
        // event entirely on rollback (gate C9 — KI-001).
        KdsOrderRecalled::dispatch(
            $result['order_id'],
            $result['branch_id'],
            $result['queue_number'],
            $actorId,
            $result['recalled_at'],
            $correlationId
        );

        return $result;
    }

    /**
     * @throws Exception
     */
    public function changeStatus(Order $order, Request $request)
    {
        try {
            $newStatus = (int) $request->input('status');
            $expectedFrom = (int) $request->input('expected_status');

            $result = DB::transaction(function () use ($order, $newStatus, $expectedFrom) {
                $locked = Order::query()
                    ->whereKey($order->id)
                    ->lockForUpdate()
                    ->firstOrFail();

                $userBranchId = (int) (auth()->user()->branch_id ?? 0);
                if ($userBranchId > 0 && (int) $locked->branch_id !== $userBranchId) {
                    abort(403, 'Accès refusé : cette commande appartient à une autre succursale.');
                }

                $fromLocked = (int) $locked->status;

                if ($fromLocked !== $expectedFrom) {
                    try {
                        Log::channel('stack')->warning('[KDS_409]', [
                            'op'                => 'kds.change_status',
                            'order_id'          => $locked->id ?? null,
                            'branch_id'         => $locked->branch_id ?? null,
                            'current_status'    => $locked->status ?? null,
                            'attempted_status'  => $newStatus,
                            'user_id'           => auth()->id(),
                            'reason'            => 'optimistic_lock_conflict',
                        ]);
                    } catch (\Throwable $logEx) { /* never break the abort flow */ }
                    abort(409, 'Order status was updated elsewhere — please refresh the KDS.');
                }

                if ($fromLocked === $newStatus) {
                    return ['model' => $locked->fresh(), 'from' => $fromLocked, 'changed' => false];
                }

                if (
                    ! KitchenReleaseRule::canTransition($fromLocked, $newStatus)
                    || ! OrderStateMachine::allows($fromLocked, $newStatus, auth()->user())
                ) {
                    throw new Exception(trans('all.message.invalid_status_transition'), 422);
                }

                // [P1 release-guard] The transition checks above validate the
                // state-machine move (ACCEPT→PREPARING→PREPARED), but NOT whether
                // the order is released onto the kitchen board. list() only
                // surfaces released orders (KitchenReleaseRule::applyBoardReleaseFilter),
                // so an UNPAID delivery / UNPAID non-cash POS order is invisible
                // to the chef yet was still bumpable by a direct change-status
                // call — firing SendOrderMail/Sms/Push (customer "being prepared"
                // notifications) before payment. Mirror list()'s contract here so
                // "visible == bumpable". PENDING_COUNTER stays bumpable (Plan B
                // kiosk→counter encashment: chef prepares while customer pays).
                if (! KitchenReleaseRule::orderIsReleasedForBoard($locked)) {
                    throw new Exception(trans('all.message.invalid_status_transition'), 422);
                }

                // [GOAL ULTRA-SYNC W4 2026-07-20] Jumeau SCHEDULED du guard
                // board-release ci-dessus — même discipline « visible == bumpable »
                // (SSOT KitchenReleaseRule) : une commande PROGRAMMÉE hors fenêtre
                // (scheduled_at > now + lead) est invisible sur le board via
                // applyScheduledBoardFilter appliqué à list() ; un bump direct
                // (appel API) doit être refusé pareil, sinon les notifications
                // client « en préparation » partiraient des heures avant l'heure
                // prévue. NULL = ASAP → toujours bumpable (existant intact).
                if (! KitchenReleaseRule::orderIsWithinScheduledWindow($locked)) {
                    throw new Exception(sprintf(
                        'Commande programmée — hors fenêtre cuisine (visible %d min avant l\'heure prévue).',
                        KitchenReleaseRule::scheduledLeadMinutes()
                    ), 422);
                }

                $locked->status = $newStatus;
                // [KITCHEN-TIMING 2026-07-04] Horodatage cuisine CENTRALISÉ dans le hook saving du modèle
                // Order (couvre tous les chemins, y compris auto-prepare) → plus de stamp explicite ici.
                $locked->save();

                OrderStateMachine::recordTransition(
                    Order::class,
                    (int) $locked->id,
                    $fromLocked,
                    $newStatus,
                    auth()->check() ? (int) auth()->id() : null,
                    null
                );

                return ['model' => $locked->fresh(), 'from' => $fromLocked, 'changed' => true];
            });

            $snapshot = $result['model'];
            $oldStatus = $result['from'];

            if (! ($result['changed'] ?? false)) {
                return;
            }

            // [#1] The DB transaction has already COMMITTED. A failure in any of the
            // post-commit notification dispatches (sync listener throw, queue driver
            // down) must NOT bubble to the outer catch(Exception) below — that would
            // re-wrap a SUCCESSFUL bump as HTTP 422 and make the chef retry into a 409.
            try {
                SendOrderMail::dispatch(['order_id' => $snapshot->id, 'status' => $newStatus]);
                SendOrderSms::dispatch(['order_id' => $snapshot->id, 'status' => $newStatus]);
                SendOrderPush::dispatch(['order_id' => $snapshot->id, 'status' => $newStatus]);
            } catch (\Throwable $e) {
                Log::warning('[KDS] Post-commit notification dispatch failed: ' . $e->getMessage());
            }

            try {
                $this->kdsTicketDispatcher->dispatch($snapshot, $oldStatus, $newStatus);
            } catch (\Throwable $e) {
                // [#1 RED] \Throwable, not \Exception: this dispatch is post-commit
                // too. An \Error here (e.g. TypeError on a malformed snapshot) would
                // escape \Exception AND the outer catch → uncaught 500 on a committed
                // bump — the exact class the notification block above closes.
                Log::warning('[KDS] OrderStatusChanged broadcast failed: ' . $e->getMessage());
            }
        } catch (HttpException $e) {
            throw $e;
        } catch (Exception $exception) {
            Log::info($exception->getMessage());
            throw new Exception(QueryExceptionLibrary::message($exception), 422);
        }
    }

    /**
     * @throws Exception
     */
    public function orderItems()
    {
        try {
            $userBranchId = auth()->user()->branch_id ?? 0;

            // [P3-2 FIX] Include ACCEPT orders so new POS orders appear on items board immediately
            // without waiting for chef to click "Start Preparing"
            $query = Order::with('orderItems')
                ->whereIn('status', KitchenReleaseRule::itemBoardStatuses());

            // [KDS-RELEASE-TWIN 2026-06-27] Même prédicat de release paiement que
            // list() (l.78) : sans ça, une commande ACCEPT mais UNPAID/non-libérée
            // (ex. delivery / POS non-cash impayée) faisait fuiter ses items vers
            // le board « à préparer » alors qu'elle est correctement absente de
            // list(). Classe du P1 déjà corrigé côté changeStatus (897d2cfff).
            KitchenReleaseRule::applyBoardReleaseFilter($query);

            // Admin bypass: branch_id=0 sees all branches
            if ($userBranchId > 0) {
                $query->where('branch_id', $userBranchId);
            }

            // [FIX-53-2] Mirror the same fix applied to list() in Phase 51:
            // orderItems() was still using Carbon::yesterday() for advance orders,
            // causing overdue orders to vanish from the items board after 24h.
            // [RED-team P1 perf 2026-05-17] whereDate non-sargable → range query (uses idx_orders_datetime)
            //
            // [Wave T R5 KDS Adversarial P0 2026-05-20 KDS-T-R5-05] CORRECTION
            // of Wave 3b heal applied to the items-board query. See list()
            // above for full rationale: empirical session_tz=Paris-local
            // invalidates the prior UTC-conversion approach which silently
            // dropped the last ~2h of every Paris day from the chef's items
            // view. Sentinel: tests/Feature/Services/SisterServicesTzAwareTest.php.
            $appTz = config('app.timezone');
            $todayStart = Carbon::today($appTz);
            $todayEnd = Carbon::today($appTz)->endOfDay();
            $tomorrowStart = Carbon::tomorrow($appTz);

            // [ULTRA MINUIT-STRADDLE 2026-07-04] Miroir de list() : fenêtre GLISSANTE 8h au lieu
            // du jour civil, sinon une commande à cheval sur minuit serait visible en CARTE mais
            // absente de l'AGRÉGAT items (« combien à préparer ») — incohérence cross-chemin.
            $staleFloor = now($appTz)->subHours((int) config('oss.stale_window_hours', 8));

            // [GOAL ULTRA-SYNC W4 2026-07-20] Miroir scheduled de list() : une
            // programmée hors fenêtre (scheduled_at > now + lead) est absente des
            // CARTES → ses items ne doivent pas gonfler l'AGRÉGAT « à préparer »
            // (même classe d'incohérence cross-chemin que le minuit-straddle et le
            // release-twin ci-dessus). NULL = ASAP inchangé. SSOT KitchenReleaseRule.
            KitchenReleaseRule::applyScheduledBoardFilter($query, now($appTz));

            $orders = $query->where(function ($query) use ($staleFloor, $tomorrowStart) {
                $query->where(function ($subQuery) use ($staleFloor, $tomorrowStart) {
                    $subQuery->where('order_datetime', '>=', $staleFloor)
                             ->where('order_datetime', '<', $tomorrowStart)
                             ->where('is_advance_order', Ask::NO);
                })->orWhere(function ($subQuery) use ($tomorrowStart) {
                    // [SYNC-P1 2026-08-05 · parité jumelles] Plancher d'âge = MÊME que list() (F-02) :
                    // sans lui, les items d'un zombie >48h gonflaient l'AGRÉGAT « à préparer » alors que
                    // sa carte avait quitté le board (incohérence cross-chemin). now(TZ) = même invariant.
                    $subQuery->where('is_advance_order', Ask::YES)
                             ->where('order_datetime', '>=', now(config('app.timezone'))->subHours((int) config('oss.advance_stale_window_hours', 48)))
                             ->where('order_datetime', '<', $tomorrowStart)
                             ->whereNotIn('status', [OrderStatus::DELIVERED, OrderStatus::CANCELED]);
                })
                // [FIX SCHEDULED-STALE 2026-07-20] Miroir de list() : une programmée
                // créée >8h avant sa cible échappe à la fenêtre datetime legacy —
                // sinon ses items manquaient à l'AGRÉGAT « à préparer » à T-lead
                // (admission temporelle = applyScheduledBoardFilter ci-dessus).
                // NULL = ASAP strictement inchangé. Sentinel : KdsScheduledOrderGateTest.
                ->orWhereNotNull('scheduled_at');
            })->get();

            $allItems = $orders->pluck('orderItems')->flatten();
            $mergedItems = $allItems->groupBy(function ($item) {
                // [CYCLE2-HEAL 2026-07-16 · KDS-MERGE-ORDER] Normaliser par CONTENU trié par id (pas
                // sortKeys() qui triait les CLÉS 0,1,2 = no-op) → deux lignes à composition IDENTIQUE
                // mais options en ORDRE différent produisaient un hash différent = non fusionnées au
                // board « à préparer » (comptage cuisine faux). Aligné sur normalizeAddonsForHash.
                $variations = empty($item['item_variations']) ? '[]' : collect($item['item_variations'])->sortBy('id')->values()->toJson();
                $extras = empty($item['item_extras']) ? '[]' : collect($item['item_extras'])->sortBy('id')->values()->toJson();
                $addons = json_encode($this->normalizeAddonsForHash(data_get($item, 'composition_snapshot.addons', [])));
                // [L2 FIX] Normalize instruction: trim whitespace and lowercase to avoid
                // spurious KDS splits caused by minor formatting differences
                $instruction = mb_strtolower(trim($item['instruction'] ?? ''));
                // [Lot 2.I / G-5] split lines whose allergens snapshots differ — food safety.
                // Two order_items sharing item_id+variations+extras+instruction MUST appear
                // as 2 distinct KDS lines if their allergens_snapshot differ. Otherwise the
                // chef sees "Burger x2" with allergens of the FIRST item only — masking the
                // second customer's allergy declaration.
                $allergensHash = sha1(json_encode($this->normalizeAllergensForHash($item['allergens_snapshot'] ?? [])));

                return json_encode([
                    'item_id' => $item['item_id'],
                    'item_variations' => $variations,
                    'item_extras' => $extras,
                    'item_addons' => $addons,
                    'instruction' => $instruction,
                    'allergens_hash' => $allergensHash,
                ]);
            })->map(function ($groupedItems) {
                $firstItem = $groupedItems->first();
                // [B-2 FIX] Always sum quantities — items with same instruction are already grouped separately
                $firstItem['quantity'] = $groupedItems->sum('quantity');
                return $firstItem;
            })->values();
            return $mergedItems;
        } catch (Exception $exception) {
            Log::info($exception->getMessage());
            throw new Exception(QueryExceptionLibrary::message($exception), 422);
        }
    }

    /**
     * [Lot 2.I / G-5] Deterministic allergen hash input.
     *
     * Defensive against legacy data shapes (null, JSON object, scalar string)
     * that may exist on rows pre-dating the 2026_04_18_140004 backfill. Empty
     * snapshot, null, and non-array values all collapse to the same hash so
     * items WITHOUT declared allergens still merge together (regression safe).
     *
     * @param  mixed  $snapshot
     * @return array<int, string>
     */
    private function normalizeAllergensForHash($snapshot): array
    {
        if (! is_array($snapshot)) {
            return [];
        }

        $normalized = array_values(array_unique(array_map(
            'strval',
            array_filter($snapshot, static fn ($value) => $value !== null && $value !== '')
        )));

        sort($normalized);

        return $normalized;
    }

    /**
     * Keep KDS merged item rows split when composer addons differ.
     *
     * @param  mixed  $addons
     * @return array<int, array<string, mixed>>
     */
    private function normalizeAddonsForHash($addons): array
    {
        if (! is_array($addons)) {
            return [];
        }

        return collect($addons)
            ->filter(fn ($addon): bool => is_array($addon))
            ->map(fn (array $addon): array => [
                'addon_id' => (int) ($addon['addon_id'] ?? 0),
                'addon_item_id' => (int) ($addon['addon_item_id'] ?? 0),
                'role' => (string) ($addon['role'] ?? ''),
                'quantity' => (int) ($addon['quantity'] ?? 1),
            ])
            ->sortBy([
                ['role', 'asc'],
                ['addon_id', 'asc'],
                ['addon_item_id', 'asc'],
                ['quantity', 'asc'],
            ])
            ->values()
            ->all();
    }
}

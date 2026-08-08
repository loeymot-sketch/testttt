<?php

namespace App\Services;



use Exception;
use Carbon\Carbon;
use App\Models\Coupon;
use App\Enums\DiscountType;
use App\Enums\OrderStatus;
use App\Models\OrderCoupon;
use App\Libraries\AppLibrary;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use App\Http\Requests\CouponRequest;
use App\Http\Requests\PaginateRequest;
use App\Libraries\QueryExceptionLibrary;
use App\Http\Requests\CouponCheckRequest;

class CouponService
{
    public $coupon;
    protected array $allowedOrderColumns = [
        'id',
        'name',
        'code',
        'discount',
        'discount_type',
        'start_date',
        'end_date',
        'minimum_order',
        'maximum_discount',
        'limit_per_user',
        'created_at',
    ];
    protected $couponFilter = [
        'name',
        'code',
        'discount',
        'discount_type',
        'start_date',
        'end_date',
        'minimum_order',
        'maximum_discount',
        'limit_per_user',
        // [PROMO-DASH-2026-05-06] Filtres avancés Dashboard
        'status',
    ];

    protected $exceptFilter = [
        'excepts'
    ];

    /**
     * @throws Exception
     */
    public function list(PaginateRequest $request)
    {
        try {
            $requests    = $request->all();
            $method      = $request->get('paginate', 0) == 1 ? 'paginate' : 'get';
            $methodValue = $request->get('paginate', 0) == 1 ? $request->get('per_page', 10) : '*';
            $orderColumn = $this->sanitizeOrderColumn((string) ($request->get('order_column') ?? 'id'));
            $orderType   = $this->sanitizeOrderDirection((string) ($request->get('order_by') ?? $request->get('order_type') ?? 'desc'));

            // [PROMO-DASH-2026-05-06] Support filtre par surface (canal) — JSON.
            $surfaceFilter = $request->get('surface');

            return Coupon::where(function ($query) use ($requests, $surfaceFilter) {
                foreach ($requests as $key => $request) {
                    if (in_array($key, $this->couponFilter)) {
                        if ($key == "start_date") {
                            $start_date  = Date('Y-m-d', strtotime($request));
                            $query->whereDate($key, '=', $start_date);
                        } else if ($key == "end_date") {
                            $end_date  = Date('Y-m-d', strtotime($request));
                            $query->whereDate($key, '=', $end_date);
                        } else if ($key == "status" || $key == "discount_type") {
                            // exact match for integer enums
                            if ($request !== null && $request !== '') {
                                $query->where($key, '=', (int) $request);
                            }
                        } else {
                            $query->where($key, 'like', '%' . $this->escapeLike((string) $request) . '%');
                        }
                    }

                    if (in_array($key, $this->exceptFilter)) {
                        $explodes = explode('|', $request);
                        if (is_array($explodes)) {
                            foreach ($explodes as $explode) {
                                $query->where('id', '!=', $explode);
                            }
                        }
                    }
                }

                // Surface scope filter — JSON containment, portable enough.
                if (is_string($surfaceFilter) && $surfaceFilter !== '') {
                    $needle = '"' . trim($surfaceFilter) . '"';
                    $query->where(function ($q) use ($needle) {
                        $q->whereNull('surfaces')
                          ->orWhere('surfaces', 'like', '%' . $needle . '%');
                    });
                }
            })->orderBy($orderColumn, $orderType)->$method(
                $methodValue
            );
        } catch (Exception $exception) {
            Log::info($exception->getMessage());
            throw new Exception(QueryExceptionLibrary::message($exception), 422);
        }
    }

    private function sanitizeOrderColumn(string $requestedColumn): string
    {
        return in_array($requestedColumn, $this->allowedOrderColumns, true) ? $requestedColumn : 'id';
    }

    private function sanitizeOrderDirection(string $requestedDirection): string
    {
        $requestedDirection = strtolower($requestedDirection);

        return in_array($requestedDirection, ['asc', 'desc'], true) ? $requestedDirection : 'desc';
    }

    private function escapeLike(string $value): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $value);
    }

    /**
     * @throws Exception
     */
    public function store(CouponRequest $request)
    {
        try {
            $this->coupon = Coupon::create($this->buildPayload($request, true));
            if ($request->image) {
                $this->coupon->addMedia($request->image)->toMediaCollection('coupon');
            }
            return $this->coupon;
        } catch (Exception $exception) {
            Log::info($exception->getMessage());
            throw new Exception(QueryExceptionLibrary::message($exception), 422);
        }
    }

    /**
     * [PROMO-DASH-2026-05-06] Toggle status ACTIVE <-> INACTIVE.
     * Pas de validation FormRequest — appelé directement par le controller
     * `toggleStatus`. Idempotent.
     */
    public function toggleStatus(Coupon $coupon): Coupon
    {
        $current = (int) ($coupon->status ?? \App\Enums\Status::ACTIVE);
        $coupon->status = $current === \App\Enums\Status::ACTIVE
            ? \App\Enums\Status::INACTIVE
            : \App\Enums\Status::ACTIVE;
        $coupon->save();
        return $coupon->refresh();
    }

    /**
     * Construit le payload (création + update) en supportant les champs avancés.
     */
    private function buildPayload(CouponRequest $request, bool $isCreate): array
    {
        $payload = [
            'name'             => $request->name,
            'description'      => $request->description,
            'code'             => $request->code,
            'discount'         => $request->discount,
            'discount_type'    => $request->discount_type,
            'start_date'       => !blank($request->start_date) ? date(
                'Y-m-d H:i:s',
                strtotime($request->start_date)
            ) : null,
            // [F-COUPON-ENDDATE-INCLUSIVE 2026-07-15 / P3] Une date de fin saisie sans heure
            // (« valable jusqu'au 31/12 ») était stockée à 00:00:00 → la comparaison stricte
            // `$now->gt(end_date)` faisait expirer le coupon dès 00:00:01 le 31/12, excluant tout
            // le dernier jour. On borne à la fin de journée (endOfDay) pour rendre le jour final
            // inclusif, cohérent avec l'affichage gestion.
            'end_date'         => !blank($request->end_date)
                ? Carbon::parse($request->end_date)->endOfDay()->format('Y-m-d H:i:s')
                : null,
            'minimum_order'    => $request->minimum_order,
            'maximum_discount' => $request->maximum_discount,
            'limit_per_user'   => $request->limit_per_user,
        ];

        // [PROMO-DASH-2026-05-06] Advanced scoping fields. On utilise has() pour
        // ne pas écraser de valeurs existantes en update partiel.
        if ($request->has('valid_days_of_week')) {
            $payload['valid_days_of_week'] = $this->normalizeArrayField($request->input('valid_days_of_week'));
        } elseif ($isCreate) {
            $payload['valid_days_of_week'] = null;
        }

        if ($request->has('valid_hours_start')) {
            $val = $request->input('valid_hours_start');
            $payload['valid_hours_start'] = !blank($val) ? (strlen((string) $val) === 5 ? $val . ':00' : $val) : null;
        } elseif ($isCreate) {
            $payload['valid_hours_start'] = null;
        }

        if ($request->has('valid_hours_end')) {
            $val = $request->input('valid_hours_end');
            $payload['valid_hours_end'] = !blank($val) ? (strlen((string) $val) === 5 ? $val . ':00' : $val) : null;
        } elseif ($isCreate) {
            $payload['valid_hours_end'] = null;
        }

        if ($request->has('branch_scope')) {
            $arr = $this->normalizeArrayField($request->input('branch_scope'));
            $payload['branch_scope'] = is_array($arr)
                ? array_values(array_map('intval', $arr))
                : null;
        } elseif ($isCreate) {
            $payload['branch_scope'] = null;
        }

        if ($request->has('surfaces')) {
            $arr = $this->normalizeArrayField($request->input('surfaces'));
            $payload['surfaces'] = is_array($arr)
                ? array_values(array_map(fn ($s) => strtolower((string) $s), $arr))
                : null;
        } elseif ($isCreate) {
            $payload['surfaces'] = null;
        }

        if ($request->has('max_uses_global')) {
            $val = $request->input('max_uses_global');
            $payload['max_uses_global'] = !blank($val) ? (int) $val : null;
        } elseif ($isCreate) {
            $payload['max_uses_global'] = null;
        }

        if ($isCreate) {
            $payload['usage_count'] = 0;
        }

        if ($request->has('status')) {
            $val = $request->input('status');
            $payload['status'] = !blank($val) ? (int) $val : \App\Enums\Status::ACTIVE;
        } elseif ($isCreate) {
            $payload['status'] = \App\Enums\Status::ACTIVE;
        }

        return $payload;
    }

    /**
     * Convertit une valeur arbitraire (array, string CSV, JSON) en array PHP.
     */
    private function normalizeArrayField($value): ?array
    {
        if (is_array($value)) {
            return $value;
        }
        if ($value === null || $value === '' || $value === 'null') {
            return null;
        }
        if (is_string($value)) {
            $trim = trim($value);
            if ($trim === '') {
                return null;
            }
            // JSON ?
            if (($trim[0] ?? '') === '[' || ($trim[0] ?? '') === '{') {
                $decoded = json_decode($trim, true);
                if (is_array($decoded)) {
                    return $decoded;
                }
            }
            // CSV
            return array_filter(array_map('trim', explode(',', $trim)), fn ($v) => $v !== '');
        }
        return null;
    }

    /**
     * @throws Exception
     */
    public function update(CouponRequest $request, Coupon $coupon)
    {
        try {
            DB::transaction(function () use ($request, $coupon) {
                $this->coupon = $coupon;
                $payload = $this->buildPayload($request, false);
                $coupon->fill($payload);
                $coupon->save();
                if ($request->image) {
                    $coupon->media()->delete();
                    $coupon->addMedia($request->image)->toMediaCollection('coupon');
                }
            });
            return $this->coupon;
        } catch (Exception $exception) {
            Log::info($exception->getMessage());
            throw new Exception(QueryExceptionLibrary::message($exception), 422);
        }
    }

    /**
     * @throws Exception
     */
    public function destroy(Coupon $coupon)
    {
        try {
            $coupon->delete();
        } catch (Exception $exception) {
            Log::info($exception->getMessage());
            throw new Exception(QueryExceptionLibrary::message($exception), 422);
        }
    }

    /**
     * @throws Exception
     */
    public function show(Coupon $coupon): Coupon
    {
        try {
            return $coupon;
        } catch (Exception $exception) {
            Log::info($exception->getMessage());
            throw new Exception(QueryExceptionLibrary::message($exception), 422);
        }
    }

    /**
     * @throws Exception
     */
    public function couponDateWise(): \Illuminate\Database\Eloquent\Collection
    {
        try {
            // [TERRAIN-HEAL 2026-07-16 · COUPON-PUBLIC-STATUS] Réutilise le scope canonique Coupon::active()
            // (status=ACTIVE + fenêtre de validité null-safe) au lieu d'un Coupon::all()->filter() qui
            // ignorait `status` → un coupon DÉSACTIVÉ dans la gestion restait listé publiquement (le toggle
            // admin n'avait aucun effet sur la vitrine ; UX trompeuse, coupon montré puis refusé au checkout
            // par validateCouponForOrder→isUsableNow). Bonus : requête DB au lieu de charger toute la table.
            // [P0 SÉCURITÉ 2026-08-08] Les coupons NOMINATIFS à usage unique (tickets promo,
            // {@see PromoFlyerService}) n'ont RIEN à faire dans une vitrine publique : leur code
            // est destiné à UNE personne, remis par son canal. Mesuré en production : l'appel
            // anonyme les renvoyait avec le prénom de la cliente, si bien qu'un inconnu pouvait
            // brûler son code avant elle (`max_uses_global = 1`, premier arrivé premier servi).
            // Signature d'un code nominatif : plafond global à 1. On l'exclut donc de la vitrine.
            // Sa VALIDATION reste entière (`coupon-checking` + `validateCouponForOrder`) : la
            // cliente saisit son code et l'obtient normalement — seule la LISTE se referme.
            return Coupon::active()
                ->where(function ($q) {
                    $q->whereNull('max_uses_global')->orWhere('max_uses_global', '<>', 1);
                })
                ->get();
        } catch (Exception $exception) {
            Log::info($exception->getMessage());
            throw new Exception(QueryExceptionLibrary::message($exception), 422);
        }
    }

    /**
     * @throws Exception
     */
    public function couponChecking(CouponCheckRequest $request)
    {
        try {
            // [CV6 P0 fix — wire isUsableNow] Read branch_id + surface from request
            // to enforce the new advanced promo scopes (status, days, hours, branch_scope, surfaces)
            // on the HTTP path (cf. INTEGRATION_AUDIT_GLOBAL_2026-05-06.md §8 risque P0).
            $branchId = $request->filled('branch_id') ? (int) $request->input('branch_id') : null;
            $surface = $request->filled('surface') ? (string) $request->input('surface') : null;

            return $this->resolveCouponByCode(
                (string) $request->code,
                (float) $request->total,
                (int) auth()->id(),
                $branchId,
                $surface
            );
        } catch (Exception $exception) {
            Log::info($exception->getMessage());
            throw new Exception(QueryExceptionLibrary::message($exception), 422);
        }
    }

    /**
     * Resolve and validate a coupon selected by ID for an order flow.
     *
     * @throws Exception
     */
    public function resolveCouponById(int $couponId, float $subtotal, int $userId, ?int $branchId = null, ?string $surface = null): Coupon
    {
        $coupon = Coupon::find($couponId);
        return $this->validateCouponForOrder($coupon, $subtotal, $userId, $branchId, $surface);
    }

    /**
     * Resolve and validate a coupon selected by code for public/frontend checks.
     *
     * @throws Exception
     */
    public function resolveCouponByCode(string $code, float $subtotal, int $userId, ?int $branchId = null, ?string $surface = null): Coupon
    {
        $coupon = Coupon::where(['code' => trim($code)])->first();
        return $this->validateCouponForOrder($coupon, $subtotal, $userId, $branchId, $surface);
    }

    /**
     * Calculate the monetary discount for a validated coupon.
     */
    public function calculateDiscountAmount(Coupon $coupon, float $subtotal): float
    {
        $amount = $coupon->discount_type == DiscountType::PERCENTAGE
            ? ($subtotal * (float) $coupon->discount) / 100
            : (float) $coupon->discount;

        $maximumDiscount = (float) ($coupon->maximum_discount ?? 0);
        if ($maximumDiscount > 0 && $amount > $maximumDiscount) {
            $amount = $maximumDiscount;
        }

        return round(max(0, min($amount, $subtotal)), 2);
    }

    /**
     * Shared validation rules used by frontend checks and order creation.
     *
     * @throws Exception
     */
    private function validateCouponForOrder(?Coupon $coupon, float $subtotal, int $userId, ?int $branchId = null, ?string $surface = null): Coupon
    {
        if (!$coupon) {
            throw new Exception(trans('all.message.coupon_not_exist'), 422);
        }

        if ((float) $coupon->minimum_order > $subtotal) {
            throw new Exception(
                trans('all.message.minimum_order_amount') . AppLibrary::currencyAmountFormat($coupon->minimum_order),
                422
            );
        }

        $now = Carbon::now();
        if ($coupon->start_date && $now->lt(Carbon::parse($coupon->start_date))) {
            throw new Exception(trans('all.message.coupon_not_yet_active'), 422);
        }
        if ($coupon->end_date && $now->gt(Carbon::parse($coupon->end_date))) {
            throw new Exception(trans('all.message.coupon_date_expired'), 422);
        }

        // [P1-D SÉCU 2026-08-04] Une commande ANNULÉE (paiement carte échoué/abandonné →
        // auto-cancel webhook) NE DOIT PAS consommer le quota : la ligne order_coupons est
        // posée à la création, avant paiement. Sans ce filtre, une tentative abandonnée brûlait
        // le coupon 1-usage (client bloqué au 422) et une campagne plafonnée pouvait être épuisée
        // par N paiements abandonnés. On ne compte QUE les commandes non-terminales-annulées.
        $liveOrderCoupon = static function ($q) {
            $q->whereHas('order', function ($o) {
                $o->whereNotIn('status', [OrderStatus::CANCELED, OrderStatus::REJECTED, OrderStatus::RETURNED]);
            });
        };

        $limitPerUser = (int) ($coupon->limit_per_user ?? 0);
        if ($limitPerUser > 0) {
            $orderedCouponCount = OrderCoupon::where([
                'user_id' => $userId,
                'coupon_id' => $coupon->id,
            ])->where($liveOrderCoupon)->count();

            if ($orderedCouponCount >= $limitPerUser) {
                throw new Exception(trans('all.message.coupon_limit_exceeded'), 422);
            }
        }

        // [COUPON-CAP-01 heal 2026-06-01] Enforce max_uses_global. The model's isUsableNow()
        // checked it against `usage_count`, but usage_count is never incremented (dead column),
        // so the global cap never tripped. Count actual redemptions from order_coupons — same
        // source-of-truth as limit_per_user above (single-box V1: same non-atomic semantics).
        $maxUsesGlobal = (int) ($coupon->max_uses_global ?? 0);
        if ($maxUsesGlobal > 0) {
            // [FLYER PROMO 2026-08-07] Sérialisation des consommations du MÊME
            // coupon. Le commentaire ci-dessus assumait des « semantics non
            // atomiques » — acceptable pour une campagne plafonnée à 500, plus
            // du tout pour un code NOMINATIF À USAGE UNIQUE distribué sur
            // ticket : deux commandes simultanées comptaient toutes deux 0
            // utilisation et passaient toutes deux, offrant deux fois la remise.
            //
            // On verrouille la ligne du coupon avant de compter : les
            // redemptions concurrentes du même code s'exécutent alors l'une
            // après l'autre, et la seconde voit bien la première. Le verrou ne
            // porte QUE sur ce coupon — deux clients avec deux codes différents
            // ne se bloquent jamais.
            //
            // `transactionLevel() > 0` : la validation est appelée aussi HORS
            // transaction (pré-contrôle du site, qui ne consomme rien). Poser un
            // verrou là serait inutile et immédiatement relâché.
            // [SENTINELLE Z6-P1-WGS 2026-08-07] `withoutGlobalScopes()` (pluriel) faisait
            // rougir l'audit des scopes. Mesuré : `Coupon` ne porte QUE `SoftDeletingScope`
            // (aucun `BranchScope`) — le remède « préféré » que propose la sentinelle
            // (`withoutGlobalScope(BranchScope::class)`) serait donc FAUX ici, il ne
            // retirerait rien. La forme exacte et explicite est `withTrashed()` : elle dit
            // ce qu'on veut vraiment, verrouiller la ligne même si le coupon a été
            // soft-deleted entre-temps (le verrou sert à SÉRIALISER, pas à valider — la
            // validation a déjà eu lieu plus haut).
            if (DB::transactionLevel() > 0) {
                Coupon::withTrashed()
                    ->whereKey($coupon->id)
                    ->lockForUpdate()
                    ->first();
            }

            $globalUsed = OrderCoupon::where('coupon_id', $coupon->id)->where($liveOrderCoupon)->count();
            if ($globalUsed >= $maxUsesGlobal) {
                throw new Exception(trans('all.message.coupon_limit_exceeded'), 422);
            }
        }

        // [CV6 P0 fix — wire isUsableNow]
        // The model exposes a richer scoping check (status, day-of-week, hour-of-day,
        // branch_scope, surfaces, max_uses_global) — this private path-validator
        // historically ignored it, leaving the new fields unenforced at the HTTP layer
        // (cf. INTEGRATION_AUDIT_GLOBAL_2026-05-06.md §8 risque P0).
        // We call it here so /coupon-checking, /apply-coupon, etc. pick up the constraints.
        // When $branchId / $surface are null (caller didn't provide), the model treats
        // them as "no branch/surface filter" — backward compatible with legacy callers.
        if (!$coupon->isUsableNow($branchId, $surface, $now)) {
            // Use a generic message to avoid leaking which scope failed.
            throw new Exception(trans('all.message.coupon_not_applicable_now'), 422);
        }

        return $coupon;
    }
}
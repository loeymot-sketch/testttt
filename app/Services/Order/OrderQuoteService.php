<?php

namespace App\Services\Order;

use App\Enums\Status;
use App\Models\KioskMachine;
use App\Models\OrderQuote;
use App\Models\User;
use App\Services\CouponService;
use App\Services\Pricing\DiscountCalculator;
use App\Services\Pricing\PricingLineResult;
use App\Services\Pricing\PricingRequest;
use App\Services\Pricing\PricingResult;
use App\Services\Pricing\PricingService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Smartisan\Settings\Facades\Settings;
use Symfony\Component\HttpKernel\Exception\HttpException;

class OrderQuoteService
{
    /**
     * [P0-FIX iter12 2026-05-09] Default TTL bumped 60s → 300s.
     *
     * Bug visible on POS payment screen "Order quote expired" : cashier
     * spends >60s entering split-payment tranches (multi-paiement modal)
     * and quote silently expires before confirm — UX broken.
     *
     * Override via config('quote.ttl_seconds', 300) for per-env tuning
     * (e.g. dev=60 to keep regression tests fast, prod=300+).
     *
     * Aligned with cashier OBSERVATIONAL median input time (~90s for
     * 2-tranche split, ~3min for 4-tranche split with change calculation).
     */
    private const TTL_SECONDS_DEFAULT = 300;
    private const SURFACE_POS = 'pos';
    private const SURFACE_KIOSK = 'kiosk';

    private function ttlSeconds(): int
    {
        $value = (int) config('quote.ttl_seconds', self::TTL_SECONDS_DEFAULT);

        return $value > 0 ? $value : self::TTL_SECONDS_DEFAULT;
    }

    public function __construct(
        private readonly PricingService $pricingService,
        private readonly CouponService $couponService,
        private readonly DiscountCalculator $discountCalculator,
    ) {
    }

    public function quote(Request $request, string $surface, ?int $consumeOrderId = null): OrderQuote
    {
        return DB::transaction(fn (): OrderQuote => $this->quoteInsideTransaction($request, $surface, $consumeOrderId));
    }

    private function quoteInsideTransaction(Request $request, string $surface, ?int $consumeOrderId = null): OrderQuote
    {
        $surface = $this->normalizeSurface($surface);
        $actor = $this->resolveActor($request);
        $branchId = $this->resolveBranchId($request, $surface, $actor);
        $items = $this->safeJsonDecode((string) $request->input('items', '[]'));
        $items = is_array($items) ? $items : [];

        $pricing = $this->calculatePricing($request, $surface, $branchId, $items, $actor);
        $this->assertManualDiscountAllowed($request, $surface, $pricing, $actor);

        $canonicalPayload = $this->canonicalPayload($request, $surface, $branchId, $actor, $items, $pricing);
        $canonicalJson = $this->canonicalJson($canonicalPayload);
        $intentHash = hash('sha256', $canonicalJson);
        $signature = hash_hmac('sha256', $canonicalJson, $this->hmacKey());

        $token = (string) $request->input('quote_token', '');
        $quote = $token !== ''
            ? $this->resolveReplay($token, $branchId, $intentHash, $signature, $request)
            : $this->findOpenQuote($surface, $branchId, (int) $actor->id, $intentHash);

        if (! $quote) {
            $quote = OrderQuote::create([
                'quote_token' => (string) Str::uuid(),
                'branch_id' => $branchId,
                'actor_id' => (int) $actor->id,
                'customer_id' => $this->customerId($request, $surface),
                'surface' => $surface,
                'intent_hash' => $intentHash,
                'hmac_signature' => $signature,
                'canonical_payload' => $canonicalPayload,
                'subtotal' => $pricing->subtotal,
                'discount' => $pricing->discount,
                'total_tax' => $pricing->totalTax,
                'delivery_charge' => $pricing->deliveryCharge,
                'total_ttc' => $pricing->total,
                'currency' => $canonicalPayload['currency'],
                'expires_at' => now()->addSeconds($this->ttlSeconds()),
            ]);
        }

        if ($request->boolean('consume') || $consumeOrderId !== null) {
            $this->consume($quote, $actor, $consumeOrderId ?? ($request->integer('order_id') ?: null));
        }

        return $quote->refresh();
    }

    public function sealForCommit(Request $request, string $surface, int $orderId, float $expectedTotal): OrderQuote
    {
        $surface = $this->normalizeSurface($surface);
        $hasClientQuote = $request->filled('quote_token') || $request->filled('quote_signature');
        if ((in_array($surface, [self::SURFACE_POS, self::SURFACE_KIOSK], true) || $hasClientQuote)
            && (! $request->filled('quote_token') || ! $request->filled('quote_signature'))) {
            throw new HttpException(401, 'Order quote token and signature are required together.');
        }

        $quote = $this->quote($request, $surface, $orderId);

        if (abs($this->money($quote->total_ttc) - $this->money($expectedTotal)) > 0.000001) {
            throw new HttpException(409, 'Order total does not match sealed quote total.');
        }

        return $quote->refresh();
    }

    /**
     * @return array<string, mixed>
     */
    public function response(OrderQuote $quote): array
    {
        return [
            'quote_token' => $quote->quote_token,
            'signature' => $quote->hmac_signature,
            'expires_at' => optional($quote->expires_at)->toIso8601String(),
            'ttl_seconds' => max(0, now()->diffInSeconds($quote->expires_at, false)),
            'subtotal' => (float) $quote->subtotal,
            'discount' => (float) $quote->discount,
            'total_tax' => (float) $quote->total_tax,
            'delivery_charge' => (float) $quote->delivery_charge,
            'total_ttc' => (float) $quote->total_ttc,
            'currency' => $quote->currency,
            'consumed_at' => optional($quote->consumed_at)->toIso8601String(),
        ];
    }

    private function normalizeSurface(string $surface): string
    {
        $surface = strtolower(trim($surface));

        return $surface === self::SURFACE_KIOSK ? self::SURFACE_KIOSK : self::SURFACE_POS;
    }

    private function resolveActor(Request $request): User
    {
        $actor = $request->user('sanctum') ?? Auth::user();
        if (! $actor instanceof User) {
            throw new HttpException(401, 'Unauthenticated');
        }

        return $actor;
    }

    private function resolveBranchId(Request $request, string $surface, User $actor): int
    {
        if ($surface === self::SURFACE_KIOSK) {
            $token = $actor->currentAccessToken();
            if ($token !== null && ! $actor->tokenCan('kiosk:order')) {
                throw new HttpException(403, 'Kiosk quote requires kiosk order ability.');
            }

            $kiosk = KioskMachine::query()
                ->where('user_id', $actor->id)
                ->first();

            if (! $kiosk) {
                throw new HttpException(403, 'Kiosk quote requires a registered kiosk machine.');
            }

            if ((int) $kiosk->status !== (int) Status::ACTIVE) {
                throw new HttpException(403, 'Kiosk quote requires an active kiosk machine.');
            }

            return (int) $kiosk->branch_id;
        }

        if (! $actor->can('pos')) {
            throw new HttpException(403, 'POS permission required.');
        }

        $branchId = (int) $request->input('branch_id', 0);
        if ($branchId <= 0) {
            throw ValidationException::withMessages(['branch_id' => 'A valid branch_id is required for a POS quote.']);
        }

        if (! $this->isGlobalAdmin($actor) && (int) ($actor->branch_id ?? 0) !== $branchId) {
            throw new HttpException(403, 'Quote branch mismatch.');
        }

        return $branchId;
    }

    /**
     * @param  array<int, object>  $items
     */
    private function calculatePricing(Request $request, string $surface, int $branchId, array $items, User $actor): PricingResult
    {
        if ($surface === self::SURFACE_KIOSK) {
            $pricing = $this->pricingService->calculateOrder(
                PricingRequest::forKiosk(
                    0,
                    $branchId,
                    $items,
                    (int) $request->input('coupon_id', 0),
                    (int) $actor->id,
                    (float) $request->input('delivery_charge', 0)
                ),
                $this->couponService
            );

            return $this->withKioskLoyaltyDiscount($request, $pricing);
        }

        return $this->pricingService->calculateOrder(
            PricingRequest::forPos(
                0,
                $branchId,
                $items,
                (int) $request->input('coupon_id', 0),
                (int) $request->input('customer_id', 0),
                (float) $request->input('discount', 0),
                (float) $request->input('delivery_charge', 0)
            ),
            $this->couponService
        );
    }

    private function withKioskLoyaltyDiscount(Request $request, PricingResult $pricing): PricingResult
    {
        $loyaltyCode = trim((string) $request->input('loyalty_code', ''));
        $requestedDiscount = (float) $request->input('discount', 0);

        if ((int) $request->input('coupon_id', 0) > 0 || $loyaltyCode === '' || $requestedDiscount <= 0.0) {
            return $pricing;
        }

        $loyaltyUser = User::query()
            ->where('loyalty_code', $loyaltyCode)
            ->where('status', 1)
            ->first();

        if (! $loyaltyUser) {
            return $pricing;
        }

        $redemption = $this->discountCalculator->kioskLoyaltyRedemption(
            null,
            $loyaltyCode,
            $requestedDiscount,
            $pricing->accumulatedSubtotal,
            $loyaltyUser
        );
        $discount = (float) $redemption['discount'];
        $pointsRequired = (int) $redemption['points'];

        if ($discount <= 0.0 || $pointsRequired <= 0) {
            return $pricing;
        }

        $total = round(max(
            0.0,
            $pricing->accumulatedSubtotal + $pricing->totalTax + $pricing->deliveryCharge - $discount
        ), 2);

        return new PricingResult(
            $pricing->orderItemInsertRows,
            $pricing->lines,
            $pricing->accumulatedSubtotal,
            $pricing->subtotal,
            $pricing->totalTax,
            $discount,
            $pricing->deliveryCharge,
            $total,
            $pricing->meta + ['loyalty_points_required' => $pointsRequired],
        );
    }

    private function assertManualDiscountAllowed(Request $request, string $surface, PricingResult $pricing, User $actor): void
    {
        if ($surface !== self::SURFACE_POS || (int) $request->input('coupon_id', 0) > 0) {
            return;
        }

        $discount = (float) $request->input('discount', 0);
        if ($discount <= 0.0) {
            return;
        }

        if ($pricing->subtotal <= 0.0 || $discount > $pricing->subtotal) {
            throw ValidationException::withMessages([
                'discount' => 'Cannot apply discount without a valid backend subtotal.',
            ]);
        }

        $pct = ($discount / $pricing->subtotal) * 100.0;

        if ($pct > 50.0 && ! $actor->can('pos-discount-unlimited')) {
            throw ValidationException::withMessages([
                'discount' => 'Only an owner can apply a discount above 50%.',
            ]);
        }

        if ($pct > 10.0
            && ! $actor->can('pos-discount-over-10-requires-manager')
            && ! $actor->can('pos-discount-unlimited')) {
            throw ValidationException::withMessages([
                'discount' => 'Discount above 10% requires manager approval.',
            ]);
        }

        if (! $actor->can('pos-discount-up-to-10')
            && ! $actor->can('pos-discount-over-10-requires-manager')
            && ! $actor->can('pos-discount-unlimited')) {
            throw ValidationException::withMessages([
                'discount' => 'You do not have permission to apply POS discounts.',
            ]);
        }
    }

    private function resolveReplay(string $token, int $branchId, string $intentHash, string $signature, Request $request): OrderQuote
    {
        $quote = OrderQuote::query()
            ->where('quote_token', $token)
            ->lockForUpdate()
            ->first();

        if (! $quote || (int) $quote->branch_id !== $branchId) {
            throw new HttpException(401, 'Invalid order quote.');
        }

        if ($quote->isExpired()) {
            throw new HttpException(410, 'Order quote expired.');
        }

        $requestSignature = (string) $request->input('quote_signature', '');
        if ($requestSignature === '' || ! hash_equals($quote->hmac_signature, $requestSignature)) {
            throw new HttpException(401, 'Order quote signature mismatch.');
        }

        if (! hash_equals($quote->intent_hash, $intentHash) || ! hash_equals($quote->hmac_signature, $signature)) {
            throw new HttpException(401, 'Order quote intent mismatch.');
        }

        return $quote;
    }

    private function findOpenQuote(string $surface, int $branchId, int $actorId, string $intentHash): ?OrderQuote
    {
        return OrderQuote::query()
            ->where('branch_id', $branchId)
            ->where('surface', $surface)
            ->where('actor_id', $actorId)
            ->where('intent_hash', $intentHash)
            ->whereNull('consumed_at')
            ->where('expires_at', '>', now())
            ->lockForUpdate()
            ->latest('id')
            ->first();
    }

    private function consume(OrderQuote $quote, User $actor, ?int $orderId): void
    {
        if ($quote->consumed_at !== null) {
            if ($orderId !== null && (int) $quote->consumed_order_id !== $orderId) {
                throw new HttpException(409, 'Order quote has already been consumed.');
            }

            return;
        }

        $quote->forceFill([
            'consumed_at' => now(),
            'consumed_by_user_id' => (int) $actor->id,
            'consumed_order_id' => $orderId,
        ])->save();
    }

    /**
     * @param  array<int, object>  $items
     * @return array<string, mixed>
     */
    private function canonicalPayload(Request $request, string $surface, int $branchId, User $actor, array $items, PricingResult $pricing): array
    {
        return [
            'version' => 1,
            'surface' => $surface,
            'branch_id' => $branchId,
            'actor' => [
                'id' => (int) $actor->id,
                'branch_id' => (int) ($actor->branch_id ?? 0),
                'roles' => $this->roleNames($actor),
            ],
            'order' => [
                'customer_id' => $this->customerId($request, $surface),
                'order_type' => (int) $request->input('order_type', 0),
                'source' => (int) $request->input('source', 0),
                'payment_method' => (int) $request->input($surface === self::SURFACE_POS ? 'pos_payment_method' : 'payment_method', 0),
            ],
            'items' => $this->normalizeForCanonical($items),
            'modifiers' => $this->canonicalModifiers($items),
            'discounts' => [
                'coupon_id' => (int) $request->input('coupon_id', 0),
                'manual_discount' => $surface === self::SURFACE_POS ? $this->money($request->input('discount', 0)) : 0.0,
                'loyalty_discount' => $surface === self::SURFACE_KIOSK ? $this->money($pricing->discount) : 0.0,
                'loyalty_code' => (string) $request->input('loyalty_code', ''),
                'promo_code' => (string) $request->input('kiosk_promo_code', ''),
            ],
            'taxes' => [
                'total_tax' => $this->money($pricing->totalTax),
                'lines' => array_map(fn (PricingLineResult $line): array => [
                    'item_id' => $line->itemId,
                    'tax_name' => $line->taxName,
                    'tax_rate' => $this->money($line->taxRate),
                    'tax_type' => $line->taxType,
                    'tax_amount' => $this->money($line->taxAmount),
                ], $pricing->lines),
            ],
            'currency' => $this->currencyCode(),
            'fees' => [
                'delivery_charge' => $this->money($pricing->deliveryCharge),
            ],
            'totals' => [
                'subtotal' => $this->money($pricing->subtotal),
                'discount' => $this->money($pricing->discount),
                'total_tax' => $this->money($pricing->totalTax),
                'total_ttc' => $this->money($pricing->total),
            ],
        ];
    }

    /**
     * @param  array<int, object>  $items
     * @return array<int, array<string, mixed>>
     */
    private function canonicalModifiers(array $items): array
    {
        return array_map(function ($item): array {
            return [
                'item_id' => (int) ($item->item_id ?? 0),
                'variations' => $this->normalizeForCanonical($item->item_variations ?? []),
                'extras' => $this->normalizeForCanonical($item->item_extras ?? []),
                'addons' => $this->normalizeForCanonical($item->item_addons ?? []),
            ];
        }, $items);
    }

    private function customerId(Request $request, string $surface): ?int
    {
        if ($surface !== self::SURFACE_POS) {
            return null;
        }

        $customerId = (int) $request->input('customer_id', 0);

        return $customerId > 0 ? $customerId : null;
    }

    /**
     * @return array<int, string>
     */
    private function roleNames(User $actor): array
    {
        if (! method_exists($actor, 'getRoleNames')) {
            return [];
        }

        $roles = $actor->getRoleNames()->map(fn ($role): string => (string) $role)->values()->all();
        sort($roles);

        return $roles;
    }

    private function isGlobalAdmin(User $actor): bool
    {
        return (int) ($actor->branch_id ?? -1) === 0
            && method_exists($actor, 'hasRole')
            && $actor->hasRole('Admin');
    }

    private function hmacKey(): string
    {
        $key = (string) config('app.key');

        if ($key === '') {
            throw new \LogicException('APP_KEY missing for OrderQuote HMAC');
        }

        return $key;
    }

    private function currencyCode(): string
    {
        $currency = (string) (Settings::group('site')->get('site_default_currency_code')
            ?: Settings::group('site')->get('currency')
            ?: config('menu.currency', 'EUR'));

        return strtoupper(substr($currency, 0, 3) ?: 'EUR');
    }

    private function safeJsonDecode(string $json): mixed
    {
        if (trim($json) === '') {
            return [];
        }

        $decoded = json_decode($json);

        return json_last_error() === JSON_ERROR_NONE ? $decoded : [];
    }

    private function canonicalJson(array $payload): string
    {
        return (string) json_encode(
            $this->sortKeys($payload),
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION
        );
    }

    private function normalizeForCanonical(mixed $value): mixed
    {
        if ($value instanceof \stdClass) {
            $value = (array) $value;
        }

        if (is_array($value)) {
            $normalized = [];
            foreach ($value as $key => $inner) {
                $normalized[$key] = $this->normalizeForCanonical($inner);
            }

            return $normalized;
        }

        if (is_float($value) || is_int($value)) {
            return $this->money($value);
        }

        return $value;
    }

    private function sortKeys(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        if (array_is_list($value)) {
            return array_map(fn ($inner): mixed => $this->sortKeys($inner), $value);
        }

        ksort($value);

        foreach ($value as $key => $inner) {
            $value[$key] = $this->sortKeys($inner);
        }

        return $value;
    }

    private function money(mixed $value): float
    {
        return round((float) $value, 6);
    }
}

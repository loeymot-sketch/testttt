<?php

namespace App\Http\Requests;

use App\Enums\Activity;
use App\Enums\OrderType;
use App\Enums\PosPaymentMethod;
use App\Http\Requests\Concerns\NormalizesAdvanceOrder;
use App\Http\Requests\Concerns\ValidatesAddonRoles;
use App\Http\Requests\Concerns\ValidatesOrderItemVariations;
use App\Models\PaymentTerminal;
use App\Rules\ValidJsonOrder;
use App\Services\Delivery\DeliveryFeeService;
use Illuminate\Foundation\Http\FormRequest;
use Smartisan\Settings\Facades\Settings;

class PosOrderRequest extends FormRequest
{
    use NormalizesAdvanceOrder;
    use ValidatesAddonRoles;
    use ValidatesOrderItemVariations;

    protected function prepareForValidation(): void
    {
        $this->normalizeAdvanceOrder();

        // [ULTRA-AUDIT Wave 2 2026-07-04 — durcissement anti-gonflage delivery_charge, miroir
        // FrontendOrderService:280 / OrderRequest] Une commande NON-DELIVERY ne doit porter AUCUN
        // delivery_charge : le champ est `nullable` pour non-livraison (rules() ci-dessous), donc
        // un payload forgé (order_type=TAKEAWAY + delivery_charge=99) OU une désync UI
        // livraison→emporter gonflerait le total facturé au client (PricingService l'ajoute au
        // rawTotal). On force 0 hors DELIVERY.
        //
        // [S2-02 / P2-f 2026-07-18 — REGISTRE_FINAL goal-intelligence-2026-07-18] Le fee d'une
        // vraie DELIVERY est TOUJOURS serveur-autoritatif, jamais la valeur client : le store ne
        // unset que total/subtotal/discount, donc sans ce recalcul un `delivery_charge` forgé (0,
        // négatif ou gonflé sous le seuil de livraison offerte) persistait jusqu'au moteur de
        // pricing (« prix 100% backend » violé, CLAUDE.md §8). Le flux POS normal envoie
        // delivery_distance_km (PosComponent.vue:3911) → fee recalculé depuis la distance ;
        // SANS distance (payload forgé / flux dégradé), on force le fee de config branche
        // (fromDistanceKm(0) — legacy 5€ si non configurée), jamais la valeur client. Ce
        // recalcul DOIT rester identique à PosController::normalizePosRuntimePayload
        // (endpoint /pos/quote), sinon l'intent scellé du quote diverge de l'intent re-dérivé
        // au commit (OrderQuoteService:463) → 401 « quote intent mismatch ».
        if ((int) $this->input('order_type', 0) === OrderType::DELIVERY) {
            // [GOAL-COMPLEMENT-2026-05-18 Z-4 LIVREUR-Z4-ARCH-03 P0] DEL-5 wire-up.
            // POS walk-in DELIVERY path resolves the per-branch fee config when
            // branch_id is in the payload. Mirrors OrderRequest:117 and
            // DeliveryQuoteService:63. Null-safe: unknown branch -> legacy formula.
            $branchId = (int) $this->input('branch_id', 0);
            $branch = $branchId > 0 ? \App\Models\Branch::find($branchId) : null;

            $this->merge([
                'delivery_charge' => app(DeliveryFeeService::class)->fromDistanceKm(
                    $this->filled('delivery_distance_km') ? $this->input('delivery_distance_km') : 0,
                    $branch,
                ),
            ]);
        } else {
            $this->merge(['delivery_charge' => 0]);
        }

        // [F-SPLIT-PAYMENT-001] When the multi-tender feature flag is OFF,
        // strip `payment_breakdown` from the payload BEFORE validation runs.
        // This way an older deployment that still receives the field from a
        // newer frontend silently falls back to single-tender (legacy path).
        if (! config('split_payment.enabled', false)) {
            $this->offsetUnset('payment_breakdown');
        }
    }

    /**
     * Determine if the user is authorized to make this request.
     *
     * V1.0.2 BUILD-6 heal: defense-in-depth — PosController constructor enforces
     * `permission:pos` on every action except `quote` (kiosk pricing path uses its
     * own auth:sanctum + ability gate). PosOrderRequest is only injected on the
     * `store` action which carries the `permission:pos` middleware. FormRequest
     * doubles down so any future route bypass (e.g. inline controller invocation)
     * still authz-checks. Pattern matches Wave 5H (CurrencyRequest/TaxRequest/...).
     */
    public function authorize(): bool
    {
        return $this->user()?->can('pos') ?? false;
    }

    /**
     * Get the validation rules that apply to the request.
     */
    public function rules(): array
    {
        // [POS-9-H.1.5] F-A5 fix: `request('order_type')` is a string-ish HTTP value
        // and `OrderType::DINING_TABLE` is an int enum value. Strict `===` always
        // returned false, so `dining_table_id` was ALWAYS `nullable` in practice.
        // Cast to int and use ==-style comparison to actually enforce the rule.
        $orderTypeInt = (int) request('order_type', 0);
        $dineInEnabled = (bool) Settings::group('pos')->get('pos_dine_in_enabled', false);

        // [ULTRA-AUDIT Wave 3 2026-07-04] Split multi-tender actif ? (prepareForValidation a déjà
        // strippé payment_breakdown quand le flag est OFF, donc présent = split réellement actif).
        // En split, le frontend frozen pose pos_payment_method = mode DOMINANT + pos_payment_note
        // ='multi-tender' + terminal_id PAR TRANCHE (pas top-level). Les règles single-tender CARD
        // (note 4 chiffres + terminal_id required_if) ne doivent donc PAS s'appliquer au champ dominant.
        $hasBreakdown = ! empty(request('payment_breakdown'));

        return [
            // Numeric daily counter OR delivery call-out name (prénom) — must not be digits-only
            'token' => ['nullable', 'string', 'max:191'],
            'customer_id' => ['nullable', 'numeric'],
            'branch_id' => ['required', 'numeric'],
            // [GAP-31-1] subtotal is recalculated server-side — nullable here, backend ignores client value
            // [P7] Reject negative client-sent amounts if present.
            'subtotal' => ['nullable', 'numeric', 'min:0'],
            'discount' => ['nullable', 'numeric', 'min:0'],
            // [POS-9.1.1] Mandatory motif for any discount above 0
            'discount_reason' => ['nullable', 'string', 'max:191'],
            // [C2-CAISSE 2026-07-05] Nom du client (optionnel) — imprimé sur le ticket.
            'pos_customer_name' => ['nullable', 'string', 'max:60'],
            // [C4-CAISSE-TELEPHONE 2026-07-07] Téléphone du client (optionnel) pour une commande
            // téléphone différée — noté sur la commande, imprimé sur le ticket.
            'pos_customer_phone' => ['nullable', 'string', 'max:30'],
            // [C4-CAISSE-TELEPHONE 2026-07-07] Mode « Commande téléphone » : la commande est
            // enregistrée + envoyée en cuisine mais PAS encaissée (paiement différé au comptoir).
            // Implique le routage counter-collect (COUNTER_DEFERRED + PENDING_COUNTER) côté service.
            'phone_order' => ['nullable', 'boolean'],
            'dining_table_id' => ($orderTypeInt === OrderType::DINING_TABLE && $dineInEnabled) ? [
                'required',
                'numeric',
            ] : ['nullable'],
            'delivery_charge' => $orderTypeInt === OrderType::DELIVERY ? [
                'required',
                'numeric',
                'min:0',
            ] : ['nullable', 'numeric', 'min:0'],
            'delivery_distance_km' => ['nullable', 'numeric', 'min:0'],
            // [POS-9.1.8] total is recomputed server-side in OrderService::posOrderStore;
            // payload value is only used as a UX cross-check for cash payments
            // (see withValidator below). nullable so a desynced UI cannot bypass
            // server logic by spoofing total. (POS-GA-F-47)
            // [AUDIT-P50-BUG4] kept min:0 — server allows total=0 for 100% loyalty redemption.
            'total' => ['nullable', 'numeric', 'min:0'],
            'quote_token' => ['nullable', 'string', 'uuid'],
            'quote_signature' => ['nullable', 'string', 'size:64'],
            'order_type' => ['required', 'numeric'],
            'is_advance_order' => ['required', 'numeric'],
            'address_id' => $orderTypeInt === OrderType::DELIVERY ? [
                'required',
                'numeric',
            ] : ['nullable'],
            'delivery_time' => ['nullable'],
            'coupon_id' => ['nullable', 'numeric'],
            'source' => ['required', 'numeric'],
            'items' => ['required', 'json', new ValidJsonOrder],
            'pos_payment_method' => ['required', 'numeric'],
            'pos_payment_note' => $hasBreakdown ? ['nullable', 'string', 'max:200'] : (request('pos_payment_method') === PosPaymentMethod::CARD || request('pos_payment_method') === PosPaymentMethod::MOBILE_BANKING || request('pos_payment_method') === PosPaymentMethod::OTHER || (string) request('pos_payment_method') === (string) PosPaymentMethod::TICKET_RESTAURANT ? (request('pos_payment_method') === PosPaymentMethod::CARD ? ['required', 'numeric', 'min_digits:4', 'max_digits:4'] : ['required', 'string', 'max:200']) : ['nullable', 'string']),
            'pos_received_amount' => request('pos_payment_method') === PosPaymentMethod::CASH ? ['required', 'numeric', 'min:0'] : ['nullable', 'numeric', 'min:0'],
            // [P1 V1 Cloud-Prep insights 2026-05-18] Single-tender CARD path
            // also requires a `terminal_id` so the Z-report TPE breakdown can
            // attribute the sale to a specific terminal. Wave 5F
            // F-SPLIT-PHANTOM-CARD-001 closed the split-tender path
            // (payment_breakdown.*.terminal_id) but legacy single-tender CARD
            // sales (pos_payment_method=CARD WITHOUT payment_breakdown) were
            // still being bucketed as "Sans TPE" in the Z-report — losing
            // per-terminal fee/volume attribution. Shape-level rule only here;
            // the deep ACTIVE+branch ownership check is enforced in
            // OrderService::posOrderStore where branch context is reliable.
            'terminal_id' => array_merge(
                ['nullable', 'integer', 'min:1'],
                // Single-tender CARD exige un terminal_id top-level (attribution TPE au Z). En split,
                // le terminal_id vit dans chaque tranche CARD (payment_breakdown.*.terminal_id) → ne pas
                // l'exiger au niveau top, sinon tout split card-dominant est rejeté 422.
                $hasBreakdown ? [] : ['required_if:pos_payment_method,'.PosPaymentMethod::CARD],
            ),
            'loyalty_customer_code' => ['nullable', 'string', 'min:4', 'max:25'],
            // [F-SPLIT-PAYMENT-001] Optional multi-tender breakdown — see SplitPaymentService.
            // When the feature flag is OFF, prepareForValidation() strips this field
            // BEFORE these rules run, so they only fire on flag-enabled deployments.
            'payment_breakdown' => ['nullable', 'array', 'max:12'],
            'payment_breakdown.*' => ['array'],
            'payment_breakdown.*.mode' => ['required_with:payment_breakdown', 'integer', 'in:1,2,3,4,5'],
            'payment_breakdown.*.amount' => ['required_with:payment_breakdown', 'numeric', 'min:0.01'],
            'payment_breakdown.*.tendered' => ['nullable', 'numeric', 'min:0'],
            'payment_breakdown.*.change' => ['nullable', 'numeric', 'min:0'],
            'payment_breakdown.*.note' => ['nullable', 'string', 'max:191'],
            'payment_breakdown.*.reference' => ['nullable', 'string', 'max:64'],
            // [Sprint H2 P1-Z7-01 2026-05-17] Optional FK to payment_terminals (Sprint 1C).
            // Nullable for V1.0.1 — POS UI selector is Stage B; legacy callers without
            // a terminal selector keep working ("Sans TPE" bucket per MASTER §5 Risk #4).
            // No `exists` rule yet — branch-level cross-check belongs in a Stage B
            // dedicated request after the UI ships, to avoid coupling V1.0.1 backend
            // wire-in to a branch_id-aware exists rule that needs ->where().
            'payment_breakdown.*.terminal_id' => ['nullable', 'integer', 'min:1'],
        ];
    }

    public function withValidator($validator)
    {
        $validator->after(function ($validator) {
            // [SELF-AUDIT P1 2026-07-05] Split (payment_breakdown) sur commande DIFFÉRÉE au comptoir
            // = invalide : confirmCounterPayment (encaissement) est MONO-tender et ne peut pas honorer
            // un split ; persister les tranches à la création double-compte le tiroir (NF525 §8).
            // Fail-closed : on rejette la combinaison (miroir de la garde service posOrderStore:1246).
            // [C4-CAISSE-TELEPHONE 2026-07-07] phone_order implique aussi le différé (miroir de
            // OrderService::posOrderStore) : une commande téléphone est encaissée à l'arrivée.
            $deferred = config('pos.walkin_route_to_counter') === true
                || $this->boolean('defer_to_counter')
                || $this->boolean('phone_order');
            if ($deferred
                && config('split_payment.enabled', false)
                && ! empty($this->input('payment_breakdown'))) {
                $validator->errors()->add(
                    'payment_breakdown',
                    'Le paiement multi-tender n\'est pas supporté pour une commande différée au comptoir (encaissement mono-mode).'
                );
            }

            // [POS-9-H.1.5] F-A5: Server-side dine-in feature gate.
            // The UI hides dine-in when `pos_dine_in_enabled` is off, but nothing
            // was enforcing it server-side. An attacker posting order_type=15
            // (DINING_TABLE) would bypass the UI and create a dine-in order.
            $orderTypeInt = (int) request('order_type', 0);
            if ($orderTypeInt === OrderType::DINING_TABLE
                && ! (bool) Settings::group('pos')->get('pos_dine_in_enabled', false)) {
                $validator->errors()->add('order_type', 'Dine-in is disabled for this branch.');

                return;
            }

            if ($orderTypeInt === OrderType::DELIVERY && Settings::group('order_setup')->get('order_setup_delivery') == Activity::DISABLE) {
                $validator->errors()->add('order_type', 'This order type is disabled now you can try another order type right now or call the management.');
            } elseif ($orderTypeInt === OrderType::TAKEAWAY && Settings::group('order_setup')->get('order_setup_takeaway') == Activity::DISABLE) {
                $validator->errors()->add('order_type', 'This order type is disabled now you can try another order type right now or call the management.');
            } elseif (blank(request('order_type'))) {
                $validator->errors()->add('order_type', 'This order type is disabled now you can try another order type right now or call the management.');
            }
            // [AUDIT-P1-B] NOTE: This validation uses the client-sent 'total' as a preliminary check.
            // The server recalculates the real total in OrderService::posOrderStore.
            // A second validation against the server-computed total is enforced there.
            // This check only prevents obvious UI errors (cashier entered less cash than shown).
            // [POS-9.1.8] Only run this UX cross-check when the client actually
            // sent a `total` (now nullable per POS-GA-F-47). The authoritative
            // total is computed server-side in OrderService::posOrderStore and
            // re-validated against pos_received_amount there.
            if (request('pos_payment_method') == PosPaymentMethod::CASH
                && request()->filled('total')
                && ((float) request('total') > (float) request('pos_received_amount'))) {
                $validator->errors()->add('pos_received_amount', 'The received amount can not be less than the total amount.');
            }

            // M-06: this request only performs shape/UX checks. Discount permission
            // and percentage authority are enforced against the backend subtotal in
            // OrderService::posOrderStore, never against the client-sent subtotal.
            $discount = (float) request('discount', 0);
            if ($discount > 0) {
                $reason = trim((string) request('discount_reason', ''));
                if (strlen($reason) < 3) {
                    $validator->errors()->add('discount_reason', 'A reason is required for any POS discount (min 3 characters).');

                    return;
                }
            }

            // [F-SPLIT-PHANTOM-CARD-001 2026-05-17] Phantom-CARD theft vector fix.
            // Without this guard, a cashier could submit a CARD tranche with a
            // forged/free-form reference and pocket the matching cash (no
            // cash_movement is written for CARD tranches → drawer reconciles).
            // Rule: every CARD tranche MUST carry a `terminal_id` pointing to
            // an ACTIVE payment_terminals row OWNED by the order's branch.
            // `exists` alone is unsafe — BranchScope is bypassed by the DB
            // query builder, so we explicitly check branch_id + status.
            $breakdown = (array) $this->input('payment_breakdown', []);
            $orderBranchId = (int) $this->input('branch_id', 0);
            foreach ($breakdown as $idx => $tranche) {
                if (! is_array($tranche)) {
                    continue;
                }
                if ((int) ($tranche['mode'] ?? 0) !== PosPaymentMethod::CARD) {
                    continue;
                }
                $terminalId = (int) ($tranche['terminal_id'] ?? 0);
                if ($terminalId <= 0) {
                    $validator->errors()->add(
                        "payment_breakdown.{$idx}.terminal_id",
                        'A valid payment terminal is required for every CARD tranche.'
                    );

                    continue;
                }
                // [Z6-P1-WGS 2026-05-19] singular form — PaymentTerminal has no
                // SoftDeletingScope so this is a no-op refactor, but the explicit
                // BranchScope::class arg documents that the bypass is intentional
                // (caller already constrains branch_id explicitly below).
                $exists = PaymentTerminal::withoutGlobalScope(\App\Models\Scopes\BranchScope::class)
                    ->where('id', $terminalId)
                    ->where('branch_id', $orderBranchId)
                    ->where('status', PaymentTerminal::STATUS_ACTIVE)
                    ->exists();
                if (! $exists) {
                    $validator->errors()->add(
                        "payment_breakdown.{$idx}.terminal_id",
                        'The selected payment terminal is not available for this branch.'
                    );
                }
            }

            $this->validateOrderItemVariationsAfter($validator);
            // [HEAL-PLAN-D.1 / RED-Z4 P0-Z4-01 2026-05-19] Bind payload
            // addon role to DB membership. Blocks the kiosk menu-formula
            // ratio injection on non-menu_component addons.
            $this->validateAddonRolesAfter($validator);
        });
    }

    public function messages()
    {
        return [
            'pos_payment_note.required' => request('pos_payment_method') == PosPaymentMethod::CARD ? 'Last 4 digits of card is required' : (request('pos_payment_method') == PosPaymentMethod::MOBILE_BANKING ? 'Transaction ID field is required' : 'Payment note field is required'),
            'pos_payment_note.min_digits' => 'The cart must contain at least 4 digits',
            'pos_payment_note.max_digits' => 'The cart must not contain more than 4 digits',
            'pos_received_amount.required' => 'The received amount field is required',
            'dining_table_id.required' => 'The dining table field is required',
        ];
    }
}

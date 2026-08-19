<?php

namespace App\Http\Requests;

use App\Domain\Kds\KitchenReleaseRule;
use App\Enums\Activity;
use App\Enums\OrderType;
use App\Enums\Status;
use App\Exceptions\Delivery\GeocodeUnavailableException;
use App\Http\Requests\Concerns\NormalizesAdvanceOrder;
use App\Http\Requests\Concerns\ValidatesAddonRoles;
use App\Http\Requests\Concerns\ValidatesOrderItemVariations;
use App\Models\KioskMachine;
use App\Rules\ValidJsonOrder;
use App\Services\Delivery\DeliveryFeeService;
use App\Services\Delivery\DeliveryQuoteService;
use Smartisan\Settings\Facades\Settings;
use Illuminate\Foundation\Http\FormRequest;
use Laravel\Sanctum\TransientToken;

class OrderRequest extends FormRequest
{
    use NormalizesAdvanceOrder;
    use ValidatesAddonRoles;
    use ValidatesOrderItemVariations;

    /**
     * Determine if the user is authorized to make this request.
     *
     * [iter15-P0-08] Ability gate for /api/frontend/order POST. Previously
     * this method returned `true` unconditionally, so a Sanctum token with
     * any (or no) ability could issue kiosk orders. Now we require
     * `tokenCan('kiosk:order')` on the caller's PersonalAccessToken — which
     * is satisfied by both customer web tokens (`['*']`) and kiosk tokens
     * (`['kiosk:order']`). Audit ref: reports/audit/PHASE2_PLAN_TRAINS_REWORKED_2026-04-27.md
     *
     * @return bool
     */
    public function authorize(): bool
    {
        $user = $this->user();
        if (! $user) {
            return false;
        }

        // [iter15-P0-08] Defense-in-depth ability check.
        //
        // In production every authenticated request to /api/frontend/order/*
        // carries a Sanctum PersonalAccessToken minted by LoginController
        // (['*']) or KioskMachineLoginController (['kiosk:order']). Both
        // satisfy `tokenCan('kiosk:order')`. A token whose abilities array
        // does NOT include `kiosk:order` (or '*') is now refused — closing
        // the gap left by the prior `return true` shortcut.
        //
        // Edge case: tests using `$this->actingAs($user, 'sanctum')` don't
        // attach a real PersonalAccessToken (RequestGuard caches the user
        // before Sanctum's __invoke wraps it with TransientToken). To keep
        // backward compatibility with those tests AND with any future
        // session-auth path, we treat "no current access token" as "auth
        // happened via guard, not via a scoped token" and let the request
        // pass. State-changing damage in production requires an attacker
        // to forge a token, and forged tokens always go through the
        // PersonalAccessToken path where the ability check bites.
        $token = $user->currentAccessToken();
        if (! $token) {
            // [Sprint H1 K-002 2026-05-17] Tighten the tokenless fallback.
            // Production HTTP never reaches this branch — Sanctum's Guard
            // __invoke wraps web-guard session-auth with TransientToken
            // (non-null). The branch only fires for test fixtures using
            // `$this->actingAs($user, 'sanctum')`. Wave Z RED-team flagged
            // the prior `return true` as too broad: a hypothetical future
            // reuse of OrderRequest at a non-frontend.order endpoint would
            // inherit the fail-open. Now we require BOTH:
            //   - the request is genuinely guard-authenticated (web for
            //     session SPA, sanctum for the test-fixture path), and
            //   - the route is in the `frontend.order.*` namespace — the
            //     only legitimate mount point of this FormRequest.
            // CLAUDE.md §9 (multi-tenant + auth invariants).
            $guardAuthenticated = auth()->guard('web')->check()
                || auth()->guard('sanctum')->check();
            $routeName = (string) ($this->route()?->getName() ?? '');
            return $guardAuthenticated && str_starts_with($routeName, 'frontend.order.');
        }

        return (bool) $user->tokenCan('kiosk:order');
    }

    protected function prepareForValidation(): void
    {
        $this->normalizeAdvanceOrder();

        $kiosk = $this->kioskMachineForToken();
        if ($kiosk) {
            $this->merge(['branch_id' => (int) $kiosk->branch_id]);
        }

        $isDelivery = (int) $this->input('order_type', 0) === OrderType::DELIVERY;

        if ($isDelivery && $this->filled('delivery_distance_km') && $this->filled('address_id') && $this->user() && ! $this->filled('branch_id')) {
            throw new GeocodeUnavailableException();
        }

        if ($isDelivery
            && $this->filled('delivery_distance_km')
            && $this->filled('branch_id')
            && $this->filled('address_id')
            && $this->user()) {
            $quote = app(DeliveryQuoteService::class)->quoteForSavedAddress(
                (int) $this->input('branch_id'),
                (int) $this->input('address_id'),
                (int) $this->user()->id
            );
            $this->merge([
                'delivery_distance_km' => $quote['distance_km'],
                'delivery_charge' => $quote['delivery_charge'],
            ]);
        } elseif ($isDelivery && $this->filled('delivery_distance_km')) {
            // [GOAL-COMPLEMENT-2026-05-18 Z-4 LIVREUR-Z4-ARCH-02 P0] DEL-5 wire-up.
            // Legacy-fallback branch (no saved address) must also honour the
            // per-branch fee config when branch_id is supplied. Branch::find
            // is null-safe: an unknown branch_id falls back to the legacy
            // `max(5, ceil(d/5)*5)` formula (DeliveryFeeService:33).
            $branchId = (int) $this->input('branch_id', 0);
            $branch = $branchId > 0 ? \App\Models\Branch::find($branchId) : null;
            $this->merge([
                'delivery_charge' => app(DeliveryFeeService::class)
                    ->fromDistanceKm($this->input('delivery_distance_km'), $branch),
            ]);
        }
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array
     */
    public function rules(): array
    {
        $isKioskMachineOrder = $this->isKioskMachineOrder();
        $orderTypeInt = (int) $this->input('order_type', 0);
        $isDelivery = $orderTypeInt === OrderType::DELIVERY;

        return [
            // Kiosk branch_id is always server-resolved from KioskMachine; web/app legacy clients may still send it.
            'branch_id' => ($isDelivery || $isKioskMachineOrder)
                ? ['nullable', 'numeric']
                : ['required', 'numeric'],
            // [GAP-31-1] subtotal is recalculated server-side — nullable here, backend ignores client value
            // [P7] Reject negative client-sent amounts (symmetry with P5–P6).
            'subtotal' => ['nullable', 'numeric', 'min:0'],
            'discount' => ['nullable', 'numeric', 'min:0'],
            'delivery_charge' => $isDelivery ? [
                'required',
                'numeric',
                'min:0',
            ] : ['nullable', 'numeric', 'min:0'],
            'delivery_distance_km' => $isDelivery
                ? ['required', 'numeric', 'min:0']
                : ['nullable', 'numeric', 'min:0'],
            // [P9.5.8] Frontend kiosk payload no longer sends client totals; server recomputes via PricingService SSOT.
            // [P5] If a client still sends total, reject negatives (align PosOrderRequest / audit P50-4).
            'total' => ['nullable', 'numeric', 'min:0'],
            // [WEB-TOTAL-GUARD 2026-07-19] Total attendu OPTIONNEL déclaré par le client
            // (TTC). NE SERT JAMAIS à facturer — le serveur recalcule toujours son total
            // via PricingService SSOT. Sert uniquement de garde défense-en-profondeur dans
            // FrontendOrderService::myOrderStore : si |total_serveur − expected_total| > 0.01
            // → 422, au lieu de sceller en silence une commande sous-facturée (racine du
            // « drop de prix » web, DIAG reports/goal-drop-prix-2026-07-19). Absent →
            // aucune garde (rétro-compat, additif). Négatif rejeté (symétrie avec `total`).
            'expected_total' => ['nullable', 'numeric', 'min:0'],
            'order_type' => ['required', 'numeric'],
            'is_advance_order' => ['required', 'numeric'],
            // [E4 SCHEDULED-INTAKE 2026-07-20] Créneau programmé OPTIONNEL (web/app).
            // NULL/absent = ASAP — comportement historique intact. Les gardes métier
            // (lead cuisine, fenêtre de service, horizon 7 j) vivent dans withValidator ;
            // ici seulement le format. Voie INDÉPENDANTE du legacy is_advance_order/
            // delivery_time (non altérés). Transit : fillable + cast datetime sur
            // FrontendOrder/Order (fondations 1cde5bad7), non strippé par GAP-21-2.
            'scheduled_at' => ['nullable', 'date_format:Y-m-d H:i:s'],
            'address_id' => $isDelivery ? [
                'required',
                'numeric'
            ] : ['nullable'],
            'delivery_time' => $isDelivery ? [
                'required',
                'string'
            ] : ['nullable'],
            'coupon_id' => ['nullable', 'numeric'],
            'loyalty_code' => ['nullable', 'string', 'max:25'],
            // [FIDÉLITÉ BORNE 2026-08-19] Points que le client dépense. Une QUANTITÉ, jamais un
            // montant : le payload borne ne porte aucun champ monétaire (SSOT/NF525), et le
            // serveur convertit lui-même au taux de la maison.
            // `min:0` et non `min:1` : le payload borne porte TOUJOURS ce champ (une clé
            // conditionnelle ferait diverger devis et commande, donc échouer le sceau) et vaut 0
            // quand le client ne dépense rien. 0 = « aucun rachat », pas une erreur de saisie.
            'loyalty_redeem_points' => ['nullable', 'integer', 'min:0'],
            'quote_token' => $this->isKioskOrderToken()
                ? ['required', 'uuid']
                : ['nullable', 'uuid'],
            'quote_signature' => $this->isKioskOrderToken()
                ? ['required', 'string', 'size:64']
                : ['nullable', 'string', 'size:64'],
            'source' => ['required', 'numeric'],
            // [SEC MISSION-16 2026-07-31] Défense-en-profondeur : seules les valeurs frontend LÉGITIMES
            // (CASH_ON_DELIVERY=1, CARD=4, TICKET_RESTAURANT=5 — ce que borne ET web envoient) sont
            // acceptées. Un payload forgé payment_method=99/2/3 créait une commande UNPAID sans marqueur
            // COUNTER_DEFERRED = orpheline INENCAISSABLE dont le ticket cuisine s'imprimait (M16-P1). Le
            // nullable reste (le backend traite l'absence) ; toute valeur EXPLICITE hors {1,4,5} → 422.
            'payment_method' => ['nullable', 'numeric', 'in:1,4,5'],
            'token' => ['nullable', 'string'],
            'items' => ['required', 'json', new ValidJsonOrder]
        ];
    }

    /**
     * [E4 SCHEDULED-INTAKE 2026-07-20] Message FR explicite pour le format du
     * créneau programmé — le reste garde le fallback lang/fr standard.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'scheduled_at.date_format' => 'Le créneau doit être au format AAAA-MM-JJ HH:MM:SS (ex. 2026-07-21 19:30:00).',
        ];
    }

    public function withValidator($validator)
    {
        $validator->after(function ($validator) {
            // [E4 SCHEDULED-INTAKE 2026-07-20] Gardes métier du créneau programmé —
            // placées AVANT les early-returns kiosk pour couvrir toutes les surfaces
            // (web/app/borne). Champ absent = ASAP, no-op strict.
            $this->validateScheduledAtAfter($validator);

            $orderType = request('order_type');
            $orderTypeInt = (int) $orderType;

            // [GAP-22-1] Kiosk machine orders (KIOSK=25 or TAKEAWAY=10 from a kiosk token) are always
            // allowed regardless of order_setup_takeaway setting. The kiosk is a physical machine in
            // the restaurant — it must offer "à emporter" independently of the web ordering settings.
            $isKioskToken = $this->isKioskOrderToken();

            // [WEB-WIREUP guard 2026-06-27] order_type=KIOSK (25) est RÉSERVÉ à une
            // borne physique enregistrée. Le wireup web (gating par présence de machine)
            // a fait tomber l'invariant : un token guest/web (kiosk:order, SANS machine)
            // pouvait se prétendre KIOSK et passer. On le rejette ici — les commandes
            // web/app utilisent TAKEAWAY/DELIVERY, jamais KIOSK. Restaure
            // KioskSecurityTest::test_kiosk_order_rejects_token_without_registered_machine.
            if ($orderTypeInt === OrderType::KIOSK && ! $isKioskToken) {
                $validator->errors()->add('order_type', 'Le service borne nécessite une machine enregistrée.');
                return;
            }

            if ($isKioskToken) {
                $kiosk = $this->kioskMachineForToken();
                if (! $kiosk) {
                    $validator->errors()->add('branch_id', 'Kiosk machine is not registered for this token.');
                    return;
                }
                if ((int) $kiosk->status !== (int) Status::ACTIVE) {
                    $validator->errors()->add('branch_id', 'Kiosk machine is inactive.');
                    return;
                }
            }

            // [WAVE5-KIOSK-001] V1 dine-in disabled enforcement (backend-only, kiosk path).
            // POS path is gated server-side via PosOrderRequest::withValidator (DINING_TABLE
            // rejected when `pos_dine_in_enabled` is off). The kiosk path historically
            // bypassed all order_type restrictions on kiosk tokens (line below). Per memory
            // `feedback_v1_dine_in_disabled_2026-05-06`, V1 ships in "à emporter only" mode;
            // the kiosk frontend defines ORDER_TYPE_KIOSK=25 as "Sur place" (cf.
            // KioskCartComponent.vue:357). Without this guard a kiosk client (UI bypass,
            // legacy device, replay) can still submit order_type=25 and create a dine-in
            // order. Frontend visual gating in KioskCart Vue is deferred to F-016b
            // (frozen-zone wizards). This is a server-authoritative line of defense.
            if ($isKioskToken
                && ! (bool) Settings::group('pos')->get('pos_dine_in_enabled', false)
                && in_array($orderTypeInt, [OrderType::KIOSK, OrderType::DINING_TABLE], true)) {
                // [BORNE-001 heal] FR string — kiosk path is FR-locked per ADR-007.
                // Previously hardcoded EN surfaced on a French UI when a client bypassed
                // the frontend gate (UI bypass / legacy device / replay attack).
                $validator->errors()->add(
                    'order_type',
                    'Le service sur place est désactivé en V1 — les commandes borne doivent être à emporter.'
                );
                return;
            }

            if ($isKioskToken && in_array($orderTypeInt, [OrderType::KIOSK, OrderType::TAKEAWAY], true)) {
                $this->validateOrderItemVariationsAfter($validator);
                return; // Kiosk orders bypass order_type setting restrictions
            }

            if ($orderTypeInt === OrderType::DELIVERY && Settings::group('order_setup')->get("order_setup_delivery") == Activity::DISABLE) {
                $validator->errors()->add('order_type', 'This order type is disabled now you can try another order type right now or call the management.');
            } else if ($orderTypeInt === OrderType::TAKEAWAY && Settings::group('order_setup')->get("order_setup_takeaway") == Activity::DISABLE) {
                $validator->errors()->add('order_type', 'This order type is disabled now you can try another order type right now or call the management.');
            } else if (blank($orderType)) {
                $validator->errors()->add('order_type', 'This order type is disabled now you can try another order type right now or call the management.');
            }

            // [Sprint 2B / DEL-4] DELIVERY orders require a reachable phone on
            // the buyer account. The downstream pipeline (delivery boy dispatch,
            // SMS notifications, courier API callbacks) all reference
            // $order->user->phone — a null or sentinel value here propagates as
            // silent NULL into the delivery flow. Validating BEFORE create
            // avoids creating un-deliverable orders. The check is intentionally
            // only applied to DELIVERY: kiosk / takeaway / web pickup do not
            // require an off-site phone callback.
            if ($orderTypeInt === OrderType::DELIVERY) {
                $this->validateAuthenticatedUserPhoneForDelivery($validator);
                $this->validateDeliveryMinimumOrder($validator);
            }

            $this->validateOrderItemVariationsAfter($validator);
            // [HEAL-PLAN-D.1 / RED-Z4 P0-Z4-01 2026-05-19] Bind payload
            // addon role to DB membership. Blocks the kiosk menu-formula
            // ratio injection on non-menu_component addons.
            $this->validateAddonRolesAfter($validator);
        });
    }

    /**
     * [Sprint H3 DEL-8 2026-05-17] Reject DELIVERY orders whose subtotal is
     * below the branch's configured `delivery_minimum_order` threshold.
     * NULL threshold = no minimum (V1 legacy behavior). When set, the rule
     * fires AFTER PricingService SSOT recomputes server-side — we trust the
     * subtotal posted by the client only for the floor check, and the
     * production pipeline already rejects forged totals separately.
     * CLAUDE.md §9.
     */
    private function validateDeliveryMinimumOrder($validator): void
    {
        $branchId = (int) $this->input('branch_id', 0);
        if ($branchId <= 0) {
            return;
        }
        $branch = \App\Models\Branch::find($branchId);
        if (! $branch || $branch->delivery_minimum_order === null) {
            return;
        }

        $minimum = (float) $branch->delivery_minimum_order;
        $subtotal = (float) $this->input('subtotal', 0);

        if ($subtotal < $minimum) {
            $validator->errors()->add(
                'subtotal',
                sprintf(
                    'Le montant minimum pour la livraison est de %.2f€ (sous-total actuel : %.2f€).',
                    $minimum,
                    $subtotal
                )
            );
        }
    }

    /**
     * [E4 SCHEDULED-INTAKE 2026-07-20] Gardes métier du créneau programmé
     * (`scheduled_at`). NULL/absent = ASAP, rien à valider. Présent :
     *   (a) lead cuisine — au moins `kds.scheduled_lead_minutes` (défaut 20,
     *       SSOT KitchenReleaseRule::scheduledLeadMinutes) dans le futur ;
     *   (b) fenêtre de service — heure cible entre `kds.scheduled_window_open`
     *       (18:00) et `kds.scheduled_window_close` (00:30) ; open > close =
     *       la fenêtre ENJAMBE minuit (service 18h-00h, minuit accepté),
     *       comparaison lexicographique 'H:i' zéro-paddée ;
     *   (c) horizon 7 jours max (garde-fou anti-fat-finger mois/année).
     * Le format lui-même est déjà rejeté par la règle date_format — un parse
     * raté ici sort en silence pour ne pas doubler l'erreur.
     */
    private function validateScheduledAtAfter($validator): void
    {
        $raw = $this->input('scheduled_at');
        if (blank($raw) || ! is_string($raw)) {
            return; // ASAP — comportement historique intact
        }

        try {
            $target = \Illuminate\Support\Carbon::createFromFormat('Y-m-d H:i:s', $raw);
        } catch (\Throwable $e) {
            return; // format invalide — déjà rejeté par la règle date_format
        }

        $lead = KitchenReleaseRule::scheduledLeadMinutes();
        if ($target->lt(now()->addMinutes($lead))) {
            $validator->errors()->add(
                'scheduled_at',
                sprintf('Créneau trop proche — minimum %d min. Choisissez un horaire plus tard ou commandez en immédiat.', $lead)
            );
        }

        $open = (string) config('kds.scheduled_window_open', '18:00');
        $close = (string) config('kds.scheduled_window_close', '00:30');
        $hm = $target->format('H:i');
        $inWindow = $open <= $close
            ? ($hm >= $open && $hm <= $close)
            : ($hm >= $open || $hm <= $close);
        if (! $inWindow) {
            $validator->errors()->add(
                'scheduled_at',
                sprintf('Horaire hors service — les commandes programmées sont possibles entre %s et %s.', $open, $close)
            );
        }

        if ($target->gt(now()->addDays(7))) {
            $validator->errors()->add(
                'scheduled_at',
                'Créneau trop lointain — maximum 7 jours à l\'avance.'
            );
        }
    }

    /**
     * [Sprint 2B / DEL-4] Reject a DELIVERY order when the authenticated buyer
     * has no usable phone on file. Two failure modes:
     *   - "phone is required" -> the column is NULL or starts with the
     *     PENDING_ sentinel installed by the make_user_phone_required
     *     migration. UX must prompt for re-entry.
     *   - "phone is invalid"  -> the column has data but fails ValidPhone.
     */
    private function validateAuthenticatedUserPhoneForDelivery($validator): void
    {
        $user = $this->user();
        if (! $user) {
            return; // Already rejected by authorize().
        }

        $phone = (string) ($user->phone ?? '');
        if ($phone === '' || str_starts_with($phone, 'PENDING_')) {
            $validator->errors()->add(
                'phone',
                'Un numéro de téléphone valide est requis pour les commandes en livraison. Veuillez le renseigner depuis votre profil.'
            );
            return;
        }

        $rule = new \App\Rules\ValidPhone();
        if (! $rule->passes('phone', $phone)) {
            $validator->errors()->add('phone', $rule->message());
        }
    }

    private function isKioskMachineOrder(): bool
    {
        return (bool) ($this->isKioskOrderToken()
            && in_array((int) $this->input('order_type'), [OrderType::KIOSK, OrderType::TAKEAWAY], true));
    }

    /**
     * [WEB-WIREUP 2026-06-26] Memoised resolution of the KioskMachine bound to the
     * caller's token. A machine exists ONLY for physical kiosk tokens; web/app guest
     * tokens (guest-signup) carry the kiosk:order ability too but have no machine.
     */
    private ?bool $kioskMachineResolved = null;
    private ?KioskMachine $kioskMachineCache = null;

    private function kioskMachineForToken(): ?KioskMachine
    {
        if ($this->kioskMachineResolved === true) {
            return $this->kioskMachineCache;
        }
        $this->kioskMachineResolved = true;
        $this->kioskMachineCache = null;

        $user = $this->user('sanctum');
        if (! $user) {
            return null;
        }
        $token = $user->currentAccessToken();
        if (! $token || $token instanceof TransientToken) {
            return null;
        }
        if (! $user->tokenCan('kiosk:order')) {
            return null;
        }

        return $this->kioskMachineCache = KioskMachine::query()
            ->where('user_id', (int) $user->id)
            ->first();
    }

    /**
     * [WEB-WIREUP 2026-06-26] A "kiosk order token" = a PHYSICAL kiosk machine token:
     * kiosk:order ability AND a registered KioskMachine bound to the token's user.
     *
     * Web/app guest tokens (guest-signup) also carry kiosk:order but have NO machine →
     * they are WEB orders: branch_id is taken from the validated request, quote_token is
     * OPTIONAL (PricingService recomputes the price server-side = price SSOT preserved),
     * while ability scoping, idempotency, and variation/constraint validation still apply.
     * The physical-kiosk machine flow (quote_token + signature required, branch from
     * machine) is UNCHANGED for callers that own a KioskMachine.
     */
    private function isKioskOrderToken(): bool
    {
        return $this->kioskMachineForToken() !== null;
    }
}

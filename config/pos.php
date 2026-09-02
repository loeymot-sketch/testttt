<?php

/**
 * POS runtime configuration.
 *
 * [2026-05-18] Adds the `simulation_hardware` flag used while the physical
 * POS hardware (cash drawer, TPE terminal, ticket printer) is not yet
 * connected. When the flag is ON, the controller-level guard that requires
 * an OPEN CashDrawerSession before a CASH-bearing order is bypassed —
 * mirroring the fact that no physical drawer exists to open.
 *
 * IMPORTANT: this flag does NOT bypass any pricing, composition, fiscal
 * sequence, audit-chain, or branch-isolation invariant. It only affects
 * the hardware-presence checks. NF525 compliance is preserved (sequence
 * allocation, HMAC chain signing, immutable composition_snapshot, etc.).
 *
 * Production day: set POS_SIMULATION_HARDWARE=false (default) and ensure
 * the physical cash drawer is open via `php artisan pos:open-drawer ...`
 * or the operator workflow.
 */
return [
    /*
    |--------------------------------------------------------------------------
    | Hardware simulation
    |--------------------------------------------------------------------------
    |
    | When true:
    |   - PosController::assertCashDrawerSessionOpenIfCashInvolved returns
    |     early (no drawer-open precondition required for CASH sales).
    |   - SplitPaymentService / PaymentService also skip the
    |     CashDrawerSessionNotOpenException short-circuit for CASH tranches
    |     when no physical drawer is attached.
    |
    | When false (default — production):
    |   - All hardware-presence checks fire normally.
    */
    'simulation_hardware' => filter_var(env('POS_SIMULATION_HARDWARE', false), FILTER_VALIDATE_BOOLEAN),

    /*
    |--------------------------------------------------------------------------
    | POS API rate-limit knobs (Wave O O-5 P-OWNER-5 heal 2026-05-20)
    |--------------------------------------------------------------------------
    |
    | The three POS-namespace throttle buckets used by RouteServiceProvider
    | (`pos-quote`, `pos-order-create`, `pos-order-update`) were hard-coded
    | at 120 / 60 / 120 req/min/user. During simulation-mode E2E owner
    | testing — repeated TPE retries while wiring composition fixes — the
    | `pos-order-create` 60/min ceiling was burned and every subsequent
    | submit surfaced `429 Too Many Attempts` ("Too many requests at this
    | time"). Owner had no escape valve short of editing source.
    |
    | Defaults below match the historical hard-coded values exactly so
    | production behaviour is unchanged. Local dev raises the ceiling via
    | `.env` only (POS_RATE_LIMIT_*). `.env.example` keeps the prod defaults
    | so a fresh clone is safe by default. AppServiceProvider's NF525 boot
    | guards are unaffected — these are throttle ceilings, not fiscal
    | invariants.
    */
    'rate_limit' => [
        // Recherche d'un client au comptoir (téléphone / code / QR). Oracle d'énumération : borné.
        'loyalty_lookup' => (int) env('POS_RATE_LIMIT_LOYALTY_LOOKUP', 30),
        'quote'        => max(1, (int) env('POS_RATE_LIMIT_QUOTE', 120)),
        'order_create' => max(1, (int) env('POS_RATE_LIMIT_ORDER_CREATE', 60)),
        'order_update' => max(1, (int) env('POS_RATE_LIMIT_ORDER_UPDATE', 120)),
        // [429 EN SERVICE 2026-08-13] Sondage des files d'impression (ticket promo + ticket
        // cuisine), une demande toutes les 5 s PAR ÉCRAN d'administration ouvert. Ces POST
        // tombaient dans le seau `admin-mutation` (60/min, dimensionné pour du CRUD) : deux
        // onglets suffisaient à le vider en sondage pur, et le service voyait « trop de requêtes,
        // réessayez plus tard ». 240/min = 20 écrans simultanés sur le même compte — très au-delà
        // de tout usage réel, tout en gardant une borne contre une boucle emballée.
        'print_queue_poll' => max(1, (int) env('POS_RATE_LIMIT_PRINT_QUEUE_POLL', 240)),
        'customer_display' => max(1, (int) env('POS_RATE_LIMIT_CUSTOMER_DISPLAY', 240)),
    ],

    /*
    |--------------------------------------------------------------------------
    | Featured category allowlist — POS first-page filter
    |--------------------------------------------------------------------------
    |
    | [2026-06-04 P-OWNER ALL-CATEGORIES] Owner decision: the POS strip on
    | `/admin/pos` shows ALL categories directly — a single horizontal
    | scrollable row, each with its photo — NOT a curated featured subset.
    | More categories are coming, so no per-slug allowlist to maintain.
    |
    | Mechanism: an EMPTY allowlist = "no filter". The controller
    | (PosCategoryController.php:204-206) marks every category
    | `featured=true` when `$featuredSet === null` (empty resolved IDs),
    | so `displayedCategories` (PosComponent.vue:1940) renders all 11
    | categories and `hasNonFeaturedCategories` becomes false → the
    | "Toutes les catégories" escape-hatch pill auto-hides (no longer
    | needed since nothing is hidden). The CSS strip is already
    | `flex-wrap:nowrap; overflow-x:auto` (single horizontal scrollable
    | row) and each category resolves a `thumb` photo via the
    | `cat-<slug>.png` fallback in config/menu_images.php.
    |
    | --- HISTORY (superseded) ------------------------------------------
    | 2026-05-18 → 2026-05-20: a curated best-seller subset (8 slugs) was
    | the landing default; categories outside it were reachable via search
    | or the "Toutes" pill. The slug-based source of truth (stable across
    | reseeds, vs the original env-dependent raw integer IDs) is retained:
    | the controller resolves slugs → IDs via the `item_categories` table
    | at request time. To re-enable a curated subset, set a non-empty
    | env CSV:  POS_FEATURED_CATEGORY_SLUGS=sandwich-cayenne,burgers,tacos
    | -------------------------------------------------------------------
    |
    | Default = EMPTY → all categories shown (single row, all photos).
    |
    | Backward compatibility — `featured_category_ids` is preserved as a
    | secondary knob for ops that still ship integer-IDs via env
    | `POS_FEATURED_CATEGORY_IDS`. The controller honors slugs FIRST and
    | falls back to IDs when slugs resolve to nothing (e.g. dev fixtures
    | without seeded slugs).
    */
    'featured_category_slugs' => array_values(array_filter(array_map(
        static fn (string $s): string => trim($s),
        explode(',', (string) env(
            'POS_FEATURED_CATEGORY_SLUGS',
            '',
        )),
    ))),

    'featured_category_ids' => array_values(array_filter(array_map(
        'intval',
        explode(',', (string) env('POS_FEATURED_CATEGORY_IDS', '')),
    ))),

    /*
    |--------------------------------------------------------------------------
    | Wave S-1 — auto-transition ACCEPT → PREPARING on payment confirmed
    |--------------------------------------------------------------------------
    |
    | Owner decision 2026-05-20 (P-OWNER Wave S-1):
    | When a paid order lands in ACCEPT (CONFIRMÉE), advance it immediately
    | to PREPARING (EN PRÉPARATION) so the kitchen sees it as "en cours"
    | without a second tap from the cashier.
    |
    | Exception (Wave S-5 sister mission): kiosk orders that the customer
    | chose to pay in CASH at the counter (`PosPaymentMethod::CASH` on
    | `confirmCounterPayment`) must stay in ACCEPT until the cashier
    | explicitly validates the cash collection through the S-5 cash-pending
    | UI. The policy class encapsulates this exception
    | ({@see \App\Domain\Order\AutoPrepareOnPaidPolicy}).
    |
    | Default = true (production). Set POS_AUTO_PREPARE_ON_PAID=false in env
    | for emergency rollback to the legacy "stay in ACCEPT" behaviour without
    | redeploying code. The KDS auto-transition watcher
    | (`KdsV2Grid.vue::autoTransitionEnabled`) defaults to FALSE post Wave
    | Q-2, so this backend hook is now the single source of truth for the
    | transition — no double-fire from the frontend.
    */
    'auto_prepare_on_paid' => filter_var(
        env('POS_AUTO_PREPARE_ON_PAID', true),
        FILTER_VALIDATE_BOOLEAN,
        FILTER_NULL_ON_FAILURE,
    ) ?? true,

    /*
    |--------------------------------------------------------------------------
    | Manual POS discount (GOAL-GOLIVE-VAT10 / F1-dormancy 2026-05-30)
    |--------------------------------------------------------------------------
    |
    | DEFAULT FALSE for V1. At a non-zero VAT rate (10% TTC) the discount→HT/TVA
    | split in the FROZEN ZReportService/PricingService is wrong (TVA on the
    | PRE-discount base) — the dormant-at-0%-VAT "F1" defect. A discounted order
    | would sign a fiscally-incorrect NF525 Z. Until F1 is fixed under a
    | lock-plan, OrderService::assertPosManualDiscountAllowed refuses any
    | non-zero manual discount. Non-discounted orders decompose correctly.
    | Re-enable (POS_MANUAL_DISCOUNT_ENABLED=true) ONLY after F1 is fixed + a
    | behavioral Z test proves discounted TVA is computed on the NET base.
    */
    // [GOAL-GOLIVE-VAT10 / F1-fix-r2 2026-05-31] Default flipped false → true.
    // F1 (fiscal-incorrect Z on a discounted order at non-zero VAT) is FIXED in
    // ZReportService under LOCK_ZREPORT_F1_DISCOUNT_NETTING_2026_05_31.md and
    // proven E2E by ZReportDiscountNettingTest::test_discounted_z_close_signs_and_chain_verifies
    // (signed Z verifySignature ✓ + verifyChain.valid ✓ + EXACT identity
    // total_tva == Σ total_by_tax_rate on a real discounted Z). Reactivation per
    // owner AskUserQuestion. The flag remains a runtime KILL-SWITCH: setting
    // POS_MANUAL_DISCOUNT_ENABLED=false in .env re-engages every dormancy gate
    // (refusing non-zero discounts at every order-creation chokepoint, hiding the
    // kiosk loyalty button + web coupon entry, refusing the pre-redeem at source).
    // The kill-switch path is locked by the *_killswitch_* sentinels.
    // [OWNER 2026-07-18] « Coupe les remises ! » → défaut RE-flippé à false
    // (kill-switch réengagé). Refuse toute remise DISCRÉTIONNAIRE non-nulle
    // (remise manuelle caisse + coupon) aux chokepoints de création + masque
    // l'entrée coupon web. Réactivation = owner + .env POS_MANUAL_DISCOUNT_ENABLED=true.
    //
    // [OWNER 2026-07-18 — DÉCOUPLAGE FIDÉLITÉ] « garde la fidélité ». Ce
    // kill-switch ne pilote PLUS la fidélité. accrual (gain de points) /
    // affichage solde / QR / scan / bouton « Mon compte » borne = TOUJOURS
    // actifs (jamais gatés ici — l'accrual n'applique aucune réduction, 0 risque
    // fiscal). Le REDEEM de points (dépense → réduction) est déplacé sur son
    // propre flag `loyalty_enabled` ci-dessous. F1 (netting TVA du Z sur base
    // remisée) est FIXÉ + prouvé (ZReportDiscountNettingTest 5/5, incl.
    // close()+sign()+verifyChain() sur un Z réellement remisé) → un ordre remisé
    // fidélité signe un Z fiscalement CORRECT. Couper les remises manuelles ne
    // coupe donc plus la fidélité.
    'manual_discount_enabled' => filter_var(
        env('POS_MANUAL_DISCOUNT_ENABLED', false),
        FILTER_VALIDATE_BOOLEAN,
        FILTER_NULL_ON_FAILURE,
    ) ?? false,

    /*
    |--------------------------------------------------------------------------
    | Loyalty program master switch (DÉCOUPLAGE FIDÉLITÉ 2026-07-18)
    |--------------------------------------------------------------------------
    |
    | Owner 2026-07-18 : « coupe les remises MAIS garde la fidélité ». Le
    | kill-switch unique `manual_discount_enabled` coupait AUSSI la fidélité — un
    | seul chokepoint gatait remise manuelle + coupon + redeem fidélité ensemble.
    | Ce flag SÉPARE la fidélité des remises discrétionnaires :
    |
    |   - accrual / affichage solde / QR / scan / config / bouton « Mon compte »
    |     borne : TOUJOURS actifs — JAMAIS gatés par un flag remise (l'accrual
    |     n'applique aucune réduction → 0 risque fiscal).
    |   - REDEEM de points (dépense → réduction) : gaté ICI (loyalty_enabled).
    |
    | Redeem = famille F1 (réduction → split TVA sur base remisée). F1 est FIXÉ
    | + prouvé dans le ZReportService frozen (netting TVA sur base NET, ratio =
    | (subtotal-discount)/subtotal), attesté par ZReportDiscountNettingTest (5/5,
    | incl. close()+sign()+verifyChain() sur un Z réellement remisé). Un ordre
    | remisé fidélité signe donc un Z fiscalement CORRECT → redeem fiscalement
    | SÛR. Défaut = true.
    |
    | Kill-switch fidélité : POS_LOYALTY_ENABLED=false désactive UNIQUEMENT le
    | redeem (accrual/solde restent actifs), sans réactiver les remises manuelles.
    | Indépendant de manual_discount_enabled dans les deux sens.
    |
    | Note surface borne : l'UI de redeem borne reste derrière le flag DÉDIÉ
    | `kiosk.promo_enabled` (défaut false) tant que le câblage coupon_id borne
    | n'est pas réparé (défaut de wiring séparé, hors périmètre). Le redeem
    | fidélité utilisable en V1 = caisse (PosRedemptionService) + API.
    */
    'loyalty_enabled' => filter_var(
        env('POS_LOYALTY_ENABLED', true),
        FILTER_VALIDATE_BOOLEAN,
        FILTER_NULL_ON_FAILURE,
    ) ?? true,

    /*
    |--------------------------------------------------------------------------
    | Codes promo — interrupteur DÉDIÉ (FLYER PROMO 2026-08-07)
    |--------------------------------------------------------------------------
    |
    | Même raisonnement que le découplage fidélité juste au-dessus, appliqué
    | aux CODES PROMO.
    |
    | Constat qui a motivé ce flag : `manual_discount_enabled` (défaut false)
    | gatait ensemble deux choses de nature très différentes —
    |
    |   1. la REMISE MANUELLE en caisse : un caissier saisit un montant
    |      arbitraire. Risque commercial et de traçabilité réel, d'où le
    |      défaut fermé.
    |   2. le CODE PROMO : un coupon créé à l'avance, avec son montant, ses
    |      dates, ses surfaces et son plafond d'utilisations. Rien d'arbitraire
    |      — c'est une décision commerciale déjà prise et enregistrée.
    |
    | Les confondre obligeait à ouvrir (1) pour obtenir (2). L'exploitant veut
    | distribuer des codes nominatifs à usage unique sur ticket, sans autoriser
    | pour autant les remises libres au comptoir. Ce flag rend ça possible.
    |
    | ⚠️ EFFET RÉEL, corrigé le 2026-08-28 après vérification ligne à ligne.
    |
    | Ce paragraphe promettait : « quand il vaut true, le pré-contrôle ET
    | L'APPLICATION d'un coupon sont autorisés, que les remises manuelles soient
    | coupées ou non ». **La seconde moitié est fausse**, et l'était depuis
    | l'écriture du drapeau.
    |
    | Ce que le drapeau commande VRAIMENT (4 lecteurs, tous en amont) :
    |   · `CouponController:55`      — le pré-contrôle public d'un code
    |   · `FrontendOrderService:1099` — la surface borne/web
    |   · `WheelService:813`          — l'émission d'un coupon par la roue
    |   · `PromoFlyerController:83`   — le ticket promo nominatif
    |
    | Ce qu'il ne commande PAS : l'application en caisse.
    | `OrderService::assertDiscretionaryDiscountAllowed` (7 sites d'appel, ligne
    | 3700) ne lit QUE `pos.manual_discount_enabled`. Le drapeau dédié
    | n'apparaît nulle part dans `OrderService.php` — vérifié par grep.
    |
    | Conséquence pour l'exploitant : mettre ce drapeau à true SANS ouvrir les
    | remises manuelles ne débloque pas l'encaissement d'un coupon. Le client
    | voit son code accepté au pré-contrôle, puis refusé au paiement.
    |
    | Ce n'est pas un défaut du garde. `plans/LOCK_ZREPORT_F1_DISCOUNT_NETTING_2026_05_31.md
    | §7` désigne explicitement `manual_discount_enabled` comme l'interrupteur
    | UNIQUE d'activation des remises. Le garde applique la décision ; c'est ce
    | commentaire qui promettait autre chose.
    |
    | Le découplage réel — faire lire ce drapeau par la caisse — change ce qu'un
    | interrupteur d'arrêt protège. C'est une décision propriétaire :
    | `docs/gates/GATE_COUPONS_EN_CAISSE_2026-08-28.md`.
    |
    | Fiscalement : le défaut F1 invoqué par le docblock du garde
    | (`OrderService:3715-3722`, daté du 2026-05-30) a été **corrigé le
    | lendemain** sous clé propriétaire, et `ZReportDiscountNettingTest` le
    | prouve (5 verts). L'obstacle fiscal n'existe plus ; il ne reste que la
    | décision commerciale.
    */
    'coupon_codes_enabled' => filter_var(
        env('POS_COUPON_CODES_ENABLED', false),
        FILTER_VALIDATE_BOOLEAN,
        FILTER_NULL_ON_FAILURE,
    ) ?? false,

    /*
    |--------------------------------------------------------------------------
    | Walk-in route to counter (GOAL-CAISSE-UNIFIED delta-(B) 2026-05-30)
    |--------------------------------------------------------------------------
    |
    | Owner model (B): route POS walk-in orders through the SAME deferred
    | counter-collection queue as the Borne (kiosk Plan B), so EVERY payment —
    | Borne + Caisse, espèces + carte — is collected from the unified
    | /admin/encaissement page. Symmetric to kiosk.payment_route_all_to_counter.
    |
    | When TRUE, OrderService::posOrderStore creates the walk-in order as
    | PENDING_COUNTER + COUNTER_DEFERRED (payment_method=CASH_ON_DELIVERY marker,
    | kitchen prepares before pay per W-D1) and DEFERS the fiscal_sequence_no
    | allocation to collection time (PaymentService::confirmCounterPayment),
    | exactly like the kiosk cash-at-counter path. NF525 stays gap-free: the
    | seq is allocated once, at collection, never at this deferred creation.
    |
    | DEFAULT = false — preserves the current inline-paid-at-creation POS flow.
    | Activation is an OWNER GATE (it changes the protected POS checkout UX:
    | the cashier collects later instead of inline). A per-request
    | `defer_to_counter=true` flag also triggers the deferred path so a
    | future non-frozen PosComponent control can opt in per order without a
    | global flip.
    */
    'walkin_route_to_counter' => filter_var(
        env('POS_WALKIN_ROUTE_TO_COUNTER', false),
        FILTER_VALIDATE_BOOLEAN,
        FILTER_NULL_ON_FAILURE,
    ) ?? false,

    /*
    |--------------------------------------------------------------------------
    | ANCIENNETÉ AU-DELÀ DE LAQUELLE UNE SESSION DE CAISSE SE SIGNALE
    |--------------------------------------------------------------------------
    | En HEURES. Le défaut (24) vaut une journée de service : une caisse qui a passé la nuit
    | n'a pas été comptée, et c'est déjà le fait qu'on veut voir.
    |
    | Réglable parce que tous les commerces n'ont pas le même rythme — une brasserie de nuit
    | n'a pas la journée d'un midi. Ce n'est PAS une garde de sécurité : ça n'empêche rien,
    | ça rend seulement visible. Mesuré en production le 2026-08-13 : deux sessions ouvertes
    | depuis 49 et 36 jours, sans que rien ne le signale.
    */
    'cash_session_stale_hours' => (int) env('POS_CASH_SESSION_STALE_HOURS', 24),

];

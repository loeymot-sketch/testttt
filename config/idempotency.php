<?php

/**
 * F-VERIFY-09-02 — HTTP-level idempotency configuration.
 *
 * `enabled`             : master flag, default OFF for safe roll-out (per PLAN_P11 §2).
 * `ttl_seconds`         : how long a COMPLETED replay record is kept (24h default).
 * `pending_ttl_seconds` : how long a PENDING placeholder lives before self-
 *                         expiring (30s default). Decoupled from `ttl_seconds`
 *                         so SIGKILL/server-restart between Phase-2 acquire()
 *                         and Phase-3 release() does NOT trap the key for 24h.
 *                         Source: Phase F.6 audit finding F-6-6-FIND-04 P2.
 *                         Trade-off: with 30s, a >30s slow request can be
 *                         re-acquired during execution; DB UNIQUE backstop
 *                         (PLAN_P11 §1.2) remains the safety net, not this TTL.
 * `race_wait_ms`        : time to wait for an in-flight twin request to publish
 *                         its COMPLETED record before returning 425.
 * `fail_open`           : when true, missing/unhealthy storage falls back to the
 *                         app-layer UNIQUE backstop instead of returning 503.
 * `required_routes`     : opt-in list. Routes outside this list are *not*
 *                         rejected when the header is missing — backwards-compat
 *                         with existing kiosk/mobile clients.
 * `cache_store`         : null = `cache.default` (`array` in tests, `redis` in prod).
 *                         Override only in unusual deployments.
 */

return [
    'enabled'             => env('IDEMPOTENCY_MIDDLEWARE_ENABLED', false),
    'ttl_seconds'         => (int) env('IDEMPOTENCY_TTL_SECONDS', 86400),
    'pending_ttl_seconds' => (int) env('IDEMPOTENCY_PENDING_TTL_SECONDS', 30),
    'race_wait_ms'        => (int) env('IDEMPOTENCY_RACE_WAIT_MS', 1500),
    'fail_open'           => (bool) env('IDEMPOTENCY_FAIL_OPEN', false),

    'required_routes' => [
        // [FIDÉLITÉ COMPTOIR 2026-08-12] Les deux écritures de fidélité au comptoir. Elles portaient
        // déjà l'intergiciel `idempotency`, mais SANS figurer ici la clé restait FACULTATIVE : un
        // client qui l'oublie passait quand même, et un double appui créait un second compte ou
        // relançait le crédit. Le crochet et l'exigence sont deux choses distinctes — la sentinelle
        // `IdempotencyRequiredRoutesCoverageTest` existe précisément pour attraper cet écart, et
        // c'est elle qui l'a attrapé.
        'api/admin/pos-loyalty/customers',
        'api/admin/pos-order/*/attach-loyalty',
        // [ONB-13 T-3.1.1 2026-08-27] Les DEUX routes qui ecrivent reellement les points
        // manquaient a l'appel du 12/08 : celui-ci a ajoute `customers` et `attach-loyalty`
        // et oublie `credit-manual` et `deduct-manual`. Meme famille, meme risque, oubliees.
        // Elles portaient bien l'intergiciel, mais sans figurer ici la cle restait
        // FACULTATIVE : un double appui creditait ou debitait deux fois. La sentinelle
        // IdempotencyRequiredRoutesCoverageTest etait ROUGE — verifie en la lancant, pas
        // en la supposant. La modale POS envoie deja X-Idempotency-Key sur les deux :
        // rendre l'exigence obligatoire ne casse aucun appelant existant.
        'api/admin/pos-loyalty/credit-manual',
        'api/admin/pos-loyalty/deduct-manual',
        // Meme situation pour l'ajustement de matiere premiere : un rejeu doublait
        // l'ajustement de stock. RawMaterialAdjustComponent envoie deja l'en-tete.
        'api/admin/raw-materials/*/adjust',
        'api/admin/pos',
        'api/admin/pos-order/change-payment-status/*',
        'api/admin/pos-order/select-delivery-boy/*',
        'api/admin/online-order/change-payment-status/*',
        'api/admin/online-order/select-delivery-boy/*',
        'api/admin/table-order/change-payment-status/*',
        'api/frontend/order',
        'api/frontend/order/*/payment-confirm',
        // [P1-4 SÉCU 2026-08-04] mollie-checkout portait le middleware `idempotency` (routes/api.php)
        // mais était ABSENT d'ici → un appelant OMETTANT X-Idempotency-Key traversait SANS dédup →
        // avec cardToken la création DU paiement EST l'encaissement → retry sur timeout = 2ᵉ débit
        // réel. Requis ici = la clé devient OBLIGATOIRE (422 si absente), plus de bypass silencieux.
        // Sentinelle IdempotencyRequiredRoutesCoverageTest rouge depuis le 08-03 → verte.
        'api/frontend/order/*/mollie-checkout',
        // [GOAL-CMS-2026-05-18 C-P0-H heal] — close header-omission bypass on
        // every route declared with `idempotency` middleware. Source: R3
        // T-1.4.2 Sec S-1 + sentinel `IdempotencyRequiredRoutesCoverageTest`
        // which surfaced 10 more URIs the R3 finding's literal list missed
        // (different `/pos/` prefix on cash-drawer + 6 change-status flows).
        // Without these entries the middleware silent-passes on missing
        // X-Idempotency-Key → double-execute on retry (double charge,
        // double cash-drawer-open, double order-status-change).
        'api/admin/pos/counter-collect/*/confirm',
        'api/admin/pos/counter-collect/*/cancel',
        'api/admin/pos/collect-kiosk-cash/*',
        // [SEC MISSION-12 2026-07-31] Sortie de stock (repas perso / perte) : décrémente le stock →
        // un rejeu réseau doit être idempotent (sinon double-décrément + double trace). La modale envoie
        // déjà X-Idempotency-Key ; l'ajout ici active l'enforcement + rend verte la sentinelle CI
        // IdempotencyRequiredRoutesCoverageTest (la route portait le middleware sans être couverte).
        'api/admin/pos/stock-outflow',
        'api/admin/pos/orders/*/print-receipt',
        // [ULTRA-AUDIT 2026-07-02] Route print-kitchen porte le middleware `idempotency`
        // (routes/api.php) mais manquait dans required_routes → IdempotencyRequiredRoutesCoverageTest
        // rouge. Même défense-en-profondeur que print-receipt (pas de double impression cuisine au retry).
        'api/admin/pos/orders/*/print-kitchen',
        'api/admin/pos/cash-drawer/open',
        'api/admin/pos/cash-drawer/sessions/open',
        'api/admin/pos/cash-drawer/sessions/*/close',
        'api/admin/pos/cash-drawer/sessions/*/reconcile',
        'api/admin/pos-order/*/refund-with-counter-entry',
        'api/admin/pos-order/change-status/*',
        'api/admin/online-order/change-status/*',
        'api/admin/table-order/change-status/*',
        'api/admin/kds-order/change-status/*',
        // [Audit 2026-05-29 SUP-2 — IdempotencyRequiredRoutesCoverageTest fix]
        // KDS chef "Annuler bump" / recall route (heal-5 / Wave Polish Final
        // 2026-05-21) ships `idempotency` middleware at routes/api.php:1159
        // but was missing from required_routes — sentinel rouge en CI.
        // Identique au pattern change-status/* ci-dessus + même defense-in-depth
        // (idempotency + throttle:kds-bump) au router.
        'api/admin/kds-order/recall/*',
        // [REMETTRE-EN-PRÉPARATION 2026-08-13] Même oubli que `recall/*` juste au-dessus, à cinq
        // ans d'écart : la route est câblée avec l'intergiciel d'idempotence dans routes/api.php
        // mais je ne l'avais pas déclarée ici. La sentinelle l'a attrapée — c'est précisément son
        // travail, et elle avait déjà servi pour `recall`. Sans cette ligne, l'en-tête
        // d'idempotence n'est pas EXIGÉ : un double appui du cuisinier sur « remettre en
        // préparation » passerait deux fois, écrivant deux transitions au registre pour un seul
        // geste.
        'api/admin/kds-order/reopen/*',
        'api/frontend/order/change-status/*',
        'api/frontend/delivery-boy-order/change-status/*',
        // Livreur cash-session routes (new V1.0.2-sub6-3 NF525 cash session
        // foundation, commit 3d5ca01f6 — parallel mission)
        'api/admin/delivery-boy/cash-sessions/open',
        'api/admin/delivery-boy/cash-sessions/*/close',
        'api/admin/delivery-boy/cash-sessions/*/reconcile',
        // [LCS-S-002 / 2026-05-19] Loyalty redeem — mobile sends Idempotency-Key
        // per B-02 spec but server ignored before this commit. Network retry
        // would double-debit loyalty points balance.
        'api/frontend/loyalty/redeem',
        // [ULTRA-AUDIT Wave 2 2026-07-04] Miroir du crédit : /add-points (staff auth:sanctum)
        // doit être idempotent comme /redeem — retry réseau = double-crédit de points sinon.
        'api/frontend/loyalty/add-points',
        // [Wave E-1 / 2026-05-19] POS cashier loyalty redeem at-payment.
        // Route declared `idempotency` middleware in routes/api.php but was
        // missing from required_routes — WE-4 final convergence sentinel
        // IdempotencyRequiredRoutesCoverageTest correctly flagged the gap.
        'api/admin/pos-order/*/redeem-loyalty',
    ],

    'cache_store' => env('IDEMPOTENCY_CACHE_STORE'),
];

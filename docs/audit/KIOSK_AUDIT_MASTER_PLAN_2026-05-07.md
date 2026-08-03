# KIOSK AUDIT MASTER PLAN — 2026-05-07

**Cycle parent** : audit borne kiosk après POS audit complet (cycles 1-7 RESOLVED)
**Demande user (2026-05-06→07)** : "passe à la borne kiosk... ultra audit comme la pos... all access no permission needed... double verification... till all green"
**Périmètre** : borne kiosk uniquement (`/kiosk/*`). KDS = cycle dédié futur.
**Décisions structurelles** :
- KioskWizardComponent V1.x → **NON-PROTÉGÉ** (cf. `feedback_kiosk_wizard_not_protected.md`)
- Wizard POS Vanilla JS → **TOUJOURS protégé** (cf. `feedback_wizard_popup_pos_protected.md`)
- Override frozen-zone V1 → **étendu cycle K** pour FrontendOrderService::finalizePaidKioskOrder si nécessaire (M-08 Option B)

---

## 1. Cartographie consolidée K0 (4 axes parallèles)

### Frontend (24 components + 14 atoms DS)
- 19 routes Vue Router avec guards (requireKioskAuth, requireCart, requireOrderRef, requireConfirmationContext)
- Atoms DS: KsButton, KsCard, KsBadge, KsChip, KsModal, KsStepper, KsPriceLine, KsAllergenBadge, KsFilterChip, KsA11ySettings, KsVirtualKeyboard, KsConsentModal, KsThemeToggle, KsHero
- 8 wizard steps (Menu, Taille, Viande, Sauce, Garnitures, Pain, Suppléments, GenericChoices)
- 23 helpers + 1 service kioskHardware (Electron bridge `window.borne.*`)
- Offline queue: IndexedDB + lock-based sync (LOCK_TTL_MS=60s, MAX_ATTEMPTS=10) + BroadcastChannel multi-tab
- Lockdown policy enforced: lint scripts (`tools/lint/scan_kiosk_bundles.mjs`) + tests (`KioskBundleLockdownTest`)
- A11y AAA + PMR + Virtual keyboard FR/EN/AR + RTL
- V1 Bold design system (`tokens-bold.css` + Fraunces font)

### Backend
- Routes `/api/frontend/*` : order, payment-confirm, menu, promo/validate, loyalty/scan|opt-in, upsell, kiosk-event, pricing/preview
- KioskMachineLoginController + Sanctum ability `kiosk:order` + token expiration 480min
- FrontendOrderService::finalizePaidKioskOrder() — **FROZEN M-08 Option B** : NO fiscal_sequence_no allocation côté kiosk
- Channel auth `branch.{branchId}` ability check (cycle K0 a fixé cycle 7A : KioskMachine pivot resolve)
- Rate limiters : kiosk-orders 5/min, kiosk-menu 60/min, kiosk-event 30/min
- Idempotency middleware (cycle 7A) actif sur `/api/frontend/order` + `/payment-confirm`
- Loyalty + Upsell endpoints **déjà existants** (préparation futur app mobile)
- Hardware events log channel séparé (whitelist 83 types, RGPD PII guard)

### Sync POS-kiosk-central (6 events Outbox)
- `OrderCreated` (kiosk submit)
- `ItemAvailabilityChanged` (admin rupture → kiosk Echo subscribe)
- `CouponChanged` (cycle 6 — **GAP : kiosk N'EST PAS subscribed**)
- `OrderStatusChanged`
- `OrderPaidAtCounter` (POS counter-collect confirm)
- `OrderPaymentStatusChanged` (cycle 7B)
- KioskAppComponent subscribe via `onEvents(branchId, [ItemAvailabilityChanged, CatalogChanged, ComposerProfileChanged])` — **MANQUE CouponChanged**
- Fallback TTL cache menu 60s si Echo absent

### Tests existants
- 65 JS unit + 35 Feature PHP + 8 E2E + 4 sentinels = ~110+ files / ~10,100 LOC
- Bien couvert : happy-path, offline queue, loyalty consent, lockdown, error routing, black-screen SPA guard, quote integrity
- Trous critiques (8) : split payment kiosk, refund, sync codes promo cycle 6 côté kiosk, group order, delivery scheduling, rate limiter recovery, locale switching, printer fault recovery

---

## 2. Risques consolidés (top P0/P1)

| # | Risque | Source | Sévérité | Cycle cible |
|---|---|---|---|---|
| KR1 | **Fiscal sequence gap kiosk direct TPE** (`fiscal_sequence_no=null` si pas POS-collected) | Backend R3 + Sync R1 | 🔴 CRITIQUE | K6 (plan ou impl) |
| KR2 | **CouponChanged Echo subscription manquante** côté kiosk (cycle 6 gap) | Sync R2 | 🟠 HAUT | K3 ou K6 (impl directe) |
| KR3 | Branch privilege escalation channel auth si KioskMachine.branch_id=0 | Backend R2 | 🟠 HAUT | K2 (test sentinel) |
| KR4 | Idempotency cache no Redis fallback prod | Backend R6 | 🟠 HAUT | K6 (config doc) |
| KR5 | Offline queue idempotency IndexedDB quota + lock TTL | Frontend P0-1 | 🟠 HAUT | K5 (test extend) |
| KR6 | Auto-return 30s post-confirmation broken si timer cassé | Frontend P0-3 | 🟠 HAUT | K5 (test extend) |
| KR7 | Idle timer + inactivity modal "Toujours là?" | Frontend P1-5 | 🟡 MOYEN | K5 |
| KR8 | Wizard allergen badge EAA 2025 | Frontend P1-6 | 🟡 MOYEN | K3 |
| KR9 | Design tokens-bold.css overrides accidentels | Frontend P2-7 | 🟡 MOYEN | K2 |
| KR10 | Menu catalog stale detection + markStaleItems | Frontend P2-8 | 🟡 MOYEN | K3 |
| KR11 | Pusher outage outbox dead-letter retry | Sync R6 | 🟡 MOYEN | K6 |
| KR12 | RTL/i18n locale switching pendant order | Tests trou #7 | 🟢 BAS | K6 ou backlog |
| KR13 | Printer fault recovery hardware | Tests trou #8 | 🟢 BAS | K6 ou backlog |
| KR14 | Refund kiosk + split payment kiosk | Tests trous #1+#2 | 🟡 MOYEN (V2) | Backlog cycle 8 |

---

## 3. Plan d'exécution K2-K6

### K2 — Surface + design + a11y axe + responsive
- Spec Playwright `tests/e2e/audit-kiosk-cycle2-2026-05-07.spec.js`
- Login KioskMachine → capture surface fullscreen (1080x1920 + 1920x1080)
- A11y axe-core sur idle/categories/wizard/cart/payment/confirmation
- 0 JS errors, 0 network 4xx/5xx
- Validation tokens (warm + bold) chargés runtime
- Sentinel branch isolation channel auth (KR3)
- Cible : 10+ Playwright PASS, 0 régression sur kioskA11y* tests existants

### K3 — Catalogue + wizard + cart + sync codes promo
- Spec extend cycle 2 ou nouvelle `audit-kiosk-cycle3-2026-05-07.spec.js`
- Browse catégories tactile, sélection produit
- Wizard kiosk (NON-PROTÉGÉ) flow complet : Menu → Taille → Viande → Sauce → Garnitures → Pain → Suppléments → AddToCart
- Cart edit (qty, suppr, retour wizard)
- Allergen badge EAA 2025 visible mid-wizard (KR8)
- Menu catalog stale detection (KR10)
- **Implémenter CouponChanged Echo subscription** (KR2) — extend KioskAppComponent::_subscribeEchoChannel ligne 479+
- Cible : 8+ Playwright PASS + sentinel CouponChanged Echo

### K4 — Paiement TPE + counter-collect + payment-confirm + sync POS
- Flow paiement complet : KioskPaymentComponent → 3 méthodes (CARD, CASH, TR)
- Direct TPE flow → POST /api/frontend/order/{id}/payment-confirm
- Counter-collect flow → POS confirm cash deferred (réutilise sync POS cycle 4)
- Validation idempotency middleware cycle 7A wired sur ces routes
- Sentinel anti-double-spend (transaction_id unique)
- Tests payment_status state machine (cycle 7B applied)
- Cible : 6+ Playwright PASS + 2 sentinels

### K5 — Lockdown + auto-return + offline + erreurs
- Re-validate `kiosk-lockdown.spec.js` existant (5 contracts D-KIOSK-01/02/03)
- `kiosk-spa-black-screen-guard.spec.js` re-run
- `kiosk-post-payment-auto-return.spec.js` extend (KR6)
- Idle timer + inactivity modal (KR7) — test "Toujours là?" 2min + 15s countdown + "Je suis là" reset
- Offline queue stress (KR5) — IndexedDB quota exceeded simulation, lock TTL recovery
- Erreurs : payment-refused, network, menu-unavailable, product-removed
- Cible : 8+ Playwright PASS + 0 régression

### K6 — Synthèse + corrections + plans + commit final
- Consolidation findings K2-K5 dans `KIOSK_AUDIT_FINAL_REPORT_2026-05-07.md`
- Corrections triviales (CouponChanged Echo subscription si pas fait K3)
- **Plan Codex P0 KR1** : `PLAN_K11_KIOSK_FISCAL_AUTO_COLLECT_2026-05-07.md` (fiscal sequence gap kiosk direct TPE — touche FrontendOrderService frozen)
- Documentation Redis fallback config (KR4)
- Sentinel `KioskCouponChangedEchoSentinelTest`
- Commit atomique cycles K complet
- Régression : 0 sur 1503+ tests baseline (post cycle 7D)

---

## 4. Garde-fous

- **NE PAS** activer `KIOSK_USE_POS_WIZARD=true` en V1 (laisse le wizard kiosk natif)
- **NE PAS** modifier `pos-wizard.js` ou `pos-wizard.css` (wizard POS protégé)
- **NE PAS** toucher `PaymentService::confirmCounterPayment` (frozen, déjà couvert cycle 7C)
- **PEUT** modifier `KioskAppComponent::_subscribeEchoChannel` pour ajouter CouponChanged (KR2)
- **PEUT** modifier `KioskWizardComponent` + step components (V1.x non-protégé)
- Plan Codex obligatoire pour `FrontendOrderService::finalizePaidKioskOrder` (frozen M-08 Option B) si KR1 résolu

---

## 5. Captures + livrables attendus

- **Specs** : 4-5 nouvelles `tests/e2e/audit-kiosk-cycle{2,3,4,5}-2026-05-07.spec.js`
- **Sentinels** : KioskCouponChangedEchoSentinel, KioskBranchPrivilegeEscalationSentinel
- **Captures** : `tests/e2e/screenshots/audit-kiosk-2026-05-07/` (1080x1920 + 1920x1080 par étape)
- **Plans Codex** : éventuellement KR1 fiscal auto-collect (frozen-zone)
- **Doc finale** : `docs/audit/KIOSK_AUDIT_FINAL_REPORT_2026-05-07.md`

---

**Statut** : ✅ K0 carto DONE, prêt pour advisor + K2-K6.

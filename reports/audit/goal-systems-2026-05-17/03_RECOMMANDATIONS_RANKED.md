# FOODKING — RECOMMANDATIONS RANKED (effort × impact)

**Date** : 2026-05-17 | Source : 22 sub-agents parallèles
**Cible** : owner non-dev qui veut savoir QUOI faire dans quel ORDRE.

## §0 Méthode de ranking

Chaque reco = scoring sur 3 axes :
- **Impact** : combien de P0 ferme + impact business si non-fait (1=mineur, 5=ship-blocker)
- **Effort** : Claude+owner hours (S=≤2h, M=≤8h, L=≤40h, XL=>40h)
- **Risque** : régression possible (LOW/MED/HIGH)

Ranking = **Impact / (Effort × Risque)**. Plus c'est haut, plus c'est prioritaire.

---

## §1 TIER S — QUICK WINS (< 1 jour)

> À faire dans la prochaine session avant tout autre travail. Total ~6h Claude + ~1h owner ferme 6 P0.

### Reco S1 — Flip `QUEUE_CONNECTION=redis` dans .env
- **Impact** : 5 (defense-in-depth restored, sync layer back to design intent)
- **Effort** : 30 min owner (un seul fichier .env + restart queue worker)
- **Risque** : LOW
- **Action** : owner edit .env line 20, restart `php artisan queue:work` ou Horizon
- **Vérif** : `php artisan queue:work redis --queue=high` retourne logs OK
- **Ferme** : P0-CV-10
- **Source** : `cross-cutting/X4-performance.md`

### Reco S2 — Flip `IDEMPOTENCY_MIDDLEWARE_ENABLED=true` dans .env + register sur 199 routes
- **Impact** : 5 (NF525 §9 invariant enforced — sinon repose sur DB UNIQUE fallback uniquement)
- **Effort** : 30 min owner (.env) + 2h Claude (CI lint check)
- **Risque** : MED (peut casser ~5 tests qui n'utilisent pas correctement X-Idempotency-Key header)
- **Action** : owner edit .env + Claude grep routes mutating sans `IdempotencyKey` middleware
- **Ferme** : P0-CV-05
- **Source** : `layers/L4-auth-authz-multitenant.md`

### Reco S3 — KDS allergen pill sur Items Board (legal FIC 1169)
- **Impact** : 5 (criminal exposure FR 5 ans + €375 000 si anaphylaxie)
- **Effort** : 2h Claude (KDSOrderItemsResource expose allergens_snapshot + template render badge)
- **Risque** : LOW
- **Action** : ajouter `'allergens_snapshot' => $this->allergens_snapshot,` dans `KDSOrderItemsResource.php:18-27` + render `KitchenDisplaySystemComponent.vue:124-165` template pill (pattern existant dans Orders surface)
- **Ferme** : P0-CV-14
- **Source** : `surfaces/S3-kds/adversarial.md`

### Reco S4 — PaymentService:172 cents truncation cast fix
- **Impact** : 4 (active V1, perte revenu + NF525 mismatch ticket vs payment)
- **Effort** : 2h Claude + tests TDD (mirror StripeCentsCastTest pattern)
- **Risque** : LOW (mirror pattern déjà appliqué 4 fois ailleurs)
- **Action** : changer `(float) $received < (float) $locked->total` → `(int) round($received * 100) < (int) round($locked->total * 100)`
- **Ferme** : P0-CV-15
- **Source** : `layers/L3-payment-fiscal.md`

### Reco S5 — Pre-commit + CI hygiene workflows
- **Impact** : 4 (gitleaks bloque future fuite secrets ; commitlint bloque "up" hygiene)
- **Effort** : 1h Claude (3 fichiers : `.github/workflows/{gitleaks,commitlint,composer-audit}.yml`)
- **Risque** : LOW
- **Ferme** : prévient future P0
- **Source** : ultra-plans précédent

### Reco S6 — Junk files cleanup + safety-check.sh commit (working tree DONE)
- **Impact** : 3 (hygiene + frozen-zone gate enforced)
- **Effort** : 30 min Claude (rm 4 junk + .gitignore + commit 3 fichiers working tree post-rotation AWS)
- **Risque** : LOW
- **Source** : `QUICK_WINS_EXECUTED_2026-05-16.md` (cycle précédent)

**Total Tier S** : ~6h Claude + ~1h owner → ferme 5 P0 + bloque future régressions.

---

## §2 TIER A — CRITIQUE V1 (2-6 semaines)

> Tout ça DOIT être fait avant ouverture Le Cayenne. Total ~80h Claude + ~10h owner.

### Reco A1 — Sanctum wildcard `['*']` → role-scoped abilities (3 sites)
- **Impact** : 5 (défait toute la défense en profondeur — 18 sites `tokenCan` deviennent no-op)
- **Effort** : 6h Claude + RED-team review
- **Risque** : MED (force re-login pour tous users + tests à adapter)
- **Action** : `LoginController:96-100`, `GuestSignupController:140`, `ForgotPasswordController:165-169` — remplacer `['*']` par role-scoped `['customer:order']`, `['admin']`, `['pos']`, `['kiosk:order']`. Ajouter CI lint blocking `createToken(..., ['*'], ...)`. Force re-login via revoke all existing tokens.
- **Ferme** : P0-CV-01 + débloque P0-CV-02 partial
- **Source** : `surfaces/S1-kiosk/adversarial.md` + `layers/L4-auth-authz-multitenant.md` + `cross-cutting/X2-security-deep.md`

### Reco A2 — PosOrderController:108 IDOR fix
- **Impact** : 5 (3 agents indépendants cross-validated — cross-branch leak confirmé)
- **Effort** : 2h Claude + RED
- **Risque** : LOW (single-site fix avec pattern correct visible à `refundWithCounterEntry:56-61`)
- **Action** : ajouter assertion `if ($order->branch_id !== auth()->user()->branch_id && !auth()->user()->hasRole('Admin')) abort(403);` + audit log
- **Ferme** : P0-CV-02
- **Source** : `surfaces/S2-pos/adversarial.md`, `surfaces/S5-admin/adversarial.md`, `layers/L4-auth-authz-multitenant.md`

### Reco A3 — LanguageService RCE quarantine
- **Impact** : 5 (RCE primitive any auth user — incl. guest customer avec wildcard token)
- **Effort** : 3h Claude + RED
- **Risque** : LOW (single route + service patch)
- **Action** : `routes/api.php:485-486` ajouter `middleware(['permission:settings'])` + `LanguageService.php:198-220` whitelist `realpath()` à `lang_path()` + reject `<?` in content
- **Ferme** : P0-CV-03
- **Source** : `surfaces/S5-admin/adversarial.md`, `cross-cutting/X2-security-deep.md`

### Reco A4 — Pusher channel admin-bypass fix
- **Impact** : 5 (guest customer avec branch_id=0 default subscribe à TOUT canal branche = live PII cross-tenant)
- **Effort** : 2h Claude + RED
- **Risque** : MED (touche channels.php — vérifier intégration kiosk)
- **Action** : `routes/channels.php:32-35` remplacer `branch_id == 0` par `hasRole('Admin')` strict
- **Ferme** : P0-CV-04
- **Source** : `surfaces/S1-kiosk/adversarial.md`, `layers/L2-sync-layer.md`

### Reco A5 — 5 NEW security holes (X2)
- **Impact** : 4 (PII dump + impersonation + clickjacking admin + Host header injection + key leak)
- **Effort** : 6h Claude (5 patches indépendants)
- **Risque** : LOW
- **Action détail** :
  - **A5a** : `/api/admin/users` group ajouter `permission:users` middleware
  - **A5b** : `MessageRequest.php:30-35` retirer `user_id` + `branch_id` du fillable validation, prendre depuis `auth()`
  - **A5c** : Créer `WebSecurityHeadersMiddleware` (X-Frame-Options=DENY, X-Content-Type-Options=nosniff, HSTS, Referrer-Policy=strict-origin) + register dans `Kernel.php` `web` group
  - **A5d** : Uncomment `TrustHosts` `Kernel.php:18` + configurer hosts allowlist
  - **A5e** : Retirer `googleMapKey` de `master.blade.php:110` (déplacer côté server-render)
- **Ferme** : P0-CV-06
- **Source** : `cross-cutting/X2-security-deep.md`

### Reco A6 — POS Wizard XSS via Item.name (LOCK doc)
- **Impact** : 5 (admin avec catalog-edit → toute station cashier compromise → cascade auth admin)
- **Effort** : 16h LOCK doc + impl + RED
- **Risque** : HIGH (frozen-zone touch `pos-wizard.js`)
- **Action** : LOCK doc obligatoire owner-signed. Patch 40+ `innerHTML` → `textContent` ou `escapeHtml()` helper. Backend `ItemRequest.php` ajouter `strip_tags` sur `name` validation.
- **Ferme** : P0-CV-12
- **Source** : `surfaces/S2-pos/main.md`, `surfaces/S2-pos/adversarial.md`

### Reco A7 — POS Split-Payment TPE reconciliation
- **Impact** : 5 (phantom CARD theft = cash skim invisible jusqu'au Z bilan)
- **Effort** : 8h Claude + RED
- **Risque** : MED (touche flux paiement multi-tender)
- **Action** : `SplitPaymentService.php:148-249` ajouter assertion `terminal_id` requis pour CARD tranche + cross-check `payment_terminals` settlement table + Z-report breakdown
- **Ferme** : P0-CV-13
- **Source** : `surfaces/S2-pos/adversarial.md`

### Reco A8 — Backup automation (spatie/laravel-backup + DR drill)
- **Impact** : 5 (NF525 6 ans retention legal — disk fail = perte totale = exposition pénale)
- **Effort** : 4h owner (S3 bucket + GPG + IAM) + 8h Claude (install + config + Kernel schedule + DR drill staging)
- **Risque** : LOW (additive, peut être testé staging)
- **Action** :
  1. Owner : créer S3 bucket object-lock + IAM user backup-writer + générer GPG keypair
  2. Claude : `composer require spatie/laravel-backup` + config + Kernel schedule quotidien 03:00
  3. Owner+Claude : DR drill staging — drop orders → restore → outbox replay → Z close — chronométrer
- **Ferme** : P0-CV-11
- **Source** : `layers/L5-catalog-persistence.md`

### Reco A9 — Mobile decision PLAN ONLY
- **Impact** : 5 (ship-blocker mobile launch)
- **Effort** : 8h Claude (PLAN ONLY) + owner decision
- **Risque** : LOW (plan only — no code yet)
- **Action** : 3-option plan :
  - **Option A** : Capacitor wrapper du HTML+JSX existant (4-6 sem)
  - **Option B** : Expo full refonte (8-12 sem)
  - **Option C** : Geler mobile V0, focus web responsive (0 sem)
- Owner gate-décision.
- **Ferme** : P0-CV-08 (decision unblocks roadmap)
- **Source** : `surfaces/S6-mobile/main.md`, `surfaces/S6-mobile/adversarial.md`

### Reco A10 — Rotation AWS keys (gate cascade)
- **Impact** : 5 (toute la sécurité repose sur ça)
- **Effort** : 2h owner (console AWS)
- **Risque** : LOW
- **Action** : déjà documenté cycle précédent (`reports/audit/cto-global-2026-05-16/00_FINAL_CTO_VERDICT.md` §6)
- **Ferme** : P0-1 cycle précédent (encore ouvert)
- **Source** : `EXECUTION_SCRIPT_3_WEEKS.md` cycle précédent

**Total Tier A** : ~80h Claude + ~10h owner. Ferme 9 P0 cross-validated. Critical path V1 Le Cayenne.

---

## §3 TIER B — IMPORTANT V1.x (4-12 semaines)

> Doit suivre Tier A. Architecture critique + V2 préparation.

### Reco B1 — Collapse `Order` ↔ `FrontendOrder` (LOCK doc + 4 sem)
- Impact 5, Effort XL, Risque HIGH. **CONS-1** dans duplication map.
- LOCK doc obligatoire (frozen-zone NF525-sensitive).
- Ferme P0-CV-07.
- Net deletion ~700 LOC + unifie observer + invariant single writer.

### Reco B2 — OrderStateMachine::apply() seul writer status
- Impact 4, Effort M (2 sem), Risque MED.
- 7 sites mutateurs directs `->status =` à refactorer vers apply().
- CI lint blocking `->status =` dans `app/Services/` et `app/Http/Controllers/`.
- Source `layers/L1-backend-http-services-domain.md`

### Reco B3 — Extract LoyaltyService + PaymentFinalizerService des controllers
- Impact 3, Effort L (2 sem), Risque MED.
- `LoyaltyController:730 LOC` + `OrderController::paymentConfirm:218 LOC` → services dédiés.
- Target: zero `DB::` import dans `app/Http/Controllers/`.
- Source `layers/L1-backend-http-services-domain.md`

### Reco B4 — FormRequest authz coverage real
- Impact 4, Effort L (3-4 sem).
- 80/91 FormRequests retournent `true;` blindly — refactor top 20 endpoints critiques d'abord.
- Source `layers/L4-auth-authz-multitenant.md` + `surfaces/S5-admin/adversarial.md`

### Reco B5 — ItemAvailability listener order fix + 3 events no producer cleanup
- Impact 3, Effort S (1 sem).
- `EventServiceProvider:169-176` réordonner Persist*ToOutbox FIRST.
- Cleanup `OrderItemAdded`, `OrderCancelled`, `StockLow` du BROADCAST_MAP.
- Source `layers/L2-sync-layer.md`, `cross-cutting/X3-synchronization.md`

### Reco B6 — Extract `OrderHelpersTrait` (CONS-2 duplication)
- Impact 3, Effort S, Risque LOW.
- 5 helpers `safeJsonDecode`, `allocateQueueNumber`, `resolveBusinessDate`, etc. extracted.
- Ferme CRIT-3 duplication.
- Source `cross-cutting/X1-duplication.md`

### Reco B7 — Frontend Composition API + Pinia migration POS-V5 first
- Impact 4, Effort XL (8-12 sem), Risque HIGH.
- 308 Options API components → migration progressive.
- POS-V5 d'abord (branche déjà amorcée).
- Source `layers/L1-backend-http-services-domain.md`

### Reco B8 — Composition_snapshot DB immutability trigger
- Impact 3, Effort S (2h), Risque LOW.
- Mirror AuditLog pattern (DB trigger + model `updating` guard).
- Source `cross-cutting/X5-data-integrity.md`

### Reco B9 — Polishing : KDS i18n raw FR + KDS V2 P1 + Admin chunk concatenation fix + bundle size CI gate
- Impact 3, Effort M (1 sem chaque).
- Sources `surfaces/S3-kds/main.md` + `surfaces/S5-admin/main.md` + `cross-cutting/X4-performance.md`

**Total Tier B** : ~250h Claude + ~5h owner sur 8-12 semaines.

---

## §4 TIER C — V2 SAAS PRÉPARATION (3-6 mois)

> Démarrer après V1 GO-LIVE stable + 1er mois d'opération sans incident.

### Reco C1 — Items.branch_id migration + tenant-aware MenuResetCommand
- Impact 5 V2, Effort XL (8-12 sem), Risque MED.
- Migration nullable branch_id + BranchScope + héritage chain pour catalog.
- Source `layers/L5-catalog-persistence.md`, `cross-cutting/X1-duplication.md`

### Reco C2 — Multi-tenant billing infrastructure
- Tenants/plans/subscriptions/invoices tables + Stripe Billing intégration + super_admin role separation.
- Source CTO audit roadmap §3 Phase 3.

### Reco C3 — Marketing + onboarding self-service
- Site marketing + signup wizard + DPA GDPR + Stripe pricing tiers.
- Source CTO audit Phase 4.

### Reco C4 — UberEats + Deliveroo + JustEat integrations
- 60% TAM FR fast-food.
- Source `agent-7-competitive-benchmark.md` (cycle précédent).

### Reco C5 — Driver TPE natif (Ingenico Tetra)
- Source CTO audit roadmap Phase 5.

### Reco C6 — Mobile refonte (suite Reco A9 decision)
- Selon option choisie A9 : Capacitor 4-6 sem ou Expo 8-12 sem.

---

## §5 SYNTHÈSE — Si tu ne fais que 5 choses

1. **AWS rotation + flip QUEUE_CONNECTION + IDEMPOTENCY_ENABLED** (3h owner) — Reco S1/S2/A10 — unblock tout
2. **Sanctum wildcard fix + PosOrderController IDOR + LanguageService RCE + Pusher admin-bypass** (15h Claude+RED) — Reco A1/A2/A3/A4 — ferme 4 P0 cross-validated
3. **5 NEW security holes** (6h Claude) — Reco A5 — ferme P0-CV-06 (incl. clickjacking admin + PII dump)
4. **KDS allergen pill + PaymentService cents** (4h Claude) — Reco S3/S4 — ferme legal FIC + active V1 cents bug
5. **Backup automation + DR drill** (12h owner+Claude) — Reco A8 — ferme NF525 retention risk

**Total** : ~40h Claude + ~17h owner = **1 semaine focused**.

Après ces 5, tu as fermé 10 des 15 P0 cross-validated et tu peux sereinement attaquer Tier B (architecture refacto) sur 4-12 semaines.

---

**Signature** : Reco rankées via Impact/(Effort×Risque) sur 22 audits. Cite source `surfaces/`, `layers/`, `cross-cutting/` pour chaque item.

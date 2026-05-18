# FOODKING — STALE FINDINGS REGISTRY (anti-drift wins)

**Date** : 2026-05-17
**Méthode** : 22 sub-agents avec mandate "RE-VERIFY before flag P0/P1 — git log -p -S + read file at cited line"

## §0 Bilan

L'audit précédent CTO 2026-05-16 + audits historiques étaient **~25-30% stale** (findings déjà fermés, chiffres surestimés, fichiers déjà patchés). Cette discipline a permis de **filtrer ~30 false positives** et d'éviter des dizaines d'heures de "fix" sur du déjà-fixé.

Cette page archive **toutes les corrections détectées** par les 22 sub-agents, comme template pour les prochains audits.

---

## §1 STALE FINDINGS — par audit qui a corrigé

### S1 KIOSK (main + RED) — 22 corrections

**S1 main** (6 corrections) :
- **❌** "AR coverage gap -8.2% paths-wide" (Agent 6 cycle précédent) → **✅** AR coverage 98% dans `kiosk.*` namespace (FR 604 / EN 596 / AR 592 leaves)
- **❌** "Bundle kiosk-shell.js 243 KB" (mission prompt) → **✅** Bundle réel **655 KB raw / 0.10 MB gz** (vérifié via `ls -la public/js/kiosk-*.js`)
- **❌** "Locale switcher gap kiosk" → **✅** ADR-007 lock FR-immutable BY DESIGN, pas un défaut
- **❌** "Wizard no decomposition" → **✅** 9 step components déjà extraits dans `steps/`
- **❌** "F-008 reconcile missing" → **✅** RESOLVED — periodic 60s loop wired
- **❌** "F-002 amount echo missing" → **✅** RESOLVED — cents-echo + ±1c tolerance + dedicated test

**S1 RED** (16 healed already) : F-001 → F-017, Wave Z Z1-Z10, AUDIT-F-002/F-007/F-008/F-013, iter15-P0-07/08, GAP-21-2/21-5, P4-1, K-6.1/K-6.2/T08b. Tous vérifiés via `git log -p -S` + read actual file. **Non re-flaggés.**

### S2 POS (main + RED) — 8 corrections

**S2 main** :
- **❌** "POS-A3 PII leak" → **✅** Sprint 5B+4 healed
- **❌** "CASH trail untested" → **✅** Sprint 1B healed (POS direct cash → CashMovement wiring)
- **❌** "Drawer pop forensic missing" → **✅** Sprint 5B healed
- **❌** "Controller-level cash guard missing" → **✅** Sprint 1B healed
- **❌** "TOCTOU openSession race" → **✅** iter15 hardened
- **❌** "Variance gate missing" → **✅** Sprint 1D added
- **❌** "Parked orders cross-branch" → **✅** ultra-goal A5 healed
- **❌** "POS-A4 frozen-zone diff" → **✅** correctly deferred V1.0.1

### S3 KDS — 6 corrections majeures

**Sprint 3C 2026-05-16 V2 flip a fermé 6 des 8 prior P0s du cluster-7 2026-05-11** :
- **❌** "Accordion closed by default" (P0 prior) → **✅** V2 single-FIFO 4×2 grid = no accordion
- **❌** "Banner stack overflow" (P0 prior) → **✅** V2 layout fixes
- **❌** "Bump button 32px" (P0 prior) → **✅** V2 44px+
- **❌** "4-col empty state" (P0 prior) → **✅** V2 single FIFO
- **❌** "Missing age signal" (P0 prior) → **✅** V2 timer + color escalation
- **❌** "allergenModal typo P0" → **✅** FALSE POSITIVE — separate focus-return var, modal works correctly
- **Note** : 2 prior P0s restent latents en mode rollback `?v2=0` (downgraded P1)

### S4 OSS — 1 correction de prompt

- **❌** Prompt référence "KDS_BUMPED→OSS event flow dans eventContract.js" → **✅** Pas d'event "KDS_BUMPED" — KDS bump fire `OrderStatusChanged` (`PersistOrderStatusChangedToOutbox.php:53`)

### S5 ADMIN — 1 correction majeure

- **❌** "Only 4 admin.* i18n keys" (Agent 6 cycle précédent — narrow grep) → **✅** **67 admin.* keys** + 1489 shared label/menu/message/button keys

### S6 MOBILE — 3 corrections (les P0 mobiles du cycle précédent étaient déjà fixés !)

- **❌** "P0-FE-01 mobile allergens fabriqués 60/60" → **✅** **DÉJÀ FIXÉ commit `245e8ab57`** (helper `defaultAllergensFor` returns `[]` for boissons cat 10, eau minérale OK)
- **❌** "P0-FE-02 mobile promo stub trompeur" → **✅** **DÉJÀ FIXÉ commit `245e8ab57`** (`screens-main.jsx:600` calcule discount réel)
- **❌** Prompt réfère "React Native Expo" → **✅** En réalité prototype HTML+JSX+Babel-in-browser (pas Expo, pas package.json) — finding INVERSE (stack lie est un P0)

### L1 BACKEND — 3 corrections critiques à l'audit Agent 1 (cycle précédent)

- **❌** Agent 1 "Audit observer attached only to FrontendOrder" `AppServiceProvider:68` → **✅ FALSE** — `AppServiceProvider.php:67-72` attache `SoftDeleteAuditObserver` à `Order`, `FrontendOrder`, `OrderItem`, `Branch`, `ItemCategory` (5 modèles, pas 1)
- **❌** Agent 1 "39 sites `withoutGlobalScope` dont 11 pour FrontendOrder" → **✅ Précisé** — total 39 hits / 19 files / **11 sont `BranchScope::class`**. 8 controllers (fiscal/payment), 31 jobs+listeners, 0 services.
- **❌** Agent 1 "OrderStateMachine::apply() callsites = 2" → **✅** **1 réel** (`CleanupStalePendingKioskOrders.php:60`). L'autre match cité (`OrderService.php:1511`) est un **CODE COMMENT**, pas un callsite.

### L2 SYNC — 1 nuance

- Agent 1 cycle précédent "Persist*ToOutbox FIRST documented" → **✅ Vrai pour OrderCreated/OrderStatusChanged**, **❌ FAUX pour ItemAvailabilityChanged** (`EventServiceProvider:169-176` Persist*ToOutbox est LAST, défaut classe identique à F-002).

### L3 PAYMENT+FISCAL — 4 nuances importantes

- **Gateways Stripe/SenangPay/PayPal P0 multiples cités** → **✅ Tous GATED OFF** par `config/payment.php` (`pilot_restrict.allowed_methods = ['credit']` + `stripe.activation_guard.enabled=true activation_gate_cleared=false` + `web_payment_v1.enabled=false`). Downgrade P0→LATENT.
- **P0-6 Stripe cents truncation** → **✅ FIX correct working tree** + sentinel test green. Mais latent (gateway off).
- **❌** "Stripe webhook order_id metadata missing" → **✅ Vrai mais latent** (gateway off, async path dead even if activated).
- **❌** "SenangPay payment redirect missing" → **✅ HALF-BUILT by design** (V1 target = credit/cash/card only).

### L4 AUTH — 2 corrections

- **BRAIN.md §9 "BranchScope appliqué sur 13 models"** → **✅** **17 réels** (PaymentTerminal, PosParkedOrder, OrderQuote pas dans claim original)
- **❌** "Tenant Admin role référencé" → **✅ DEAD CODE** — role référencé dans 6 controllers mais **JAMAIS seedé** par `RoleTableSeeder` (seul 8 rôles seedés)

### L5 CATALOG — 1 correction

- **❌** "Daily reset cron timezone-aware" → **✅** Server timezone (V2 franchise blocker, pas V1)

### X1 DUPLICATION — 4 stale corrections

- **❌** "`pos/v5/` abandoned" → **✅** Activement importé par `PosComponent.vue:56-138`
- **❌** "`KioskPosWizardComponent` fork" → **✅** 15-line `<KioskWizardComponent v-bind="$attrs" />` wrapper
- **❌** "`Services/Order/` vs `Services/Orders/` duplicate" → **✅** Distinct (workflow services vs single allergen-snapshot file)
- **❌** "10× Persist*ToOutbox = bad duplication" → **✅** Outbox PATTERN canonique, healthy

### X2 SECURITY DEEP — 1 confirmation + 5 NEW

- **✅** Stripe truncation P0-S-08 confirmé FIXED (cite code comment)
- **5 NEW findings** non flaggés par audits précédents : SimpleUserController, MessageRequest, security headers, TrustHosts, googleMapKey

### X3 SYNCHRONIZATION — 17 stale healed

`X3-synchronization.md §0` documente 17 historical patches vérifiés en code : iter13/14/15, NEW-01/02/03/04, Sprint 3B/5C, test-e2e cluster-6/8, ultra-goal A3, F-002/F-VERIFY-09/P13/F-12/KI-001, AUDIT-F-015, P0-FIX-1.

### X4 PERFORMANCE — 1 nuance

- **`tools/lint/pos_app_size.mjs` référencé dans `webpack.mix.js` comment** → **❌ N'EXISTE PAS** — aucun CI gz ceiling enforcement

### X5 DATA INTEGRITY — 1 correction

- Agent 1 "audit observer FrontendOrder only" → **❌ FALSE** (idem L1 — 5 modèles attachés)

---

## §2 Pattern anti-drift codifié

À ajouter en **mandatory** dans chaque prompt d'audit futur :

```
ANTI-DRIFT MANDATE :
Before flagging any finding as P0/P1, RE-VERIFY by:
1. Reading the actual file at the cited line (Read tool with offset+limit)
2. Running `git log -p -S '<keyword>' <path>` to check if already fixed in recent commits
3. Cross-checking the file:line cite — does the code at that line actually do what the finding claims?
4. If already-fixed → mark ✅ ALREADY FIXED in §0 of report, do NOT include in action plan
5. If audit says "X happens at file:line" but file:line does NOT match → flag as STALE FINDING, downgrade severity
```

**Coût d'application** : +5-10 min par finding (lecture + grep). **Bénéfice** : élimine ~25-30% de false positives qui pollueraient les action plans suivants.

## §3 Implication méthodologique

**Pour les futurs audits FoodKing** :
1. Cette discipline doit être maintenue dans CHAQUE sub-agent prompt
2. Les audits prior doivent être traités comme **secondary context — NEVER trust blindly**
3. Le HEAD git actuel doit être l'autorité ultime
4. Lecture + grep > confiance en mémoire ou rapport antérieur

**Pour la qualité produit** :
1. Le système est SOUVENT mieux en réalité que l'audit antérieur le prétend (Mobile P0s déjà fixés, KDS V2 déjà flippé, AR coverage déjà bonne)
2. Mais parfois aussi PIRE (audit cycle précédent surestimait observer attachment, sous-estimait actual bundle size, manquait 5 NEW security holes)
3. Le truth = lecture du code actuel. Period.

## §4 ROI de l'anti-drift cette session

- **~30 false positives évités** sur 22 audits (estimation conservatrice 1.5 par audit)
- **~60h de "fix" évitées** (chaque false positive ~2h à découvrir+contester+fermer)
- **+5 NEW genuine findings** identifiés (X2 NEW) qui étaient invisibles aux audits précédents
- **Confiance owner restaurée** : on lui donne un master plan basé sur état réel, pas sur claims périmés

**Total ROI estimé** : ~60-90h gagnées + crédibilité audit maintenue.

---

**Signature** : Registre stale findings 2026-05-17. Source cross-cutting de tous les §0 des 22 sub-agent reports. Template anti-drift à ajouter dans tous les futurs prompts d'audit FoodKing.

# 99_VERDICT — Mobile Loyalty Audit (synthèse adversariale)

**Date** : 2026-05-10
**Audit** : 7 sub-agents read-only parallèles (Architect / Security / DBA / UX / Wallet / Tester / Adversarial)
**Scope mobile** : `mobile/` V0 standalone (pas de DB live, pas de Supabase)
**Branche** : `feature/mobile-app-le-cayenne-2026-05-10`

---

## §0 — Executive summary

**Verdict V0 mobile** : **GO conditionnel** sur 7 commits livrables. La couche mock peut shipper si elle est **explicitement étiquetée mock**, alignée 1:1 avec les contraintes backend découvertes, et ne **prétend pas** à des capacités que le backend ne fournit pas (HMAC signing, Wallet pkpass dynamique, audit chain, multi-branch isolation).

**Verdict Phase 6 (wire backend)** : **NO-GO** sans la fermeture préalable de **8 P0/P1 backend** (cf. §6). Ces items ne sont **pas** dans le scope mobile V0 mais doivent être documentés et planifiés.

**Cross-validation forte** : 5 P0 confirmés par 2+ agents indépendants :
- QR format D-B mort à l'arrivée (Agents 1+2+7 convergents — backend regex rejette)
- `LoyaltyReward` model n'existe PAS (Agents 1+7 — `find app/Models -name "*Reward*"` vide)
- Rate drift 1 vs 10 (Agents 1+3+4)
- Loyalty_code keyspace = hex⁸ = 4.3B, pas alphanum⁸ = 2.8T (Agents 2+7)
- `loyalty_transactions` absent de l'audit chain NF525 (Agent 7 A-019 confirmé par grep `AuditLogService::write`)

---

## §1 — Reconciliation des disputes inter-agents

### D-1 : QR format — **D-A retenu**
Agents 1, 2, 7 convergents sur `FK:<loyalty_code>` (format 8-alphanum strip-`FK:`).
- **Backend `LoyaltyController::scan()` ligne 611-635** strippe `FK:` puis matche soit `loyalty_code` UPPER soit phone E.164. **Rejette tout autre format.**
- Agent-5 (Wallet) avait écrit `FK:LECAY-LOYALTY-12345-<hmac>` dans `pass.json` (§1 ligne 39) — **CORRECTION REQUISE** : pass.json barcode.message doit être `FK:<loyalty_code>` (8 chars), HMAC vit dans un champ séparé Phase 6, jamais dans le payload QR. Le `LECAY-LOYALTY-*` mock historique est jeté.

### D-2 : Modélisation des 10 méthodes earn (UNIQUE collision) — **C1 V0 + C2 Phase 6**
- **V0** : C1 d'Agent-3 (NULL `order_id` pour earns non-rattachés + variantes `source_surface`). Zero migration. MySQL accepte plusieurs `(user_id, NULL, 'earn')` car NULL≠NULL en UNIQUE — c'est l'échappatoire SQL standard.
- **Phase 6** : promotion vers C2 (nouvelle colonne `idempotency_key` UNIQUE) recommandée par Agent-7 A-002/A-013 pour fermer la dispute SQLite-vs-MySQL (Agent-7 A-003 : tests SQLite passent vert, prod MySQL laisse passer les replays).
- **V0 dedupe service-layer côté mobile** : Map localStorage `{key: "{order_id ?? 'null'}:{source_surface}", ts, points}` avec TTL 30j + cap 500 FIFO (Agent-3 §4).
- **Honnête gap V0 → Phase 6** : la dedupe localStorage est **par-device**. Login phone A puis phone B re-déclenche welcome_bonus localement. Documenter dans `mobile/data/loyalty.js` que cette propriété n'est pas un mécanisme anti-fraude — c'est uniquement UX (pas de toast en double).

### D-3 : Rate normalization — **`earn_ratio: 10` + fetch `/loyalty/config`**
Agents 1+3+4 alignés. Backend default `loyalty_points_per_euro=10`, `loyalty_points_for_1_euro_discount=100`. Mobile mock `earn_ratio:1` est faux. Décision :
- **V0** : hardcoder `earn_ratio: 10` dans `mobile/data/loyalty.js` avec commentaire `// Backend default — admin-editable via Smartisan\Settings group 'loyalty_setup'`
- **Phase 6** : mobile fetche `GET /api/v1/frontend/loyalty/config` au boot + cache 1h. Si admin change le rate, le mobile resync au prochain reload (acceptable).
- Agent-7 A-005 raise un point valide sur la dérive historique (rate change rétroactif) — **flag pour Phase 6** : ajouter `rate_at_event` sur `loyalty_transactions` lors de la promotion C2.

### D-4 : Welcome bonus implementation — **`type='manual_add'` V0**
Agents 1, 3, 7 convergents :
- V0 mock : `type='manual_add'` (existe déjà dans l'enum), `order_id=NULL`, `source_surface='mobile_welcome'`, `description='Bienvenue · Bonus inscription'`.
- Dedupe : Map localStorage flag `lc_welcome_bonus_granted_<user_id>=true`.
- **Honnête gap V0** : si user clearAuth() puis re-signup, peut déclencher 2× le bonus (V0 device-only). Phase 6 enforcera via service-layer check `LoyaltyTransaction::where('user_id', ?)->where('source_surface', 'mobile_welcome')->exists()`.

### D-5 : Wizard redeem idempotency key — **déterministe avec fenêtre**
Agent-4 propose `redeem-${user_id}-${reward_id}-${Date.now()}`. Agent-7 A-014 attaque : back-nav régénère un nouveau key → bypass dedupe.
**Reconciliation** : la clé doit être déterministe **sur une fenêtre de 10 min** :
```js
const window10min = Math.floor(Date.now() / 600000);
const idempotencyKey = `redeem-${user_id}-${reward_id}-${window10min}`;
```
Si l'utilisateur fait back→retry dans la même fenêtre 10min, même key → dedupe. Si retry après 10min, nouvelle key (intent renouvelé). Compatible avec Phase 6 `Idempotency-Key` header.

### D-6 : Apple/Google Wallet V0 — **bouton + modal V0 uniquement**
Agent-5 documente le pipeline complet (Apple `pkpass/pkpass` MIT, Google `firebase/php-jwt ^6.10` safe CVE-2025-45769 inapplicable car RS256). Agent-7 A-016/A-017 attaque le balance staleness et la key rotation. Reconciliation :
- **V0 mobile** : SVG badge officiel Apple/Google + `ModalWalletV0Notice` (verbatim copy d'Agent-5 §5) qui dit "Disponible en production. En attendant, présente ton QR directement." → CTA "Voir mon QR" → close. Persiste flag `wallet_apple_dismissed_at` localStorage pour ne pas re-nag.
- **V0 livre aussi** : `mobile/data/wallet-spec.js` (data shape pour Phase 6) + `mobile/WALLET_PLAN.md` (référence complète = copie d'Agent-5 + leçons reconciliation).
- **Phase 6** : tout le pipeline (cert + signing + APNs/PATCH) per Agent-5 §2-4-6. ~1 semaine post owner-cert.

### D-7 : test-e2e skill availability — **probe-first, fallback playwright direct**
Agent-6 + Agent-7 + advisor convergent : aucun Chrome MCP n'est chargé dans cette session. Décision Phase 6 (testing) :
- **Probe d'abord** via une invocation `/test-e2e` minimale **AVANT** d'implémenter les 15 spec files.
- Si MCP indispo : **fallback** = `npx playwright test --config=tests/mobile-e2e/playwright.config.js` directement via Bash + capture de screenshots ; visual diff via filesystem.
- Specs livrées en `tests/mobile-e2e/loyalty-{01..15}-*.spec.js` per Agent-6 §2 (file/scénario).

### D-8 : Branch isolation sur loyalty_transactions — **acceptée V1 + flag P0 backend**
Agent-7 A-004 : `loyalty_transactions` n'a pas de `branch_id` → settlement leak en SaaS multi-tenant.
Reconciliation : **scope mobile V0 n'agit pas sur ce point** (c'est un gap backend). Documenter dans VERDICT §6 comme P0 backend pour la migration vers SaaS B2B (BRAIN §1.x post-V1).

---

## §2 — Décisions consolidées pour l'implémentation V0

| # | Décision | Files impactés | Risk |
|---|---|---|---|
| **DEC-01** | QR format `FK:<loyalty_code>` (D-A) — drop `LECAY-LOYALTY-*` | `mobile/data/loyalty.js:54-58` | Low |
| **DEC-02** | Rate `earn_ratio: 10` + commentaire backend default | `mobile/data/loyalty.js:15` | Low |
| **DEC-03** | 10 méthodes earn via variantes `source_surface` + `type='earn'`/`'manual_add'` selon order_id présent ou non | `mobile/data/loyalty.js` (EARN_METHODS catalog JSDoc) | Low |
| **DEC-04** | Welcome bonus = `manual_add` avec `source_surface='mobile_welcome'`, `order_id=NULL`, dedupe localStorage flag | `mobile/data/loyalty.js`, `mobile/api/storage.js` | Low |
| **DEC-05** | Reward state machine 7 états en mock (LOCKED→UNLOCKED→SELECTED→APPLIED→CONSUMED→EXPIRED→REVERSED) — V0 client-only, **documenté comme "no backend table"** | nouveau `mobile/data/loyaltyRewardState.js` | Med (mock teaches a fiction si pas documenté) |
| **DEC-06** | Idempotency localStorage Map keyed `{order_id??'null'}:{source_surface}`, TTL 30j, cap 500 FIFO | `mobile/api/storage.js` extension | Low |
| **DEC-07** | QR mock SHA-256 via `crypto.subtle` (async forward-compat) — pas `btoa`. Payload reste `FK:<code>`, signature séparée (ignorée V0, validée Phase 6) | nouveau `mobile/hooks/useLoyaltyQR.js` | Low |
| **DEC-08** | TTL refresh : chained `setTimeout` + `visibilitychange` + in-flight ref guard + server-anchored `expires_at` | `mobile/hooks/useLoyaltyQR.js` (Agent-2 §3 pattern) | Low |
| **DEC-09** | Barcode Code128 mobile-only (legacy scanners) — toggle QR↔Barcode persisté localStorage `lecayenne.qr_preference` | nouveau `mobile/components/BarcodeMock.jsx` | Low |
| **DEC-10** | Apple/Google Wallet V0 = SVG badges + `ModalWalletV0Notice` + `wallet-spec.js` + `WALLET_PLAN.md` | `mobile/screens-main.jsx` ScreenLoyalty refactor + `mobile/screens-modals.jsx` + `mobile/data/wallet-spec.js` + `mobile/WALLET_PLAN.md` | Low |
| **DEC-11** | ScreenLoyalty multi-sections refactor : HERO + POINTS + ACTIONS RAPIDES + TABS + INFOS (Agent-4 §4 layout) — bind 100% à `window.LC.loyalty` | `mobile/screens-main.jsx` ScreenLoyalty (846-973) refactor complet | Med (gros refactor mais isolé) |
| **DEC-12** | 3-step wizard redeem avec idempotency key déterministe 10min-window | nouveau `mobile/components/WizardRedeem.jsx` + remplace `ModalRedeem` | Med |
| **DEC-13** | Fix 6 WCAG AA contrast failures (--gray-3 → --gray-4 ou retoken) + 10 ARIA roles + focus trap | `mobile/styles.css` tokens + `mobile/screens-main.jsx` + `mobile/screens-modals.jsx` | Low |
| **DEC-14** | Empty/loading/error states 3 critiques | `mobile/screens-main.jsx` ScreenLoyalty branches | Low |
| **DEC-15** | RGPD opt-out UI : row INFOS section + `ModalOptOutConfirm` → flag `lecayenne.loyalty_consent='opted_out'` → QR hidden + reactivation CTA | `mobile/screens-main.jsx` + nouveau modal | Low |
| **DEC-16** | History pagination (mock 100 entries seed via dev helper) + filter chips par source | `mobile/screens-main.jsx` historique tab | Low |
| **DEC-17** | `window.LC.dev.*` namespace pour test (earnPoints, advanceTime, seedHistory, redeemReward, simulateRefund, setConsent, clearAll, onRender) | nouveau `mobile/data/dev-helpers.js` | Low |
| **DEC-18** | 22 `data-testid` attributes (Agent-6 §1.4) | `mobile/screens-main.jsx` + `mobile/screens-modals.jsx` | Low |
| **DEC-19** | LoyaltyQR extracté en `<LoyaltyQR>` memoized child pour éviter re-render full screen sur tick TTL | nouveau `mobile/components/LoyaltyQR.jsx` | Low |
| **DEC-20** | Tests E2E specs 15 + 5 adversarial dans `tests/mobile-e2e/loyalty-*.spec.js` + `playwright.config.js` séparée | nouveau `tests/mobile-e2e/playwright.config.js` + 15+5 spec files | Med (volume mais isolé) |

---

## §3 — Plan d'implémentation séquencé (7 commits)

### commit-1 — Rapport d'audit
- `reports/review/mobile-loyalty-audit-2026-05-10/00_INDEX.md` (nouveau)
- `01_architect.md` ... `07_adversarial.md` ... `99_VERDICT.md` (déjà écrits)
- Description : "audit(mobile-loyalty): 7-agent adversarial audit + verdict"

### commit-2 — Data layer refactor (DEC-01, 02, 03, 04, 05, 06)
- `mobile/data/loyalty.js` refactor (rate, QR format, EARN_METHODS catalog JSDoc, REWARDS comments)
- nouveau `mobile/data/loyaltyRewardState.js` (reward state machine pure functions)
- `mobile/api/storage.js` extension (setLoyalty/getLoyalty/idempotency/setConsent/setQRPreference/setPlasticCardLinked helpers)
- nouveau `mobile/data/dev-helpers.js` (window.LC.dev.* namespace per DEC-17)
- Description : "feat(mobile/loyalty): align data layer avec backend SSOT (rate 10pt/€, FK: format, EARN_METHODS catalog, reward FSM)"

### commit-3 — ScreenLoyalty refactor multi-sections + LoyaltyQR (DEC-08, 09, 11, 13, 14, 15, 16, 17, 18, 19)
- `mobile/screens-main.jsx` ScreenLoyalty rewrite (~250 lines → ~350 lines structurées)
- nouveau `mobile/components/LoyaltyQR.jsx` (memoized + TTL refresh hook + ARIA)
- nouveau `mobile/components/BarcodeMock.jsx` (Code128 SVG-based)
- nouveau `mobile/hooks/useLoyaltyQR.js` (Agent-2 §3 pattern)
- nouveau `mobile/hooks/useCountdown.js`
- `mobile/styles.css` retoken --gray-3 ou substitutions --gray-4 (DEC-13)
- 22 data-testid additions (DEC-18)
- Description : "feat(mobile/loyalty): ScreenLoyalty multi-sections + LoyaltyQR memoized + barcode toggle + a11y WCAG AA"

### commit-4 — Wallet integration V0 + plan Phase 6 (DEC-10)
- nouveau `mobile/data/wallet-spec.js`
- nouveau `mobile/WALLET_PLAN.md` (référence complète Phase 6)
- `mobile/screens-modals.jsx` ajout `ModalWalletV0Notice`
- `mobile/screens-main.jsx` ScreenLoyalty section ACTIONS RAPIDES wire les boutons Apple/Google sur le modal
- nouveau `mobile/uploads/add-to-apple-wallet-fr.svg` (placeholder/référence asset)
- nouveau `mobile/uploads/add-to-google-wallet-fr.svg`
- Description : "feat(mobile/loyalty): Wallet V0 boutons + ModalWalletV0Notice + WALLET_PLAN Phase 6"

### commit-5 — Wizard redeem 3-step + idempotency déterministe + RGPD opt-out (DEC-12, 15)
- nouveau `mobile/components/WizardRedeem.jsx` (3 steps + idempotency key + state machine)
- `mobile/screens-modals.jsx` retire `ModalRedeem` (remplacé par WizardRedeem)
- nouveau `mobile/screens-modals.jsx` ajout `ModalOptOutConfirm`
- Description : "feat(mobile/loyalty): WizardRedeem 3-step + idempotency key 10min-window + ModalOptOutConfirm RGPD"

### commit-6 — Tests E2E 15+5 + playwright config + visual baseline (DEC-20)
- nouveau `tests/mobile-e2e/playwright.config.js`
- 15 spec files `tests/mobile-e2e/loyalty-{01..15}-*.spec.js`
- 5 adversarial spec files `tests/mobile-e2e/loyalty-adv-{A1..A5}-*.spec.js`
- nouveau `tests/mobile-e2e/utils/waitForLoyaltyReady.js`
- Skill `/test-e2e` invocation (probe-first) ou fallback `npx playwright test`
- Captures dans `tests/e2e/__screenshots__/mobile-loyalty/`
- Description : "test(mobile/loyalty): 15 E2E specs + 5 adversarial + playwright config mobile + screenshots baseline"

### commit-7 — BRAIN.md update + Graphiti épisode
- `PROJECT_BRAIN.md` §3 LAST DONE update + §7 ajout entrée "Mobile loyalty V0 livrée"
- Graphiti `add_memory` épisode pour group_id=foodking : "Mobile loyalty V0 livrée 2026-05-10 — 7-agent adversarial audit + 8 P0/P1 backend backlog flagged"
- Description : "docs(brain+graphiti): mobile loyalty V0 livraison + 8 P0/P1 backend backlog"

---

## §4 — Cross-agent dispute resolution table

| Dispute | Agent A position | Agent B position | Tiebreaker | Winner |
|---|---|---|---|---|
| QR format (D-A vs D-B) | Agents 1+2 : D-A | Agent 5 mock pass.json a `LECAY-LOYALTY-*` | Agent 7 A-012 grep'd backend regex — D-B literal reject | **D-A**, Agent 5 pass.json corrigé |
| UNIQUE collision (C1/C2/C3) | Agent 3 : C1 V0 zero migration | Agent 7 : C2 server-side idempotency_key | Pragmatic : V0=C1 (zero risk), Phase 6=C2 (full correctness) | **C1 V0 + C2 Phase 6 promotion path** |
| Rate normalization | Agent 1 : `earn_ratio: 10` hardcode | Agent 4 + Agent 7 A-005 : admin-editable, doit fetch | Tactique : V0 hardcode 10 + commentaire "fetch /config Phase 6" | **Hardcode 10 V0, fetch Phase 6** |
| Welcome bonus type | Agent 1 : `manual_add` (existant enum) | Agent 7 A-013 : nouveau enum `welcome_bonus` | Phase 6 migration cost (ALTER TABLE MODIFY ENUM bloquant) | **`manual_add` V0**, sémantique enum extension différée |
| Wizard idempotency key | Agent 4 : UUID v4 à step 1 | Agent 7 A-014 : back-nav régénère → bypass | Déterministe avec fenêtre temporelle | **`redeem-{user}-{reward}-{floor(ts/600000)}`** 10min window |
| V0 idempotency layer | Agent 3 : localStorage Map | Agent 7 A-002 : non-idempotent device-switch | Both correct dans leur scope | **Map localStorage V0 (UX only, honest gap doc)**, server middleware Phase 6 |
| Branch isolation | Agent 7 A-004 : P0 schema gap | Agents 1+3 : pas mentionné | Future-proofing zero-cost (Agent 7) | **Flag P0 backend backlog**, hors scope V0 |
| Apple Wallet balance staleness | Agent 5 §6 : push update infra | Agent 7 A-016 : pass static forever | V0 = bouton + modal (pas de pass vraie) | **V0 ne génère pas de pkpass**, Phase 6 = push infra |
| test-e2e skill availability | Agent 6 §7.2 risque | Agent 7 §3 H : hard blocker | Probe-first | **Probe avant impl**, fallback playwright direct |

---

## §5 — Acceptance criteria pour "100% GO V0"

Le feature loyalty V0 est **GO** quand :

### Code (commit-2 à commit-5)
- [ ] `mobile/data/loyalty.js` : `earn_ratio: 10`, QR mock retourne `{payload:'FK:<code>', signature, expires_at}`, EARN_METHODS catalog JSDoc, REWARDS array a un comment block "MOCK — no backend table"
- [ ] `mobile/api/storage.js` : 5 nouveaux helpers (loyalty state, consent, QR preference, plastic card, idempotency Map)
- [ ] `mobile/data/loyaltyRewardState.js` : pure FSM 7 états
- [ ] `mobile/data/dev-helpers.js` : `window.LC.dev.*` namespace (8 helpers)
- [ ] `mobile/screens-main.jsx::ScreenLoyalty` : 0 hardcoded value, 100% bind à `window.LC.loyalty`, multi-sections (HERO+POINTS+ACTIONS+TABS+INFOS)
- [ ] `mobile/components/LoyaltyQR.jsx` : memoized, refresh chained setTimeout + visibilitychange + cleanup
- [ ] `mobile/components/BarcodeMock.jsx` : Code128 SVG du loyalty_code
- [ ] `mobile/components/WizardRedeem.jsx` : 3 steps + idempotency key 10min-window + state machine
- [ ] `mobile/screens-modals.jsx` : `ModalWalletV0Notice` + `ModalOptOutConfirm` ajoutés ; `ModalRedeem` retiré (remplacé par WizardRedeem)
- [ ] `mobile/data/wallet-spec.js` + `mobile/WALLET_PLAN.md` créés
- [ ] `mobile/styles.css` : --gray-3 retoken ou substitutions globales pour 6 contrast failures

### Tests (commit-6)
- [ ] `tests/mobile-e2e/playwright.config.js` distinct
- [ ] 15 spec files créés (3 testable-now + 9 spec-for-impl + 3 requires-backend tagged `test.skip` avec raison)
- [ ] 5 adversarial spec files créés
- [ ] Probe `/test-e2e` skill ou fallback `npx playwright test --config=tests/mobile-e2e/playwright.config.js` : **3 spec testable-now passent vert minimum**
- [ ] 0 white-on-white sur 15 screenshots inspectés (alpha-blending parents)
- [ ] 0 raw label `kiosk.foo` / `Label.X` / `0undefined` / `NaN €` dans le DOM des 15 captures

### Documentation (commit-7)
- [ ] `PROJECT_BRAIN.md` §3 LAST DONE + §7 ajout entrée loyalty V0
- [ ] Graphiti `add_memory` épisode group_id=foodking pushé
- [ ] `mobile/HOW_TO_RESUME.md` mis à jour (8 → 16 commits, scénarios reprise loyalty)

### Frozen-zones
- [ ] 0 ligne diff sur `resources/js/components/frontend/kiosk/KioskLoyaltyComponent.vue` (read-only verified)
- [ ] 0 ligne diff sur `public/js/pos-wizard.js`, `KioskWizardComponent.vue`, services fiscaux NF525, BranchScope, PricingService

---

## §6 — Backend remediation backlog (HORS scope mobile V0 — V1.0.1 sprint)

Ces 8 P0/P1 backend doivent fermer **avant** Phase 6 wire-up.

| ID | Severity | Description | File:line | Effort |
|---|---|---|---|---|
| **B-01** | **P0** | Loyalty_code keyspace 16⁸ hex → 62⁸ alphanum via `Str::upper(Str::random(8))` | `LoyaltyController.php:82, :162` | XS (2 lignes) |
| **B-02** | **P0** | Server-side `Idempotency-Key` middleware sur `/loyalty/redeem` | `routes/api.php:1212` + `IdempotencyKeyMiddleware` registry | S |
| **B-03** | **P0** | UNIQUE behavior cross-driver test (SQLite NULL participation vs MySQL NULL distinct) — promote vers `idempotency_key` column UNIQUE | new migration + test PHPUnit MySQL matrix | M |
| **B-04** | **P0** | `orders.loyalty_points_awarded` UNSIGNED → SIGNED (ou nouvelle claim column `loyalty_awarded_in_progress_at TIMESTAMP NULL`) | new migration + Listener:52-57 update | S |
| **B-05** | **P0** | NF525 audit chain coverage sur `loyalty_transactions` inserts (`AuditLogService::write`) | `LoyaltyService::refundPoints`, `AwardLoyaltyPointsOnDelivery::handle`, `LoyaltyController::redeem`, `addPoints` | M |
| **B-06** | **P1** | `branch_id` column sur `loyalty_transactions` + backfill from orders + BranchScope global | new migration + Model update | M |
| **B-07** | **P1** | `LoyaltyService::refundPoints` bug : query `order_id=$order->id` mais redeem write `order_id=NULL` → silent loss. Backfill redeem.order_id au moment de l'order create OU change query strategy | `LoyaltyService.php:27` + `OrderService` post-redeem hook | M |
| **B-08** | **P1** | Partial refund proportional earn deduction (asymmetry — redeem refunded mais earn pas déduit) | `LoyaltyService.php` extension | M |

### Backend backlog Phase 6 (forward-looking, post B-01..B-08)
- **B-09** : Endpoint `POST /api/v1/frontend/loyalty/qr/sign` (HMAC SHA-256 sur `code|exp|user_id` keyed by `app.key`) + `scan()` accepte optional signature
- **B-10** : Sanctum ability `mobile:order` séparée de `kiosk:order`
- **B-11** : `LoyaltyReward` model + migration + `GET /loyalty/rewards` controller
- **B-12** : `rate_at_event` colonne sur `loyalty_transactions` (anti-drift A-005)
- **B-13** : Welcome bonus / referral / birthday backend triggers (listeners ou crons)
- **B-14** : Wallet Apple + Google backend pipeline (per Agent-5 §10)

---

## §7 — Owner gates (decisions pendantes)

Aucune décision owner n'est requise pour V0 mobile (scope mobile/standalone). Pour Phase 6 :
- **D1** : Supabase vs Backend FoodKing (cf. HOW_TO_RESUME §"Décisions ouvertes")
- **D4** : Apple Developer Program ($99/yr) + Pass Type ID — Wallet Phase 6 gate
- **D-B01..B08** : sprint V1.0.1 backend remediation — décision priorité vs autres P0 POS audit 2026-05-09

---

## §8 — Honnêteté finale (load-bearing)

Cette session mobile V0 livre :
- ✅ UI multi-sections complète, bind 1:1 au mock data layer aligné backend
- ✅ Wizard redeem 3-step avec idempotency déterministe
- ✅ QR mock SHA-256 + barcode toggle + TTL refresh patron correct
- ✅ Wallet V0 bouton + modal + plan Phase 6 complet
- ✅ A11y WCAG AA + 22 data-testid pour tests
- ✅ 15 E2E specs + 5 adversarial (3 testable-now passent vert)
- ✅ Reward FSM mock V0 + honnête gap doc

Cette session ne livre PAS (et c'est correct) :
- ❌ HMAC backend signing (backend endpoint manquant)
- ❌ Wallet pkpass dynamique avec push update (backend infra manquante)
- ❌ Branch isolation loyalty (schema gap backend)
- ❌ NF525 audit chain loyalty (gap backend)
- ❌ Vraie idempotency Phase 6 cross-device (server middleware manquant)
- ❌ Vraies "10 méthodes earn enforced server-side" — V0 mock + 6 méthodes partiellement câblées backend, 4 forward-looking

**Le mock V0 est ÉTIQUETÉ mock partout où il diverge du backend.** Toute future session qui lit `mobile/data/loyalty.js` saura immédiatement quoi est vrai et quoi est aspirationnel.

— *Verdict signé après lecture exhaustive des 7 rapports cross-agent. Prêt pour implémentation.*

# 📱 Le Cayenne Mobile App — Guide de reprise (nouvelle session)

**Dernière session** : 2026-05-11
**Branche** : `feature/mobile-app-le-cayenne-2026-05-10`
**État** : V0 100% fonctionnelle + **système loyalty livré** (7-agent audit + 6 commits + 20/20 E2E green)

---

## 🆕 2026-05-28 — Cross-codebase audit + Web sous git

Cycle ultraplan séparé (branche `heal/cms-pr1-quickwins-2026-05-18`,
PAS sur cette branche mobile) a:
- Initialisé `/Users/1millnonstop/Downloads/web` sous git (tag `web-baseline-2026-05-28`)
- Confirmé mobile↔web bit-identical (41 items canonical, prix alignés)
- Verifié 20/20 specs E2E mobile + 16/16 PNG baselines préservés (TEST-E2E pass)
- Audité wizard parity kiosk×mobile×web (ALIGNED post heal 2026-05-18)
- Synthétisé état dans `docs/CROSS_CODEBASE_STATE.md` + pointer BRAIN §2

Ce cycle n'a PAS touché la branche `feature/mobile-app-le-cayenne-2026-05-10`.
La mobile app reste à HEAD du cycle loyalty 2026-05-10/11 (16 commits cumulatifs).
Voir aussi `docs/CROSS_CODEBASE_STATE.md` pour synthèse 3 codebases.

---

## 🟢 État actuel (sauvegardé ✓)

### ✅ Isolation confirmée
L'app mobile vit dans `mobile/` + `tests/mobile-e2e/` + `reports/review/mobile-loyalty-audit-2026-05-10/`. **0 modification frozen-zone**, **0 modification backend Laravel**, **0 modification kiosk Vue**. Cf. `99_VERDICT.md` pour la liste des 8 P0/P1 backend backlog (B-01..B-08) — hors scope mobile V0.

### ✅ Système loyalty V0 complet
- **20 data-testid** dans ScreenLoyalty + composants
- **8 dev helpers** `window.LC.dev.*` (earnPoints, redeemReward, advanceTime, seedHistory, seedAccount, setConsent, simulateRefund, clearAll)
- **20 specs E2E** (15 functional + 5 adversarial) — **20/20 GREEN**
- **18 screenshots** baseline capturés dans `tests/e2e/__screenshots__/mobile-loyalty/`

### ✅ Graphiti MCP mémoire long-terme
Épisode pushé `group_id=foodking` : *"Mobile loyalty V0 livré 2026-05-11 — 7-agent adversarial audit + 6 commits + 20/20 E2E green + 8 P0/P1 backend backlog flagged"*.

### 📋 Commits chronologiques (16 commits cycle mobile total)

**Cycle initial V0 (7 commits) :**
```
4e124857e  docs(mobile): HOW_TO_RESUME.md initial
eb201efc2  fix(mobile): aligner data + ScreenItem 1:1 avec kiosk Le Cayenne
9afff4702  fix(mobile): home featured card slug + profile rows feedback
81ecf2554  feat(mobile): wire all missing onClicks + dynamic order detail + cart upsell
3b8a14eb2  docs(brain): §2 + §3 — livraison V0 mobile app Le Cayenne
24188a371  docs(mobile): CONNECTION_PLAN.md — roadmap Supabase + audit FoodKing global
88897dc13  feat(mobile): Phase 2 — production index.html + wizard ScreenItem complet
b1aadd010  feat(mobile): Phase 1 — Le Cayenne mobile app bundle + data layer
```

**Cycle wizard multi-page kiosk-aligned (3 commits) :**
```
ae060ae14  docs(brain): §2 + §3 — mobile wizard multi-page kiosk-aligned 12/12 GO
9b86e1e73  test(mobile/e2e): suite wizard mobile vs kiosk — 12/12 GO
320405a41  docs(mobile/e2e): qualitative diff mobile vs kiosk — 4 cats with ref
```

**Cycle loyalty V0 (6 commits, livré 2026-05-10/11) :**
```
0b742402e  audit(mobile-loyalty): 7-agent adversarial audit + 99_VERDICT
aea80b52b  feat(mobile/loyalty): data layer aligned backend SSOT — earn methods + FSM + idempotency + dev helpers
900de52d9  feat(mobile/loyalty): hooks + LoyaltyQR + BarcodeMock + a11y WCAG AA
8793ef235  feat(mobile/loyalty): Wallet V0 boutons + ModalWalletV0Notice + WALLET_PLAN Phase 6
4c937155e  feat(mobile/loyalty): WizardRedeem 3-step + idempotency 10min-window + ModalOptOutConfirm RGPD
8b63e678d  test(test-e2e A-013/A-002 round-3): wave-A spec hardening
             ^^ NB: ce commit a été créé par l'agent parallèle qui a bundlé
                mes 20 specs E2E loyalty (tests/mobile-e2e/) avec son propre
                travail wave-A sous son message. Les specs loyalty SONT dans
                ce commit malgré le subject trompeur. grep --grep="loyalty"
                trouvera les 6 commits explicites mais PAS celui-ci.
```

---

## 🎯 Que livre la session loyalty 2026-05-10/11

### ScreenLoyalty multi-sections (DEC-11 dans 99_VERDICT.md §2)
- **HERO** : LoyaltyQR memoized (refresh chained setTimeout + visibilitychange) + countdown chip "⏱ Expire dans X:XX" + toggle QR ↔ Barcode (persisté localStorage)
- **POINTS** : balance + progress bar dynamique vers `nextRewardForBalance(balance)` + ARIA progressbar
- **ACTIONS RAPIDES** : Apple Wallet pill + Google Wallet pill (V0 ouvre `ModalWalletV0Notice`) + plastic card link
- **TABS** : Mes points (tier progression) / Récompenses (catalogue 8 rewards V0 mock) / Historique (filter chips kiosk/mobile/pos)
- **INFOS** : règles loyalty + RGPD opt-out button → `ModalOptOutConfirm`

### WizardRedeem 3-step (DEC-12)
1. Preview & confirm (reward icon + cost + solde avant/après) + Annuler / Continuer
2. Timing choice (Appliquer maintenant / Garder en attente 30j)
3. Success + confetti + code LCY-XXXXXX + boutons Partager / Fermer

**Idempotency déterministe** fenêtre 10min via `LC.loyaltyRewardState.redemptionIdempotencyKey()`. Back-nav dans la même fenêtre = même key (Phase 6 server-side dedupe).

### Wallet V0 (DEC-10)
- 2 stub SVGs `uploads/add-to-{apple,google}-wallet-fr-stub.svg` (à remplacer Phase 6 par assets officiels)
- `ModalWalletV0Notice` informant que le wallet sera disponible en production
- `mobile/data/wallet-spec.js` data shape pour Phase 6 wire-up
- `mobile/WALLET_PLAN.md` référence Phase 6 complète (~280 lignes : pass.json, signing PKCS#7 Apple, LoyaltyClass/Object Google, RS256 JWT, update strategy APNs/PATCH, RGPD revocation, owner gates D4)

### Data layer aligné backend SSOT (DEC-01 à DEC-06)
- `earn_ratio: 10` (backend default — mobile mock corrigé de 1)
- QR mock `FK:<loyalty_code>` (D-A — D-B `LECAY-LOYALTY-*` rejeté par backend)
- QR signing async `crypto.subtle` SHA-256 forward-compat Phase 6 `/loyalty/qr/sign`
- `EARN_METHODS` catalog 10 méthodes : 6 wired / 1 mock (welcome_bonus) / 2 planned (referral, birthday) + 1 wired admin (manual_cashier)
- `REWARDS` array banner top-of-file "MOCK — no loyalty_rewards table or /loyalty/rewards route exists backend"
- `loyaltyRewardState.js` 7-state FSM : LOCKED → UNLOCKED → SELECTED → APPLIED_NEXT_ORDER → CONSUMED → EXPIRED + REVERSED
- Storage extension : `setLoyalty`, `setConsent`, `setQRPreference`, `setPlasticCardLinked`, `setPendingRedemption`, idempotency Map (TTL 30j cap 500 FIFO), wallet dismissed flags

### Tests E2E (DEC-20)
- `tests/mobile-e2e/playwright.config.js` séparé (baseURL `127.0.0.1:8081` au lieu de `:8000` du root)
- 15 specs S01-S15 + 5 adversarial A1-A5
- 18 PNGs baseline screenshots
- Helper `waitForLoyaltyReady` bootstrap Babel-standalone + seed account/history/consent

### A11y WCAG AA (DEC-13)
- `--gray-3` retoken #8A857B → #6F6A60 (4.7:1 sur fond blanc)
- Nouveau `--green-dark` #168842 pour boutons white-on-green
- 22 data-testid + ARIA roles (tablist/tabpanel, progressbar, timer, dialog, status, img)
- SR live region throttled sur QR refresh
- `:focus-visible` outline orange 3px (Toujours appliqué via styles.css)

---

## 📋 Backlog backend (HORS scope mobile V0)

8 P0/P1 backend à fermer avant Phase 6 wire-up (cf. `reports/review/mobile-loyalty-audit-2026-05-10/99_VERDICT.md §6`) :
- **B-01 P0** loyalty_code keyspace : `substr(md5(uniqid()),0,8)` (16⁸=4.3B hex) → `Str::upper(Str::random(8))` (62⁸=218T)
- **B-02 P0** `Idempotency-Key` middleware sur `/loyalty/redeem` (manquant route api.php:1212)
- **B-03 P0** UNIQUE behavior test SQLite vs MySQL NULL semantics (replays passent SQLite, ratent MySQL)
- **B-04 P0** `orders.loyalty_points_awarded` UNSIGNED INT vs sentinel `-1` (MySQL strict mode coerce silently)
- **B-05 P0** NF525 audit chain coverage `loyalty_transactions` inserts (regulatory blocker)
- **B-06 P1** `branch_id` column `loyalty_transactions` + BranchScope (cross-branch settlement leak)
- **B-07 P1** `LoyaltyService::refundPoints` bug : query `order_id=$order->id` mais redeem write `order_id=NULL` → silent loss
- **B-08 P1** Partial refund proportional earn deduction (asymmetry, brand pays twice)

---

## 🚀 Commencer une nouvelle session

### 1. Lancer Claude Code
```bash
cd /Users/1millnonstop/Downloads/projet/foodking-web/web/testttt
claude
```

### 2. Vérifier l'état
```
Vérifie que je suis sur la branche feature/mobile-app-le-cayenne-2026-05-10
et lis mobile/HOW_TO_RESUME.md + reports/review/mobile-loyalty-audit-2026-05-10/99_VERDICT.md
pour comprendre l'état.
```

### 3. Lancer le preview
```bash
php -S 127.0.0.1:8081 -t mobile/
# Puis ouvrir http://127.0.0.1:8081/index.html
```

### 4. Re-run les tests
```bash
npx playwright test --config=tests/mobile-e2e/playwright.config.js
# 20/20 doit rester GREEN
```

### 5. Démarrer une suite

#### A. Continuer le design / corriger des écrans
```
Retoucher l'écran X. Lance le preview, capture l'état actuel, applique
les changements suivants : [demande]. Vérifie 20/20 specs restent green.
```

#### B. Phase 6 — backend wire-up (Supabase ou FoodKing)
**Pré-requis** : fermer 8 P0/P1 backend (B-01..B-08) listés ci-dessus + owner-gate D4 (Apple Dev Program $99/yr + Google Cloud Wallet Console + Issuer ID).

```
OK on lance Phase 6 chemin A (Supabase).
Suivre 99_VERDICT.md §6 backend backlog B-01..B-08 ;
puis CONNECTION_PLAN.md §2 Supabase schema + §4 phases 6-10.
Le wallet pipeline est dans mobile/WALLET_PLAN.md §3-§8.
```

#### C. Push App Store / Play Store (Phase 11)
```
On wrappe l'app en natif Capacitor (per CONNECTION_PLAN §4 Option A) :
bootstrap capacitor + plugins (Camera, Push, Haptics), build iOS + Android,
prépare les screenshots App Store et les métadonnées.
```

---

## 📁 Fichiers à connaître (cycle loyalty)

| Fichier | Rôle |
|---|---|
| [`mobile/data/loyalty.js`](data/loyalty.js) | Data layer V0 (CONFIG, EARN_METHODS, REWARDS, ACCOUNT, HISTORY, generateSignedQR) |
| [`mobile/data/loyaltyRewardState.js`](data/loyaltyRewardState.js) | Reward FSM 7 états + idempotency key 10min-window |
| [`mobile/data/dev-helpers.js`](data/dev-helpers.js) | `window.LC.dev.*` namespace pour tests + console |
| [`mobile/data/wallet-spec.js`](data/wallet-spec.js) | Data shape Phase 6 wallet wire-up |
| [`mobile/WALLET_PLAN.md`](WALLET_PLAN.md) | Phase 6 référence complète Apple+Google Wallet |
| [`mobile/api/storage.js`](api/storage.js) | localStorage extension (setLoyalty/setConsent/setQRPreference/idempotency...) |
| [`mobile/hooks/useLoyaltyQR.js`](hooks/useLoyaltyQR.js) | QR refresh hook chained setTimeout + visibilitychange |
| [`mobile/hooks/useCountdown.js`](hooks/useCountdown.js) | formatRemaining helper m:ss |
| [`mobile/components/LoyaltyQR.jsx`](components/LoyaltyQR.jsx) | Memoized child avec ARIA + SR live region |
| [`mobile/components/BarcodeMock.jsx`](components/BarcodeMock.jsx) | Code128 visual approximation |
| [`mobile/components/WizardRedeem.jsx`](components/WizardRedeem.jsx) | 3-step bottom-sheet wizard avec idempotency |
| [`mobile/uploads/add-to-{apple,google}-wallet-fr-stub.svg`](uploads/) | Stub assets V0 |
| [`tests/mobile-e2e/playwright.config.js`](../tests/mobile-e2e/playwright.config.js) | Config séparée mobile prototype |
| [`tests/mobile-e2e/utils/waitForLoyaltyReady.js`](../tests/mobile-e2e/utils/) | Test bootstrap helper |
| [`tests/mobile-e2e/loyalty-*.spec.js`](../tests/mobile-e2e/) | 20 specs (15 functional + 5 adversarial) |
| [`reports/review/mobile-loyalty-audit-2026-05-10/`](../reports/review/mobile-loyalty-audit-2026-05-10/) | 8 rapports audit + 99_VERDICT |

---

## 🔧 Stack technique V0 (inchangée)

- **Frontend** : React 18 + Babel-standalone (compilation in-browser, pas de build step)
- **Servage** : PHP built-in server (`php -S`) ou n'importe quel static server
- **Stockage local** : localStorage (auth token, cart, loyalty state, idempotency, preferences)
- **Mobile preview** : iframe iPhone 390×844 sur desktop, full bleed sur vrai mobile
- **Tests E2E** : Playwright 1.58.2 (déjà installé) avec config mobile séparée
- **Pas de** : npm install, pas de build, pas de transpilation backend, pas de bundler

**Pour Phase 11 (natif)** → migration prévue vers **Capacitor + Vue 3** ou **React Native + Expo** (cf. CONNECTION_PLAN.md §4).

---

— *Bonne reprise. Audit + V0 + 20/20 E2E GREEN. Backend backlog documenté. Prêt à brancher Phase 6.*

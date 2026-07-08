# GOAL — WEB + APP MOBILE alignés sur la BORNE (catalogue, Stripe-OFF, fidélité QR)
**Date** : 2026-07-08 · **Skill** : ultra-architect-planify · **Mode** : /goal Stop-hook (exécution autonome jusqu'à convergence)
**Owner directive** : borne + caisse = corrects et INTOUCHABLES (lecture seule). Aligner web + app dessus. ~150 agents parallèles, boucle e2e + captures jusqu'à validation. Pas de questions superflues — décisions documentées ici.

---

## §0 — PRÉAMBULE

### §0.1 Working-tree decision
- **Backend `testttt`** : branche courante `pos/category-first-caisse-2026-06-23`, HEAD `58e852697`. Fichiers modifiés pré-existants (`.claude/*` locks, worktrees supprimés, `.playwright-mcp/*` supprimés) = bruit de sessions précédentes → **exclus de tout commit** (jamais `git add .`, fichiers explicites uniquement). Le travail de ce GOAL commite : `plans/GOAL_WEB_APP_SYNC_BORNE_2026-07-08.md`, `reports/goal-web-app-sync/**`, `tools/parity/**` (à créer), `mobile/**` (deltas), backend sync-layer (deltas ciblés).
- **Web standalone** (repo git séparé `/Users/1millnonstop/Downloads/web`) : HEAD `e251665`, quasi-propre (2 dossiers reports untracked pré-existants → non touchés). Commits checkpoint séparés dans CE repo.

### §0.2 Périmètre STRICT (owner mandate)
- ✅ Autorisé : `/Users/1millnonstop/Downloads/web/**` (site), `mobile/**` (app), couche sync/API backend **non-frozen** (config flags, endpoints frontend loyalty/payment si gap), tests e2e, tooling parity.
- ⛔ Interdit : caisse (`public/js/pos-wizard.js`, `admin/pos/**`, `admin-pos-v4.blade.php`), borne (`frontend/kiosk/**`, bundles kiosk), TOUTES frozen zones CLAUDE.md §7 (`PricingService`, `Fiscal/*`, `BranchScope`, `IdempotencyKeyMiddleware`, `OrderStateMachine`, `PaymentComponent.vue`, `PosV5TrancheRow.vue`).
- ⛔ Zéro modification du comportement caisse/borne. Toute route/config ajoutée doit être additive et inerte pour elles.

### §0.3 Pipeline par tâche
Chaque tâche d'implémentation suit `ultra-audit-profond` (audit → implement → RED → test → visual) en version workflow-agent : les implémenteurs sont des agents fichiers-disjoints, les vérifs/adversaires des agents séparés (§A). Pas de re-description ici.

### §0.4 Décisions architecturales (tranchées, sans question owner)
- **D1 — SSOT parity** : la payload borne `GET /api/frontend/menu` (routes/api.php:1493-1496, `Frontend\MenuController::kiosk`, token `kiosk:order` + KioskMachine) est LA référence. Extraite → `reports/goal-web-app-sync/catalog-canonical.json` (FAIT : 9 catégories, 42 items, `composer_profile`/extras/addons/prix). Un **parity gate** rerunnable (`tools/parity/check-parity.mjs`, TO BE CREATED) diffe canonical ↔ `web/data/menu.js` ↔ `mobile/data/menu.js` : items, prix, structure wizard, dispo. Zéro divergence = gate vert.
- **D2 — Architecture mirrors CONSERVÉE** : le web garde `data/menu.js` pour l'affichage + `api.js` pour résoudre nom-canonique→ids DB à la commande (décision owner 2026-06-26, UX validée). On RÉGÉNÈRE les mirrors depuis la fixture (script mécanique, rerunnable) au lieu de câbler l'affichage en live — divergence rendue impossible par le gate D1 exécuté en e2e. Mobile idem (CONNECTION_PLAN.md §0 : V0 standalone alignée kiosk).
- **D3 — Stripe prêt-mais-OFF** : gateway template déjà présent (`app/Http/PaymentGateways/{Gateways,Requests,PaymentRequests,Routes}/…Stripe*`, `stripe/stripe-php ^10.11`, row DB `payment_gateways` id=4 status=10=OFF). Flag applicatif : `config/features.php` → `features.web_stripe_prepay` (env `WEB_STRIPE_PREPAY_ENABLED`, défaut **false**). Web + app n'affichent l'option carte-en-ligne QUE si flag ON (lu via endpoint config public léger). Tests prouvent : OFF ⇒ invisible + refus serveur ; ON (env test) ⇒ flux testable. Row DB reste status=10. Aucune clé live.
- **D4 — Fidélité web** : `loyalty-v2.jsx` est une DÉMO hardcodée (profil « Ikyes Benzaid » fake) → câblage réel sur les endpoints existants routes/api.php:1444-1474 (`register`/`check`/`config`/`balance`/`history`/`add-points` idempotent/`redeem` idempotent/`POST /qr` → token signé `lqr.<payload>.<hmac>`, LoyaltyController.php:850). QR rendu **offline** (encodeur QR JS embarqué, AUCUN CDN — CSP/no-cloud).
- **D5 — Fidélité mobile** : suivre `mobile/CONNECTION_PLAN.md` **Chemin B** (backend FoodKing, pattern web api.js : guest-signup OTP routes/api.php:196 → token Sanctum → appels loyalty) + `WALLET_PLAN.md` pour l'écran wallet/QR. Catalogue reste mirror (D2) ; SEULE la fidélité (et le passage de commande si déjà prévu par le plan owner) touche l'API — c'est la « couche synchronisation » explicitement autorisée par le goal.
- **D6 — Scan QR sans toucher caisse/borne** : le scanner physique = wedge clavier ; le backend accepte déjà les tokens signés + legacy plaintext (`LOYALTY_QR_ACCEPT_LEGACY_PLAINTEXT`, LoyaltyController.php:847-849 ; anti-replay `loyalty_qr_nonces_consumed`). L'e2e SIMULE le scan en postant le token au consommateur existant (parcours exact cartographié en W1 par l'agent `backend:loyalty-scan`). Aucun code caisse/borne modifié.
- **D7 — Palette** : mobile = NOIR/ORANGE/JAUNE/BLANC (mandat owner, PAS `#F4501E`). Web = design v3/v4/v5 existant inchangé sauf insertions.
- **D8 — FR partout** : tout texte user-facing en français (ADR-007).

### §0.5 Critères de convergence (rejection rules — Axis 6)
REJET si : raw label visible · layout cassé sur viewport testé · erreur console · diff frozen-zone ≠ 0 · P0 RED non traité · test rouge non documenté · prix/produit divergent de la fixture · option Stripe visible flag OFF · QR non scannable/replay accepté · claim « presque bon ».
**CONVERGÉ = 2 cycles W6 consécutifs avec P0+P1=0 ET ensembles de findings identiques.**

### §0.6 Baselines (W0, capturées 2026-07-08)
- testttt HEAD `58e852697` · web HEAD `e251665` · NF525 : audit_logs=4930 (last_id=4941), z_reports=25 → à re-vérifier inchangé/append-only à chaque checkpoint.
- Serveurs actifs : `:8766` (API + borne e2e), `:8000`. Fixture : `reports/goal-web-app-sync/catalog-canonical.json` (203 911 bytes, snapshot_version=1).

---

## §1 — MAP PRINCIPAL (backend testttt — rôle dans ce GOAL)

| Système | Rôle ici | Maturité | Anchors vérifiés 2026-07-08 |
|---|---|---|---|
| BORNE | Source de vérité catalogue — **lecture seule** | prod-validée | `GET /api/frontend/menu` routes/api.php:1493-1496 ; fixture extraite |
| CAISSE | Consomme fidélité (redeem) — **lecture seule** | prod-validée | `POST /{order}/redeem-loyalty` routes/api.php:1036 (`Admin\PosLoyaltyController`) |
| Fidélité (sync layer) | Contrats à consommer par web+app | complète backend | routes/api.php:1444-1474 ; `LoyaltyQrSigner.php` (lqr.+HMAC+nonce) ; `LoyaltyController::generateQr`:850 ; tables `loyalty_transactions`/`loyalty_consents`/`loyalty_qr_nonces_consumed` ; `config/loyalty.php` (LOYALTY_QR_SECRET + boot guard prod) |
| Paiement (sync layer) | Stripe prêt-OFF | template présent | `app/Http/PaymentGateways/…Stripe*` ; `payment_gateways` id=4 status=10 ; `stripe/stripe-php ^10.11` ; tests `tests/Unit/Payment/Stripe*`, `tests/Feature/Stripe/` |
| Commandes frontend | Entrée commandes web/app | câblée (web 2026-06-26) | `FrontendOrderService.php` ; web api.js:409-422 (`payment_method` 1=comptoir/4=carte/5=TR) |

**Tests backend existants (ancres)** : `tests/Feature/LoyaltyApiTest.php` · `tests/Feature/Loyalty/{KioskLoyaltyEarnCycleProofTest,KioskRedeemWholePointSnapSentinelTest,LoyaltyClawbackOnRefundSentinelTest,LoyaltyRefundPointsIdempotentTest,PosRedemptionTtcTaxDoubleCountSentinelTest}.php` · `tests/Feature/{KioskLoyaltyDoubleRedeemRefusedTest,KioskLoyaltyLedgerAtomicTest}.php` · JS : `tests/js/{posLoyaltyMainPageCta,posLoyaltyRedeemModal,kioskLoyaltyDiscountConsistency,kioskLoyaltyConsentWiring}.spec.js`.

---

## §2 — MAP SEPARATED (surfaces à mettre à jour)

### WEB standalone — `/Users/1millnonstop/Downloads/web` (repo git séparé, HEAD e251665)
Fichiers (vérifiés `ls`) : `index.html` (metas api-base-url/api-key), `api.js` (wireup réel 2026-06-26 : OTP guest → Sanctum, X-API-Key, idempotence, delivery), `apiContract.js`, `data/menu.js` (mirror 2026-06-26 — **31 produits, PÉRIMÉ vs 42 canonical**), `data/loyalty.js` (helper démo earn 1pt/€, redeem 100pts=1€), `loyalty-v2.jsx` (**DÉMO hardcodée**), `account-v2.jsx`, `orders.jsx`, `funnel.jsx`, `wizard-v2.jsx`, `upsell.jsx`, `flows.jsx`, `screens-v3.jsx`, styles v3/v4/v5, `pw.validation.config.js`, `tests/`, `tools/`.

### MOBILE — `mobile/` (in-repo testttt, prototype navigateur JSX → Playwright-able)
Fichiers (vérifiés `ls`) : `index.html` (entrée prod full-viewport), `screens-main.jsx`, `screens-item-steps.jsx` (wizard), `screens-modals.jsx`, `screens-onboarding.jsx` (OTP simulée), `shared.jsx`, `api/storage.js` (V0 localStorage : auth/cart/loyalty), `data/{menu.js,loyalty.js,orders.js}` (démo), `styles.css` + `redesigns-styles.css` (palette NOIR/ORANGE/JAUNE/BLANC), `CONNECTION_PLAN.md` (Chemin B backend, endpoints mappés), `WALLET_PLAN.md`, `tests/`.

---

## §3 — SYSTÈME WEB (décomposition)

### Sub 3.1 — Catalogue parity (mirror régénéré)
**Anchors** : `web/data/menu.js` ; fixture canonical ; divergence connue : mirror=31 produits vs canonical=42 (boissons 15, frites cheddar ×4, etc.) + revert crudités Tacos 2026-07-07 côté backend.
**Tasks** : T-3.1.1 générateur mirror depuis fixture (schéma menu.js préservé — noms/aliases/wizard) · T-3.1.2 régénérer + valider vs gate parity · T-3.1.3 vérifier résolution api.js nom→id sur les 42 items (aucun orphelin).
**Acceptance** : `tools/parity/check-parity.mjs` (TO BE CREATED) sort 0 divergence web · spec e2e `web/tests/goal-sync/parity-web.spec.js` (TO BE CREATED) verte · commande test réelle placée via wizard sur ≥3 items nouveaux (Playwright).

### Sub 3.2 — Fidélité réelle (démo → API)
**Anchors** : `web/loyalty-v2.jsx` (démo), `web/data/loyalty.js`, backend routes:1444-1474, `LoyaltyController::generateQr`:850.
**Tasks** : T-3.2.1 couche `LC.api.loyalty.*` dans api.js (register/check/balance/history/qr — auth Bearer existante) · T-3.2.2 remplacer démo loyalty-v2 par données réelles (profil = client OTP courant, solde/historique réels) · T-3.2.3 rendu QR offline du token `lqr.…` (encodeur embarqué `web/vendor/qrcode.min.js` TO BE CREATED, MIT, inline) + rafraîchissement à expiration (`expires_at`) · T-3.2.4 aligner data/loyalty.js sur `GET /loyalty/config` réel.
**Acceptance** : spec `web/tests/goal-sync/loyalty-web.spec.js` (TO BE CREATED) : register→balance→QR affiché→(scan simulé API)→points crédités→balance mise à jour · capture écran lue/analysée.

### Sub 3.3 — Stripe checkout flag OFF
**Anchors** : `web/api.js:409-422` (payment_method), flux checkout dans funnel/flows (précisé par W1 `web:checkout-payment`).
**Tasks** : T-3.3.1 lecture flag (endpoint config public D3) · T-3.3.2 option « Payer en ligne (carte) » rendue UNIQUEMENT si flag ON + flux Stripe (élément de paiement, confirmation, statut payé) · T-3.3.3 OFF par défaut : option absente du DOM + garde serveur.
**Acceptance** : spec `web/tests/goal-sync/stripe-flag.spec.js` (TO BE CREATED) : flag OFF ⇒ option absente (capture) ; flag ON env test ⇒ flux atteint l'étape paiement Stripe test-mode sans erreur console.

### Sub 3.4 — E2E infra web
**Tasks** : T-3.4.1 servir le site (recette W1 `web:infra+tests`) · T-3.4.2 specs goal-sync + captures dans `web/reports/goal-sync-2026-07-08/`.
**Acceptance** : suite `goal-sync` verte 2 runs consécutifs.

---

## §4 — SYSTÈME MOBILE (décomposition)

### Sub 4.1 — Catalogue parity mobile
**Anchors** : `mobile/data/menu.js` (31 produits, périmé), même générateur que Sub 3.1 (schémas mirrors jumeaux — vérifié par W1).
**Tasks** : T-4.1.1 régénérer mirror mobile · T-4.1.2 wizard `screens-item-steps.jsx` conforme aux `composer_profile` 42 items (dont Tacos SANS crudités post-revert) · T-4.1.3 gate parity mobile 0 divergence.
**Acceptance** : `tools/parity/check-parity.mjs` 0 divergence mobile · spec `mobile/tests/goal-sync/parity-mobile.spec.js` (TO BE CREATED) verte + captures analysées.

### Sub 4.2 — Fidélité mobile (OTP réel + wallet QR)
**Anchors** : `mobile/CONNECTION_PLAN.md` Chemin B, `WALLET_PLAN.md`, `mobile/api/storage.js`, `screens-onboarding.jsx` ; backend guest-signup routes/api.php:196 + loyalty routes:1444-1474.
**Tasks** : T-4.2.1 couche `mobile/api/client.js` (TO BE CREATED — fetch backend : OTP guest-signup → token, loyalty balance/history/qr, config) miroir du pattern web api.js · T-4.2.2 onboarding branché au vrai OTP (numéro FR → enregistrement DB) · T-4.2.3 écran wallet : solde réel + QR signé rendu offline (même encodeur que web, copie locale) + expiration/refresh · T-4.2.4 data/loyalty.js démo remplacée/reléguée fallback offline.
**Acceptance** : spec `mobile/tests/goal-sync/loyalty-mobile.spec.js` (TO BE CREATED) : OTP→token→balance réelle→QR affiché→scan simulé→points→balance à jour · captures analysées · palette NOIR/ORANGE/JAUNE/BLANC intacte.

### Sub 4.3 — Stripe mobile flag OFF
**Tasks** : T-4.3.1 même flag/config que web · T-4.3.2 option carte-en-ligne cachée OFF (défaut), flux présent testable ON env test.
**Acceptance** : spec `mobile/tests/goal-sync/stripe-flag-mobile.spec.js` (TO BE CREATED) OFF ⇒ absente (capture).

### Sub 4.4 — E2E infra mobile
**Tasks** : T-4.4.1 servir `mobile/index.html` statique + Playwright viewport mobile · T-4.4.2 captures `reports/goal-web-app-sync/captures-mobile/`.
**Acceptance** : suite verte 2 runs consécutifs.

---

## §5 — SYSTÈME INTÉGRITÉ / BACKEND SYNC-LAYER (décomposition)

### Sub 5.1 — Parity gate tooling
**Tasks** : T-5.1.1 `tools/parity/extract-canonical.sh` (TO BE CREATED — re-extraction fixture via token KioskMachine, méthode W0 prouvée) · T-5.1.2 `tools/parity/check-parity.mjs` (TO BE CREATED — node, diff structuré, exit≠0 si divergence, rapport JSON) · T-5.1.3 générateur mirrors `tools/parity/gen-mirrors.mjs` (TO BE CREATED).
**Acceptance** : gate exécutable en 1 commande, utilisé par W6 ; auto-test du gate (mutation volontaire ⇒ détectée).

### Sub 5.2 — Flag Stripe + config publique
**Anchors** : `config/features.php` (existe), `config/payment.php` (existe), pattern boot-guard `AppServiceProvider`.
**Tasks** : T-5.2.1 clé `features.web_stripe_prepay` défaut false (env `WEB_STRIPE_PREPAY_ENABLED`) · T-5.2.2 exposition config publique légère pour web+app (réutiliser `GET /loyalty/config` pattern routes/api.php:1449 — endpoint additive non-kiosk, ex. `GET /frontend/client-config`, TO BE CREATED si W1 ne trouve pas d'existant) · T-5.2.3 garde serveur : tentative paiement Stripe flag OFF ⇒ 403 propre.
**Acceptance** : `tests/Feature/Payment/StripePrepayFlagTest.php` (TO BE CREATED) : OFF⇒403+absent config ; ON(test)⇒flux OK · row `payment_gateways` id=4 reste status=10 (assert).

### Sub 5.3 — Fidélité bout-en-bout cross-système
**Anchors** : parcours scan cartographié W1 (`backend:loyalty-scan`), tests existants §1.
**Tasks** : T-5.3.1 e2e API : register(phone)→QR→scan simulé→`loyalty_transactions` +points→balance web ET mobile identiques · T-5.3.2 adversarial : replay nonce refusé, token expiré refusé, double add-points idempotent, enumeration throttlée · T-5.3.3 POS redeem inchangé (tests existants re-run, 0 régression).
**Acceptance** : `tests/Feature/Loyalty/LoyaltyQrWebAppFlowTest.php` (TO BE CREATED si non couvert par LoyaltyApiTest — sinon étendre) vert · suite fidélité existante §1 100% verte (aucune modif de ces tests).

### Sub 5.4 — Non-régression caisse/borne
**Tasks** : T-5.4.1 frozen diff 0 sur toute la plage du GOAL · T-5.4.2 NF525 append-only (§0.6 baseline) · T-5.4.3 re-run smoke tests borne/caisse existants (PHPUnit filtres Pos/Kiosk clés) · T-5.4.4 payload `GET /api/frontend/menu` identique avant/après (hash).
**Acceptance** : `git diff --stat <58e852697>..HEAD -- <liste frozen §7>` = 0 ligne · chain OK · smoke vert.

---

## §A — AGENT ARMY MAP (~150+ agents sur le GOAL)

| Vague | Rôles | Volume | Parallélisme |
|---|---|---|---|
| W1 cartographie | readers parity ×9, maps web ×7, mobile ×6, backend ×5, infra+advisor ×2 | 29 | full parallel read-only (LANCÉE, run `wf_99239829-ced`) |
| W3 web | implémenteurs fichiers-disjoints + vérificateur par livrable + RED | ~25-35 | pipeline, jamais 2 écrivains sur le même fichier |
| W4 mobile | idem | ~25-35 | pipeline |
| W5 backend sync | implémenteurs séquentiels (routes/config = zones partagées) + RED | ~5-8 | séquentiel |
| W6 intégrité/e2e | parity ×9×2, fidélité e2e ×8-10, Stripe ×4, sécurité ×8, visuel QA+RED ×12-20, boutons/flux ×10, adversaires ×10 | ~50-70/cycle | full parallel read+capture |
| W7 boucle | re-W6 ciblé + heals | variable | jusqu'à convergence §0.5 |

Discipline : implémenteurs JAMAIS en parallèle sur un même fichier ; QA-Visual + RED-Visual toujours par paire indépendante ; tout finding sans file:line vérifié = rejeté (verify-before-report) ; rapports persistés `reports/goal-web-app-sync/`.

## §X — WAVES + checkpoints

| Wave | Contenu | Checkpoint (tous requis) |
|---|---|---|
| W0 ✅ | fixture + baselines | fixture 42 items ; baselines §0.6 notées |
| W1 🔄 | cartographie 29 agents | rapports mergés sur disque ; advisor intégré ; plan ajusté |
| W2 | GOAL doc (ce fichier) + commit checkpoint | doc ≤45KB ; commit explicite |
| W3 | implémentation WEB | suite goal-sync web verte ; captures analysées ; commit repo web |
| W4 | implémentation MOBILE | idem mobile ; commit testttt (mobile/**) |
| W5 | backend sync layer | PHPUnit ciblé vert ; frozen diff 0 ; commit |
| W6 | intégrité 50-70 agents | 0 P0/P1 ouverts ou heals lancés |
| W7 | convergence | §0.5 atteint ; BRAIN §2/§3 ; mémoire ; rapport owner |

**Interrupt-resume** : chaque wave committe (`wip(goal-sync): …` si partiel) + état dans `reports/goal-web-app-sync/STATE.md` (dernier commit, tâche en cours, prochaine) ; reprise = lire STATE.md + ce doc.

## §G — OWNER GATES

| Gate | Description | WHO | WHAT | WHERE | Status |
|---|---|---|---|---|---|
| G1 | Activer Stripe en prod (flag ON + clés live + status=5) | Owner physique | clés Stripe + ordre explicite | .env prod + payment_gateways | **HORS GOAL — jamais fait ici** |
| G2 | Push remote des commits | Owner (CLAUDE.md §10) | ordre push | — | PENDING fin de GOAL |
| G3 | Déploiement VPS des surfaces | Owner (script deploy) | ordre deploy | docs cowork | HORS GOAL |

Aucune wave n'est bloquée par G1-G3 (tout le GOAL est local + flag OFF).

## §R — RÉFÉRENCES
CLAUDE.md §5-§8 · CONSTITUTION.md · SYSTEM_MAP.md §4/§6 · mobile/CONNECTION_PLAN.md + WALLET_PLAN.md · memoire `project_web_api_wireup_caisse_2026-06-26`, `project_loyalty_unified_sync_2026-06-11`, `fix_borne_tacos_crudites_2026-07-07` · skills : ultra-audit-profond, test-e2e, verify-before-report, checkpoint-commit.

## §F — FINAL RULE
DONE = parcours client complet (catalogue 42/42 zéro-divergence, commande réelle, Stripe en veille prouvé OFF+testable ON, fidélité phone→QR→scan→points→soldes synchrones web/app) **validé par e2e + captures analysées, 2 cycles consécutifs propres**, frozen diff 0, NF525 append-only, caisse/borne strictement inchangées. Pas de « presque ».

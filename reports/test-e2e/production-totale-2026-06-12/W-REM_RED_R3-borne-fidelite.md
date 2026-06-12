# W-REM — Contre-audit adversarial RED, voie R3-borne-fidelite
**Date** : 2026-06-12 · **Adversaire** : RED R3 (read-only code) · **Branche** : `release/v1-integration-2026-06-12` · **Serveur** : :8770 (PID 26441, cwd vérifié = `integration-v1-2026-06-12/public`) · **DB mutations** : `foodking_e2e` uniquement · **DB tests** : `foodking_test`

## Verdict global : 9/9 CONFIRMED — aucun fix réfuté
+ **1 finding P2 NOUVEAU** (RED-R3-01, trou RGPD ré-enrôlement post-opt-out, prouvé live)
+ **1 observation P3** (RED-R3-02, auto-opt-out inatteignable pour comptes borne status=1)
+ **1 gate dur re-prouvé** : `kdsBundleFreshnessSentinel` ROUGE tant que le rebuild Mix groupé n'est pas fait (by design — seule rouge du sweep 1184 tests).

## Discipline vérifiée (plage complète des 6 commits)
- **Frozen-diff = 0 ligne** : `git diff <c>^..<c> --stat -- <15 fichiers §7>` exécuté pour CHACUN de `cb6b21746 d673c4226 afd36ef9e 43e13c6b5 3001e2fb6 f68ff64df` → sortie vide ×6. `KioskAppComponent.vue` intouché (claim « voie 100% non-frozen » T-R3.2 exact).
- **Scope-minimal** : `git show --stat` ×6 — aucun fichier hors voie R3 ; aucun bundle `public/js` commité (discipline « pas de rebuild dans la voie » respectée) ; f68ff64df = 3 binaires reports/ only.
- **Tests existants modifiés (3001e2fb6)** : diff inspecté — adaptations de payload au nouveau contrat consent UNIQUEMENT, **aucune assertion supprimée ni affaiblie** (LoyaltyRateSsotTest garde l'assertion idempotence re-register même payload).

---

## Verdicts fix par fix

### 1. T-R3.1a F-BV-03 (chunks tunnel achat offline) — `cb6b21746` — **CONFIRMED**
- Re-run MOI : `kioskOfflineChunkGuard.spec.js` **6/6** + `kioskCashInstructionUnifiedPaymentCopy` 6/6 + `kioskLoyaltyEmptyCartConsult` 11/11 (run 19:32:42, « 23 passed (23) »).
- Voisins : `kioskErrorScreensEagerOffline` + `kioskPerfChunks` + `KioskPhase3Routes` + `kioskRouterLockdown` + `KioskPhase3EdgeCases` = **37/37**.
- Différentiel RED prouvé : parent `cb6b21746^` = `const KioskCartComponent = () => import(/* webpackChunkName: "kiosk-shell" */ …)` (lazy, lignes 31-34) → courant = imports statiques lignes 32-34. Le spec (lu) importe le VRAI module routeur — pas une tautologie.

### 2. T-R3.1b F-BV-04 (« Mon compte » panier vide) — `d673c4226` — **CONFIRMED**
- Re-run MOI : `kioskLoyaltyEmptyCartConsult.spec.js` **11/11**.
- Capture BEFORE commitée **lue + analysée** (`r3-fbv04-BEFORE-moncompte-paniervide.jpeg`) : « VOTRE PANIER / 0 article / Votre panier est vide » — repro réelle du bug.
- Diff inspecté : `requireCart` retiré de `kiosk.loyalty` SEUL ; upsell/payment le gardent (kioskRoutes.js:246,253 vérifié grep). Cas adversarial creusé : panier vide, bouton confirm ENABLED avec `redeemChoice` null → `applyLoyalty()` branche else = `setLoyalty(discount: 0)` (client attaché au panier pour accrual, aucun appel redeem) — sain.
- i18n `continue_menu` présent ×5 locales (fr « Choisir mes articles » ; bn/de = miroir fr, pattern miroir mécanique R2, pas de raw label).
- Pixel après-fix = différé au rebuild groupé (bundles :8770 antérieurs — caveat honnête de l'implémenteur, vérifié exact).

### 3. T-R3.1b F-BV-05 (copy cash unifié) — déjà convergé spine — **CONFIRMED**
- Re-run MOI : sentinel `kioskCashInstructionUnifiedPaymentCopy.spec.js` **6/6**.
- `fr.json kiosk.cash_instruction.help` = « Réglez à la caisse — espèces, carte ou ticket restaurant. » (lu via python json).
- `grep -rn "espèces uniquement" resources/js/` = **0 occurrence**. Décision de ne PAS recommitter conforme plan §0.1.

### 4. T-R3.1b F-BV-07 (toast kiosk-auth-retried silencé) — déjà convergé spine — **CONFIRMED**
- Re-run MOI : `kioskAuthRetriedToastSilenced.spec.js` **3/3** (stdout du run montre le console.debug de remplacement).
- `kioskAuthInterceptor.js:84` `SILENCED_EVENTS = new Set(['kiosk-auth-retried'])` + stopImmediatePropagation en capture ; installé `bootstrap-kiosk.js:57-58`. Grep-confirmé.

### 5. T-R3.1c contraste KDS « Historique du jour » — `afd36ef9e` — **CONFIRMED**
- Re-run MOI : `kdsHistoryDrawerTitleContrast.spec.js` **4/4** (calcul WCAG réel dans le spec).
- Root cause re-vérifiée indépendamment : `resources/css/app.css:31-36` `h1..h6 { @apply text-heading }` (= #1F1F39 tailwind.config) pose une règle DIRECTE sur le h2 → bat l'héritage blanc du header #111111. Fix = `color:#ffffff` scopé `.kds-history-drawer__title` (6 lignes, +commentaire).
- Captures **lues + analysées** : BEFORE = titre sombre quasi invisible sur header noir (bug réel) ; AFTER-preview = titre blanc parfaitement lisible, règle injectée identique au fix.
- ⚠️ **Gate dur re-prouvé** : `tests/js/sentinels/kdsBundleFreshnessSentinel.spec.js` **FAIL** (« KdsHistoryDrawer.vue mtime 17:02 > admin-kds.js 15:59 ») — c'est l'UNIQUE rouge du sweep 1184 tests et c'est VOULU : la sentinelle force le rebuild Mix groupé avant T-INT.5. Pixel final post-rebuild.

### 6. T-R3.2 BORNE-BOOT-401 — `43e13c6b5` — **CONFIRMED**
- Re-run MOI : `kioskBootBearerFreshness.spec.js` **9/9** ; sentinelles ws `wsAuth*`+`wsReconnect*` **21/21** ; voisins kioskCart (`CheckoutErrorsFr/OfflinePaymentScope/SendPayload/Restyle/RuptureMarking/Promo`) **50/50**.
- Voie non-frozen VÉRIFIÉE : frozen-diff 0, KioskAppComponent intouché. Triple verrou inspecté ligne à ligne :
  1. `kioskRoutes.js` rotation au boot (`isBoot = !from || !from.name`) avec fallback dégradé `.catch(() => (token ? next() : login))` — pas de régression offline ;
  2. wrappers Echo `private/encryptedPrivate/join` → `_refreshEchoAuth()` AVANT subscribe ;
  3. `SET_KIOSK_TOKEN` passe le token explicite — **pas un NO-OP** : `window._refreshEchoAuth(explicitToken)` (bootstrap.js:368-371) supportait déjà le paramètre (P-AUTH-SYNC), le chemin localStorage était bien stale-by-one.
- **Attaque cross-surface tentée et ÉCHOUÉE** : bootstrap.js est partagé admin/POS/KDS — mais `_getEchoBearerToken` → `selectSurfaceBearerToken` (axios-setup.js:34-41) est surface-aware (`/kiosk` → kioskToken, sinon userToken). Les wrappers appellent sans argument = token surface-correct. Aucune contamination admin.
- Observations (non bloquantes) : (a) chaque boot avec auto-credentials fait désormais 1 appel `kioskLogin` AVANT next() = +1 RTT au boot (boot SPA déjà 3,2-4 s §0.1 ; hard-load ~1×/jour) ; (b) si la rotation échoue pour cause de credentials invalides (pas offline), le 401 one-shot residuel revient — dégradé documenté ; (c) preuve LIVE de la disparition du 401 impossible avant rebuild groupé (bundles stale) — test-level + revue de code seulement à ce stade.

### 7. T-R3.3 Q-4 RGPD consentement + opt-out — `3001e2fb6` — **CONFIRMED** (avec 1 P2 + 1 P3 nouveaux)
- Re-run MOI : `LoyaltyConsentOptOutTest` **8/8 (31 assertions)** ; `tests/Feature/Loyalty` **40/40** ; `LoyaltyApiTest` **9/9** ; `LoyaltyOptInEndpointTest` **6/6**. (NB : le claim « 8/8+3/3+1/1 » se re-vérifie fichier PAR fichier — phpunit 9 n'accepte qu'un chemin.)
- **Re-preuve LIVE :8770 / foodking_e2e** :
  - register SANS consent → **422** `{"consent_accepted":["Le consentement explicite est requis (RGPD)."]}` ;
  - register AVEC consent (phone 0699887701) → **200** + ligne `loyalty_consents` (consent=1, version v1.0, ip_hash sha256 tronqué `9755183db8c8…`, jamais brut) + user 63 code FD23129D / 25 pts + ledger `earn +25 Bonus de bienvenue` ;
  - opt-out staff-assisté (token Admin id 61, payload `{"phone":"0699887701"}`) → **200** + code NULL + points 0 + ligne consents **consent=0 version 'opt-out'** + ledger `manual_deduct -25 balance_after 0` ;
  - outbox L2 : `domain_events` rows `loyalty.balance_changed` reasons `welcome` (×2) + `opt_out` aux timestamps exacts de mes appels.
- Appelants de register audités : seul `KioskLoyaltyComponent` (mis à jour, envoie consent ; `KsConsentModal` câblé préexistant `485b47df1`) + optIn interne (attribut skip anti-double-ligne vérifié dans le diff). Aucun consommateur cassé.
- BranchScope sur User : non-problème — `BranchScope::apply` early-return `if ($model instanceof User)`.

#### 🔴 P2 NOUVEAU — RED-R3-01 : l'opt-out n'est PAS durable (ré-enrôlement silencieux par lazy-mint)
Prouvé live : user 63 radié (consents finit par consent=0, code NULL) → un simple `POST /loyalty/check {"code":"0699887701"}` (token machine kiosk) → **200 + NOUVEAU code D6579EC2 minté**, AUCUNE nouvelle ligne `loyalty_consents`. Le droit de retrait CNIL est annulé par n'importe quelle saisie du téléphone (borne OU staff à la caisse, à l'insu du client). Le welcome n'est pas re-crédité (idempotence OK, anti-abus) mais le RÉ-ENRÔLEMENT sans consentement re-journalisé est un trou de conformité. **Reco** : marqueur `loyalty_opted_out_at` (ou lecture de la dernière ligne consents) → lazy-mint refuse de re-minter sans nouveau consentement explicite. Lazy-mint = préexistant, mais l'opt-out est NOUVEAU : la complétude Q-4 exige cette fermeture.

#### 🟡 P3 — RED-R3-02 : auto-opt-out inatteignable pour les comptes créés borne/caisse
Prouvé live : token minté pour user 63 (status=1 legacy posé par register) → `POST /opt-out` → **401 « User account inactive »** (`EnsureUserStatusActive` exige `Status::ACTIVE=5` strict, middleware AVANT contrôleur). Préexistant (ces clients n'ont aucun credential de toute façon) ; la voie opérante = staff-assistée (prouvée verte). À documenter au dossier owner : le workflow CNIL de retrait pour clients borne = assistance comptoir.

### 8. T-R3.3 F1-02 welcome lazy-mint — `3001e2fb6` — **CONFIRMED**
- Re-run MOI : `LoyaltyWelcomeLazyMintTest` **3/3 (16 assertions)**.
- **Re-preuve LIVE** : client « caisse » créé phone-only sans code (user 64, tinker e2e) → `check {"code":"0699887702"}` (token machine kiosk réel + KioskMachine row) → **code 00597EDE minté + 25 pts + ledger `earn +25 Bonus de bienvenue` source kiosk**. Idempotence prouvée live : 2e check → toujours 25 pts, **1 seule** ligne bonus. User opté-out re-minté → **0 pt** (pas de 2e bonus — la promesse « une fois par client » tient même après radiation).
- Décision produit documentée (docblock `awardWelcomeBonusOnFirstJoin` + message de commit) comme exigé par le plan.

### 9. T-R3.3 F3-03 SettingsUpdated loyalty_setup — `3001e2fb6` — **CONFIRMED**
- Re-run MOI : `LoyaltySetupSettingsUpdatedTest` **1/1 (2 assertions)**.
- Diff = 1 import + 1 `SettingsUpdated::dispatch(['loyalty_setup'])` après `Settings::set` — pattern identique aux 4 sites existants cités (grep-confirmé CurrencyController/TaxController/CompanyController/OrderSetupController). Scope-minimal exemplaire.

---

## Régression voisine — sweep complet module
`npx vitest run kiosk kds Kiosk Kds` = **156 fichiers / 1184 tests : 1182 passed, 1 skipped, 1 failed** — l'unique échec est `kdsBundleFreshnessSentinel` (bundle stale vs source R3, voir fix 5 — rouge ATTENDUE jusqu'au rebuild groupé orchestrateur). Zéro autre régression. PHPUnit : Loyalty 40/40 + voisins ci-dessus.
Préexistant non-R3 noté au passage : clé `kiosk.cash_instruction.cta_back_home` absente ar/bn/de (introduite spine `43c5f2d76`, fallback fr fonctionne) ; heures 12h « 06:28 PM » dans le drawer KDS (gate TIME_FORMAT connu).

## T-R3.4 (LOCK_CAISSE-01-v2) — écart déclaré, déjà couvert ailleurs
Le plan ligne 74 INCLUT T-R3.4 dans la voie R3 ; l'implémenteur le déclare hors liste assignée (« à assigner »). Vérification : `plans/LOCK_CAISSE-01-V2_2026-06-12.md` **existe et est commité** (`da32af6b9`, 17:41, AVANT les commits R3, aussi présent sur heal/ultra-audit-w4) — scope pos-wizard.js:3883-3894 by-name, rollback, acceptance triple-vert, intérim caisse. **L'écart est clos par une autre session ; reste le contreseing owner G2.** Rien à réassigner.

## Quarantaine R1 (mandat « pour R1 », N/A voie R3)
Constat incident à ma voie : les 3 `domain_events` loyalty créés par mes appels live ont `dispatched_at = NULL` (pending, pas de broadcast parti à la création) — cohérent avec la quarantaine R1 en place. Vérification redis db5/worker = du ressort de l'adversaire R1.

## Résidus de test (DB e2e jetable, documentés)
`foodking_e2e` : users 63 (`RED Adversaire`, opté-out puis re-minté D6579EC2 — pièce à conviction RED-R3-01) et 64 (`Client Caisse RED`, 25 pts) ; tokens sanctum 2833/2836/2839 ; 3 domain_events pending. Aucune écriture sur `foodking` ni OVH.

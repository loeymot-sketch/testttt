# Wave W1 — DIMENSION CONSOLE/NETWORK — Pages CAISSE FoodKing

**Date** : 2026-06-11
**App** : http://127.0.0.1:8768 (`APP_ENV=e2e`, DB `foodking_e2e` jetable)
**Harness** : Playwright node, `tests/e2e/_w1-console-network-audit.mjs`, viewport 1440×900, locale fr-FR, headless (chrome channel). READ-ONLY sur le code source.
**Login** : admin@lecayenne.fr (branch_id=0).

---

## Méthodologie

Pour chaque page : `page.on('console')` (erreurs+warnings, dédupliqués), `page.on('pageerror')` (exceptions JS), `page.on('response')` (status ≥400, lenteurs >3s), `page.on('request')` (détection polling >1×/2s), `page.on('websocket')`. Navigation → attente 5s → interaction légère (scroll + 1 clic inoffensif filtre/pagination/onglet) ; sur `/admin/pos` mini-commande (clic tuile produit). Ordre : pos-orders → show/4511 → tracker → encaissement → cash-overview → cash-sessions-report → historique → pos → **floorplan en dernier**.

> **Note d'environnement (contention multi-vagues)** : le compte `admin@lecayenne.fr` est partagé par plusieurs vagues d'audit parallèles tournant en même temps sur `:8768`. `LoginController.php:155` (`$user->tokens()->where('name','auth_token')->delete()`) **révoque le token à chaque login**. Quand une vague parallèle se logue, mon token est invalidé → vague de 401 (`/api/admin/default-access`, `/api/admin/setting/branch`, `/api/auth/logout`) → redirection `/login` ou `/admin/dashboard`. **Ces 401 sont un artefact du harness multi-session, PAS un défaut des pages caisse** (DB confirme la rotation `auth_token`, et en V1 LOCAL Le Cayenne mono-poste/mono-opérateur le cas ne se produit pas). Chaque page a donc été capturée dans une **fenêtre propre** (login non contesté) ; les runs éjectés ont été écartés.

---

## Tableau par page (capture propre, login non contesté)

| Page | URL finale | Erreurs console | Exceptions JS | 4xx/5xx | Observations |
|------|-----------|-----------------|---------------|---------|--------------|
| **pos-orders** | /admin/pos-orders | 0 (1 warning WS) | 0 | 0 | Propre. |
| **pos-orders/show/4511** | /admin/pos-orders/show/4511 | 0 (1 warning WS) | 0 | 0 | Order `SUP-LOY-1` existe (confirmé DB). Police Rubik-Regular.ttf lente (~3.7s) sous charge parallèle (artefact env). |
| **pos-orders-tracker** | /admin/pos-orders-tracker | 0 (1 warning WS) | 0 | 0 | Polling tracker = `POLL_WS_MS=60000` / `POLL_NO_WS_MS=8000` (`PosOrdersTrackerComponent.vue:429-430`) — cadence by-design, jamais <2s. Propre. |
| **encaissement** | /admin/encaissement | 0 (1 warning WS) | 0 | 0 | Propre. |
| **cash-overview** | /admin/cash-overview | 0 (1 warning WS) | 0 | 0 | Propre. |
| **cash-sessions-report** | /admin/cash-sessions-report | 0 (1 warning WS) | 0 | 0 | Propre. |
| **historique** | /admin/historique | 0 (1 warning WS) | 0 | 0 | Propre. |
| **pos** (mini-commande) | /admin/pos | 0 (1 warning WS) | 0 | 0 | Boot-burst sur `GET /api/admin/oss-order` (×6 en 9s) + `counter-collect/pending` (×4) — **rafale de montage** (plusieurs loaders au mount), se stabilise à la cadence fallback **5s** (`_kioskPollingInterval`, `PosComponent.vue:2750-2764`, GOAL-HEAL-SYNC-001 : ≤5s si WS dégradé). Mini-dup : `GET /api/admin/users/address/2` ×3 au mount. Aucun 4xx en steady-state. |
| **pos/floorplan** | /admin/pos/floorplan | **0** (pas même le warning WS) | 0 | 0 | Le plus propre. Poll floorplan 15s (`FloorplanComponent.vue:122`). |

**Synthèse** : 9/9 pages **console-propres** et **0 exception JS, 0 4xx/5xx en flux normal**. Le seul bruit récurrent est le warning WebSocket de boot (voir P3-1).

---

## WebSocket (SYNC-WS-01)

`ws://127.0.0.1:6001` (`bootstrap.js:327-331`, broadcaster pusher/soketi).
- **Port 6001 OUVERT** et soketi répond HTTP `OK` (vérifié `nc` + `curl`).
- Chaque page logue **1 seul** warning : `WebSocket connection to 'ws://127.0.0.1:6001/...' failed: WebSocket is closed before the connection is established.`
- **Mais la 2ᵉ tentative de connexion RÉUSSIT** (l'objet ws suivant reste `closed:false`, `error:null`). Echo se reconnecte après le 1ᵉʳ handshake raté (race boot avant que le Bearer/app-key soit injecté, `bootstrap.js:372-373`).
- **Le fallback est silencieux** : 1 warning unique par chargement, **pas de spam d'erreurs**. Conforme à l'attendu. floorplan ne logue même pas le warning.

---

## Findings

### [P3-1] Warning WebSocket de boot sur 8/9 pages caisse (cosmétique, self-heal)
- **Repro** : charger n'importe quelle page admin → 1 warning console `WebSocket is closed before the connection is established` (`ws://127.0.0.1:6001/app/app-key...`).
- **Root cause** : race au boot — Laravel Echo ouvre le socket avant l'injection du header `Authorization` Bearer (`resources/js/bootstrap.js:327-373`). La 2ᵉ connexion réussit (soketi up sur 6001).
- **Impact** : nul fonctionnellement. La sync temps-réel marche (2ᵉ ws reste ouvert) ; même hors-WS le POS retombe sur polling ≤5s. Ce n'est pas un spam (1 occurrence/chargement).
- **Reco** : différer la 1ʳᵉ connexion Echo jusqu'à présence du token (ou squelchez le warning de boot). Non-bloquant V1. Connu = SYNC-WS-01.

### [P3-2] Rafale de requêtes au mount du POS (oss-order ×6, counter-collect ×4 en 9s)
- **Repro** : ouvrir `/admin/pos`, observer le réseau les 9 premières secondes.
- **Root cause** : plusieurs loaders indépendants tirent `oss-order` au montage (`PosComponent.vue` `loadKioskCashOrders`+`loadActiveOrdersStats`+`loadReadyOrders` + gate pré-modale `counter-collect/pending` ligne 3165/4000). Ce n'est PAS un polling 1.5s permanent : la cadence steady-state est **5s** (fallback WS-dégradé documenté `_kioskPollingInterval` `PosComponent.vue:2750-2764`).
- **Impact** : négligeable mono-poste. Léger surcoût réseau au boot.
- **Reco** : mutualiser les loaders de boot en 1 appel `oss-order` partagé. Optionnel.

### [P3-3] Double/triple fetch `GET /api/admin/users/address/2` au mount POS
- **Repro** : `/admin/pos` → 3 appels identiques `users/address/2` au boot.
- **Root cause non confirmée précisément** (probable double-watch sur le client courant). À investiguer si pertinent — verify-before-report : je n'ai pas isolé le `file:line` exact, donc reporté en P3 sans sur-affirmation.
- **Impact** : minime.
- **Reco** : dédupliquer/cacher l'appel adresse client au mount.

### Non-findings (artefacts env, écartés)
- **Vagues de 401** (`default-access`, `setting/branch`, `auth/logout`) + redirections `/login`÷`/admin/dashboard` : **artefact de contention multi-vagues** sur le compte admin partagé (`LoginController.php:155` révoque `auth_token` à chaque login parallèle). Reproduit uniquement sous audit concurrent ; absent en exploitation mono-opérateur. **Pas un défaut applicatif.**
- **Chargements statiques lents** (Rubik-Regular.ttf ~3.7s, tabs.js/dropdown.js/pos-wizard.js ~5s) : observés **uniquement** quand plusieurs navigateurs headless parallèles saturent le `php artisan serve` mono-thread. Pas un problème de la page.

---

## Top 3

1. **Aucun défaut console/réseau bloquant** : 9/9 pages caisse propres — **0 exception JS (P0), 0 erreur console récurrente, 0 5xx, 0 4xx en flux normal**. Les pages POS/encaissement/historique/cash sont saines côté front.
2. **SYNC-WS-01 = bénin et self-healing** (P3-1) : soketi est UP sur 6001, le warning de boot est unique et la 2ᵉ connexion WS réussit ; le fallback est silencieux (pas de spam). À polir, pas à corriger en urgence.
3. **POS : rafale de boot non-pathologique** (P3-2/P3-3) : `oss-order`/`counter-collect` en rafale au mount puis cadence fallback 5s documentée (GOAL-HEAL-SYNC-001), plus un mini-dup d'appel adresse — optimisations cosmétiques, aucun impact V1.

**Verdict dimension CONSOLE/NETWORK : GREEN.** Aucun P0/P1/P2. 3× P3 cosmétiques.

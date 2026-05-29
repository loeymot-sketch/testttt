# GOAL Living-Sync Validation — CONVERGENCE / « Le Livre »
**Date** : 2026-05-29 · **Branche** : `heal/cms-pr1-quickwins-2026-05-18`
**Mandat owner** : agir en superviseur, corriger les 3 états « non-living », valider
empiriquement (GStack + Superpowers + Adversarial + E2E visuel), ne revenir que validé.

> Verdict honnête, état par état. Ce qui est **VALIDÉ** est prouvé par mesure empirique
> live (pas par « les tests passent »). Ce qui reste **ouvert** est listé sans
> enrobage — c'est exactement le piège du « tout validé » de la session précédente
> que cette campagne corrige.

---

## 0. TL;DR

| État non-living | Verdict | Preuve |
|---|---|---|
| **P-AUTH** — falaise TTL 8h | ✅ **CORRIGÉ** | refresh proactif 2h via `/api/refresh-token` (commit `3c1fa0eb7`) |
| **P-LIVE-SYNC** — push WS temps réel | ✅ **VALIDÉ + 1 P1 CORRIGÉ** | push WS mesuré **6 ms** ; race subscribe chef au login corrigée (commit `5f2c6947f`) |
| **P-COMES-OUT** — cycle de vie « ça sort » | ✅ **VALIDÉ LIVE** | 427 PREPARING→PREPARED réel, `OrderStatusChanged` reçu en **512 ms** bout-en-bout |

**Le push temps-réel EST vivant** (≈6 ms sur le bus WS, ≈0,5–1 s bout-en-bout dominé par
le `queue:work sleep=1`). MAIS il était **silencieusement cassé au login du chef** par une
race de token — corrigé ici. NF525 chain CHAIN OK. Frozen zones : 0 ligne touchée par le
travail sync (la seule modif frozen de session = `ZReportService +21` sous
`LOCK_ZREPORT_REFUND_NETTING`, SHA baseline à jour, sentinelle verte).

---

## 1. Architecture réelle (carte ancrée, vérifiée code + live)

Cascade live (tracée par l'agent Architect, citations exactes vérifiées) :

```
Order créé / status changé
  └─ Event domaine (FrontendOrderService:1283 | PaymentService:409/423 | KitchenDisplaySystemOrderService:458)
      └─ Listener Persist*ToOutbox  →  table `domain_events` (firstOrCreate idempotent)
            channel = ["private-branch.{branch_id}"]   broadcast_as = OrderCreated|OrderStatusChanged|…
          └─ DispatchDomainEventsJob (claim 3-phase lockForUpdate + dispatched_at guard ; backoff [1,5,15,60,300]/tries=6)
              └─ PusherBroadcaster → soketi (127.0.0.1:6001)
                  ├─ WS push → Echo private('branch.{id}') → eventContract.js onEvents → KDS _debouncedRefresh   (≈6 ms)
                  └─ FALLBACK delta-poll (KdsSyncService / composant autoRefresh) qui lit `orders` EN DIRECT (pas l'outbox)
```

**Split par rôle (clé de tout)** — `KitchenDisplaySystemComponent.vue:1891-1896` :
- **Staff de branche (`branch_id>0`, le vrai chef)** → `subscribeEcho()` s'abonne au canal
  `private-branch.{id}` → **push sub-seconde**. C'est le LIVING sync.
- **Admin (`branch_id=0`)** → PAS d'abonnement canal, **poll passif 60 s** (WS up) / 5 s (WS down)
  par design (`_pollingInterval():1878`). C'est volontaire et documenté — acceptable pour de
  la supervision admin, mais ce n'est PAS du temps réel.

⚠️ **Le test doit se faire en compte chef (`chef@lecayenne.fr`, branch 1)**, pas admin. La
session précédente validait probablement en admin (poll 60 s) et confondait « la page se
recharge » avec « push live » — la racine de l'overclaim.

---

## 2. P-AUTH — falaise TTL 8h → CORRIGÉ (W1, commit `3c1fa0eb7`)

- **Problème** : SPA Bearer-partout (poll KDS/OSS + auth canal WS = même token Sanctum,
  TTL 480 min). Aucun refresh proactif → à ~8h, tout le sync (WS + poll) meurt
  silencieusement jusqu'à re-login manuel (reproduit la session passée : token 788 expiré
  → poll 401 → 44 erreurs → redirect).
- **Fix** : timer 2h dans `app.js`/`pos-app.js` → action `refreshAuthToken` → POST
  `/api/refresh-token` (endpoint existant `RefreshTokenController`, abilities préservées,
  ancien token supprimé). Mutation `authTokenRefreshed` ré-injecte le token frais dans Echo.
- **Preuve unit/backend** : `RefreshTokenAbilityPreserveTest` 4/4 (kiosk garde `kiosk:order`
  only ; admin garde `*` ; token invalide → 401). Vitest spec dédiée 6/6.
- **Preuve LIVE (chef, canal abonné)** : `dispatch('refreshAuthToken')` → token **rotaté**
  (`b7881e0c`→`4d54a254`), header Echo mis à jour, **canal `private-branch.1` reste
  subscribed:true** (Pusher ne ré-auth pas mid-connection ; le nouveau token est posé pour
  la prochaine reconnexion via le fix §3.1), et un broadcast post-refresh est reçu en **6 ms**.
  → la rotation 2h ne casse PAS le sync live. C'est la garantie all-day de P-AUTH.

---

## 3. P-LIVE-SYNC — VALIDÉ + 1 P1 RÉEL CORRIGÉ (W2/W4, commit `5f2c6947f`)

### 3.1 Le défaut réel trouvé (P1) — race de token au subscribe
- **Symptôme live** : au login frais du chef, le canal `private-branch.1` finissait
  `subscribed:false` et **ne récupérait jamais** sans reconnexion WS → la cuisine tournait
  silencieusement sur le poll 60 s au lieu du push sub-seconde.
- **Racine** (trouvée par l'agent WS-auth en lecture code ET confirmée live) :
  `window._refreshEchoAuth()` lit le Bearer depuis **localStorage**, mais il est appelé
  **synchroniquement dans les mutations `authLogin`/`authTokenRefreshed`** — AVANT que
  `vuex-persistedstate` (subscribe post-mutation) n'écrive localStorage. Il injectait donc
  le token PRÉCÉDENT ; le subscribe canal suivant échouait l'auth ; Pusher ne re-tente pas
  un subscribe en échec terminal.
- **Fix** : `_refreshEchoAuth(explicitToken)` — passer le token frais ; `authLogin` passe
  `payload.token`, `authTokenRefreshed` passe `token`. Fallback localStorage conservé pour
  le filet réactif `subscription_error` + login kiosk.
- **Preuve avant/après (live, chef branch 1, origine `localhost:8000` = APP_URL)** :
  - AVANT : login réel → SPA-nav KDS → `private-branch.1` **subscribed:false**.
  - APRÈS : même chemin → header Echo == token frais AU MOMENT de la mutation →
    **subscribed:true** au 1er essai, automatiquement.

### 3.2 Le bus WS lui-même — VIVANT
- Broadcast direct → `private-branch.1` → socket chef abonné reçu en **6 ms** (l'appel
  broadcast lui-même 8 ms). Socket vivant (pong reçu), `connState:connected`.
- Canal/nom alignés (agent Architect, vérifié) : serveur `private-branch.{id}` brut ↔ client
  `Echo.private('branch.{id}')` → `private-branch.{id}`. Pas de double-préfixe.
- Le fix dédupe `aggregateId` (`eventContract.js:264`, session précédente) jugé **SOUND**
  par l'agent : byte-identique pour les events order, ne peut ni perdre des events distincts
  ni rater un vrai doublon.

### 3.3 Dégradation WS → poll fallback (LIVE-validé, pas seulement code)
- WS coupé live (`pusher.disconnect()`) → état `disconnected` → le composant flippe
  `wsConnected=false` et **le poll 5 s prend le relais** : à **t=5003 ms** le fetch réel
  `admin/kds-order` (+`kds-order/items`) part. Reconnexion → `connected`.
- L'endpoint poll renvoie du **VRAI JSON** (`content-type: application/json`, clés
  `server_now/branch_id/version/orders/deleted_ids`) — pas un masquerade HTML du catch-all
  SPA (piège vérifié : `window.axios` baseURL=`/api`, donc chemin `/admin/...` PAS `/api/admin/...`).
  Le SRE-agent confirme que ce poll lit `orders` EN DIRECT (KdsSyncService.php:96), pas l'outbox
  → **0 perte de donnée** même bus de broadcast mort.
- ⚠️ Distinction clé : **WS-down** (soketi/réseau) → poll **5 s** (validé ici). **Worker mort**
  (soketi UP mais `queue:work` mort) → Pusher reste « connected » → poll reste **60 s** + events
  s'empilent dans l'outbox = c'est l'item ouvert **O-1 (P1)** ci-dessous, distinct, monitored.

---

## 4. P-COMES-OUT — VALIDÉ LIVE (W3)

- Transition réelle via l'endpoint KDS réel : `POST /api/admin/kds-order/change-status/427`
  `{status:8, expected_status:7}` (PREPARING→PREPARED) → **HTTP 202**.
- DB : order 427 `status=8` (persisté). `domain_events` id=587 `order.status_changed`
  channel `["private-branch.1"]` `broadcast_as=OrderStatusChanged` dispatched attempts=1.
- **Live** : `OrderStatusChanged` reçu sur le canal chef **512 ms après le POST** — cascade
  complète réelle (HTTP → event domaine → outbox → worker → broadcast → soketi → socket chef),
  dominée par le `queue:work sleep=1`.
- Visuel KDS : board propre, items canoniques (Galette Normale / Coca-Cola 33cl), i18n FR,
  branding Cayenne, 0 erreur console, états « Prêt » / « EN ATTENTE ENCAISSEMENT » corrects.

---

## 5. Items OUVERTS — honnêtes, non corrigés ici (priorisés)

| # | Sévérité | Item | Mitigation existante | Source |
|---|---|---|---|---|
| O-1 | **P1** | Worker `queue:work` mort + soketi up → toutes surfaces affichent « live » mais dégradent en silence au poll 60 s | `outbox:monitor` (1/min) + `/api/health/ready` 503 + `ws:heartbeat` ; le poll lit `orders` EN DIRECT donc **0 perte de donnée** (push perdu, pas data). Dépend du runbook prod supervisant worker+cron | agent Architect + SRE |
| O-2 | **P2** | Orphelin outbox `attempts≥5` (crash-claimed) hors de toute voie d'auto-recovery → re-drive manuel | rescue lane = `attempts<5` ; `retry-failed` = `dispatched_at NULL` ; monitor alerte seulement. Poll DB récupère l'état order quand même | agent SRE (`OutboxRescueCommand:47`) |
| O-3 | **P2** | `/api/refresh-token` (middleware `installed,apiKey`, PAS `auth:sanctum`) utilise `findToken()` sans check d'expiration → un token expiré peut être rafraîchi ~24h (jusqu'à `sanctum:prune-expired`) | abilities préservées (pas d'escalade) ; fenêtre bornée par le prune quotidien | agent WS-auth |
| O-4 | P3 | Admin (`branch_id=0`) KDS = poll 60 s par design (pas de push) | volontaire/documenté ; le vrai poste cuisine est un compte branche | composant `:1896` |
| O-5 | P3 | Origine d'accès doit matcher `APP_URL` (`localhost:8000`) sinon l'auth canal WS 302 (cross-origin) | discipline d'accès ; CLAUDE.md §6 liste `127.0.0.1` (incohérent avec APP_URL) | live (302 sur 127.0.0.1) |

---

## 6. Convergence / preuves

- **Vitest** : 1878 passed | 3 skipped | **0 failed** (275 fichiers) — après les 2 fix.
- **PHP** : **2714 passed | 0 failed** (1 risky, 2 incomplete, 29 skipped — tous pré-existants/documentés : fixtures load S72/S73 « owner-finalize »). Suite séquentielle complète, 421 s. Identique au baseline vert → 0 régression backend (changements de session = JS-only).
- **NF525** : `fiscal:verify-chain --all` → **CHAIN OK** (branche 1).
- **Frozen** : SHA256 baseline sentinelle **verte** ; diff frozen de session = `ZReportService +21`
  sous `LOCK_ZREPORT_REFUND_NETTING` (gate owner, SHA à jour). Travail sync = **0 frozen**.
- **Agents adversariaux** (3, read-only, rapports sur disque dans ce dossier) :
  `sync-cascade-architect.md` (cascade SOUND, 0 P0 / 1 P1), `ws-auth-token-refresh.md`
  (refresh conditionnellement safe, P2-A/P2-B), `sync-degradation-sre.md` (survie shift
  CONDITIONAL-YES, worst-case staleness ≈60 s, backstop poll-DB).

## 7. Commits de la campagne
- `3c1fa0eb7` — feat(P-AUTH) refresh proactif 2h (+ correction sentinelle admin-shell stale).
- `5f2c6947f` — fix(P-AUTH-SYNC) ré-injection Echo token frais → subscribe chef OK au login.

## 8. Verdict superviseur
Les 3 états non-living sont **adressés et prouvés live**. Le sync EST vivant (push 6 ms,
bout-en-bout ~0,5–1 s) une fois le canal abonné — ce que le fix garantit désormais dès le
login. Restent **O-1 (P1, monitored, 0 perte donnée)** et **O-2/O-3 (P2)** comme durcissement
opérationnel/cloud-prep, **non bloquants pour V1 LOCAL Le Cayenne** (single-box, runbook
supervise worker+cron ; le poll-DB est le filet). Pas de « tout validé » creux : ce qui est
vert est mesuré, ce qui reste est nommé.

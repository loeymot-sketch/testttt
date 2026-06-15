# SAFE LOT (post-ship KDS) — ADVERSAIRE RED VERDICT

- **Date** : 2026-06-15
- **Worktree** : `.claude/worktrees/post-ship-safe-2026-06-14`
- **Branche** : `heal/post-ship-safe-2026-06-14` (HEAD `7223e3a7a`, off tronc poussé `f2c12bed7`)
- **Mode** : READ-ONLY (aucune édition de prod, aucun commit). 1 test jetable créé puis supprimé.
- **Posture** : disputer/réfuter les 3 fixes commités, chercher la régression.

## VERDICT GLOBAL : **3 CONFIRMED / 0 REFUTED → GO pour push de la branche**

Aucun défaut réel trouvé. Tous les angles d'attaque demandés ont été couverts et REFUTÉS
(c.-à-d. le fix tient). 0 finding neuf P0–P3.

---

## B-KDS-01 `832ec5de5` — cap-50 FIFO + vrai backlog DB → **CONFIRMED**

`KitchenDisplaySystemOrderService::list()` + `KitchenDisplaySystemController` (hors-frozen).

| Dispute | Résultat | Preuve |
|---|---|---|
| (a) Fenêtre TZ + sentinel `KdsTodayWindowTzSentinelTest` tiennent ? | **REFUTÉ (fix OK)** | Re-run vert : **3 tests / 6 assertions OK**. Le bloc TZ (L127-172) est inchangé par le diff (hunks ne touchent que L29/L57/L167-185). |
| (b) `(clone $query)->count()` clone-t-il APRÈS filtres+fenêtre ? | **REFUTÉ (fix OK)** | `$query` est muté en place par tous les `->where()` (Eloquent retourne `$this`) jusqu'à L172 ; le clone L178 est pris **après** TZ-window + status/branch filters. Prouvé empiriquement (test jetable supprimé) : 70 actives→backlog 20 ; `status=PREPARING`→clone ne compte que les 60→backlog 10 ; `status=ACCEPT` (10)→backlog 0 / overflow false. |
| (c) Tri d'affichage `id desc` respecté sur les 50 retenues ? | **REFUTÉ (fix OK)** | Le board hardcode `order_column:"id"` + `order_by:"desc"` (L1138-1139, jamais muté). Le fetch FIFO `id asc` puis re-tri in-mem `sortByDesc('id')` (gardé `orderColumn==='id' && orderType==='desc'`) couvre EXACTEMENT le seul cas demandé en prod. |
| (d) `historyToday()` intact ? | **REFUTÉ (fix OK)** | 0 occurrence dans le diff. Méthode isolée (L240) : n'utilise ni `$query` de `list()` ni `lastListBacklog/Overflow`. État reset en tête de `list()` (L60-61). |
| (e) Régression filtrage status/branche ? | **REFUTÉ (fix OK)** | Voir (b). Suite KDS complète **118 tests / 410 assertions OK**. `KdsFeedFifoCapTest` **3/3 OK** (cap 50, FIFO head=`min(id)` servi, backlog=total−50). |

Note : `lastListOverflow` passe de `limit(51)->get()->count()>50` (hack row-fetch) à
`(clone $query)->count()>50` (vrai count). Sémantique équivalente, plus propre. PHP lint clean
sur les 2 fichiers backend.

---

## B-KDS-02 `2d958251b` — offline V2 (`v2OfflineSince`) → **CONFIRMED**

`KitchenDisplaySystemComponent.vue` (hors-frozen). Bundle `admin-kds.js` rebuild `7223e3a7a`.

| Dispute | Résultat | Preuve |
|---|---|---|
| (a) Guard `=== null` empêche le ré-armement (sinon chrono ne dépasse jamais 60s) ? | **REFUTÉ (fix OK)** | Source : `if (useV2Layout && v2OfflineSince === null) v2OfflineSince = Date.now()` aux 3 sites d'armement (WS-disconnect, poll-fail `_refreshWithCurrentFilter`, list-fail `list`). Bundle minifié vérifié : `seV2Layout&&null===e.v2OfflineSince&&(e.v2OfflineSince=Date.now())` ×3 → le guard a survécu à la minification. Spec : 2e `disconnected()` ne reset PAS le stamp. |
| (b) Legacy (`useV2Layout=false`) → `v2OfflineSince` reste null ? | **REFUTÉ (fix OK)** | Guard `useV2Layout &&` à chaque armement. Spec dédiée (poll-fail legacy + WS-disconnect legacy) : reste `null`. Legacy garde son `kdsErrorBanner` (`_raiseKdsErrorBanner`, non armé). |
| (c) Poll transitoire <60s qui réussit reset-il bien ? | **REFUTÉ (fix OK)** | Succès → `v2OfflineSince = null` inconditionnel (4 sites : WS-connect, poll-success, list-success). Spec : `v2OfflineSince=Date.now()-5000` puis poll success → `null`. |
| (d) Legacy `kdsErrorBanner` intact ? | **REFUTÉ (fix OK)** | `_raiseKdsErrorBanner`/`_clearKdsErrorBanner` inchangés ; le fix n'ajoute que des lignes `v2OfflineSince` autour. |

Re-run `kdsOfflineBanner.spec.js` : **5 tests OK**. Bundle frais (build 09:01 > source 08:55),
contient bien la logique armée.

---

## B-KDS-03 `3d1b9c78b` — toast 409 legacy → **CONFIRMED**

`KitchenDisplaySystemComponent.vue::orderStatus()` (hors-frozen).

| Dispute | Résultat | Preuve |
|---|---|---|
| (a) 409 → refresh silencieux, pas d'autre toast ? | **REFUTÉ (fix OK)** | L2377-2382 : `if (409) { _debouncedRefresh(); return; }` — la ligne `alertService.error(kds_status_conflict)` est supprimée, early-return avant tout autre toast. |
| (b) Chemin non-409 (422/500) affiche TOUJOURS l'erreur ? | **REFUTÉ (fix OK)** | Fall-through L2385-2386 : `alertService.error(msg)`. Spec `DOES toast on 422` vert. |
| (c) `recall()` (autre flux 409) INTOUCHÉ ? | **REFUTÉ (fix OK)** | `recallOrderOnServer()` L1934-1939 garde son 409 `something_went_wrong` + badge — hors du diff. La seule occurrence `kds_status_conflict` restante dans le bundle (×1) = `onV2ChangeStatus` (banner V2 L1682), légitime. |
| (d) `onV2ChangeStatus` intouché ? | **REFUTÉ (fix OK)** | L1658-1685 hors diff ; déjà silencieux sur 409 (modèle dont s'aligne le legacy). |

Re-run `kdsLegacyConflictToast.spec.js` : **2 tests OK**.

---

## Vérifications transverses

- **Frozen-zone diff `f2c12bed7..HEAD`** sur les 16 chemins frozen (kiosk ×3, PaymentComponent,
  PosV5TrancheRow, **pos-wizard.js / .css / admin-pos-v4.blade**, FiscalSequence/ZReport/AuditLog,
  BranchScope, IdempotencyKeyMiddleware, PricingService, OrderStateMachine) = **VIDE**.
  `public/js/pos-wizard.js` (vanilla hand-written, non-mix) NON touché.
- **Full Vitest** : **376 files / 2525 tests passed, 3 skipped, 0 fail**.
  - 1 « Uncaught Exception » = bruit de teardown async (`setTimeout` dans le frozen
    `KioskWizardComponent.vue` qui tire après tear-down jsdom → `document is not defined`).
    PAS une régression : le spec passe **9/9 en isolation** ; le `.vue` kiosk SOURCE n'est PAS
    touché (frozen diff vide) ; seuls les bundles kiosk ont été recompilés.
- **Bundles non-KDS recompilés** (`kiosk-wizard.js`, `pos-app.js`, `admin-oss.js`,
  `admin-reports.js`, etc.) : **1 ligne changée chacun = renumérotation des chunk-IDs webpack**
  (`9475`→`6499`, `n(6…`→`n(2…`), conséquence normale d'un rebuild mix complet quand `admin-kds`
  gagne du code. Aucune logique modifiée. Bénin.
- **PHPUnit KDS** (`--filter Kds|KDS|KitchenDisplay`) : **118 tests / 410 assertions OK**.
- **B-OSS-01 `a6bd99124`** (rideur de branche) : commit **sentinel-only** (NO-OP refuté,
  0 fichier prod). `posOrdersTrackerLanes.spec.js` **3/3 OK**. Inoffensif.

## Findings neufs : AUCUN.

## Recommandation : **GO** — la branche `heal/post-ship-safe-2026-06-14` est pushable.
(Le push lui-même reste un gate owner par §10 / §3quater — RED ne pousse pas.)

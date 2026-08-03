# KDS/OSS — Lentille SYNC/LOGIQUE (sync temps-réel + dégradation/poll) — r1

Rôle: SRE/Sync. Mandat READ-ONLY (SELECT only). DB `foodking_e2e`. Aucun fichier modifié.
Sous-système ciblé: Sub 3.c (Echo/poll/dégradation) + recouvrement Sub 3.d (OSS sync).

## VERDICT GLOBAL: backbone sync ROBUSTE — 0 P0 / 0 P1. 2 observations P3 (fail-safe, self-healing).

Toutes les défenses des germes adversaires sont déjà en place ET prouvées vertes:
- Release-filter SSOT (`visible == bumpable`): `KitchenReleaseRule::applyBoardReleaseFilter` (SQL)
  miroir de `orderIsReleasedForBoard` (in-memory, Service:447). Admet PAID(5)|PENDING_COUNTER(15)|POS+CASH.
  Tests: `KitchenReleaseRuleTest` 7/7, `KdsUnreleasedOrderBumpTest` 1/1, `KdsUnreleasedOrderBumpP1Test` 5/5.
  Data live confirme: orders status∈{4,7,8} payment=10(UNPAID) order_type=5 NON-POS = correctement
  EXCLUS du board; status=4 payment=15(PENDING_COUNTER) order_type=10 pos=6 (kiosk/takeaway counter-deferred)
  ×43 = correctement INCLUS (flux Plan B borne→caisse).
- Cadence clamp [250ms, 60_000ms] base + [0, 30_000ms] jitter, symétrique KDS+OSS:
  `KdsSyncService.js:463-498` (clampBase/clampJitter), `OssSyncService.js:437-442` (_clampCadence).
  Misconfig 999999999 → clampé à 60s (pas de freeze 11.5j). Tests: `kdsCadenceFloor` 9/9, `posOssCadenceCap` 11/11.
- Reconnect-storm: breaker AWS decorrelated-jitter `WebSocketService.js:277-352` (4 disconnect/30s →
  cool-down 5-30s + 1 reconnect); KdsSyncService réagit avec jitter 0-500ms `KdsSyncService.js:263-281`.
  Dedup id+version: `KdsSyncService.js:174-187` (`version <= previousVersion` → gated, ringbuffer 256).
  Tests: `kdsReactsToReconnectStorm` 3/3.
- 5xx backoff ×2 cap 30s `KdsSyncService.js:372-394`; erreur réseau → `_schedule()` self-heal dans le
  catch `:224-226` (jamais de poll-loop figé). OSS: backoff sur 4xx/5xx/réseau `OssSyncService.js:311`.
  Tests: `kdsBackoffOn5xx` 3/3, `ossSyncFallback` 4/4.
- Bannière dégradation FAIL-SAFE-TO-VISIBLE (mandat « silencieux = grave »): V2 (défaut) rend
  `KdsStatusBanner` « SYNC · LOCAL » quand `fallbackMode=true` (KdsV2Grid:26 → KdsStatusBanner:108-114).
  `wsConnected` init `!!(window._wsService?.isConnected())` (Component:1141): _wsService absent → false →
  bannière visible. `isConnected()` strict `=== CONNECTED` (WebSocketService:118) → tout état non-connecté
  (connecting/unavailable/failed/session_invalid/disconnected) → bannière visible. AUCUN trou silencieux.
- Paris-TZ: bornes Carbon `config('app.timezone')` (Service list:121-124, historyToday:226-228,
  KdsSyncService:91-94). Tests: `KdsSyncTzAwareTest` 1/1, `KdsSyncSargableTest` 1/1.
- Frontière PII: public OSS `CDSOrderDetailsResource` = id/order_serial_no/token/queue_number/order_type/
  status SEULEMENT (0 nom/tél/adresse/total). `listForBranch` allowlist KIOSK/TAKEAWAY + status∈{7,8} +
  scope branche. Admin `KdsSyncController` cross-branch → 403 (`:60-66`). `Admin/KdsSyncControllerTest` 8/8.

Vitest sync: 45/45 (kdsBackoffOn5xx, kdsCadenceFloor, kdsReactsToReconnectStorm, kdsSyncCadence,
kdsV2KillSwitch, ossChimePublicWall, ossSyncFallback, posOssCadenceCap, orderStatusScreenOssSync).
PHPUnit sync/release: 23/23 (sqlite :memory:, READ-ONLY, foodking_e2e non touché).

---

## FINDINGS

[P3] config/kds.php:57 — knob `kds.show_fallback_banner` (.env KDS_SHOW_FALLBACK_BANNER) INERTE (non câblé)
  repro: grep "FK_KDS_SHOW_FALLBACK_BANNER" resources/views/master.blade.php → 0 occurrence (seuls
    FK_KDS_V2_DEFAULT_ENABLED:246, ossFallbackPolling:179, kdsFallbackPolling:187 sont injectés).
    Le SEUL lecteur de `window.FK_KDS_SHOW_FALLBACK_BANNER` est le composant (Component:1340) — jamais SET
    côté serveur → `=== false` toujours faux → `kdsSuppressFallbackBanner()` retourne toujours false →
    bannière TOUJOURS visible quand WS down, même si l'opérateur met KDS_SHOW_FALLBACK_BANNER=false.
  evidence: config/kds.php:43-60 documente lui-même « wiring THIS config value through master.blade.php
    is DEFERRED » + Component:1328-1331 « wiring … is deferred ». Cohérent avec le code, mais le knob
    .env annoncé ne fait RIEN aujourd'hui.
  lentille: technique (honnêteté de config — pas de risque cuisinier: l'état par défaut est fail-safe).
  reco: soit câbler `window.FK_KDS_SHOW_FALLBACK_BANNER = @json((bool) config('kds.show_fallback_banner'))`
    dans master.blade.php (1 ligne, NON-frozen), soit retirer le knob .env de la doc tant que différé.
    NON-bloquant V1-LOCAL: le comportement par défaut (bannière visible) satisfait déjà le mandat owner.

[P3] app/Services/KdsSyncService.php:180 — version-gate delta = updated_at en SECONDES → double-transition
     même-seconde transitoirement avalée sur le canal delta (self-heal via full-list poll)
  repro: `computeOrderVersion` retourne `optional($order->updated_at)->getTimestamp()` (secondes; colonne
    orders.updated_at = TIMESTAMP sans fraction — vérifié `SHOW COLUMNS FROM orders`). Le client gate
    `version <= previousVersion` (KdsSyncService.js:178). Deux transitions de statut dans la MÊME seconde
    wall-clock produisent le même `updated_at` → même version → 2ᵉ delta gated (avalé).
    Atteignable en data réelle: `SELECT order_id, occurred_at, COUNT(*) FROM order_status_transitions
    GROUP BY order_id, occurred_at HAVING COUNT(*)>1` → order 5004 = 3 transitions à 2026-06-19 23:01:02;
    order 113 = 2 à 2026-05-28 19:12:11.
  evidence: docstring KdsSyncService.php:165-172 (« TODO D-03bis … status moved without other field
    updates »). Le delta-gate `on('sync')` du composant ignore la carte si tous gatés (Component:1551
    `hasFreshOrders = orders.some(o => !gatedIds.includes(o.id))`).
  lentille: cuisinier (carte potentiellement en retard sur le delta) — MAIS auto-corrige: le poll
    plein-liste NON-gaté (`startAutoRefresh`→`refreshOrderList`→`list()`, Component:1914-1933, cadence
    5s WS-down / 60s WS-up) + le handler Echo `OrderStatusChanged`→`_debouncedRefresh` re-fetchent le
    statut autoritatif. Donc PAS de perte permanente: la board converge ≤5s (WS-down) / ≤60s (WS-up).
  reco: déféré dette technique D-03bis (déjà tracké MEGA_PLAN_SYNC_HARDENING_v3 §5): basculer version sur
    `max(updated_at_unix, status_changed_at_unix)` quand la colonne `status_changed_at` arrivera.
    NON-bloquant V1-LOCAL mono-poste: le full-list poll couvre déjà le cas; impact = latence delta, pas
    commande ratée. Aucun fix frozen requis.

---

## NON-FINDINGS (germes adversaires VÉRIFIÉS RÉFUTÉS / déjà couverts)
- « soketi mort → board fige SANS bannière »: RÉFUTÉ. Par défaut (box Le Cayenne, flag undefined)
  bannière VISIBLE en V2 et legacy (cf. chaîne ci-dessus). Suppression UNIQUEMENT si local ET opt-out
  explicite `window.FK_KDS_SHOW_FALLBACK_BANNER===false` (jamais set sur la box).
- « cadence misconfig 999999999 → freeze »: RÉFUTÉ — clampé 60s (KdsSyncService.js:481, OssSyncService.js:441).
- « bump 202 mais broadcast échoue → board ment »: COUVERT — interceptor axios self-heal sur 2xx
  change-status (Component:1522-1542) + dispatch post-commit en try/catch \Throwable (Service:477-493,
  ne re-wrap jamais un bump committé en 422).
- « reconnect-storm → N refresh »: COUVERT — dedup id+version + jitter 0-500ms + breaker.
- « bump non-payé → notif client avant paiement »: COUVERT — release-guard Service:447 (sister du filtre
  SQL list). UNPAID non-cash → 422.
- « fuite PII sur surface authed servant le mur »: public path = CDSOrderDetailsResource (0 PII); admin
  path (PosShortcutOrderResource, expose total) sert le widget POS authentifié, pas le mur public.

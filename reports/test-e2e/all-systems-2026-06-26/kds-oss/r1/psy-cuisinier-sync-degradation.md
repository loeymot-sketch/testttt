# KDS/OSS — Lentille PSYCHOLOGIE CUISINIER · Sous-système « Sync temps-réel + dégradation/poll »

Round r1 · DB foodking_e2e (READ-ONLY) · 0 fichier modifié · 0 fichier frozen touché.
Vecteurs abusés : perte sync silencieuse en local · bannière dégradation fail-safe ·
clamp cadence misconfig · reconnect-storm dedupe · bump 202-broadcast-fail self-heal ·
OSS poll-only lag · double-chime.

Tests exécutés (anti-régression sync, tous VERTS) :
`kdsSyncCadence(3) kdsCadenceFloor(9) kdsBackoffOn5xx(3) kdsReactsToReconnectStorm(3)
kdsV2KillSwitch(4) ossSyncFallback(4) posOssCadenceCap(11)` = 37/37 ·
`kdsRemediationComponents(6)` = 6/6.

---

## VERDICT GLOBAL DE LA LENTILLE

Le germe central — « soketi mort, le board fige sur poll SANS bannière → le cuisinier
croit la file à jour » — est **RÉFUTÉ pour l'avertissement immédiat**. La bannière de
secours est conçue **fail-safe-to-visible** et s'affiche bien par défaut sur la box
(local), aussi bien en layout V2 (défaut) qu'en legacy. La couche sync (clamp, backoff,
reconnect-storm, dedupe version, self-heal réseau, isolation listener, intercepteur
bump-202) est robuste et testée.

**1 seul défaut prouvé (P2)** : l'escalade vers la bannière ROUGE « OFFLINE » (la plus
visible, dégradation > 60 s) est du **code mort** — la prop source `v2OfflineSince`
n'est jamais affectée. Le cuisinier reste averti (bannière ambre « SYNC · LOCAL »
visible immédiatement), mais ne reçoit JAMAIS l'alarme renforcée même après plusieurs
minutes hors-ligne. Pas une perte silencieuse ; une alarme « moindre que prévu ».

---

## FINDINGS

### [P2] resources/js/components/admin/kitchenDisplaySystem/KitchenDisplaySystemComponent.vue:1225 — Bannière ROUGE « OFFLINE » (dégradation > 60 s) = code mort : `v2OfflineSince` déclaré + lié à la prop mais JAMAIS affecté

- **repro** : `grep -n "v2OfflineSince" KitchenDisplaySystemComponent.vue` → 2 occurrences
  uniquement : `:38 :offline-since="v2OfflineSince"` (passage prop vers `KdsV2Grid`) et
  `:1225 v2OfflineSince: null` (déclaration data). Aucune assignation
  (`this.v2OfflineSince = …`) nulle part dans les ~3000 lignes, ni dans un watcher, ni
  dans `_bindWsService`/`_onWsDisconnected`. Confirmé identique dans le bundle compilé :
  `grep "v2OfflineSince" public/js/admin-kds.js` → `:1466 null` + `:4309
  "offline-since": $data.v2OfflineSince` (toujours `null`).
- **evidence** : `KdsStatusBanner.vue:81` — la branche `level:'error'` (rouge plein,
  40px, spinner, tag `OFFLINE`, libellé `kds_connection_lost_long`) est gardée par
  `if (this.offlineSince && nowMs - this.offlineSince > 60_000)`. Comme `offlineSince`
  reçoit toujours `null`, la condition est toujours fausse → la bannière rouge ne se
  rend JAMAIS en intégration. Le test `tests/js/kdsRemediationComponents.spec.js:16-30`
  est VERT (6/6) mais il **injecte `offlineSince` directement en prop** — il prouve que
  le composant fonctionne isolément, et **masque** le trou de câblage à la couture
  orchestrateur→grille (faux-vert au seam). En V2 (layout par défaut), la seule alerte
  de dégradation effective est donc l'ambre `fallbackMode` (« SYNC · LOCAL », 32px).
- **lentille** : cuisinier — en rush, une coupure soketi prolongée (>1 min) devrait
  crier en rouge plein écran (« CONNEXION PERDUE 2m 13s »). Au lieu de ça il n'a qu'un
  liseré ambre discret en haut, qui peut passer inaperçu sur un mur cuisine ; il croit
  voir une simple notice « LOCAL » habituelle et continue à se fier au board.
- **reco** : câbler `v2OfflineSince` dans le cycle WS de l'orchestrateur (HORS-frozen,
  fichier éditable) : dans `_onWsDisconnected` poser `this.v2OfflineSince = Date.now()`
  (si non déjà posé), et dans `_onWsConnected` le remettre à `null`. Ajouter un test
  d'intégration `tests/js/kdsOfflineEscalationWiring.spec.js` qui monte l'orchestrateur,
  simule `disconnected` puis avance le temps > 60 s et asserte la bannière rouge
  `tag=OFFLINE`. Scope-minimal, ~2 lignes + test. Le germe « moindre alarme » disparaît.

---

## CONFIRMATIONS (germes RÉFUTÉS — non-findings, gardés pour la convergence)

- **Bannière de secours fail-safe-to-visible — OK.** `config/kds.php:60`
  `show_fallback_banner` (défaut `true`) documente le contrat mais son câblage vers
  `window.FK_KDS_SHOW_FALLBACK_BANNER` est **différé** (commentaire config + composant
  `:1327-1331`). Le global n'est donc JAMAIS posé : `grep "FK_KDS_SHOW_FALLBACK_BANNER"
  resources/views/` = 0 assignation. Gate `:1340`
  `return env === 'local' && window.FK_KDS_SHOW_FALLBACK_BANNER === false` → sur la box,
  `undefined === false` = `false` → **non supprimée → bannière VISIBLE**. Vérifié en V2
  (`:40 :fallback-mode="!wsConnected && !kdsSuppressFallbackBanner"` →
  `KdsStatusBanner:108`) ET en legacy (`:70 v-if="!wsConnected &&
  !kdsSuppressFallbackBanner"`, `data-testid=kds-sync-mode-banner`). Le risque
  historique « hide in local » est neutralisé (commentaire PR-02 core-bulletproof).
- **`wsConnected` réactif à la coupure — OK.** `_bindWsService:1893-1906` écoute
  `connected`/`disconnected` de `window._wsService`. `WebSocketService:234-239` émet bien
  `connected` (état CONNECTED) ET `disconnected` (DISCONNECTED/UNAVAILABLE/FAILED). Init
  `data: wsConnected: !!(window._wsService?.isConnected())` (`:1141`) → `false` au boot
  si soketi absent (`_state=INITIALIZED`, `isConnected()` faux). Si Echo absent,
  `bootstrap.js:419-422` force `_setState(UNAVAILABLE)` → `isConnected()` faux → bannière
  visible. Pas de blocage `wsConnected=true` fantôme.
- **Clamp cadence misconfig 999999999 — OK.** `KdsSyncService:25-26` + `:477-482`
  `clampBase` borne [250 ms, 60 000 ms] ; `clampJitter` [0, 30 000 ms]. `OssSyncService:34-35`
  + `:437-442` borne identique [250 ms, 60 000 ms]. Le germe « gel 11,5 j » est verrouillé
  (`kdsCadenceFloor` 9/9, `posOssCadenceCap` 11/11 verts).
- **Self-heal sur erreur réseau (board ne fige jamais sur outage HTTP+WS) — OK.**
  `KdsSyncService:224-226` re-`_schedule()` dans le `catch` réseau ; commentaire explicite
  « concurrent WS+HTTP outage would leave the kitchen permanently blind » → mitigé.
  `OssSyncService:_handleErrorBackoff` (4xx/5xx/réseau) + `_emit` isolant les throws
  listener (`:457-466`, « keep poll loop alive »).
- **Bump 202 mais broadcast échoue → self-heal — OK.** `KitchenDisplaySystemComponent.vue:1508-1542`
  installe un intercepteur axios response qui, sur tout POST `admin/kds-order/change-status/`
  en 2xx, déclenche un refresh immédiat (sans debounce) quel que soit l'appelant
  (méthode Vue, axios brut, futur tooling). Le board ne « ment » pas après une transition
  réussie sans broadcast.
- **Reconnect-storm → dedupe + jitter anti-troupeau — OK.** `WebSocketService:294-352`
  circuit-breaker decorrelated-jitter (4 disconnects/30 s → cooldown 5-30 s) ; émet
  `reconnect_storm` AVANT le `state_change` (`:342-351`) pour que le poll engage tout de
  suite. `KdsSyncService:263-279` réagit avec jitter 0-500 ms (anti-thundering-herd).
  Dedupe par version : `KdsSyncService:177-186` (`version <= previousVersion` → `gated`),
  `kdsReactsToReconnectStorm` 3/3 vert.
- **OSS mur poll-only « connected mais 0 push » → 5 s, pas 60 s — OK.**
  `PreparingAndReadyComponent.vue:266-271` (TRAP-4) force `intervalMsWhenConnected: 5_000`
  sur le mur public (`authBranchId() <= 0`) car `subscribeEcho` early-return
  (`:283 if (branchId <= 0) return`) → aucun push n'atteint la surface ; sans l'override
  le mur lagguerait ~1 min (budget SYNC-2 8 s). Authed (branchId>0) garde 60 s.
- **Double-chime Echo+poll sur PRÊT — OK.** `PreparingAndReadyComponent.vue:292-300`
  pré-enregistre l'ID dans `_echoMarkedReady` quand Echo livre PREPARED ; `:403-411`
  `_hydrateFromRows` saute les IDs déjà marqués (`!echoMarked.has(item.id)`) → un seul
  chime/flash. Chime gardé `authBranchId() > 0` (`:366`) car mur public sans geste
  audio-unlock (inaudible structurellement) ; flash visuel reste seul canal — documenté
  P0-OSS-01 Option C.
- **`state_change` mismatch de payload (`{previous,current}` émis vs `{from,to}`
  destructuré) — INOFFENSIF.** `KdsSyncService:241-243` destructure `{from,to}` (tous
  deux `undefined`) MAIS retombe sur `this.wsService.state` réel via `to || this.wsService.state`,
  et `_baseCadence:298` lit `this.wsService.state` directement, pas le payload. Aucun
  impact cadence/affichage (le ré-emit `{from,to}` côté composant ne sert qu'à bumper
  `syncNowTick`).

---

## DONNÉES DB (foodking_e2e, lecture release/sync)

`SELECT id,status,payment_status,branch_id FROM orders WHERE deleted_at IS NULL ORDER BY id DESC LIMIT 15`
→ toutes status=7 (PREPARING), payment_status=5 (PAID), branch_id=1 → toutes éligibles
board (release-guard satisfait). Aucun ordre dans un état sync-incohérent observé.
Lens sync : pas de finding DB (la lisibilité compo / 2-viandes / allergènes relève d'une
autre lentille du même système ; non re-traité ici).

---

## CHECKPOINT LENTILLE

- P0 = 0 · P1 = 0 · **P2 = 1** (escalade OFFLINE morte) · P3 = 0.
- Frozen touché : 0 (analyse READ-ONLY ; le seul fix proposé est HORS-frozen).
- Le défaut P2 est un câblage manquant côté orchestrateur (fichier éditable), non un
  risque NF525/fiscal, non une perte silencieuse. Sévérité V1-LOCAL : P2 (dégradation UX
  moindre, kitchen toujours avertie en ambre).

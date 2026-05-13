# K15 — Hardware / Offline / Speech / A11y composables

> Branche: `feature/mobile-app-le-cayenne-2026-05-10` — HEAD `6a33a9763`.
> Mode: READ-ONLY audit. Citations file:line vérifiées par lecture directe.

## Files audited

- `resources/js/services/kioskHardware.js` — 389 LOC (bridge wrapper Electron `window.borne.*` + stub)
- `resources/js/config/kioskHardware.js` — 37 LOC (timings/retries/stages constants)
- `resources/js/helpers/kioskPrinter.js` — 340 LOC (ESC/POS receipt builder + fallbacks)
- `resources/js/helpers/kioskReceiptPersistence.js` — 168 LOC (F5-proof snapshot localStorage)
- `resources/js/helpers/kioskOfflineQueue.js` — 653 LOC (queue v2 IndexedDB + lock + backoff + replay)
- `resources/js/helpers/kioskOfflineQueueDb.js` — 141 LOC (idb-keyval + localStorage fallback)
- `resources/js/helpers/kioskAnalytics.js` — 367 LOC (consent-gated tracking + whitelist + queue)
- `resources/js/composables/useKioskSpeech.js` — 222 LOC (Web Speech API + AR mp3 fallback)
- `resources/js/composables/useKioskA11y.js` — 193 LOC (sync store kioskSettings → `<html>` attrs)
- `resources/js/composables/useKioskTheme.js` — 245 LOC (light/dark/auto + prefers-color-scheme)
- `resources/js/store/plugins/kioskAnalyticsPlugin.js` — 106 LOC (mutation → analytics bridge)

Total: 2 861 LOC. Aucun fichier marqué FROZEN dans cette tranche.

## Findings

### P0 (blocker pre-merge V1)

#### K15-P0-01: Race condition `syncQueue` écrase les ordres saisis pendant la boucle
- **File**: `resources/js/helpers/kioskOfflineQueue.js:590`
- **Issue**: À la fin de `syncQueue`, `_queueCache = remaining;` remplace intégralement le cache. Si un appel à `saveOrder()` (via `kioskCart.js:783`) intervient *pendant* la boucle `for (const entry of _queueCache)` (lignes 529–588) — chose plausible car `_payloadWithFreshQuote` fait un POST réseau asynchrone par entrée — la nouvelle entrée enregistrée dans `_queueCache` (via `_mergeQueue` ligne 401) est **silencieusement perdue** quand `remaining` (qui ne la contient pas) écrase le cache. Le `_persistQueue` qui suit grave la perte dans IndexedDB.
- **Evidence**: `saveOrder` (ligne 395) ne s'attend pas au verrou de sync. `_syncInFlight` réutilise la promesse mais ne bloque pas `saveOrder`. Aucun test ne couvre `saveOrder` concurrent à `syncQueue`. À branche/borne occupée, ce scénario est inévitable (TPE qui prend 90 s, customer 2 qui paie cash hors-ligne entre temps).
- **Suggested fix**: Snapshoter `_queueCache` au début du `syncQueue` et calculer un diff lors de la fusion finale: `_queueCache = _mergeQueue(remaining, _queueCache.filter(e => !snapshotKeys.has(e.localKey)));`. Ajouter un test Vitest "saveOrder during syncQueue preserves new entry" basé sur `makeSharedQueueDbMockFactory`.

#### K15-P0-02: Plugin Vuex `kioskAnalyticsPlugin` registré globalement, mais consent jamais activé hors composant kiosk
- **File**: `resources/js/store/index.js:280` + `resources/js/components/frontend/kiosk/KioskAppComponent.vue:1040`
- **Issue**: Le plugin appelle `kioskAnalytics.track(...)` à chaque mutation `kioskCart/*` (`ADD_ITEM`, `REMOVE_ITEM`, `UPDATE_QUANTITY`). `kioskAnalytics.setConsent(...)` n'est appelé que dans `KioskAppComponent.vue` mounted, après l'utilisateur ait validé le `KsConsentModal`. Tant que ce hook n'est pas exécuté, `state.consent === false` et `track()` no-op (ligne 264 — OK). Mais **`saveOrder` émet `_track('offline.queued', ...)` à chaque enqueue** (ligne 415), même pendant la session de paiement initiale, **avant** consent décidé. Heureusement, `setConsent(false)` est l'état initial donc l'event est silencieusement dropped — pas de fuite. **MAIS**: après `setConsent(true)`, si l'utilisateur révoque depuis `KsConsentModal` (ligne 295–296 `setConsentLoyalty(false)` + `setConsentAnalytics(false)`), la queue de 200 events accumulée pendant le consent grant est vidée (ligne 161) — correct. Risque résiduel: aucun pour la fuite PII, P1 pour observabilité (les events offline silencieusement perdus pendant consent off ne sont pas re-tracés). 
- **Reclassement** → demoted to P1-04. *Aucune fuite RGPD vérifiée.* Maintenu ici uniquement pour transparence audit; le P1 entry plus bas reste applicable.

*(Note auditeur: ce finding est rétrogradé P1 après vérification ligne 264 de kioskAnalytics. Voir K15-P1-04 ci-dessous.)*

#### K15-P0-03: Whitelist analytics divergente — 5 events `offline.queue.v2.*` silencieusement droppés
- **File**: `resources/js/helpers/kioskAnalytics.js:62-102` vs `kioskOfflineQueue.js:223,267,459,542`
- **Issue**: Le helper `_track` (ligne 34 de `kioskOfflineQueue.js`) appelle `kioskAnalytics.track(eventName, ...)`. La whitelist `ALLOWED_EVENTS` (lignes 62–102) contient `offline.queued`, `offline.replayed`, `offline.abandoned`, `offline.recovered`. Le code émet aussi:
    - `offline.queue.v2.migrated` (line 223)
    - `offline.queue.v2.quota_exceeded` (line 267) — **critical: quota exceeded loss**
    - `offline.queue.v2.stale_marked` (line 459)
    - `offline.queue.v2.backoff_skip` (line 542)
- Ces 4 events ne sont **pas dans `ALLOWED_EVENTS`** → silent-drop ligne 268 (`if (!ALLOWED_EVENTS.has(eventName)) return false`). Le backend `KioskEventController::ALLOWED_ANALYTICS_EVENTS` (ligne 89 du controller) est donc parfaitement aligné côté backend, mais **côté frontend la perte est totale** — l'opérateur ne verra jamais "quota exceeded" sur 200+ commandes enfermées sur la borne ni les "backoff_skip" qui pourraient signaler une boucle dégradée.
- **Evidence**: Lignes 96–100 de kioskAnalytics.js: la liste s'arrête à `'offline.recovered'`. Le test `kioskOfflineQueue.spec.js:51-107` n'inclut aucune assertion sur `offline.queue.v2.quota_exceeded`. La régression est invisible.
- **Suggested fix**: Ajouter les 4 events `offline.queue.v2.{migrated,quota_exceeded,stale_marked,backoff_skip}` à `ALLOWED_EVENTS` (kioskAnalytics.js) ET à `ALLOWED_ANALYTICS_EVENTS` (backend KioskEventController). Ajouter test couvrant l'envoi effectif (mock sendBeacon) pour les 4 events.

#### K15-P0-04: Disparition silencieuse de la queue legacy v1 avant garantie d'écriture v2
- **File**: `resources/js/helpers/kioskOfflineQueue.js:220-225`
- **Issue**: La séquence migration:
    ```
    await setQueueEntry(QUEUE_KEY, migrated);   // 220
    _clearLegacyQueue();                          // 221  ← supprime LEGACY_QUEUE_KEY
    ```
- `setQueueEntry` (kioskOfflineQueueDb.js:108) utilise IndexedDB par défaut, fallback localStorage si KO. **Si IndexedDB lève un throw silencieux (quota, jsdom, Safari incognito iOS)**, on tombe dans `shouldFallback` qui ne propage *pas* l'erreur, écrit dans le localStorage fallback, retourne `value`. Mais la clé fallback est `__kiosk_idb_fallback__:kiosk:offline-queue:v2`, **différente de la clé legacy `kiosk_offline_queue_v1`**. Donc `_clearLegacyQueue()` supprime toujours v1, même si v2 n'a *jamais* atterri sur le bon backend pour cause de cascade fallback partielle. Pire: au prochain reload, `getQueueEntry(QUEUE_KEY)` (ligne 199) lira depuis localStorage fallback, et `_loadLegacyQueue` (ligne 232) trouvera vide → on perd N commandes hors-ligne avec impact fiscal direct (allocation `fiscal_sequence_no` côté backend = orphelin).
- **Evidence**: `kioskOfflineQueueDb.js:79-99` (`run` helper) avale silencieusement les errors qui matchent `shouldFallback`. Pas de signal d'échec côté caller. Le test `kioskOfflineQueueMigration.spec.js:28-58` valide le happy path; aucun test n'instrument un fallback IDB → localStorage durant migration.
- **Suggested fix**: Confirmer la persistence v2 (re-read via `getQueueEntry`) avant d'appeler `_clearLegacyQueue()`. Pseudocode:
    ```
    await setQueueEntry(QUEUE_KEY, migrated);
    const confirm = await getQueueEntry(QUEUE_KEY);
    if (Array.isArray(confirm) && confirm.length === migrated.length) {
        _clearLegacyQueue();
    } else {
        _track('offline.queue.v2.migration_failed', { ... });
    }
    ```

### P1 (high — V1.0.1 sprint)

#### K15-P1-01: Backoff calcule `now() + delay` au lieu de `lastFailedAt + delay` partiellement OK mais comparaison non-monotonic
- **File**: `resources/js/helpers/kioskOfflineQueue.js:288-291` + `539-540`
- **Issue**: `_computeNextRetryAt(entry)` retourne `entry.lastFailedAt + _computeRetryDelay(entry.attempts - 1)`. L'attribut `attempts` est incrémenté *avant* `lastFailedAt = failedAt` au cycle suivant (ligne 564–567), donc l'exponentiel `2^(attempts-1)` est cohérent. **MAIS** le `Math.max(0, attempts - 1)` (ligne 283) signifie qu'à `attempts === 1` on retry à `lastFailedAt + 1000` ms (pas 0). C'est légitime pour la 1e *failure*, mais le 1er essai initial (`attempts === 1, lastFailedAt === null`) est immédiat (ligne 289: `if (!entry.lastFailedAt) return 0` — OK).
- Le risque résiduel: `Date.now()` n'est pas monotonic (NTP slew). Sur une borne Electron en sleep/wake, un saut horloge négatif peut faire que `nextRetryAt > now()` reste vrai longtemps → starvation.
- **Suggested fix**: Considérer `performance.now()` rebased, ou capper le delay max à `MAX_BACKOFF_MS` côté condition (déjà fait ligne 285 OK), et ajouter une trace si `now() - lastFailedAt > 24h` → force retry immédiat (recovery automatique).

#### K15-P1-02: Receipt persistence: pas de `branchId` ni `kioskMachineId` dans le snapshot
- **File**: `resources/js/helpers/kioskReceiptPersistence.js:67-102`
- **Issue**: Le snapshot stocke `orderId, queueNumber, total, items, loyaltyCustomerName, etc.` mais **aucun identifiant de branche/borne**. Si la borne est démontée et le snapshot importé sur une autre borne (debug, support), `KioskConfirmationComponent.vue:271` exposera ce ticket à un client d'une autre franchise. C'est faible probabilité opérationnelle mais touche au principe `BranchScope` du projet.
- **Suggested fix**: Ajouter `branchId` et `kioskMachineId` au payload. Au read, refuser le snapshot si le contexte courant (`store.state.kioskCart.branchId`) ne match pas.

#### K15-P1-03: Printer fallback ne loggue pas l'échec `kiosk.confirmation.print_failed` côté analytics
- **File**: `resources/js/helpers/kioskPrinter.js:198-263`
- **Issue**: La fonction `printReceipt` retourne `{method: 'none', error: 'No print method available'}` quand `el === null` ou `window.print` indispo (ligne 262). L'i18n `kiosk.confirmation.print_failed` (fr.json:1856, en.json:2011, ar.json:1841) est correctement résolu côté UI dans `KioskConfirmationComponent.vue:44`. **MAIS** aucun appel à `kioskAnalytics.track('hardware_error', ...)` n'est fait depuis kioskPrinter quand on tombe en `method: 'none'`. `reportPrinterFailure` (ligne 269) existe mais n'est pas appelé automatiquement — la responsabilité retombe sur le composant Vue (frozen).
- **Suggested fix**: Dans `printReceipt`, après le fallthrough complet (ligne 262), appeler `reportPrinterFailure(receipt.queueNumber || 'unknown', 'No print method available')`. Couvrir par test Vitest (mock axios, expect post).

#### K15-P1-04: Plugin analytics commit avant consent gate — observability hole
- **File**: `resources/js/store/plugins/kioskAnalyticsPlugin.js:94-106` + `kioskAnalytics.js:264`
- **Issue**: Les mutations `kioskCart/ADD_ITEM` etc. sont émises *avant* que `KsConsentModal` ne soit affiché (le user choisit un item d'abord). Tous les events `add_to_cart`, `remove_from_cart`, `quantity_changed` *entre* `IdleScreen → Wizard → Cart → ConsentModal` sont silencieusement droppés. Si la borne offre un funnel A/B sur la phase wizard, l'analytics rate exactement la moitié signal le plus utile.
- **Suggested fix**: Buffer in-memory (cap 100 events) jusqu'au verdict consent. Si grant → drain buffer; si denied → flush sans envoi. Cohérent avec `state.queue` (ligne 113) déjà présent mais inutilisé pour ce cas (la doc ligne 158–161 indique au contraire "ils sont JETÉS" — un choix légal acceptable mais qui mérite confirmation owner gate).

#### K15-P1-05: Speech composable n'expose pas de fallback visuel pour ar locale sans key
- **File**: `resources/js/composables/useKioskSpeech.js:139-166`
- **Issue**: `locale === 'ar' && opts.key` est exigé pour le fallback mp3. Si un caller passe `speak(text, {locale: 'ar'})` *sans* `key`, la fonction tombe sur Web Speech (ligne 169) qui n'a très probablement pas de voix `ar-*` installée → résolve `false` silencieusement. **Aucun event analytics** signal cette dégradation. WCAG 1.4.4 (User-controllable audio) est respecté grâce à `audio: false` par défaut (kioskSettings.js:99) — vérifié OK — mais le cas "ar sans mp3" laisse l'utilisateur a11y sans support audio sans signal.
- **Suggested fix**: Tracer un `hardware_error` event quand AR speech échoue sans clef OU sans voix. Documenter dans le composable que `key` est *obligatoire* pour ar.

### P2 (medium — backlog priorisé)

#### K15-P2-01: `bootstrapKioskThemeEarly` lit localStorage avant Vuex
- **File**: `resources/js/composables/useKioskTheme.js:232-243`
- **Issue**: Le bootstrap synchrone (anti-FOUC) lit `kiosk_theme_v1` *avant* l'hydration Vuex. Si l'admin a poussé une valeur via `setTheme` qui n'a pas été persistée (par `vuex-persistedstate`), il y aura un flash. Non-critique en V1.
- **Suggested fix**: Aligner les deux chemins de persistence sur la même clé. Ajouter test Vitest pour le scénario.

#### K15-P2-02: Receipt persistence TTL fixe 1 h — pas configurable depuis kioskSettings
- **File**: `resources/js/helpers/kioskReceiptPersistence.js:38`
- **Issue**: `DEFAULT_TTL_MS = 3 600 000`. Pas de pont vers `kioskSettings.receiptMs` (qui est le délai *écran*, certes différent, mais correlé). Sur une borne mode "express" où le ticket est servi en 5 min, 1 h c'est long; sur "table service" 30 min de cuisson, 1 h c'est court (consommateur revient après livraison).
- **Suggested fix**: Exposer un override via `kioskSettings.receiptSnapshotTtlMs` (clamped 5 min ↔ 4 h).

#### K15-P2-03: A11y composable: pas de focus trap intégré
- **File**: `resources/js/composables/useKioskA11y.js`
- **Issue**: Le composable applique des attributs HTML (contrast, pmr, audio, audioDescription, reducedMotion, lang, dir) sur `<html>` — c'est sa charge. Mais il **n'expose pas** d'API focus trap / announce / scroll lock, contrairement à ce que suggère le scope K15. Les focus traps sont implémentés ad-hoc dans `KsConsentModal.vue` et `KioskOfflineConflictModalComponent.vue` (testé `kioskOfflineQueueV2.spec.js:505-524`). Pas un bug, mais un manque structurel — chaque modal réinvente. 
- **Suggested fix**: Phase 8.12 — créer `useKioskFocusTrap()` réutilisable et migrer les 4–5 modals existants.

#### K15-P2-04: Hardware bridge `runSafe` retourne `{ok: true, data: result}` quand le bridge ne retourne pas `{ok}`
- **File**: `resources/js/services/kioskHardware.js:106-116`
- **Issue**: La heuristique "si pas `ok` dans le result, on enveloppe en `{ok: true, data: result}`" peut masquer un cas où le bridge retourne `{success: false, error: 'foo'}`. Pas un retour explicite mais shape légitime côté Electron.
- **Suggested fix**: Si `result?.success === false`, retourner `fail(result.error || 'bridge_failure')`. Documenter le contrat exact dans `docs/design/KIOSK_HARDWARE_CALLS.md`.

#### K15-P2-05: Pas de cap sur la queue analytics (200 events) qui dépasse `MAX_QUEUE`
- **File**: `resources/js/helpers/kioskAnalytics.js:230`
- **Issue**: `MAX_QUEUE = 200`. À 5 events/min × 8 h, on est à 2 400 events/jour. Cap FIFO eviction = OK, mais aucune trace n'est conservée lors de l'éviction. Donc on perd silencieusement les *plus anciens* events sans signal.
- **Suggested fix**: Tracer un `offline.queue.v2.analytics_evicted` (whitelisté) quand l'éviction kicks in.

### P3 (low — nice-to-have)

#### K15-P3-01: `TAB_ID` random suffit pour l'ownership lock mais pas unique cross-reboot Electron
- **File**: `resources/js/helpers/kioskOfflineQueue.js:20`
- **Issue**: `kiosk-tab-${Math.random()}` est éphémère par session. Au redémarrage Electron, le lock ancien est encore en IDB. `_acquireLock` lit l'expiration (ligne 305 `lock?.expiresAt > currentTime`) — c'est bien, mais si la borne crash *au milieu* d'un sync (lock expiresAt = +60s), il faut attendre 60 s post-reboot pour reprendre. Acceptable mais notable.
- **Suggested fix**: Ajouter une fonction `_breakStaleLockOnBoot()` qui invalide tout lock avec `expiresAt < Date.now()` au tout début du bootstrap. Déjà couvert implicitement ligne 305.

#### K15-P3-02: ESC/POS receipt builder hardcoded à 32 chars (printer 58 mm)
- **File**: `resources/js/helpers/kioskPrinter.js:42`
- **Issue**: `RECEIPT_WIDTH = 32` est un constant local. Les bornes 80 mm (48 chars) ne sont pas supportées sans modification du fichier.
- **Suggested fix**: Lire depuis `KIOSK_HARDWARE.PRINTER_WIDTH_CHARS` (à ajouter au config).

#### K15-P3-03: `slugifyKey` arabe coupe à 80 chars mais limite n'est documentée nulle part
- **File**: `resources/js/composables/useKioskSpeech.js:42-48`
- **Issue**: `key.slice(0, 80)` — collision possible si deux i18n keys partagent les premiers 80 chars. Très peu probable, mais documenter.

#### K15-P3-04: Theme `bootstrapKioskThemeEarly` n'écoute pas `prefers-color-scheme` initial avant Vue mount
- **File**: `resources/js/composables/useKioskTheme.js:236-243`
- **Issue**: Si initial = 'auto', résolution synchrone via `resolveAuto()`. Pas d'écoute jusqu'à l'init du composable post-mount. Acceptable.

#### K15-P3-05: Le stub hardware retourne toujours `chargeCard` en `approved` sans contre-pied test
- **File**: `resources/js/services/kioskHardware.js:64-66`
- **Issue**: `tpeCharge` stub: `async () => ({ok: true, tx_ref: 'stub-' + Date.now()})`. Idéal dev, mais aucun test ne couvre le cas `tpe_unavailable` réel (bridge présent mais TPE down). Couverture E2E manquante.

## Existing E2E coverage

- `tests/js/kioskOfflineQueue.spec.js` — Lifecycle base: enqueue, in-flight mutex, partial replay, abandoned threshold 10, idempotency-key preservation, stale entry pruning.
- `tests/js/kioskOfflineQueueV2.spec.js` — Backoff exponentiel + jitter + cap 30s; quote refresh pré-replay; lock heartbeat cross-tab (BroadcastChannel mock); stale items markers + dedup; force retry + cancel; quota exceeded event window dispatch; modal a11y (axe) + focus trap + cancel/force events.
- `tests/js/kioskOfflineQueueMigration.spec.js` — Migration v1→v2 IDB + clear legacy key; idempotence cas IDB déjà peuplée; replayability post-migration; preservation abandoned + staleItems pre-existants; cancel post-migration removes from v2.
- `tests/js/kioskReceiptPersistence.spec.js` — Round-trip save/read; pas de PII (email/phone/IBAN); TTL expiré → null + purge; JSON corrompu → null + purge; version inconnue → null; clear; argument invalide; TTL custom.
- `tests/js/kioskSpeechComposable.spec.js` — audio off → no-op; audio on + mock SpeechSynthesis → speak called; voice selection fr-FR exact match; stop → cancel; AR + key → mp3 fallback via mock Audio class.
- `tests/js/kioskMedia.spec.js` — Helpers image src + variations (concerne kioskMedia.js — léger, hors scope strict K15 mais relevant).
- `tests/js/kioskSettingsIdleTimeouts.spec.js` — Defaults 180s/30s/30s; patch partiel; clamp min/max; NaN → default; reset restaure defaults; getter idleTimeouts composite; setConsentAnalytics et setConsentLoyalty indépendants; reset() ne touche pas aux consents.

**Couverture inexistante / faible**:
- Aucun test direct sur `services/kioskHardware.js` (stub vs bridge réel).
- Aucun test sur `helpers/kioskPrinter.js` (build escpos, fallback chain, reportPrinterFailure).
- Aucun test sur `helpers/kioskAnalytics.js` (consent gate, ALLOWED_EVENTS whitelist, sendBeacon vs fetch fallback, FORBIDDEN_KEYS).
- Aucun test sur `composables/useKioskA11y.js` (attributs HTML appliqués, watchers idempotents).
- Aucun test sur `composables/useKioskTheme.js` (preference cascade, matchMedia listener).
- Aucun test sur `store/plugins/kioskAnalyticsPlugin.js` (mutation → track).

## Proposed new E2E tests

### T-K15-01: Race saveOrder pendant syncQueue ne perd aucune entrée
- **Steps**:
  1. Mock `kioskOfflineQueueDb` partagé entre 2 imports.
  2. `saveOrder({items: [{item_id: 1}]}, 'a')` puis lance `syncQueue(postFn)` où `postFn` retarde de 50 ms.
  3. Pendant la latence, `saveOrder({items: [{item_id: 2}]}, 'b')`.
  4. Await syncQueue.
- **Assertions**: `getPendingCount()` ≥ 1 (l'entrée 'b' doit subsister); `b` est encore queryable via `__unsafeGetQueueForTests()`.

### T-K15-02: Migration v1→v2 robuste face à un `setQueueEntry` qui échoue silencieusement
- **Steps**:
  1. Seed `localStorage['kiosk_offline_queue_v1']` avec 3 entries.
  2. Mock `setQueueEntry` pour throw `QuotaExceededError` SAUF si key match `kiosk:offline-queue:v2` → fallback localStorage (qui réussit).
  3. Reload module.
- **Assertions**: `localStorage['kiosk_offline_queue_v1']` est encore présent (ne pas avoir clearé v1 si v2 n'est pas confirmé) OR un event `offline.queue.v2.migration_failed` est trackable.

### T-K15-03: Whitelist analytics — `offline.queue.v2.quota_exceeded` arrive bien au backend
- **Steps**:
  1. `kioskAnalytics.setConsent(true)`.
  2. Mock IDB setQueueEntry pour throw QuotaExceededError au 1er `_persistQueue`.
  3. Mock `navigator.sendBeacon` + `window.axios.post`.
  4. `saveOrder({items: [{item_id: 1}]}, 'k')`.
- **Assertions**: `sendBeacon` (ou axios.post) appelé avec `event_name === 'offline.queue.v2.quota_exceeded'`. Aujourd'hui ce test FAIL — drive le fix P0-03.

### T-K15-04: Printer fallthrough emit `hardware_error` analytics
- **Steps**:
  1. `kioskHardware.isKioskBridge()` retourne `false` (stub).
  2. `document.getElementById('kiosk-print-receipt')` retourne null.
  3. Mock `window.axios.post` to capture.
- **Assertions**: `printReceipt(...)` → `{method: 'none'}`, ET un POST `frontend/kiosk-event` envoyé avec `type: 'printer_failure'`.

### T-K15-05: Speech AR sans key trace une dégradation
- **Steps**:
  1. `store.dispatch('kioskSettings/setLocale', 'ar')` + `setAudio(true)`.
  2. Mock `window.speechSynthesis.getVoices()` → `[]` (aucune voix ar).
  3. `useKioskSpeech().speak('مرحبا')` sans `key`.
- **Assertions**: `speak()` résout `false`; un event `hardware_error` trackable avec `code: 'ar_no_voice_no_mp3'`.

### T-K15-06: Receipt persistence branch-scoped
- **Steps**:
  1. `saveKioskReceiptSnapshot({orderId: 1, branchId: 7, ...})`.
  2. Mount `KioskConfirmationComponent` avec `store.state.kioskCart.branchId === 8`.
- **Assertions**: `readKioskReceiptSnapshot()` retourne `null` (mismatch branch). Drive le fix P1-02.

## Risks & open questions

1. **[OWNER GATE]** Faut-il considérer la queue offline comme NF525-sensitive? Si oui, le P0-04 et P0-01 deviennent block immédiat (orphan fiscal_sequence_no côté backend si une commande disparaît silencieusement).
2. **[OWNER GATE]** Buffer in-memory analytics avant consent (P1-04) — légalement OK si jamais émis vers réseau? Cohérent avec RGPD strict consent-first, mais doc actuelle (ligne 161 kioskAnalytics.js) dit "jetés". À confirmer DPO.
3. **[OWNER GATE]** Receipt persistence — ajouter `branchId/kioskMachineId` modifie le contrat sérialisé v1. Migration douce: read tolère absence (legacy), save inclut toujours.
4. Aucun fichier de K15 n'est marqué FROZEN. Tous les fixes sont applicables sans gate de release branch.
5. La couverture test côté `services/kioskHardware.js`, `kioskPrinter.js`, `kioskAnalytics.js` est marginale — chantier hardening V1.0.1 souhaitable même sans fix P0.

## Sommaire findings

| Sévérité | Count |
|---|---|
| P0 | 3 (race syncQueue, whitelist drift, migration clear-legacy-too-early) |
| P1 | 5 |
| P2 | 5 |
| P3 | 5 |

**Verdict K15**: NO-GO V1 sans fix de 3 P0 (queue intégrité + analytics observabilité critique). Le hardware/speech/a11y/theme côté composables est de qualité production; le périmètre offline queue v2, bien que solide en happy path, présente des trous de robustesse au moment exact où la résilience est demandée (sleep/wake, multi-tab race, quota saturation, migration partielle).

# K12 — Error states + Offline conflict

> Branch `feature/mobile-app-le-cayenne-2026-05-10` — HEAD `6a33a9763`.
> Mode read-only. 6 fichiers analysés. Aucun frozen-zone touché.

## Files audited
- `resources/js/components/frontend/kiosk/KioskErrorLayoutComponent.vue` — 146 lines (layout partagé)
- `resources/js/components/frontend/kiosk/KioskErrorMenuUnavailableComponent.vue` — 68 lines
- `resources/js/components/frontend/kiosk/KioskErrorNetworkComponent.vue` — 73 lines
- `resources/js/components/frontend/kiosk/KioskErrorPaymentRefusedComponent.vue` — 97 lines
- `resources/js/components/frontend/kiosk/KioskErrorProductRemovedComponent.vue` — 71 lines
- `resources/js/components/frontend/kiosk/KioskOfflineConflictModalComponent.vue` — 187 lines
- (refs) `resources/js/router/modules/kioskRoutes.js:259-289` — 4 error routes
- (refs) `resources/js/store/modules/kioskCart.js:77-100` — `resolveKioskErrorRoute` + `goToKioskError`
- (refs) `resources/js/helpers/kioskAnalytics.js:34-53,290-293` — `KIOSK_ERROR_CODES` + `trackKioskErrorEvent`
- (refs) `tests/js/kioskGlobalErrors.spec.js` (98 lines), `tests/js/kioskRuptureUx.spec.js` (137 lines)
- (refs) `tests/js/kioskOfflineQueueV2.spec.js:486-566` — conflict modal coverage

## Findings

### P0 (blocker pre-merge V1)

- **K12-P0-01: OfflineConflictModal — i18n totalement absente, hardcoded FR**
  - File: `KioskOfflineConflictModalComponent.vue:7,10-13,29-30,39,42-43,47,54,80-81,86,90` (title `"Conflits file d'attente"`, intro `"Une ou plusieurs commandes…"`, labels `"Annuler"`/`"Forcer envoi"`, empty state `"Aucun conflit en attente."`, helper `'Aucun'`/`'Date inconnue'`)
  - Issue: Le composant n'utilise **jamais** `$t(...)`. Tous les libellés sont en FR littéral. Le mandate i18n FR/EN/AR de §2 du plan est cassé. Si owner active EN/AR (kiosk multi-locale future), le modal affiche du FR brut. Même côté FR-lock V1, c'est inconsistant avec les 4 autres composants d'erreur (`$t('kiosk.error.*')`).
  - Evidence : ligne 7 `title="Conflits file d'attente"` ; ligne 10 `<p>Une ou plusieurs commandes…</p>` ; ligne 39 `Annuler` ; ligne 47 `Forcer envoi`.
  - Suggested fix: Ajouter clés `kiosk.offline_queue.conflict.*` dans `lang/{fr,en,ar}.json` (titre/intro/btn_cancel/btn_force/empty/saved_at_unknown/produits_label) + remplacer hardcoded par `$t()`.

- **K12-P0-02: OfflineConflictModal affiche des IDs item bruts au client**
  - File: `KioskOfflineConflictModalComponent.vue:30,79-84` (rendu via `formatItemList(staleItems)` → `staleItems.join(', ')`)
  - Issue: `staleItems` est un array de **`item_id` numériques** (cf. `kioskOfflineQueue.js:140-141` `parseInt(id, 10)`). L'UI affiche donc `"Produits impactés : 12, 13"` au client — incompréhensible et non-conforme à la philosophie FoodKing "human readable". Pire : un client ne peut pas identifier quel produit annuler vs forcer.
  - Evidence: `kioskOfflineQueue.js:140` `entry.staleItems.map((id) => parseInt(id, 10))` → tableau d'IDs.
  - Suggested fix: Au moment de surfacer le conflit (parent `KioskAppComponent`), résoudre les IDs → noms via `kioskMenu/allItems` getters et passer `staleItemNames` (string[]) dans `entries`. Conserver les IDs en data-attr pour analytics.

- **K12-P0-03: OfflineConflictModal — `localKey` exposé au client (data interne)**
  - File: `KioskOfflineConflictModalComponent.vue:27`
  - Issue: `<strong>{{ entry.localKey }}</strong>` affiche la clé interne IndexedDB (ex. `kiosk_offline_quota-key-abc123`) directement à l'utilisateur. C'est du data interne sans valeur métier, pollue l'UI et peut leaker la structure de stockage.
  - Evidence: ligne 27 `<strong>{{ entry.localKey }}</strong>`.
  - Suggested fix: Remplacer par un libellé i18n type `"Commande du {savedAt}"` ou `"Brouillon #{shortId}"` calculé depuis localKey hash court — masquer la clé brute.

### P1 (high)

- **K12-P1-04: Network/Menu/Payment "Retry" et "Call staff" — handlers émettent dans le vide**
  - File: `KioskErrorNetworkComponent.vue:54-67`, `KioskErrorMenuUnavailableComponent.vue:52-57`, `KioskErrorPaymentRefusedComponent.vue:77-86`
  - Issue: Les composants émettent `retry`, `call-staff`, `pay-at-counter`. Recherche `grep` confirme **aucun parent ne s'abonne** à ces events (`@retry|@call-staff|...` introuvable hors fichiers eux-mêmes). Comme ils sont mounted via `<router-view>` (composants routés), pas de parent intermédiaire — l'event meurt. Conséquence : appuyer sur "Réessayer" met le spinner 500ms puis rien. "Prévenir l'équipe" ne fait que logger un event analytics et c'est tout (pas de buzzer, pas de signal staff).
  - Evidence: `kioskGlobalErrors.spec.js:84-96` valide juste que `trackKioskErrorEvent` est appelé — pas le retry réel ; aucun template parent ne contient `@retry=`.
  - Suggested fix: Soit (a) ajouter handlers dans chaque error component (Network → router.push idle + tenter `kioskMenu/fetchMenu` ; Menu idem ; Payment retry → router.push back to payment step), soit (b) consommer via Vuex action (`dispatch('kioskCart/retryAfterError', code)`). "Call staff" doit déclencher un vrai signal (toast + buzzer API ou flash banner staff via `frontend/kiosk/event` type=`staff_call_requested`).

- **K12-P1-05: `role="alert"` + `aria-live="assertive"` sur écran plein-page**
  - File: `KioskErrorLayoutComponent.vue:5-6`
  - Issue: Le pattern correct pour un écran complet d'erreur est `role="status"` (poli) ou un `<main role="main">` avec un focusable h1, **pas** `role="alert"` qui est destiné à un message ponctuel inséré dans une page existante. Sur AT (NVDA/JAWS/VoiceOver), `assertive` interrompt la lecture en cours et peut entraîner une **double annonce** (titre `<h1>` + region alert). WCAG 2.1 4.1.3 risk.
  - Evidence: ligne 5 `role="alert"` ligne 6 `aria-live="assertive"` sur `<section>` plein-écran.
  - Suggested fix: Remplacer par `<main role="main" aria-labelledby="…">`, déplacer focus sur le `<h1>` au mount (`tabindex="-1"` + `ref.focus()`), garder le contenu naturellement lu en sequence. Si tu veux conserver alert sémantique, le restreindre au `<p class="kiosk-err__subtitle">` avec `role="status" aria-live="polite"`.

- **K12-P1-06: Pas de mécanisme retour automatique (auto-redirect) sur écrans erreur**
  - File: 4× `KioskError*.vue`
  - Issue: La borne reste indéfiniment sur l'écran d'erreur si le client part. Aucun timer "Retour accueil dans Ns" (alors qu'on en a un sur Cash Instruction `auto_redirect` et Confirmation). Risque : borne bloquée jusqu'à intervention staff.
  - Evidence: aucun `setTimeout`/`setInterval` dans les 4 fichiers `KioskError*Component.vue`.
  - Suggested fix: Ajouter prop `autoRedirectMs` (default 60s pour Network/Menu, 90s pour Payment, 45s pour Product) + countdown visible bas d'écran identique au pattern Confirmation.

- **K12-P1-07: PaymentRefused "code" exposé sans wrapping**
  - File: `KioskErrorPaymentRefusedComponent.vue:39-41`
  - Issue: `<span v-if="errorCode">code : {{ errorCode }}</span>` — `"code"` est hardcoded FR minuscule, pas i18n, et le code TPE (ex. `CARD_DECLINED`) est exposé au client sans encart ni distinction visuelle pour staff. Risque "raw label" + leak ux pauvre.
  - Evidence: ligne 40.
  - Suggested fix: Clé `kiosk.error.payment_refused.diagnostic_code` (FR `Code diagnostic` / EN `Diagnostic code` / AR `رمز التشخيص`) + design discret en footer monospaced.

### P2 (medium)

- **K12-P2-08: PaymentRefused — Cancel = `danger` button visuellement agressif**
  - File: `KioskErrorPaymentRefusedComponent.vue:29-37`
  - Issue: 3 CTA empilés dont le dernier rouge (`variant="danger"`) peut induire le tap accidentel d'annulation en frustration. Le tap moyen kiosque tend vers le bouton du bas.
  - Suggested fix: Soit reordonner Retry/Cancel/Pay-counter (Cancel en milieu), soit utiliser `variant="ghost"` (texte rouge sans fond). Garder confirmation modale "Vraiment annuler ?" pour cancel.

- **K12-P2-09: ProductRemoved — subtitle construit en concaténation JS sans i18n format**
  - File: `KioskErrorProductRemovedComponent.vue:6`
  - Issue: `:subtitle="productName ? '${$t('kiosk.error.product_removed.subtitle')} — ${productName}' : …"` mélange JS string interpolation et i18n. En AR (RTL), le tiret cadratin ` — ` ne se place pas correctement et peut casser la direction de lecture.
  - Suggested fix: Ajouter clé `kiosk.error.product_removed.subtitle_with_name` `"{subtitle} — {name}"` (et version RTL appropriée en AR) + utiliser `$t(..., { name: productName, subtitle: ... })`.

- **K12-P2-10: KioskErrorLayout — `<h1>` font-size = `display * scale` non capé**
  - File: `KioskErrorLayoutComponent.vue:110`
  - Issue: Avec `--kiosk-text-scale=1.5` (a11y option big text), display `display` peut dépasser 100px et provoquer overflow sur écrans portrait 1080. Pas de `max-height` ni `overflow` géré.
  - Suggested fix: Ajouter `clamp()` ou `max-font-size` via `min()`.

- **K12-P2-11: OfflineConflictModal — focus trap testé mais pas de gestion ESC**
  - File: `KioskOfflineConflictModalComponent.vue:1-58`
  - Issue: Délègue à `KsModal` (qui semble gérer ESC selon `@close`). Mais pas de test de fermeture par ESC, et si l'écran kiosque a `closable=false` par défaut KsModal config, l'utilisateur peut être trapé sans option close.
  - Suggested fix: Ajouter test `keydown.escape` + vérifier `closable` prop sur `KsModal`.

- **K12-P2-12: Tous les error components — pas de fallback i18n si clé manquante**
  - File: `KioskErrorMenuUnavailableComponent.vue:5-7` (et similaires)
  - Issue: Si `$t('kiosk.error.network.title')` retourne la clé brute (cas locale manquante), pas de garde — l'utilisateur voit `"kiosk.error.network.title"`. Le rapport K20 cross-cutting devrait pointer ce risque général i18n.
  - Suggested fix: `t-or-fallback` directive ou wrapper `$tSafe(key, fallback)`.

### P3 (low)

- **K12-P3-13: Icons emoji utilisés (📡 🍽 🚫 ❌)**
  - File: 4× `KioskError*.vue` template `icon=…`
  - Issue: Rendu emoji varie selon OS/browser (Apple vs Noto). Pas de cohérence brand FoodKing. Sur kiosk Linux avec font basique, peut afficher tofu.
  - Suggested fix: Migrer vers SVG icons custom (lucide-vue ou inline SVG sprite).

- **K12-P3-14: OfflineConflictModal — `formatSavedAt` toujours locale `fr-FR`**
  - File: `KioskOfflineConflictModalComponent.vue:88`
  - Issue: `Intl.DateTimeFormat('fr-FR', …)` hardcoded. Pas de support EN/AR. Cohérent avec FR-lock V1 mais drift potentiel si multi-locale activé.
  - Suggested fix: Utiliser locale courante via `useKioskLocale()` ou injection store.

- **K12-P3-15: Telemetry `trackKioskErrorEvent` — pas de retry/backoff sur 5xx**
  - File: `helpers/kioskAnalytics.js:293`
  - Issue: Si `/frontend/kiosk/event` est down, l'event est perdu sans queue. Acceptable pour P3 mais à noter.

## Existing E2E coverage

- `tests/js/kioskGlobalErrors.spec.js` — couvre normalization codes, route resolution, `goToKioskError`, telemetry helper post + assertion "no direct axios in error components". 5 tests verts.
- `tests/js/kioskRuptureUx.spec.js` — couvre product card rupture (catégories), `is_available=false` → `aria-disabled`, badge "Épuisé", click bloqué. 1 test vert. Note : c'est sur `KioskCategoriesComponent`, pas sur les error screens eux-mêmes — focus rupture amont.
- `tests/js/kioskOfflineQueueV2.spec.js:485-566` — couvre le modal conflict : render entries, cancel/force events, focus trap Tab, axe a11y, empty state, opened hook. 5 tests.
- **Gap : pas de test pour** le routing pratique vers les pages d'erreur après crash réseau réel, pas de test du auto-redirect (puisque pas implémenté), pas de test des handlers retry/call-staff aboutissant à une action réelle (consommateurs manquants).

## Proposed new E2E tests

- **T-K12-01: Network error retry actually reloads menu**
  - Steps: mock `/api/frontend/menu` 503 → kiosk dispatches `goToKioskError('network')` → page network monte → click `kiosk-error-network-cta-retry` → assert `kioskMenu/fetchMenu` re-dispatched OR `$router.push` to idle/categories.
  - Assertions: `axios.get('/api/frontend/menu')` called ≥2 (initial + retry) ; `trackKioskErrorEvent('error_retry', 'network')` called ; final route ≠ `kiosk.error.network` après succès.

- **T-K12-02: PaymentRefused full flow (retry / pay-at-counter / cancel)**
  - Steps: mount `KioskErrorPaymentRefusedComponent` route avec query `code=TPE_TIMEOUT&order_id=K-900` ; tester chaque CTA.
  - Assertions: retry → emit `retry` ; pay-counter → emit `pay-at-counter` ; cancel → `$router.push({ name: 'kiosk.idle' })` ; analytics types matchent (`error_payment_retry|switch_cash|cancel`).

- **T-K12-03: ErrorLayout a11y — `<h1>` focusable et focus mis au mount**
  - Steps: mount layout, assert `<h1>` reçoit `tabindex="-1"` et `document.activeElement === h1` après next-tick. Run axe → 0 violations.
  - Assertions: pas de `role="alert"` sur `<section>` plein-écran (régression P1-05 si remédiée).

- **T-K12-04: OfflineConflictModal i18n — Render EN locale shows EN strings**
  - Steps: mount avec `createI18n({locale:'en'})` + entries.
  - Assertions: title contient EN equivalent (e.g. `"Queue conflicts"`), `Forcer envoi` absent, `Force send` ou clé i18n correcte présente. Aussi vérifier AR locale + dir RTL.

- **T-K12-05: OfflineConflictModal staleItems rendered as names not raw IDs**
  - Steps: mount avec `entries: [{ localKey: 'k1', savedAt: Date.now(), staleItemNames: ['Tacos XL', 'Coca'] }]`.
  - Assertions: text contient `Tacos XL, Coca` ; pas de matching `/\b\d{2,}\b/` (pas d'IDs numériques bruts en sortie).

- **T-K12-06: Auto-redirect Network back to idle après 60s**
  - Steps: `vi.useFakeTimers()` ; mount `KioskErrorNetworkComponent` ; advance timers 60s.
  - Assertions: `$router.push({ name: 'kiosk.idle' })` appelé.

## Risks & open questions

- **Q1 [OWNER GATE]**: Est-ce que les pages d'erreur doivent vraiment être des **routes** (full navigation) ou un **modal overlay** sur l'écran courant ? Le pattern routé tue le panier ; modal le préserverait. Décision UX requise (V1 vs V1.0.1).
- **Q2**: Le mandate "Call staff" doit-il vraiment signaler quelque chose côté staff (badge POS, son, sms) ou rester un simple event analytics ? Si signal staff, c'est un nouvel endpoint backend (`POST /frontend/kiosk/staff-signal`) à scoper.
- **Q3 [P1 ARIA]**: La fix proposée pour P1-05 peut affecter régressions test a11y existants (Playwright assertions `role=alert`). À valider via cross-search avant patch.
- **Q4 [Frozen-zone]**: `KioskAppComponent.vue` (frozen) intègre `KioskOfflineConflictModalComponent`. Si la résolution `staleItems → names` se fait dans le parent (K12-P0-02 suggested fix), il faut owner gate sur KioskAppComponent. Alternative scope-mini : résoudre dans le modal lui-même via `inject('kioskMenuItems')` ou store ref.

## Cross-validation hints

- K17 (Menu API + cache) doit confirmer que `503` est bien le code utilisé pour menu_unavailable (vs payload vide → autre code).
- K18 (Order creation + payment) doit confirmer les codes TPE remontés (`CARD_DECLINED`, `TPE_TIMEOUT`, `TPE_REJECTED`) — sinon la liste analytics frontend est fictive.
- K15 (Offline queue) confirme `staleItems` contient bien des `item_id` numériques (vu via grep ; +cross-check spec V2 modal cas n°1 ligne 491 `staleItems: [12, 13]` confirme IDs bruts).
- K20 (cross-cutting i18n/a11y) doit promouvoir P0-01 + P1-05 si confirmé.

## Verdict K12

**NO-GO V1 en l'état** pour le module Errors+Offline :
- 3 P0 (offline modal i18n absente + IDs bruts + localKey leaké),
- 4 P1 (handlers morts retry/call-staff, ARIA pattern, auto-redirect, error code i18n).

Effort estimé fix P0+P1 : ~1.5 jour dev + 0.5 jour QA. Aucun frozen-zone touch requis si on agit dans le modal lui-même (option scope-mini Q4).

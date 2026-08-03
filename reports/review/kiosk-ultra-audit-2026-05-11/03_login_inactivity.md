# K03 — Login + Inactivity Overlay

> Branche `feature/mobile-app-le-cayenne-2026-05-10` — HEAD `245e8ab57`.
> Scope read-only : auth borne, idle/abandon overlay, focus trap, kbd a11y,
> i18n FR/EN/AR + RTL. Cross-link backend P0-07 (RefreshTokenController
> abilities `['*']`) et P0-08 (`abilities:kiosk:order`) — voir §Cross-link.

## Files audited

- `resources/js/components/frontend/kiosk/KioskLoginComponent.vue` (323 lignes)
- `resources/js/components/frontend/kiosk/KioskInactivityOverlayComponent.vue` (249 lignes)
- `resources/js/composables/useKioskA11y.js` (193 lignes)
- `resources/js/store/modules/kioskSettings.js` (313 lignes)

Référencés (non-modifiés, lus en contexte) :
- `resources/js/store/modules/kioskCart.js` (action `kioskLogin`, état `kioskToken`)
- `resources/js/store/index.js` (paths persistedState — kioskToken local)
- `resources/js/components/frontend/kiosk/KioskAppComponent.vue` (idle timer wire-up)
- `resources/js/bootstrap.js` (Echo auth — relit `kioskToken` depuis localStorage)
- `resources/js/i18n.js` (FR-lock kiosk runtime)
- `routes/api.php` (route `auth/kiosk-login` + `throttle:kiosk-login`)
- `config/sanctum.php` (`expiration` 480 min)

## Findings

### P0 (blocker pre-merge V1)

- **K03-P0-01 : Token kiosk Sanctum stocké en clair dans `localStorage` (XSS-exfiltratable)**
  - File: `resources/js/store/index.js:263-264`
  - File: `resources/js/bootstrap.js:206-216` (relecture côté Echo)
  - File: `resources/js/shared/axios-setup.js:44-58`
  - Issue: Le Bearer token Sanctum `kiosk:order` (TTL 480 min, CLAUDE.md §9)
    est persisté en clair dans `localStorage` via `vuex-persistedstate`
    (clé `vuex` → `kioskCart.kioskToken`). Toute XSS sur la borne — y compris
    un menu HTML mal échappé poussé par catalog/promo — exfiltre le token
    et permet pendant 8h de placer des commandes (`kiosk:order`) sur la
    branche depuis n'importe où. Pas de rotation à mi-vie, pas de
    `HttpOnly` cookie, pas de `SameSite=Strict`. La borne Electron a un
    seul user mais reste un navigateur web — le surface XSS est réel
    (catalog HTML, error message backend rendu via `error` reactive line
    22, etc.).
  - Evidence:
    ```js
    // store/index.js:263
    "kioskCart.kioskToken",
    "kioskCart.kioskMachineId",
    ```
    ```js
    // bootstrap.js:208
    const vuex = JSON.parse(localStorage.getItem('vuex') || '{}');
    return selectSurfaceBearerToken({ kioskToken: vuex.kioskCart?.kioskToken || null, ... });
    ```
  - Suggested fix: Migrer le Bearer kiosk vers cookie `HttpOnly` +
    `SameSite=Strict` + `Secure` posé par `KioskMachineLoginController`,
    et n'exposer côté Vuex que `kioskMachineId` + `tokenExpiresAt`.
    Alternative court terme : XSS-armor (CSP `script-src 'self'`,
    audit complet des `v-html`, escape backend errors).
    `[OWNER GATE — touche auth flow + RefreshTokenController]`.

- **K03-P0-02 : Pas de TTL frontend ni de pré-expiry refresh — borne
  silencieusement déconnectée après 480 min en plein parcours client**
  - File: `resources/js/components/frontend/kiosk/KioskLoginComponent.vue:97-137`
  - File: `resources/js/store/modules/kioskCart.js:383-392, 456-478`
  - Issue: Le token Sanctum a TTL 480 min (`config/sanctum.php:51`),
    mais le frontend ne stocke **aucune** date d'expiration. Le seul
    déclencheur de re-login est un **401** côté axios interceptor (cité
    dans le commentaire kioskCart.js:447) — ce qui veut dire que le
    client va déclencher un re-login **au milieu d'une commande payée**
    (ex: POST `/api/frontend/order` ou `/api/frontend/quote-order`),
    avec le risque de double-soumission ou de perte d'idempotency-key
    le temps du round-trip. `bootstrap.js:260` confirme explicitement :
    `"No timer-based proactive refresh: there is no backend refresh-token endpoint."` —
    cela laisse le **flow paiement vulnérable à un 401 mid-checkout**.
  - Evidence:
    ```js
    // kioskCart.js:469
    commit('SET_KIOSK_TOKEN', { token, machineId });  // pas de expires_at stocké
    ```
  - Suggested fix: (1) Backend renvoie `expires_at` ISO8601 dans
    `/auth/kiosk-login` response ; (2) frontend stocke
    `state.kioskTokenExpiresAt` ; (3) timer proactif dans
    `KioskAppComponent.mounted` qui re-login à `expires_at - 30 min`,
    sur écran idle uniquement (jamais en plein wizard/payment).
    Idem `KioskAppComponent.vue:881` exclut déjà `kiosk.payment` et
    `kiosk.confirmation` du idle timer — pattern réutilisable.

- **K03-P0-03 : Backend error message rendu cru dans le UI → fuite i18n + risque
  d'injection contenu non sanitizé**
  - File: `resources/js/components/frontend/kiosk/KioskLoginComponent.vue:22, 125-129`
  - Issue: `<p v-if="error" class="kiosk-login-error">{{ error }}</p>` rend
    via Vue text interpolation (XSS-safe via escape DOM), mais le
    contenu est piochée dans cet ordre :
    `errors.validation` → `errors.username[0]` → `errors.password[0]` →
    `data.message` → fallback i18n. Sur un backend qui ne respecterait
    pas FR-lock (réponse en EN du Sanctum default) ou qui renvoie un
    `password[0]` brut "The password field is required.", l'utilisateur
    voit du texte non-localisé en plein hall. La regle CLAUDE.md §6
    "no raw label" est violée si `err.response.data.errors.password[0]`
    arrive en anglais — c'est exactement le bug D-007 référencé en
    commentaire ligne 113 que la correction `err_rate_limited` couvre
    pour 429 **uniquement**.
  - Evidence:
    ```js
    msg = err?.response?.data?.errors?.validation
      || err?.response?.data?.errors?.username?.[0]
      || err?.response?.data?.errors?.password?.[0]
      || err?.response?.data?.message
      || this.$t('kiosk.login_screen.err_login_failed');
    ```
  - Suggested fix: Forcer toujours la branche i18n côté frontend selon
    le code d'erreur HTTP (`401` → `err_login_failed`, `422` →
    `err_invalid_credentials`, `500+` → `err_server`), ignorer le
    payload backend pour l'affichage (logger côté analytics seulement).

### P1 (high — V1.0.1 sprint)

- **K03-P1-01 : Focus trap absent — Tab/Shift+Tab sortent du
  `KioskInactivityOverlayComponent` malgré `aria-modal="true"`**
  - File: `resources/js/components/frontend/kiosk/KioskInactivityOverlayComponent.vue:77-87, 139-141`
  - Issue: Le commentaire JSDoc revendique
    "Focus trap simple (on focus le bouton 'Je suis là' au mount)" — mais
    aucun listener `keydown.tab` ne renvoie le focus vers les boutons
    intérieurs. En tactile pur cela passe, mais **EAA 2025** (cf. §6 du
    plan maître + CLAUDE.md §3 EAA) exige le clavier comme parcours
    alternatif obligatoire — y compris sur borne. Un clavier USB
    branché par le service tech tabbe hors de l'overlay et peut
    déclencher Stay ou Leave via le DOM sous-jacent encore actif.
  - Evidence:
    ```js
    // ligne 139-141
    this.$nextTick(() => {
      try { this.$refs.stayBtn?.$el?.focus?.(); } catch (_) {}
    });
    ```
    Aucune méthode `onTabKey` / `trapFocus` / `inertSiblings` n'existe.
  - Suggested fix: Implémenter un mini focus-trap (Tab/Shift+Tab cycle
    entre `stayBtn` et le bouton ghost) + ajouter
    `inert` sur le `.kiosk-app` parent quand `showStillHere=true`
    (Vue 3 supporte `inert` attr natif).

- **K03-P1-02 : `useKioskA11y` ne pose pas `dir="rtl"` automatiquement —
  borne Le Cayenne FR-only V1, mais code AR persistedstate possible**
  - File: `resources/js/composables/useKioskA11y.js:32-40, 77-87, 156-164`
  - Issue: Le watcher locale ligne 156 appelle `setLocale(lang)` mais
    **n'écrit pas `dir`** sur `<html>` (que `i18n.js:24-26` réalise au
    boot via `setDocumentDirection`). Si on switch locale runtime (peu
    probable car FR-lock §commentaire ADR-007, mais le code l'autorise
    via `setIdleTimeouts`/`SET_LOCALE`), le DOM reste en `dir="ltr"`
    pour AR → layout cassé. Trouvé aussi dans la liste `HTML_ATTR`
    lignes 38-39 : `DIR: 'dir'` est déclaré mais jamais utilisé dans
    aucun `applyAttr(HTML_ATTR.DIR, ...)` appel — code mort.
  - Evidence:
    ```js
    // useKioskA11y.js:39
    DIR: 'dir',  // déclaré mais jamais appliqué
    // ligne 156-164
    watch(() => store.state.kioskSettings?.locale, (value) => {
      const lang = value || 'fr';
      setLocale(lang);     // côté i18n.js — qui pose `<html dir>`
      if (i18n?.locale) i18n.locale.value = lang;
    });
    ```
  - Suggested fix: Ajouter `applyAttr(HTML_ATTR.DIR, lang === 'ar' ? 'rtl' : 'ltr')`
    + `applyAttr(HTML_ATTR.LANG, lang)` dans le watcher locale et dans
    `applyKioskA11yFromStore`. Garantit RTL même si `i18n.js` n'a pas
    réagi (race au boot Electron).

- **K03-P1-03 : Throttle backend `kiosk-login` 30/min vs retry frontend
  exponentiel buggé — peut amplifier le 429**
  - File: `resources/js/components/frontend/kiosk/KioskLoginComponent.vue:86-95`
  - Issue: `scheduleRetry()` plafonne à 10 tentatives mais le calcul
    `4000 * Math.max(1, 2 ** Math.max(0, this.retryAttempts - 1))`
    démarre à 4s puis 4s, 8s, 16s, 32s → cappé 30s par `Math.min(30000)`.
    Sur 10 tentatives, total ~5 min. Sur **plusieurs bornes** dans un
    même restaurant qui démarrent ensemble (panne réseau partagée), elles
    convergeront toutes vers une période **30s** stable → spike 429
    backend. Aucun `jitter` random. La cohérence frontend↔backend (30/min)
    est tight : ~2/s pic si 60 bornes synchronisent.
  - Evidence:
    ```js
    const delayMs = Math.min(30000, 4000 * Math.max(1, 2 ** Math.max(0, this.retryAttempts - 1)));
    ```
  - Suggested fix: Ajouter jitter `delayMs * (0.7 + Math.random() * 0.6)`,
    plafond `retryAttempts >= 10` déjà OK mais ajouter un **circuit
    breaker** : après 10 fails, attendre 5 min avant nouveau cycle
    (vs return early sans reset).

- **K03-P1-04 : `KioskInactivityOverlayComponent` n'écoute pas `Tab` mais
  écoute `Esc` qui Stay — confusion clavier (UX réservée admin tech)**
  - File: `resources/js/components/frontend/kiosk/KioskInactivityOverlayComponent.vue:13`
  - Issue: `@keydown.esc.prevent="onStay"` est correct (Esc = rester,
    invariant DATA_CONTRACT §12 cité ligne 80). Mais `Enter` sur
    le bouton stay focus ne refait pas Stay s'il y a un ghost element
    parent qui capture (KsButton.vue est externe au scope mais le focus
    DOM est sur `.$el` qui peut ne pas être `<button>` natif → Enter
    pas auto-traité). Risque mineur car tactile primaire, mais EAA
    2025 exige kbd nav.
  - Evidence: ligne 140 `this.$refs.stayBtn?.$el?.focus?.()` —
    si `KsButton` est un `<div role="button">` au lieu d'un `<button>`,
    Enter n'invoque pas l'action sans handler explicite.
  - Suggested fix: Vérifier que `KsButton.vue` rend un `<button type="button">`
    natif + ajouter `keydown.enter` explicite sur `onStay` au niveau
    overlay root.

### P2 (medium — backlog priorisé)

- **K03-P2-01 : `data-testid="kiosk-inactivity-leave"` cliquable mais pas
  destructif visuel — risque "regret"**
  - File: `resources/js/components/frontend/kiosk/KioskInactivityOverlayComponent.vue:43-49`
  - Issue: Bouton "Abandonner" (variant `ghost`) ne demande pas de
    confirmation et vide instantanément le panier (cf. `KioskAppComponent.vue:928-933`
    `onInactivityLeave` → `resetKiosk`). Un tap accidentel sur le
    bouton ghost (qui n'est visuellement pas "danger") perd 5+ min de
    composition wizard. Le commentaire ligne 80 indique que **backdrop+Esc=Stay**
    pour éviter accidents — bon — mais le bouton lui-même reste un
    clic-direct destructif.
  - Suggested fix: Soit ajouter confirmation 2 steps (label "Sûr ?"),
    soit déplacer le destructive vers variant `danger` rouge avec icône
    poubelle, soit allonger le countdown à 30s (vs 15s défaut). À
    arbitrer avec UX owner.

- **K03-P2-02 : `IDLE_BOUNDS.confirmMs` max 60s alors que
  `inactivityCountdownMs` (KioskAppComponent:295) cappe à 60s — borne
  partagée mais admin peut configurer > 60s implicitement**
  - File: `resources/js/store/modules/kioskSettings.js:48-52`
  - Issue: `IDLE_BOUNDS.confirmMs = { min: 3000, max: 60000 }` côté store,
    mais `KioskAppComponent.inactivityCountdownMs` re-cappe `Math.min(60000, Math.max(3000, raw))`.
    Si admin POST `setIdleTimeouts({ confirmMs: 90000 })` via
    `KioskSetupController` (hors scope K03 mais cross-K19), le store
    coerce à 60000, OK. Pas un blocker — juste de la défense en
    profondeur duplicate. Néanmoins **le bouton "stay" reste focusable
    le temps complet du `expireTimer`** : si admin règle confirmMs=3s,
    le client n'a pas le temps de lire la traduction AR longue
    "هل أنت هنا؟ سيتم مسح طلبك خلال 3 ثوان" et le panier est vidé.
  - Suggested fix: Imposer min 10s sur AR locale (texte plus long
    visuellement) ou min 15s par défaut. À discuter avec a11y.

- **K03-P2-03 : `aria-live="polite"` sur countdown peut être ignoré par
  certains lecteurs d'écran**
  - File: `resources/js/components/frontend/kiosk/KioskInactivityOverlayComponent.vue:29`
  - Issue: Le commentaire ligne 79 explique le choix `polite` (non
    critique). Mais quand `secondsLeft` arrive à `<=5`, l'annonce
    devient critique (perte de commande). Aucune logique pour
    promouvoir `polite` → `assertive` à 5s.
  - Suggested fix: Ajouter `:aria-live="secondsLeft <= 5 ? 'assertive' : 'polite'"`.

- **K03-P2-04 : `showDevSeedHint` lit `process.env.NODE_ENV !== 'production'`
  — fiable si build Mix, fragile sinon**
  - File: `resources/js/components/frontend/kiosk/KioskLoginComponent.vue:52-54`
  - Issue: Sur la borne en mode hors-ligne / build local non-prod, le
    `devhint` (lignes 36-38) affiche les instructions `.env.example` →
    fuite d'infrastructure (`KIOSK_MACHINE_*`, `php artisan migrate --seed`)
    sur écran public. Le contrôle est purement build-time, pas
    runtime — un déploy raté en mode dev expose ces instructions.
  - Suggested fix: Croiser avec `foodkingConfig.appEnv === 'production'`
    runtime injecté côté Blade.

### P3 (low — nice-to-have)

- **K03-P3-01 : Emoji 🖥️ comme logo (ligne 5)**
  - File: `resources/js/components/frontend/kiosk/KioskLoginComponent.vue:5`
  - Issue: `<div class="kiosk-login-icon">🖥️</div>` — l'emoji rendu
    dépend du système (Windows kiosk Electron vs macOS dev). Pas
    cohérent avec branding owner (palette mobile noir/rouge/jaune/blanc,
    memory `feedback_design_flat_organized.md`). Logo Le Cayenne attendu.
  - Suggested fix: SVG inline branded.

- **K03-P3-02 : Background gradient `#0f0f1a → #1a1a2e` (login) ne suit pas
  le theme refresh light mode owner**
  - File: `resources/js/components/frontend/kiosk/KioskLoginComponent.vue:148-155`
  - Issue: Memory `project_kiosk_design_refresh_2026-05-10.md` :
    "light mode + palette mobile app (noir/rouge/jaune/blanc)". Le
    login écran reste sombre. Discordance visuelle avec idle/categories
    refreshed.
  - Suggested fix: Aligner avec `KsLoginCard` du DS, palette light.

- **K03-P3-03 : `clearKioskA11yAttributes` exporté mais non appelé**
  - File: `resources/js/composables/useKioskA11y.js:64-70`
  - Issue: Fonction "utile lorsqu'on quitte la surface kiosk" mais
    aucun call site dans `KioskAppComponent.beforeUnmount` ni dans
    le router guard. Si un user nav `/kiosk` → `/admin` (impossible
    en V1 borne, mais théorique), `data-kiosk-pmr="true"` leak.
  - Suggested fix: Hook `beforeUnmount` de KioskAppComponent.

## Cross-link backend (K16)

- **P0-07 RefreshTokenController abilities `['*']`** → **NON exposé côté
  kiosk frontend.** Aucun call site dans `resources/js/` n'invoque
  `/refresh-token`. Le commentaire `bootstrap.js:260` confirme
  explicitement : "No timer-based proactive refresh: there is no backend
  refresh-token endpoint." utilisé. Risque kiosk = 0 sauf si un attaquant
  vole `kioskToken` (K03-P0-01) ET appelle directement
  `/api/refresh-token` — mais c'est l'**ability `['*']`** côté backend
  qui amplifie l'impact (out-of-scope K03, voir K16).

- **P0-08 missing `abilities:kiosk:order`** → Pas de call site frontend
  qui dépend explicitement de la middleware abilities ; le frontend
  envoie juste le Bearer et le backend doit faire le check. Si la
  middleware manque, K03 ne le détecte pas — voir K18 pour audit des
  routes `/api/frontend/order` et `/api/frontend/menu`.

## Existing E2E coverage

- `tests/js/KioskLogin.spec.js` — auto-login + ignore stale maintenance flag
  (1 cas, mock kioskLogin action)
- `tests/js/kioskA11yAxe.spec.js:116` — KioskInactivityOverlay stubbé true (pas testé directement)
- `tests/js/kioskIdleWarningEvent.spec.js` — vérifie le nom canonique de
  l'event `idle_warning_shown` (regression vs `idle_warning`)
- `tests/js/userReportedBlockersRuntime.spec.js:28` — read-only sur
  KioskLoginComponent.vue (vérif texte)
- `tests/e2e/kiosk-edge-cases.spec.js:79-122` — Suite 4 Scenario 2 :
  détection overlay + structure stay/leave/countdown DOM
- `tests/e2e/test-e2e-borne-2026-05-10-wave-E.spec.js:130-136` — helper
  dismissInactivity
- `tests/e2e/test-e2e-mobile-design-full-wave-E.spec.js:131-136` — idem

## Proposed new E2E tests

- **T-K03-01 : Token expiration TTL — Sanctum reject post 480 min**
  - Steps:
    1. Mock backend `auth/kiosk-login` retourne token + faux `created_at` -481min
    2. Tente `POST /api/frontend/quote-order`
    3. Assert: backend renvoie 401, frontend invalide cache + redirect `/kiosk/login`
  - Assertions: `kioskToken === null` après 401, `kioskLogin` action redispatchée 1× (pas N×), aucun double-soumission idempotency.

- **T-K03-02 : Inactivity overlay focus trap Tab cycle (EAA 2025)**
  - Steps:
    1. Composer un panier de 2 items
    2. Force `showStillHere=true` via `$store` ou wait `idleMs - confirmMs`
    3. `await page.keyboard.press('Tab')` ×5 → assert focus reste **dans** le `[data-testid="kiosk-inactivity-overlay"]` (use `document.activeElement.closest('[data-testid="kiosk-inactivity-overlay"]')`)
    4. `await page.keyboard.press('Enter')` sur Stay → assert `secondsLeft` reset
  - Assertions: focus never escapes overlay, Enter triggers Stay, `aria-modal="true"` présent.

- **T-K03-03 : Login retry exponential backoff + jitter — pas de 429 amplification**
  - Steps:
    1. Mock backend `auth/kiosk-login` 5× 401 puis success
    2. Capturer `setTimeout` calls (vi.useFakeTimers)
    3. Assert: delays = [4000, 8000, 16000, 30000, 30000] avec ±20% jitter (fail expected = pas de jitter actuellement)
  - Assertions: delay sequence respecte plafond 30s, jitter présent, `retryAttempts` cappé 10.

- **T-K03-04 : Backend error rendering — toujours i18n FR, jamais raw EN**
  - Steps:
    1. Mock backend retourne `{ errors: { password: ['The password field is required.'] } }` 422
    2. Assert: `wrapper.vm.error` === `frMessages.kiosk.login_screen.err_login_failed` (PAS le string EN)
  - Assertions: pas de "The password field is required." dans DOM rendu.

- **T-K03-05 : i18n AR RTL — overlay countdown direction**
  - Steps:
    1. Set `kioskSettings.locale = 'ar'`
    2. Assert: `<html dir="rtl">` posé par `useKioskA11y`
    3. Assert: `kiosk-inactivity-title` contient "هل أنت هنا؟"
    4. Assert: bouton stay aligné droite (CSS check via `getComputedStyle`)
  - Assertions: RTL appliqué, traductions présentes, countdown lisible.

## Risks & open questions (owner gate)

1. **K03-P0-01 (token in localStorage)** : migrer vers cookie HttpOnly
   touche `KioskMachineLoginController` (backend) + tous les call sites
   axios + Echo authEndpoint. **Owner gate requis** — change architectural.
2. **K03-P0-02 (TTL non géré)** : nécessite contract change response
   `auth/kiosk-login` (ajouter `expires_at`). Coordination backend K16.
3. **K03-P1-02 (focus trap)** : `KsButton.vue` est dans le DS — fix
   doit vérifier qu'il rend `<button>` natif. À cross-checker avec
   K07/K12 qui touchent au DS.
4. **K03-P2-02 (countdown AR locale)** : implique discussion EAA 2025
   sur durée minimum acceptable selon longueur localisée — pas de
   norme stricte trouvée, décision owner.

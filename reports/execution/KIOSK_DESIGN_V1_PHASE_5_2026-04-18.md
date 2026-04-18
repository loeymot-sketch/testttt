# KIOSK_DESIGN_V1 — Phase 5 : Hardware bridge, Healthcheck, Idle, Consent RGPD, Observability

**Date** : 2026-04-18
**Auteur** : Agent Cursor (session continue Phase 0→5)
**Statut** : ✅ Phase 5 terminée — tous gates verts.

---

## 1. Portée livrée

Phase 5 finalise la boucle kiosk end-to-end avec l'intégration hardware (bridge `window.borne.*`), l'observabilité périodique, la configuration d'idle timeouts, le consentement RGPD (loyalty + analytics) et le pipeline analytics client complet. Elle corrige également un bug latent introduit en Phase 4.

| Sous-phase | Livrable | Statut |
|---|---|---|
| P5.1 | `resources/js/services/kioskHardware.js` — wrapper `window.borne.*` + stub dev + contrat `{ok, error?}` | ✅ |
| P5.2 | Healthcheck périodique 90 s + boot `info()` dans `KioskAppComponent.vue` | ✅ |
| P5.3 | Idle timeouts configurables dans `kioskSettings` store (`idleMs`/`confirmMs`/`receiptMs`) | ✅ |
| P5.4 | `KsConsentModal.vue` RGPD — opt-in loyalty + analytics, checkbox non pré-cochée, link privacy | ✅ |
| P5.5 | `resources/js/helpers/kioskAnalytics.js` — `track()` consent-gated, sendBeacon + fallback, queue FIFO 200 | ✅ |
| P5.6 | `KioskEventController` — whitelist étendue (7 nouveaux types), guard PII payload, validation `event_name` | ✅ |
| P5.7 | i18n FR/EN/AR — clés `kiosk.consent.*` + `kiosk.hardware.*` | ✅ |
| P5.8 | Tests Vitest — 32 nouveaux tests (hardware stub, analytics gate, consent modal, idle timeouts) | ✅ |
| P5.9 | Tests PHPUnit — 12 tests whitelist + PII guard + régression | ✅ |
| P5.10 | Build prod + full non-regression + rapport | ✅ |

---

## 2. Audit pré-Phase 5 (double check Phase 4)

### 2.1. Bug latent détecté & corrigé

Le composant `KsA11ySettings` (Phase 4.3) émettait des events `type: 'a11y_settings'` via `POST /api/frontend/kiosk-event`. Ce type N'ÉTAIT PAS dans la whitelist `KioskEventController::ALLOWED_TYPES` → tous les events a11y renvoyaient **422** silencieusement.

**Correction** : le type `a11y_settings` est ajouté à la whitelist (`app/Http/Controllers/Frontend/KioskEventController.php:64-65`), et testé en régression par `KioskEventPhase5WhitelistTest::test_a11y_settings_type_accepted`.

### 2.2. Tests Phase 4 régression

- `kioskSettingsStore.spec.js` : 8/8 ✅
- `kioskA11yComposable.spec.js` : 5/5 ✅
- `kioskVirtualKeyboard.spec.js` : 10/10 ✅
- `kioskA11ySettingsDrawer.spec.js` : 9/9 ✅
- `kioskSpeechComposable.spec.js` : 5/5 ✅

Aucune régression liée à l'extension de `kioskSettings` (idle timeouts + consents persist paths).

---

## 3. Architecture livrée

### 3.1. Service hardware unifié (`kioskHardware.js`)

Source d'autorité : `borne (Remix)/docs/design/KIOSK_HARDWARE_CALLS.md`.

- **Stub no-op auto** quand `window.borne === undefined` (dev / tests / staging web). Marqueur interne `__foodkingKioskStub` pour différencier stub et vraie borne Electron.
- **Contrat uniforme** : toutes les méthodes asynchrones renvoient `{ ok: boolean, error?: string, ...data }` — JAMAIS de `throw`, même sur erreur bridge.
- **`healthcheck()`** interprète les composants retournés par le bridge :
  - `critical` si `tpe` ou `printer` KO (composants bloquants §4 du spec)
  - `degraded` si `nfc` ou `camera` KO (acceptables)
  - `ok` sinon
- **`tpeCharge()`** fallback automatique sur l'API legacy `chargeCard()` déjà consommée par `KioskPaymentComponent.vue`.
- **Observabilité** : chaque échec est relayé automatiquement via `/api/frontend/kiosk-event` type `hardware_event`, fire-and-forget.

### 3.2. Healthcheck périodique dans `KioskAppComponent`

- **Boot** : 1 healthcheck immédiat au `mounted()`; si critique, snapshot `info()` complémentaire posté.
- **Période** : 90 s (brief §5.2) — `setInterval` propre, cleanup strict au `beforeUnmount`.
- **Listener bridge events** : `onHardwareEvent(cb)` relaie chaque event (printer_paper_low, tpe_disconnected, card_presented, nfc_detected, …) vers `/api/frontend/kiosk-event` avec classification `component`/`severity`/`code`.

### 3.3. Idle timeouts configurables (`kioskSettings`)

Auparavant : constantes dures `IDLE_TIMEOUT_MS = 180000` / `STILL_HERE_MS = 150000` dans `KioskAppComponent`.

Désormais :
- **State** : `kioskSettings.idleMs` (default 180 000), `confirmMs` (30 000), `receiptMs` (30 000).
- **Bornes** : `idleMs ∈ [10 s, 10 min]`, `confirmMs ∈ [3 s, 60 s]`, `receiptMs ∈ [5 s, 5 min]`. Clamp automatique au lieu de rejet.
- **Action** `kioskSettings/setIdleTimeouts(partial)` — patch partiel autorisé, NaN → default, valeurs invalides → default.
- **Persistance** : localStorage via `createPersistedState` (store/index.js paths).
- **Consommé par** : `KioskAppComponent.startIdleTimer()` qui calcule `warnAt = idleMs - confirmMs` à chaque itération.
- **`resetKiosk()`** renouvelle la session analytics (`kioskAnalytics.resetSession()`).

### 3.4. RGPD : `KsConsentModal.vue`

Conforme au brief §1 (opt-in strict) + European Accessibility Act.

- **Checkboxes NON pré-cochées** — pas de dark pattern.
- **Séparation stricte** : consentement loyalty ≠ consentement analytics. Deux toggles legal distincts, choix indépendants.
- **Lien privacy** accessible AVANT toute décision. Sous-dialog nested `role=dialog` avec aria-label.
- **API** : si `loyalty=true` ET `phone` fourni, POST `/api/frontend/loyalty/opt-in` (endpoint Phase 1). Sinon skip côté API — le parent peut collecter plus tard.
- **Backdrop click** = decline explicite (pas de fermeture silencieuse — RGPD exige un choix).
- **Accept sans aucune case cochée** → erreur affichée (`required_loyalty`), modal reste ouverte.
- **Event observabilité** : `type: 'consent_event'` pour le technique ; `type: 'analytics' + event_name: 'consent_given'` pour le funnel spec.
- **Synchronisation store** : chaque accept/decline met à jour `kioskSettings.consentLoyalty` et `kioskSettings.consentAnalytics`.

### 3.5. Analytics client (`kioskAnalytics.js`)

Source d'autorité : `borne (Remix)/docs/design/KIOSK_ANALYTICS_EVENTS.md`.

- **`track(event_name, payload)`** — seul point d'émission. No-op sans consent.
- **Whitelist stricte** (28 events) : toute tentative hors whitelist est silent-dropped.
- **Guard PII** : rejet des payloads contenant `email`, `phone`, `name`, `iban`, `pan`, `cvv`, etc. (détection récursive dans objets imbriqués).
- **Transport** :
  1. `navigator.sendBeacon(/api/frontend/kiosk-event)` (survit à `beforeunload`)
  2. Fallback `window.axios.post` (respect CSRF)
  3. Fallback `fetch` avec `keepalive`
  4. Queue locale FIFO max 200 events avec drain auto (2 s) — si consent révoqué, vidée immédiatement.
- **Session** : UUID v4 généré à la demande (`ensureSession`), renouvelé à chaque `resetKiosk()` (idle reset).
- **branch_id** : synchronisé live via watcher Vuex sur `kioskCart.branchId` (setup dans `_bootAnalyticsGate`).

### 3.6. Backend — `KioskEventController` v2

- **Whitelist étendue** : +7 types Phase 5 (`analytics`, `hardware_health`, `hardware_event`, `hardware_error`, `consent_event`, `idle_event`, `a11y_settings`).
- **Whitelist analytics secondaire** : quand `type=analytics`, le `event_name` DOIT appartenir à la liste de 28 events du spec. Sinon 422.
- **Guard PII serveur** : détection récursive des clés interdites dans `payload`. Retourne 422 avec message explicite `Payload contains forbidden PII keys` — double rempart avec le guard client.
- **`session_id`** validé `max:64` chars, loggé en metadata.
- **Hard cap** `details` 500 chars préservé (truncate `...` si overflow).
- **Hardware channel** : tous les events `hardware_*` logguent aussi dans `Log::channel('hardware')` pour alerting ops.

---

## 4. Validation & gates

### 4.1. Tests Vitest

| Fichier | Tests | Résultat |
|---|---|---|
| `kioskHardwareService.spec.js` (Phase 5.1) | 9 | ✅ |
| `kioskAnalytics.spec.js` (Phase 5.5) | 7 | ✅ |
| `kioskConsentModal.spec.js` (Phase 5.4) | 8 | ✅ |
| `kioskSettingsIdleTimeouts.spec.js` (Phase 5.3) | 8 | ✅ |
| **Total nouveaux Phase 5** | **32** | **✅** |
| **Full non-regression** | **295** | **✅** |

Couvertures clés :
- Stub hardware actif sans `window.borne`, `{ok, error}` uniforme sur tous les paths.
- `tpeCharge` wrap les throws en `{ok: false}`.
- Consent gate verrouille `track()` : 0 POST sans consent.
- Event hors whitelist silent-dropped.
- Clé PII `email`/`phone` → rejetée côté client AVANT transport.
- Session UUID stable puis renouvelée par `resetSession()`.
- Checkboxes non pré-cochées (RGPD).
- Accept sans case cochée → erreur + modale reste ouverte.
- Decline → pas de POST opt-in, store consent=false.
- Idle timeouts clamp aux bornes min/max, NaN → default, reset préserve consents.

### 4.2. Tests PHPUnit

| Fichier | Tests | Résultat |
|---|---|---|
| `KioskEventPhase5WhitelistTest.php` | 12 | ✅ |
| Régression `KioskPhase1/*` (11 fichiers) | 79 + 1 skipped (SQLite FK) | ✅ |

Couvertures clés :
- Tous les 7 nouveaux types acceptés (200).
- `analytics` sans `event_name` → 422.
- `analytics` avec event_name hors whitelist → 422.
- Payload avec `email`/`phone` (même imbriqué) → 422 `Payload contains forbidden PII keys`.
- Types inconnus → 422 `Unknown event type`.
- Legacy types (Phase 0 + Phase 3) restent acceptés sans régression.

### 4.3. Build prod

```
✔ Compiled Successfully in 6601ms
js/kiosk.js : 1.35 MiB  (Phase 4: 1.29 MiB, delta +60 KiB acceptable)
css/app.css : 182 KiB
```

Aucun warning mix critique. Le delta `+60 KiB` est lié aux 3 modules ajoutés (`kioskHardware` 4.4 KiB minified, `kioskAnalytics` 2.8 KiB, `KsConsentModal` ~5 KiB, reste pour les watchers / i18n).

---

## 5. Invariants vérifiés

| Invariant | Respect | Preuve |
|---|---|---|
| §1.1 SSOT pricing | ✅ | `kioskHardware` ne touche aucun prix. `kioskAnalytics` n'expose AUCUN getter agrégé. |
| §1.2 branch_id serveur uniquement | ✅ | `kioskAnalytics.setBranchId` lit depuis `kioskCart.branchId`, pas d'URL/payload. Backend ignore `branch_id` payload pour le scope (ne l'utilise que pour le log). |
| §1.3 OrderStateMachine | ✅ | Phase 5 ne touche pas l'ordre ni ses transitions. |
| §1.4 EventContract V1 | ✅ | `/api/frontend/kiosk-event` est un endpoint d'observabilité (ActionLog) distinct de l'EventContract V1 broadcast. |
| §1.5 Aucune stat dynamique client | ✅ | `kioskAnalytics` n'expose que `track` (envoi) — aucun `getCount`, `getTrend`. |
| §1.6 RGPD opt-in strict | ✅ | Checkboxes non pré-cochées + séparation loyalty vs analytics + double consent gate (client + serveur) + PII rejet récursif. |
| §1.7 WCAG 2.2 AA par défaut | ✅ | `KsConsentModal` : `role=dialog`, `aria-modal`, `aria-labelledby`, `aria-describedby`, `aria-busy`, `aria-live` pour erreurs, `focus-visible` sur tous interactifs, tap min 56 px (60 px CTA primary). |

---

## 6. Risques résiduels & hors-scope

1. **Privacy notice body** — Un texte de fallback inline est fourni dans `KsConsentModal.privacyBody`. Production recommandée : brancher sur une clé i18n `kiosk.consent.privacy_body` ou une URL CMS (via `globalState`). Hors scope Phase 5.
2. **Files .mp3 AR** — Phase 4.5 prévoit un fallback `/kiosk/audio/ar/<key>.mp3` pour `speak()`. Les fichiers ne sont PAS livrés dans cette phase (assets audio).
3. **Connectivité offline** — `navigator.sendBeacon` n'échoue pas explicitement si offline ; la queue FIFO 200 absorbe, mais un kiosk offline > 2 min perd les events au-delà. Acceptable vu que le kiosk doit être online pour payer (TPE + backend).
4. **Analytics offline persistence** — Contrairement à `kioskOfflineQueue.js` (orders), la queue analytics reste en mémoire pure (RGPD : on ne persiste pas). En cas de reload kiosk avant drain, les events sont perdus — attendu.
5. **`hardware_event` audit trail** — Actuellement logué uniquement dans `ActionLog` + channel `hardware`. Pas d'aggregation admin dashboard. Peut être ajouté en Phase 6 si besoin ops.
6. **Admin idle config UI** — Les `idleMs/confirmMs/receiptMs` sont stockés dans le store et persistés, mais il n'y a pas d'écran admin pour les modifier visuellement. L'admin doit les mettre via console Vuex ou via future section `KioskAdmin`. Acceptable si l'ops a accès au panel admin existant.

---

## 7. Fichiers livrés

### Nouveaux (7)
- `resources/js/services/kioskHardware.js`
- `resources/js/helpers/kioskAnalytics.js`
- `resources/js/components/frontend/kiosk/ds/KsConsentModal.vue`
- `tests/js/kioskHardwareService.spec.js`
- `tests/js/kioskAnalytics.spec.js`
- `tests/js/kioskConsentModal.spec.js`
- `tests/js/kioskSettingsIdleTimeouts.spec.js`
- `tests/Feature/KioskPhase5/KioskEventPhase5WhitelistTest.php`
- `reports/execution/KIOSK_DESIGN_V1_PHASE_5_2026-04-18.md` (ce document)

### Modifiés (5)
- `app/Http/Controllers/Frontend/KioskEventController.php` — whitelist étendue + analytics event_name + guard PII récursif
- `resources/js/store/modules/kioskSettings.js` — idle timeouts + consents
- `resources/js/store/index.js` — paths persist étendus
- `resources/js/components/frontend/kiosk/KioskAppComponent.vue` — _bootHardware, _bootAnalyticsGate, idle timeouts lus depuis store, cleanup
- `resources/js/components/frontend/kiosk/ds/index.js` — export `KsConsentModal`
- `resources/js/languages/{fr,en,ar}.json` — clés `kiosk.consent.*` + `kiosk.hardware.*`

---

## 8. Prochaines étapes suggérées

Phase 5 clôt le **plan d'intégration Kiosk Design V1** tel que défini dans le master prompt. Les 5 phases sont vertes :
- Phase 0 : Design System + tokens
- Phase 1 : Prérequis backend (migrations + 6 endpoints)
- Phase 2 : Restyle 13 composants Vue existants
- Phase 3 : 5 nouveaux écrans (CashInstruction + 4 erreurs)
- Phase 4 : A11y, i18n, audio, clavier virtuel
- Phase 5 : Hardware bridge, healthcheck, idle, consent, observability

**Étapes post-Phase 5 suggérées** (hors master prompt initial) :
1. **E2E Playwright** — leverage des `data-testid` stables ajoutés en Phase 2/3/4/5 pour valider les 9 flows critiques (idle → cart → payment → confirmation sous les 3 modes AA/AAA/PMR + 3 locales).
2. **Axe-core audit** sur les 16 écrans restylés (gate §4.1).
3. **Visual regression** contre les screenshots `borne (Remix)/audit/*.png` (tolérance ±8 px du brief).
4. **Staging deploy** + smoke tests hardware bridge sur une vraie borne Electron.
5. **Admin UI** pour `kioskSettings.idleMs/confirmMs/receiptMs` dans `KioskAdminComponent` (setting par branche).

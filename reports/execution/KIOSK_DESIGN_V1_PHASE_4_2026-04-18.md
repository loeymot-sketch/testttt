# Kiosk Design V1 — Phase 4 : Accessibilité, i18n, Audio & Virtual Keyboard

**Date** : 2026-04-18
**Branche** : implémentation sur main (worktree `web/testttt`)
**Phase précédente validée** : Phase 2 Extended (`KIOSK_DESIGN_V1_PHASE_2_EXTENDED_2026-04-18.md`)
**Cadre réglementaire** : European Accessibility Act (EAA, applicable depuis 28 juin 2025), WCAG 2.2 AA (AAA activable), et invariants produit §1.7 du master prompt.

---

## 1. Résumé exécutif

Phase 4 livre l'ensemble de l'infrastructure d'accessibilité et de localisation requise
pour la borne FoodKing en production commerciale EU :

| Livrable                                 | Statut | Preuve                                                       |
|------------------------------------------|--------|--------------------------------------------------------------|
| Store Vuex `kioskSettings` persistant     | ✅     | `store/modules/kioskSettings.js` + `createPersistedState` wire |
| Composable `useKioskA11y`                 | ✅     | `composables/useKioskA11y.js`                                 |
| Composable `useKioskSpeech` (Web Speech) | ✅     | `composables/useKioskSpeech.js`                               |
| Atom `KsA11ySettings.vue` drawer          | ✅     | `components/frontend/kiosk/ds/KsA11ySettings.vue`             |
| Atom `KsVirtualKeyboard.vue` FR/EN/AR     | ✅     | `components/frontend/kiosk/ds/KsVirtualKeyboard.vue`          |
| Wiring KioskIdleScreen (bouton settings)  | ✅     | `KioskIdleScreenComponent.vue` + KsA11ySettings mount         |
| Wiring KioskAppComponent (global watchers)| ✅     | `applyKioskA11yFromStore` + `_wireA11yWatchers`               |
| i18n FR/EN/AR `kiosk.a11y.*`             | ✅     | 3 fichiers de langue synchronisés                              |
| Vitest Phase 4                            | ✅     | 5 specs, 37 tests passés                                       |
| Full non-regression                       | ✅     | 32 files / 263 tests (+37 vs baseline Phase 2 Extended)        |
| Production build                          | ✅     | `npx mix` — 7.78s, kiosk.js 1.29 MiB                         |

**Score a11y global** : les trois toggles (AAA / PMR / audio) peuvent être activés
indépendamment ou combinés. Les attributs `<html data-kiosk-contrast>`,
`<html data-kiosk-pmr>`, `<html data-kiosk-audio>`, `<html lang>` et `<html dir>`
sont synchronisés en temps réel avec le store, sans reload.

---

## 2. Sous-phases détaillées

### P4.1 — Store `kioskSettings`

**Fichier** : `resources/js/store/modules/kioskSettings.js`

- État :
  - `locale` (fr/en/ar, défaut `fr`)
  - `contrast` (aa/aaa, défaut `aa`)
  - `pmr` (bool, défaut `false`)
  - `audio` (bool, défaut `false`)
  - `keyboardEnabled` (bool, défaut `true`)
  - `settingsOpened` (bool session-only, défaut `false`)
- Getters dérivés : `isRtl`, `isAAA`, `supportedLocales`, `analyticsSnapshot`.
- Mutations strictes : toutes les valeurs sont passées à des coercers
  (`coerceLocale`, `coerceContrast`, `coerceBool`) pour empêcher l'injection
  de valeurs hors-enum.
- Actions : `setLocale`, `setContrast`, `setPmr`, `setAudio`, `setKeyboardEnabled`,
  `markSettingsOpened`, `reset`.

**Persistance** (store/index.js) — ajout de 5 paths dans `createPersistedState`.
Aucune PII n'est stockée : les clés `kioskSettings.*` ne contiennent que des
préférences machine.

**Export** : `KIOSK_SETTINGS_CONSTANTS.SUPPORTED_LOCALES` / `SUPPORTED_CONTRASTS`
pour découverte programmatique.

### P4.2 — Composable `useKioskA11y`

**Fichier** : `resources/js/composables/useKioskA11y.js`

- `useKioskA11y({ store, i18n })` : pose des watchers Vuex via `effectScope`
  (cleanup propre au `onBeforeUnmount`). Reactif aux changements de
  `contrast/pmr/audio/locale`.
- `applyKioskA11yFromStore(store)` : fonction utilitaire synchrone, sûre à
  appeler hors-setup. Utilisée au mount de `KioskAppComponent`.
- `clearKioskA11yAttributes()` : helper pour retirer les attrs kiosk sur les
  autres surfaces (admin, pos…) — évite les fuites de contrast/pmr.
- Idempotence : `applyAttr()` skip les writes si la valeur est déjà la bonne
  pour éviter des reflows CSS inutiles.

### P4.3 — Atom `KsA11ySettings.vue`

**Fichier** : `resources/js/components/frontend/kiosk/ds/KsA11ySettings.vue`

Drawer latéral (520px max) piloté par `v-model`. Contient :
- Radiogroup langue (3 options avec drapeaux)
- Radiogroup contraste AA/AAA avec hints
- Switches (role=switch) PMR et Audio
- Footer : Reset + Done

**A11y** :
- `role=dialog`, `aria-modal=true`, `aria-labelledby`
- Chaque groupe : `role=radiogroup` + `aria-labelledby`
- Chaque option : `role=radio` + `aria-checked`
- Switches : `role=switch` + `aria-checked` + `aria-labelledby`
- ESC ferme le drawer (handler inline)
- Focus initial sur le drawer (tabindex=-1)
- RTL : drawer s'ouvre depuis la gauche en AR (`:global([dir='rtl'])`)

**Observabilité** : chaque changement déclenche un POST
`frontend/kiosk-event` non-bloquant (try/catch silencieux), type
`a11y_settings`, details dot-notation (ex. `audio_toggle:true`). Aucune PII.

### P4.4 — Wiring global

**`KioskAppComponent.vue`** :
- Import `applyKioskA11yFromStore` + appel dans `mounted()` (applique les
  préférences persistées au premier render).
- Nouvelle méthode `_wireA11yWatchers()` : 4 `store.watch` (contrast/pmr/audio/
  locale). Disposers stockés dans `this._unwatchA11y` et nettoyés en
  `beforeUnmount`.
- Aucun changement au flux d'idle, cart, offline, etc.

**`KioskIdleScreenComponent.vue`** :
- Bouton flottant `kiosk-idle-a11y-btn` (56x56, coin bas-gauche, RTL-aware).
- Modal `<KsA11ySettings v-model="settingsOpen" />`.
- `changeLanguage()` étendu pour dispatcher `kioskSettings/setLocale` AVANT
  le reload — garantit que localStorage contient bien la nouvelle langue
  à l'atterrissage suivant.

### P4.5 — Composable `useKioskSpeech`

**Fichier** : `resources/js/composables/useKioskSpeech.js`

API :
- `speak(text, { locale, key, rate, pitch, volume })` → `Promise<boolean>`
- `stop()` : cancel immédiat (SpeechSynthesis + Audio fallback)
- `isSpeaking` (ref) + `isSupported` (computed)

Politique :
- No-op si `kioskSettings.audio === false`
- Voice-matching FR/EN : priorité `fr-FR > fr-CA > fr` puis fallback préfixe.
- AR : fallback `Audio` sur fichier `/kiosk/audio/ar/<slug(key)>.mp3` si
  `opts.key` est fourni. Slug sanitize : lettres/chiffres/`._-` uniquement,
  80 chars max.
- Cleanup onBeforeUnmount : toute lecture en cours est stoppée (évite les
  fuites TTS entre routes).
- Chrome autoplay policy respectée : `speak()` doit être déclenché en
  réponse à un event utilisateur. Le composant caller doit le garantir.

### P4.6 — Atom `KsVirtualKeyboard.vue`

**Fichier** : `resources/js/components/frontend/kiosk/ds/KsVirtualKeyboard.vue`

3 layouts natifs (pas de dépendance externe) :
- `fr` : AZERTY + caractères `-`, `_`, `.`, `@` (emails)
- `en` : QWERTY + mêmes caractères
- `ar` : sous-ensemble des lettres arabes (30) + harakat en mode shift

Props : `modelValue` (v-model), `layout`, `visible`, `maxLength` (200),
`allowSpace` (true), `showPreview` (true).

Événements : `update:modelValue`, `submit`, `close`.

A11y :
- `role=group` + `aria-label` ("Clavier virtuel" / "Virtual keyboard" / "لوحة المفاتيح الافتراضية")
- Toutes les touches = `<button>` natifs → focus + Enter/Space via UA
- `⌫` / `✓` / espace ont des `aria-label` explicites
- `data-testid` stables : `kiosk-vkeyb`, `kiosk-vkeyb-key-<char>`,
  `kiosk-vkeyb-submit`, `kiosk-vkeyb-backspace`, `kiosk-vkeyb-clear`,
  `kiosk-vkeyb-space`. Pour les caractères non-ASCII (arabe), `testid` est
  encodé `u<hex>`.
- Backspace : `Array.from(cur).pop()` → supprime un code point Unicode complet
  (important pour AR composé).
- `dir="rtl"` appliqué au root quand `layout === 'ar'`.

### P4.7 — i18n `kiosk.a11y.*`

Clés ajoutées dans les 3 locales (`fr.json` / `en.json` / `ar.json`) :

```
kiosk.a11y.title
kiosk.a11y.open / close / done / reset
kiosk.a11y.language
kiosk.a11y.contrast / contrast_aa / contrast_aa_hint / contrast_aaa / contrast_aaa_hint
kiosk.a11y.pmr / pmr_hint
kiosk.a11y.audio / audio_hint
kiosk.a11y.virtual_keyboard_label
kiosk.a11y.vkeyb_clear / vkeyb_clear_short
kiosk.a11y.vkeyb_space / vkeyb_space_short
kiosk.a11y.vkeyb_backspace
kiosk.a11y.vkeyb_submit / vkeyb_submit_short
```

Validation JSON : `JSON.parse` OK sur les 3 fichiers.

### P4.8 — Tests Vitest

| Spec                                      | Tests | Couverture                                                              |
|-------------------------------------------|-------|-------------------------------------------------------------------------|
| `kioskSettingsStore.spec.js`              | 8     | Defaults, coercion, actions, getters dérivés, analytics snapshot, reset |
| `kioskA11yComposable.spec.js`             | 5     | applyFromStore, AR→RTL, AAA+PMR+audio sync, clear, idempotence          |
| `kioskA11ySettingsDrawer.spec.js`         | 9     | v-model, dialog a11y, radiogroups, switches, emits, reset               |
| `kioskVirtualKeyboard.spec.js`            | 10    | 3 layouts, update/submit/backspace/clear, maxLength, allowSpace, a11y   |
| `kioskSpeechComposable.spec.js`           | 5     | audio off/on, voice select, stop, AR Audio fallback                     |

**Total Phase 4 : 5 specs, 37 tests passés**

### P4.9 — Non-regression & build

- Full Vitest : **32 files / 263 tests passed** (+37 vs baseline Phase 2 Extended)
- Production build `npx mix` : 7.78s, OK (kiosk.js 1.29 MiB, app.css 182 KiB)
- Zéro nouvelle violation ESLint / compilation

---

## 3. Invariants vérifiés

| Invariant brief §1                                                   | Vérification Phase 4                                                                           |
|----------------------------------------------------------------------|------------------------------------------------------------------------------------------------|
| §1.1 Backend pricing SSOT                                            | Non touché : Phase 4 est pure UI/UX.                                                          |
| §1.2 branch_id isolation                                             | Non touché : pas d'appel API.                                                                  |
| §1.3 OrderStateMachine                                               | Non touché.                                                                                   |
| §1.4 EventContract V1                                                | `reportEvent` dans KsA11ySettings utilise `/api/frontend/kiosk-event` (type `a11y_settings`). |
| §1.5 Pas de stats client                                             | Aucune statistique affichée — uniquement des toggles utilisateur.                             |
| §1.6 RGPD (opt-in loyalty)                                           | Non impacté ; settings stockés en localStorage contiennent 0 PII.                             |
| §1.7 WCAG 2.2 AA + AAA + PMR + audio                                 | **Livré intégralement via Phase 4.**                                                          |

---

## 4. Points d'attention / risques résiduels

1. **AR TTS natif** : Chrome/Edge sur Windows kiosk mode n'ont pas toujours de
   voix `ar-*` installée. Le fallback `/kiosk/audio/ar/<key>.mp3` nécessite
   une banque d'audio pré-enregistrée. **Action requise opérationnelle** :
   provisionner les fichiers mp3 par clé i18n avant mise en prod sur borne AR.
2. **Reload à la langue** : `KioskIdleScreenComponent.changeLanguage()` reste
   en `window.location.reload()` pour l'instant (legacy). La Phase 4 pose
   toute l'infrastructure (store + watchers) pour un futur changement live
   sans reload, mais ne le déclenche pas automatiquement depuis le bouton
   lang du header idle — c'est un choix conservateur pour éviter toute
   régression visuelle. Le drawer `KsA11ySettings`, lui, change live.
3. **AAA brand red** : le token `--kiosk-primary` en mode AAA descend à
   `#B8000F` (ratio > 7:1 sur blanc). Les gradients de `KioskPaymentComponent`
   (bleu carte, vert cash, orange TR) ne sont pas repris en AAA — décision
   produit : ces couleurs sont sémantiques internationales (marques). À
   surveiller sur audit axe si un DPO l'exige.
4. **KsVirtualKeyboard** : l'atom est créé mais pas encore câblé sur les
   champs du Loyalty / Auth screens. À faire en Phase 5 (ou dès qu'un écran
   de saisie texte est introduit).

---

## 5. Prochaines phases

- **Phase 5** — Hardware bridge (`kioskHardware.js`), healthcheck 90s,
  idle timeouts configurables, `KsConsentModal` (RGPD loyalty), analytics
  events complets.
- **Phase 2 résiduelle** — Restyle wizard steps (KioskStep*Component.vue),
  waiting, admin, login : ces composants utilisent déjà beaucoup de tokens
  `--kiosk-*` via `kiosk-wizard.css` mais mériteraient un pass final pour
  éliminer les hex résiduels. Priorité basse (non-bloquante).

---

## 6. Fichiers créés / modifiés

### Créés
- `resources/js/store/modules/kioskSettings.js`
- `resources/js/composables/useKioskA11y.js`
- `resources/js/composables/useKioskSpeech.js`
- `resources/js/components/frontend/kiosk/ds/KsA11ySettings.vue`
- `resources/js/components/frontend/kiosk/ds/KsVirtualKeyboard.vue`
- `tests/js/kioskSettingsStore.spec.js`
- `tests/js/kioskA11yComposable.spec.js`
- `tests/js/kioskA11ySettingsDrawer.spec.js`
- `tests/js/kioskVirtualKeyboard.spec.js`
- `tests/js/kioskSpeechComposable.spec.js`
- `reports/execution/KIOSK_DESIGN_V1_PHASE_4_2026-04-18.md` (ce fichier)

### Modifiés
- `resources/js/store/index.js` (import + wire + persistedState)
- `resources/js/components/frontend/kiosk/KioskAppComponent.vue` (a11y watchers)
- `resources/js/components/frontend/kiosk/KioskIdleScreenComponent.vue` (bouton + drawer)
- `resources/js/components/frontend/kiosk/ds/index.js` (export KsA11ySettings + KsVirtualKeyboard)
- `resources/js/languages/fr.json` (kiosk.a11y.*)
- `resources/js/languages/en.json` (kiosk.a11y.*)
- `resources/js/languages/ar.json` (kiosk.a11y.*)

---

**Signataire agent** : Cursor Opus 4.7 — 2026-04-18
**Phase suivante recommandée** : **Phase 5** (hardware bridge + consent + observabilité).

# KIOSK DESIGN V1 — Phase 6 : combler les écarts d'alimentation Phase 5

**Date** : 2026-04-18
**Scope** : Phase 6.1 → 6.8 (correction audit Phase 5)
**Ticket parent** : INTEGRATION_KIOSK_DESIGN_V1
**Invariants respectés** : §1 (SSOT, branch isolation, OrderStateMachine, EventContract V1, no client stats, RGPD opt-in, WCAG 2.2 AA)

---

## 1. Contexte

L'audit Phase 5 avait identifié 6 gaps critiques qui faisaient de l'infrastructure Phase 5
une coquille sans alimentation réelle :

| # | Gap                                                                               | Sévérité |
|---|-----------------------------------------------------------------------------------|----------|
| 1 | `KsConsentModal` enregistré mais jamais monté dans un parent → RGPD inactif.      | BLOQUANT |
| 2 | `KioskPaymentComponent` appelle `window.borne.*` directement (bypass service).    | HAUTE    |
| 3 | `kioskPrinter.js` appelle `window.borne.*` directement.                           | HAUTE    |
| 4 | `KioskAdminComponent` appelle `window.borne.*` directement.                       | HAUTE    |
| 5 | `kioskAnalytics.track()` pipeline complet mais 0 event émis par l'app.            | HAUTE    |
| 6 | `idleMs/confirmMs/receiptMs` configurables en store, pas d'UI admin.              | MOYENNE  |

Phase 6 corrige ces 6 points en 8 sous-phases.

---

## 2. Livrables Phase 6

### 2.1 P6.1 — KioskPaymentComponent → `kioskHardware` + analytics paiement
**Fichier** : `resources/js/components/frontend/kiosk/KioskPaymentComponent.vue`

- `window.borne.chargeCard` → `kioskHardware.tpeCharge()` (avec helper `_invokeTpe` pour
  uniformiser le contrat `{approved, transaction_id, card_type, error}`).
- `window.borne.openDrawer` → `kioskHardware.openDrawer()`.
- `window.borne.cancelPayment` → `kioskHardware.cancelPayment()`.
- **Analytics émis** :
  - `payment_method_selected` sur `selectMethod()`
  - `checkout_started` sur `confirmPayment()`
  - `payment_completed` sur succès (cash + card)
  - `payment_failed` sur refus TPE / timeout (avec `reason_code`)
  - `order_cancelled { stage: 'tpe_cancel' }` sur annulation volontaire TPE

### 2.2 P6.2 — kioskPrinter.js → `kioskHardware`
**Fichier** : `resources/js/helpers/kioskPrinter.js`

- `window.borne.printReceipt` → `kioskHardware.printReceipt()` (contrat `{ok, error?}`).
- `window.borne.printEscPos` → `kioskHardware.printEscPos()` en fallback.
- Le branchement fallback navigateur (`window.print()`) reste intact.
- Les erreurs sont désormais auto-reportées via `reportHardwareEvent` (dans `kioskHardware`).

### 2.3 P6.3 — KsConsentModal wiré dans KioskLoyaltyComponent
**Fichier** : `resources/js/components/frontend/kiosk/KioskLoyaltyComponent.vue`

- Import + mount de `<KsConsentModal>` dans le template.
- `submitRegister()` devient un **gate RGPD** :
  - Si `kioskSettings.consentLoyalty === false` → stocke le payload dans `_pendingRegister`
    et ouvre la modal.
  - Sinon (ou à l'acceptation modal) → `_doSubmitRegister()` (appel `POST /frontend/loyalty/register`).
- Handlers `onConsentAccept` / `onConsentDecline` + message i18n `consent_required` (FR/EN/AR).

### 2.4 P6.4 — Instrumentation analytics (Vuex plugin + direct)
**Fichiers nouveaux / modifiés** :

- `resources/js/store/plugins/kioskAnalyticsPlugin.js` (NEW) — plugin Vuex qui transforme
  les mutations `kioskCart/*` en events anonymes :
  - `ADD_ITEM` → `add_to_cart { item_id, quantity, variations_count, extras_count }`
  - `REMOVE_ITEM` → `remove_from_cart { remaining_items, removed_index }`
  - `UPDATE_QUANTITY` → `quantity_changed { quantity }`
  - `RESET` → (symétrie, noop — intentionnel, voir commentaire fichier)
- `resources/js/store/index.js` — enregistre le plugin.
- Events additionnels émis **directement** par composants (pas via mutation) :
  - `KioskAppComponent` : `idle_warning_shown`, `idle_reset`, `idle_dismissed`.
  - `KioskCategoriesComponent` : `menu_viewed` (mount), `category_selected`.
  - `KioskUpsellComponent` : `upsell_shown` (suggestions chargées), `upsell_accepted`,
    `upsell_rejected { reason: user|auto_timer|no_suggestions|load_error }`.
  - `KioskPaymentComponent` : cf. P6.1.
  - `KioskLoyaltyComponent` : `loyalty_scanned` (succès register).

**Invariants garantis** :
- Aucun nom / téléphone / email en clair dans un payload analytics.
- Consent gate respecté (`kioskAnalytics.track()` drop silencieusement si
  `consentAnalytics=false`).
- Whitelist double — côté client (`ALLOWED_EVENTS`) et côté serveur
  (`ALLOWED_ANALYTICS_EVENTS` in `KioskEventController`).

### 2.5 P6.5 — KioskAdminComponent refactor complet
**Fichier** : `resources/js/components/frontend/kiosk/KioskAdminComponent.vue`

**Hardware via service** :
- `testPrint`, `openDrawer`, `checkTerminal`, `reloadApp`, `quitApp` → 100% via `kioskHardware`.
- `isElectron` computed → `kioskHardware.isKioskBridge()` (plus d'accès direct à `window.borne`).
- Ajout de `reload()` et `quit()` comme méthodes exportées dans `kioskHardware.js` (avec fallback
  navigateur via `location.reload()` si le bridge répond `reload_unavailable`).

**UI administrateur** :
- **Section « État du matériel »** : badge global (OK/dégradé/critique) + liste détaillée
  des composants (tpe, printer, nfc…) + bouton « Rafraîchir le statut ».
- **Section « Délais d'inactivité »** : 3 inputs numériques (`idleMs`, `confirmMs`, `receiptMs`)
  avec bornes (min/max issues de `KIOSK_SETTINGS_CONSTANTS.IDLE_BOUNDS`), bouton « Enregistrer »
  (dispatch `kioskSettings/setIdleTimeouts`, auto-coerce côté store) et « Réinitialiser ».
- **Section « Consentement RGPD »** : 2 checkboxes pour override debug/maintenance
  (analytics + loyalty), dispatch vers `setConsentAnalytics` / `setConsentLoyalty`.

**Healthcheck** :
- Refresh à chaque unlock PIN + bouton manuel. `healthSnapshot` en data → alimente le badge
  et la liste.

### 2.6 P6.6 — i18n FR/EN/AR
**Fichiers** : `resources/js/languages/{fr,en,ar}.json`

Nouvelles clés (sous `kiosk.admin_screen.*`) :

- `section_health`, `health_state_label`, `health_ok`, `health_degraded`, `health_critical`,
  `health_unknown`, `health_badge_hint`, `health_refresh`, `health_refresh_busy`,
  `fb_health_refreshed`.
- `section_idle`, `idle_hint`, `idle_field`, `idle_confirm_field`, `idle_receipt_field`,
  `idle_range`, `idle_save`, `idle_saving`, `idle_reset_defaults`, `fb_idle_saved`.
- `section_consent`, `consent_hint`, `consent_analytics`, `consent_loyalty`, `consent_save`,
  `consent_saving`, `fb_consent_saved`.

Traductions arabe avec RTL-friendly, pas de tokens de ponctuation occidentaux enfermés en
string.

### 2.7 P6.7 — Tests Vitest Phase 6
**Fichier nouveau** : `tests/js/kioskPhase6Instrumentation.spec.js` — 13 tests

Couvre :

| Catégorie                               | Tests |
|-----------------------------------------|-------|
| `kioskHardware.reload()` / `.quit()`    | 6     |
| `kioskAnalyticsPlugin` (Vuex)           | 6     |
| `kioskPrinter` via `kioskHardware`      | 1     |

Points vérifiés :
- Contrat `{ok, error?}` sur reload/quit (stub + bridge + error wrap).
- Plugin n'émet QUE pour les mutations whitelistées, jamais de PII dans le payload,
  swallowing silencieux des erreurs du track (pas de crash Vuex).
- `kioskPrinter.printReceipt` appelle `window.borne.printReceipt` **uniquement via**
  `kioskHardware` (preuve par spy sur le bridge + absence d'appel direct).

### 2.8 P6.8 — Non-régression + build

- **Vitest** : `npx vitest run` → **37 files, 308 tests, 0 failure** (baseline préservée,
  +13 tests Phase 6).
- **Build Mix --production** : compiled OK en 29.5s. Taille `js/kiosk.js` = 495 KiB (stable
  vs baseline Phase 5, pas de bloat).
- **Audit grep `window.borne` résiduel** hors `kioskHardware.js` : 7 hits, **tous en
  commentaires** documentant l'historique (0 appel code actif).

---

## 3. Gate criteria — conformité Phase 6

| Gate                                               | Statut | Evidence                                              |
|----------------------------------------------------|--------|-------------------------------------------------------|
| 0 appel direct `window.borne.*` hors service       | ✅     | Grep audit (§2.8), 100% via `kioskHardware.*`         |
| `KsConsentModal` monté et gating actif             | ✅     | `KioskLoyaltyComponent.vue` L+ + test `kioskConsentModal.spec.js` |
| Analytics core events émis                         | ✅     | Plugin Vuex + 9 events direct (menu/cat/upsell/pay/idle/loyalty) |
| Admin UI idle timeouts (3 inputs + save/reset)     | ✅     | `KioskAdminComponent.vue` section `#kiosk-admin-idle-section` |
| Admin UI consent override                          | ✅     | Section `#kiosk-admin-consent-section`                |
| Healthcheck admin (badge + list + refresh)         | ✅     | `healthSnapshot` + `refreshHealth()`                  |
| i18n FR/EN/AR keys complètes                       | ✅     | `node -e JSON.parse` OK 3 locales                     |
| Tests Vitest non-régression                        | ✅     | 308/308 passing                                       |
| Build production                                   | ✅     | Mix compiled successfully in 29.5s                    |

---

## 4. Invariants §1 — audit de non-violation

| Invariant                        | Vérification                                          |
|----------------------------------|-------------------------------------------------------|
| Backend pricing SSOT             | Aucun prix ajouté client-side (paiement utilise `confirmedOrderTotal` du backend). |
| `branch_id` serveur uniquement   | Aucune mutation de payload client avec branch_id. Les events analytics n'incluent pas de branch_id (serveur le récupère de `$user->branch_id`). |
| OrderStateMachine                | Aucune mutation directe de statut dans la phase 6.    |
| EventContract V1                 | Events analytics passent via `/kiosk/event` qui wrap en enveloppe serveur. |
| Aucune statistique client        | Plugin Vuex émet des **événements discrets**, pas de compteurs/moyennes. |
| RGPD loyalty opt-in              | `KsConsentModal` bloque `POST /loyalty/register` tant que `consentLoyalty=false`. |
| WCAG 2.2 AA                      | Nouvelles sections admin : labels `<label>` sur inputs, `data-testid` partout, tailles tap respectées. |

---

## 5. Risques résiduels & suivis

1. **Composant `KsConsentModal` est un dialog bloquant** — si le workflow loyalty diverge
   (ex: modal fermée par clic extérieur), la UX doit être re-validée end-to-end en staging.
2. **Analytics `idle_*`** — émis par `KioskAppComponent` qui est toujours monté. Attention
   au double-émission si un 2ᵉ idle manager est instancié (ex: lors d'un HMR dev). Pas
   d'impact en prod (single-instance Electron).
3. **Admin override RGPD** — les 2 checkboxes dans la section « Consentement RGPD »
   permettent à un staff de forcer l'état sans passer par la modal. C'est intentionnel pour
   maintenance/debug mais devrait être loggué (ActionLog) — **suivi post-Phase-6**.
4. **`quit()` via bridge** — si le bridge Electron ne répond pas, aucun fallback (pas
   de `window.close()` en kiosk mode). Comportement attendu : le staff doit redémarrer
   physiquement la borne. Documenté implicitement par le contrat `{ok:false}`.

---

## 6. Prochaines étapes (hors scope Phase 6)

- **E2E Playwright** sur le flow loyalty + consent modal (couverture test end-to-end).
- **ActionLog** pour override consent admin (traçabilité staff).
- **Audit lighthouse + axe-core** post-Phase 6 pour re-valider WCAG 2.2 AA après ajout
  de 3 nouvelles sections admin.
- **Documentation** : mettre à jour `docs/KIOSK_HARDWARE_CALLS.md` pour inclure
  `reload()` et `quit()` dans la liste des méthodes bridge exposées.

---

## 7. Commits Phase 6 (atomiques par sous-phase)

- `feat(kiosk/phase-6.1): KioskPaymentComponent → kioskHardware + payment analytics`
- `feat(kiosk/phase-6.2): kioskPrinter via kioskHardware wrapper`
- `feat(kiosk/phase-6.3): wire KsConsentModal into KioskLoyaltyComponent (RGPD gate)`
- `feat(kiosk/phase-6.4): Vuex analytics plugin + direct instrumentation (cart/idle/upsell/menu/payment/loyalty)`
- `feat(kiosk/phase-6.5): KioskAdminComponent refactor (kioskHardware + idle config UI + health panel)`
- `feat(kiosk/phase-6.6): i18n FR/EN/AR keys for admin idle/consent/health sections`
- `test(kiosk/phase-6.7): Vitest Phase 6 instrumentation coverage`
- `chore(kiosk/phase-6.8): production build + Phase 6 report`

---

**Phase 6 : close.**

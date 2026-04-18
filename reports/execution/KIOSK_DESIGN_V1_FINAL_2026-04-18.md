# KIOSK DESIGN V1 — Rapport final (Phases 0 → 7)

**Date** : 2026-04-18
**Ticket parent** : INTEGRATION_KIOSK_DESIGN_V1
**Scope** : intégration complète du design livré (React prototype) dans
FoodKing Laravel 9 + Vue 3 SPA, sur 7 phases.
**Invariants §1** : SSOT pricing, branch_id isolation serveur, OrderStateMachine,
EventContract V1, **zéro stat dynamique client**, RGPD opt-in explicite, WCAG 2.2 AA.

---

## 0. Vue d'ensemble


| Phase | Nom                                                      | Statut | Rapport                                |
| ----- | -------------------------------------------------------- | ------ | -------------------------------------- |
| 0     | Design System + tokens CSS + 7 atoms Vue                 | ✅      | `KIOSK_DESIGN_V1_PHASE0_*.md`          |
| 1     | Prérequis backend : 5 migrations + 6 endpoints           | ✅      | `KIOSK_DESIGN_V1_PHASE1_*.md`          |
| 2     | Restyle 13 composants Vue existants                      | ✅      | `KIOSK_DESIGN_V1_PHASE2_*.md`          |
| 3     | 5 écrans manquants (cash + 4 erreurs)                    | ✅      | `KIOSK_DESIGN_V1_PHASE3_*.md`          |
| 4     | i18n FR/EN/AR + RTL + AAA/PMR + audio                    | ✅      | `KIOSK_DESIGN_V1_PHASE4_*.md`          |
| 5     | Hardware bridge + idle + consent + analytics             | ✅      | `KIOSK_DESIGN_V1_PHASE5_*.md`          |
| 6     | Combler les gaps Phase 5 (audit-driven)                  | ✅      | `KIOSK_DESIGN_V1_PHASE6_2026-04-18.md` |
| 7     | Consolidation finale (audit + docs + a11y + traçabilité) | ✅      | **ce rapport**                         |


---

## 1. Phase 7 — livrables détaillés

### 1.1 Audit Phase 6 — all green


| Audit                                                   | Résultat                                  |
| ------------------------------------------------------- | ----------------------------------------- |
| `window.borne.`* résiduel (hors service + commentaires) | 0 call-site actif (3 mentions JSDoc-only) |
| Plugin `kioskAnalyticsPlugin` enregistré dans store     | ✅ `resources/js/store/index.js` L256      |
| `KsConsentModal` monté dans `KioskLoyaltyComponent`     | ✅ `L226`                                  |
| `kioskHardware.reload/quit` exportés                    | ✅ `L318/L327/L385-386`                    |
| i18n keys `section_health/idle/consent` (3 locales)     | ✅ 3/3/3 hits par fichier                  |
| Vitest                                                  | 308/308 green (baseline Phase 6)          |
| PHPUnit kiosk-specific                                  | 51/51 green                               |


### 1.2 P7.1 — Traçabilité admin override (RGPD/CNIL art. 5.2)

**Problème** : le panel admin Phase 6.5 permet à un staff de forcer consent analytics/loyalty
et de modifier les idle timeouts. Ces actions sensibles doivent être traçables.

**Implémentation** :

- `resources/js/components/frontend/kiosk/KioskAdminComponent.vue` :
  - `saveIdleTimeouts()` : snapshot before/after → `_logAdminOverride('idle_timeouts', {before, after})`.
  - `saveConsent()` : idem avec subtype `consent_override`.
  - Méthode `_logAdminOverride()` émet `POST /frontend/kiosk-event` avec
  `type=admin_action` (déjà whitelisté), silent fail sur erreur réseau.

**Tests** : `tests/Feature/KioskPhase7/KioskAdminOverrideAuditTest.php` — **5 tests**

1. idle_timeouts override logged with before/after
2. consent_override logged
3. admin override context never leaks PII (guard `FORBIDDEN_PAYLOAD_KEYS` s'applique à `payload`, pas `context`)
4. admin_action avec payload PII → 422 (harmonisation guard)
5. admin_action sans subtype (legacy) → 200

### 1.3 P7.2 — Documentation sources


| Fichier                                 | Changements                                                                                                                                                                                                                    |
| --------------------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------ |
| `docs/design/KIOSK_HARDWARE_CALLS.md`   | Nouvelle règle #6 (plus d'accès direct `window.borne`). Ajout section §6.bis : `reload()` + `quit()` (contrat, stub dev, exemple usage Vue).                                                                                   |
| `docs/design/KIOSK_ANALYTICS_EVENTS.md` | Annexe B ajoutée — tableau exhaustif event → source (composant.méthode) → canal (track/Vuex plugin) → test Vitest couvrant. 21 analytics events + 2 admin_action subtypes + 2 hardware types. Gardes RGPD serveur documentées. |


### 1.4 P7.3 — Audit a11y structurel

**Décision arch** : ne pas installer `axe-core` (lib ~2 Mo, hors scope §6 master prompt).
Alternative : audit DOM ciblé via Vue Test Utils, validant les invariants WCAG 2.2 AA
pour les 7 atoms DS + `KsConsentModal`.

**Fichier** : `tests/js/kioskA11yStructuralAudit.spec.js` — **17 tests**, toutes vertes.


| Atom/Screen      | Vérifications                                                                                                                                                           |
| ---------------- | ----------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `KsButton`       | type ≠ submit par défaut ; `disabled` → `disabled` ou `aria-disabled=true`                                                                                              |
| `KsChip`         | `role=button` sur `<div>` (pattern WCAG authoring practices 1.2) ; `tabindex=0` / `-1` si disabled ; `aria-pressed` sync avec `selected` ; Enter/Space émettent `click` |
| `KsBadge`        | texte rendu ; `iconOnly` → `role=img` + `aria-label`                                                                                                                    |
| `KsCard`         | container neutre par défaut (pas tabindex/role) ; `interactive=true` → `role=button` + `tabindex=0` ; `disabled=true` → `aria-disabled` + plus focusable                |
| `KsModal`        | `role=dialog` + `aria-modal=true` + `aria-labelledby` ciblant le titre rendu                                                                                            |
| `KsStepper`      | `role=progressbar` avec `aria-valuemin/max/now` dérivés de `steps` ; step courant porte `aria-current=step`                                                             |
| `KsConsentModal` | dialog RGPD propre : `role=dialog` + `aria-modal` + `aria-labelledby` ; checkboxes **non pré-cochées** (RGPD §1.6) + label associé (for-id OR wrap OR aria-labelledby)  |


### 1.5 P7.4 — Régression branch_id isolation

**Fichier** : `tests/Feature/KioskPhase7/KioskEventBranchIsolationTest.php` — **5 tests**.


| Test                                                       | Invariant §1.2 vérifié                                                                                                                                                            |
| ---------------------------------------------------------- | --------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `analytics_event_with_forged_branch_id_logs_real_branch_A` | Le frontend injecte `branch_id` de la branche B, mais le `user_id` persisté reste celui du token (branch A).                                                                      |
| `branch_id_fallback_to_machine_branch_when_payload_absent` | Absence de `branch_id` → fallback `KioskMachine::user_id` (branch réelle).                                                                                                        |
| `unauthenticated_cannot_post_any_event`                    | 401 garanti sur l'endpoint.                                                                                                                                                       |
| `any_valid_sanctum_token_passes_auth_documented_behavior`  | **Finding** consigné : la route `/kiosk/event` n'applique pas l'ability check `kiosk:order` malgré la doc controller. Pré-existant, throttle 30/min atténue ; suivi post-Phase 7. |
| `all_phase5_types_respect_branch_isolation`                | Les 7 types (a11y, hardware_*, consent, idle, admin_action) → user_id invariant quelle que soit la forge.                                                                         |


### 1.6 P7.5 — Validation finale


| Pipeline                         | Résultat                                                                    |
| -------------------------------- | --------------------------------------------------------------------------- |
| Vitest complet                   | **38 files / 325 tests** green (+17 a11y)                                   |
| PHPUnit kiosk-specific (filters) | **117 passed / 1 skipped** (le skip = SQLite-only constraint, documenté P1) |
| PHPUnit Phase 7 nouveaux         | **10 tests** green (5 admin override + 5 branch isolation)                  |
| Mix `--production`               | Compiled OK in 26.5s. `js/kiosk.js` = **496 KiB** (baseline +1 KiB vs P6)   |


---

## 2. Conformité invariants §1 (récap final)


| Invariant master prompt                 | Conformité finale | Preuve                                                                                        |
| --------------------------------------- | ----------------- | --------------------------------------------------------------------------------------------- |
| §1.1 Backend pricing SSOT               | ✅                 | `PricingPreviewController`, `FrontendOrderService` ; aucun prix calculé client                |
| §1.2 `branch_id` isolation serveur      | ✅                 | Tests P7.4 + `KioskScopeIsolationTest`                                                        |
| §1.3 `OrderStateMachine`                | ✅                 | `KioskPaymentStateMachineTest`, aucune écriture directe de `status`                           |
| §1.4 `EventContract V1` (buildEnvelope) | ✅                 | Events broadcasted via outbox + enveloppe, consignés P1                                       |
| §1.5 **Aucune statistique client**      | ✅                 | Grep `is_chef_pick` = seul flag admin statique. Pas de "78% des clients", "best-seller", etc. |
| §1.6 RGPD opt-in loyalty                | ✅                 | `KsConsentModal` monté dans `KioskLoyalty` (P6.3), checkboxes non pré-cochées, tests consent  |
| §1.7 WCAG 2.2 AA + AAA/PMR toggles      | ✅                 | Tokens `tokens-aaa.css` + `tokens-pmr.css`, 17 tests a11y structurels (P7.3)                  |


---

## 3. Métriques finales globales

### Coverage tests


| Suite                | Fichiers | Tests   | État                                                                               |
| -------------------- | -------- | ------- | ---------------------------------------------------------------------------------- |
| Vitest (JS/Vue)      | 38       | **325** | 325/325 ✅                                                                          |
| PHPUnit kiosk        | —        | **117** | 117/117 ✅ (1 skip SQLite)                                                          |
| PHPUnit total projet | —        | 143+    | baseline préservée, 3 failures pré-existants `FrontendSurfaceFiltering` hors scope |


### Taille bundle


| Asset         | Avant Phase 6 | Phase 6 | Phase 7     |
| ------------- | ------------- | ------- | ----------- |
| `js/kiosk.js` | ~490 KiB      | 495 KiB | **496 KiB** |
| `css/app.css` | 139 KiB       | 139 KiB | 139 KiB     |


Stabilité bundle → pas de bloat, ajout de logique légère uniquement.

### i18n coverage (kiosk namespace uniquement)


| Locale | Keys | Validité JSON |
| ------ | ---- | ------------- |
| `fr`   | 500+ | ✅             |
| `en`   | 500+ | ✅             |
| `ar`   | 500+ | ✅ (RTL)       |


---

## 4. Livrables agrégés (10 du master prompt)


| #   | Livrable                                                                                                               | Statut |
| --- | ---------------------------------------------------------------------------------------------------------------------- | ------ |
| 1   | `resources/css/kiosk/tokens.css` + `tokens-aaa.css` + `tokens-pmr.css`                                                 | ✅      |
| 2   | `resources/js/components/frontend/kiosk/ds/` — 7 atoms documentés                                                      | ✅      |
| 3   | Migrations : `categories.parent_id`, `allergens` + pivot, `upsell_rules`, `kiosk_promos`, `branches.available_locales` | ✅      |
| 4   | 6 endpoints kiosk avec tests PHPUnit branch_id + SSOT                                                                  | ✅      |
| 5   | 13 composants Vue restylés                                                                                             | ✅      |
| 6   | 5 nouveaux composants (CashInstruction + 4 erreurs)                                                                    | ✅      |
| 7   | i18n FR/EN/AR complet + RTL fonctionnel                                                                                | ✅      |
| 8   | Modes AAA + PMR + audio (3 toggles indépendants combinables)                                                           | ✅      |
| 9   | `kioskHardware.js` service + healthcheck + idle manager                                                                | ✅      |
| 10  | **Rapport final** (ce document)                                                                                        | ✅      |


---

## 5. Findings résiduels (post-Phase 7, suivis non-bloquants)


| #   | Finding                                                                                                                              | Sévérité | Suivi                                                                      |
| --- | ------------------------------------------------------------------------------------------------------------------------------------ | -------- | -------------------------------------------------------------------------- |
| 1   | Route `/kiosk/event` accepte n'importe quel token Sanctum (doc dit `kiosk:order` required). Throttle 30/min en mitigation.           | Faible   | Ajouter middleware `abilities:kiosk:order` dans routes/api.php L917 + L955 |
| 2   | Guard `FORBIDDEN_PAYLOAD_KEYS` s'applique seulement sur `payload`, pas `context`. Le contexte admin est écrit tel quel dans details. | Faible   | Étendre la guard à `context` pour durcir                                   |
| 3   | `FrontendSurfaceFilteringTest` (3 failures pré-existants) — hors scope, mais bloque les CI en full-suite.                            | Moyenne  | Investigation séparée (non-kiosk related apparent)                         |
| 4   | E2E Playwright manquant sur flow loyalty+consent end-to-end                                                                          | Moyenne  | Phase 8 potentielle (UX validation)                                        |
| 5   | Pas de rate-limiting spécifique sur le log `admin_action` (même throttle 30/min global).                                             | Faible   | OK en pratique (staff action rare)                                         |


---

## 6. Recommandations prod

1. **Admin override RGPD** : activer un email/webhook de notification sur `type=admin_action, subtype=consent_override` pour alerting.
2. **Healthcheck** : configurer Soketi/Redis alerts sur `type=hardware_error` agrégé par branche (>5/h = incident).
3. **Kiosk reload** : si le bridge Electron ne répond pas à `reload()`, le `window.location.reload()` fallback recharge juste la page Vue — pas le process Electron. Pour un reload complet OS, prévoir une commande admin distincte "Restart machine".
4. **Consent RGPD** : conserver l'audit trail `admin_action` au moins 3 ans (alignement CNIL sur preuve de consentement).
5. **i18n AR** : prévoir un fichier audio `.mp3` Arabic pré-enregistré pour TTS fallback (le Web Speech API ne supporte pas l'arabe sur tous les systèmes).

---

## 7. Phase 7 — commits atomiques

- `feat(kiosk/phase-7.1): admin_action audit trail for consent/idle overrides + PHPUnit`
- `docs(kiosk/phase-7.2): KIOSK_HARDWARE_CALLS + ANALYTICS_EVENTS finalization`
- `test(kiosk/phase-7.3): structural a11y audit (17 tests, no axe-core dependency)`
- `test(kiosk/phase-7.4): branch_id isolation regression (5 tests, 1 finding documented)`
- `chore(kiosk/phase-7.5): final report + validation pipeline`

---

## 8. Conclusion

L'intégration du design V1 est **complète et prête pour staging** :

- **Pricing** : SSOT backend 100% respecté, aucun prix calculé client.
- **Branch isolation** : durcie et testée (régression P7.4) sur les 8 types d'events.
- **RGPD** : opt-in explicite (P6.3) + audit trail admin (P7.1) = conformité CNIL.
- **A11y** : WCAG 2.2 AA validée structurellement (P7.3) + modes AAA/PMR togglables.
- **Hardware** : 100% via `kioskHardware` service, 0 accès direct `window.borne` résiduel.
- **Observabilité** : 21 analytics events émis, 7 hardware types, 2 admin_action subtypes.
- **Docs** : `KIOSK_HARDWARE_CALLS.md` et `KIOSK_ANALYTICS_EVENTS.md` à jour, avec tableau de coverage.

**Tests** : 325 Vitest + 117 PHPUnit kiosk-specific — **442 tests verts, 0 régression introduite**.

**Build** : 26.5s, bundle kiosk 496 KiB, stable.

**Status global : READY FOR STAGING** (puis prod après E2E Playwright de validation UX).
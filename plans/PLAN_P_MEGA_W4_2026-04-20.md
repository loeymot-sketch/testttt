# PLAN_P_MEGA_W4_2026-04-20 — i18n / RTL (P-MEGA-10 + P-MEGA-11)

**Cycle parent** : `PLAN_MEGA_FUNCTIONAL_CORRECTNESS_2026-04-20.md` Vague 4
**Date** : 2026-04-20
**Mode** : RUNNER_MODE single-session + auto-remediation
**Origine** : Vague 4 du chantier P-MEGA. W3 (allergens / filtre persistant) a livré nouvelles clés i18n consommées dans 5 langues — risque drift immédiat. RTL arabe non audité depuis intégration `kioskFilter` + `KsAllergenBadge`.

---

## TASK_ID

`P_MEGA_W4_I18N_RTL_2026-04-20`

---

## DECOUPAGE — 2 cycles séquentiels (W4.A → W4.B)

**Justification du split** :

1. **Dépendance qualité** : P-MEGA-11 (script audit i18n) est une **infrastructure de test réutilisable**. Une fois livré, il **valide** automatiquement P-MEGA-10 (qui peut introduire de nouvelles clés CSS-driven type `kiosk.rtl.skip_to_main`, `kiosk.lang.switch_aria`, etc.). Inverser l'ordre = perdre la garantie auto-régression.
2. **Surface technique disjointe** :
   - W4.A = Node script + Vitest + 1 rapport CSV. Zéro composant Vue. Zéro CSS.
   - W4.B = Audit lecture (HTML/CSS) + patches CSS additifs (logical properties) + ajustements Vue ciblés. Zéro logique métier.
   - Les deux peuvent partager un commit atomique séparé sans risque de coupling.
3. **Risque blast radius** : fusionner gonflerait le diff (~300+ LOC mêlant tooling + CSS) et brouillerait l'audit final. Séparation = audit indépendant possible par cycle.
4. **LOC séparées** : W4.A ~250 LOC (script + spec + report), W4.B ~200 LOC CSS/Vue + 1 spec Playwright optionnelle. Chaque cycle reste ≤ 400 LOC, dans la fenêtre routine acceptable.
5. **Routing** : les deux cycles sont **routine** (pas de pricing, pas d'auth, pas de schema, pas de symétrie OrderService, pas de branch_id, pas de dispatch). → `Composer` les deux.

---

## RUNNER_MODE / PRIMARY_MODEL / SUBAGENT

### Cycle W4.A — P-MEGA-11 (audit i18n tooling)

- **PRIMARY_MODEL** : `Composer` (routine)
- **SUBAGENT** : `foodking-routine-implementer`
- **Justification routing** : pure analyse statique + script Node + Vitest. Aucun contact avec : pricing, auth, schema, symétrie, branch_id, dispatch, frozen zones. Pas de CSS, pas de composant Vue. Aucun trigger de la table de routing → routine OK.
- **PLAN_FILE** : ce fichier (section W4.A)
- **REPORT_FILE** : `reports/execution/RUN_P_MEGA_W4_A_I18N_AUDIT_TOOL_2026-04-20.md`
- **GATE_FILE** : aucun anticipé

### Cycle W4.B — P-MEGA-10 (audit RTL + fix bornés)

- **PRIMARY_MODEL** : `Composer` (routine)
- **SUBAGENT** : `foodking-routine-implementer`
- **Justification routing** : audit visuel / CSS additif sur composants front kiosk. Modifications limitées à : `dir="rtl"` (déjà câblé via `i18n.js` + `KioskAppComponent.vue:305`), logical properties CSS (`padding-inline-*`, `margin-inline-*`), classes utilitaires `:dir(rtl)`, mirror SVG. Aucun changement de logique métier, aucun re-rendu de prix, aucun toucher backend. Si l'audit révèle un défaut profond (ex : composant qui calcule des positions JS hardcodées en `left:`) → **ESCALATION pré-déclarée → re-route W4.B en complex ou gate**.
- **PLAN_FILE** : ce fichier (section W4.B)
- **REPORT_FILE** : `reports/execution/RUN_P_MEGA_W4_B_RTL_AUDIT_FIX_2026-04-20.md`
- **GATE_FILE** : aucun anticipé (sauf déclenchement ESCALATION)

---

## SUBSYSTEMS_TOUCHED (read/write par cycle)

### W4.A — i18n audit tool

| Path | Intent | branch_id | dispatch |
|---|---|---|---|
| `tools/i18n/audit_locale_keys.mjs` (NEW) | write | n/a | n/a |
| `resources/js/languages/{fr,en,ar,de,bn}.json` | **read-only** | n/a | n/a |
| `resources/js/components/**/*.vue` | **read-only** (grep `$t('...')`) | n/a | n/a |
| `resources/views/**/*.blade.php` | **read-only** (grep `__('...')`, `@lang`) | n/a | n/a |
| `tests/js/i18nAuditTool.spec.js` (NEW) | write | n/a | n/a |
| `reports/i18n/missing_keys_per_locale_2026-04-20.csv` (NEW) | write | n/a | n/a |
| `reports/i18n/dead_keys_2026-04-20.csv` (NEW, bonus) | write | n/a | n/a |
| `reports/i18n/identical_fr_en_2026-04-20.csv` (NEW, bonus) | write | n/a | n/a |
| `package.json` | write (script entry only `npm run i18n:audit`) | n/a | n/a |

### W4.B — RTL audit + bounded fix

| Path | Intent | branch_id | dispatch |
|---|---|---|---|
| `resources/js/components/frontend/kiosk/KioskCartComponent.vue` | write (CSS only, additif) | n/a | n/a |
| `resources/js/components/frontend/kiosk/KioskWizardComponent.vue` | write (CSS only, additif) | n/a | n/a |
| `resources/js/components/frontend/kiosk/steps/*.vue` | write (CSS only, additif) | n/a | n/a |
| `resources/js/components/frontend/kiosk/KioskPaymentComponent.vue` | write (CSS only, additif) | n/a | n/a |
| `resources/js/components/frontend/kiosk/KioskOrderSummaryComponent.vue` | write (CSS only, additif) | n/a | n/a |
| `resources/js/components/frontend/kiosk/ds/*.vue` (DS atoms) | write (CSS only si requis) | n/a | n/a |
| `resources/css/kiosk-rtl.css` (NEW, optionnel — sinon scoped styles) | write | n/a | n/a |
| `resources/js/i18n.js` | **read-only** (déjà câblé) | n/a | n/a |
| Receipt admin/POS components | **read-only** (audit only — out of scope fix) | n/a | n/a |
| `tests/Browser/kioskRtl.spec.js` (NEW, OPTIONNEL Playwright) | write | n/a | n/a |
| `reports/i18n/rtl_audit_findings_2026-04-20.md` (NEW) | write | n/a | n/a |

---

## SUBSYSTEMS_OFF_LIMITS (les deux cycles)

- `app/**` (back PHP — aucun changement)
- `database/migrations/**`, `database/seeders/**`
- `routes/**`
- `app/Services/Pricing/**`, `app/Services/OrderService.php`, `app/Services/FrontendOrderService.php`
- `app/Models/OrderItem.php`, `app/Models/Order.php`
- Auth (`app/Http/Middleware/**`, `config/auth.php`)
- Frozen zones (cf. `docs/gates/`)
- `KioskEventController` (port K-6 réservé à P-MEGA-20)
- Logique de calcul prix/TVA côté front (interdit par invariant 1)
- Composants admin/POS hors lecture pour audit

---

## INVARIANTS_AT_RISK

1. **Invariant 1 (Backend Pricing SSOT)** — RTL ne doit JAMAIS modifier l'**ordre de rendu** des montants ni introduire un format/séparateur localisé qui changerait la valeur affichée. Toute mention prix reste un binding `{{ priceFromBackend }}`. Aucune `Number.toLocaleString` ajoutée pour "améliorer le RTL". → **AUDIT W4.B doit confirmer 0 modification du rendu numérique.**
2. **Invariant 6 (Frozen zones)** — vérifier au plan si un composant kiosk modifié figure dans `docs/gates/GATE_VERIFY_P0_FROZEN_*`. À ce stade : aucun connu pour `KioskCart/Wizard/Payment/OrderSummary/steps`. Si un fichier modifié W4.B se révèle frozen → STOP + gate brief.
3. **Invariant 5 (Symétrie OrderService)** — non concerné (zéro back).
4. **Invariant 3 (branch_id)** — non concerné (UI seulement).
5. **Invariant 4 (Dispatch after commit)** — non concerné.
6. **Invariant 2 (OrderStatus enum)** — non concerné.

---

## GATE_CONDITIONS

**Aucune anticipée** sur les deux cycles tels que scopés.

**Conditions qui DÉCLENCHENT une gate (pré-déclarées)** :

- **G1** : W4.B révèle un composant frozen-zone touché → gate brief obligatoire avant fix.
- **G2** : W4.A audit produit > 100 clés manquantes pour `en` (référence prod internationale) ou > 250 cumulé sur `de|ar|bn` → STOP, ne pas auto-traduire (cf. ESCALATION E1).
- **G3** : W4.B audit révèle qu'un composant rend un montant via JS calculé (et pas seulement bind serveur) → invariant 1 à risque, gate avant tout fix.
- **G4** : W4.B nécessite modification d'un composant POS / admin / KDS hors `kiosk/*` → hors scope, gate ou nouveau cycle.

---

## ESCALATIONS pré-déclarées (count = 4)

- **E1 (W4.A)** : si > 100 clés manquantes par locale → log `ESCALATION` dans REPORT_FILE, ne génère AUCUNE traduction, propose 3 options à l'humain :
  - (a) lancer un cycle dédié de traduction par locale (1 cycle = 1 langue, scope cap = 100 clés)
  - (b) accepter le drift et marquer les langues comme "incomplete" dans `i18n.js` (fallback `en` automatique)
  - (c) reduce scope : garder uniquement `fr`+`en`, retirer `de|ar|bn` du selector → impacte W4.B (RTL devient sans usage prod)
- **E2 (W4.B)** : si l'audit RTL identifie un bug bloquant (ex : bouton "valider commande" sort du viewport en `dir=rtl`) qui ne peut PAS être corrigé par CSS additif (refactor JS positionnel requis) → log `ESCALATION`, fournir reproduction + screenshot dans REPORT_FILE, proposer cycle dédié W4.B.bis avec routing **complex (GPT-5.4)**.
- **E3 (W4.B)** : si W4.A révèle des clés manquantes spécifiques à RTL (ex : nouvelles clés introduites par les patches CSS comme `kiosk.lang.toggle_aria`) → ne pas les ajouter dans 5 langues d'un coup ; déclarer un mini-finding et différer le complétion au cycle E1 ou à un cycle "i18n keys" séparé. Compléter au minimum `fr` + `en`.
- **E4 (transverse)** : si l'audit révèle qu'`i18n.js` `isRTL()` retourne `true` mais que certains composants ne réagissent pas (ex : composant rendu côté serveur Blade) → log `ESCALATION`, hors scope front-only de W4.B.

---

## Test strategy

### W4.A (P-MEGA-11)

- **Vitest obligatoire** : `tests/js/i18nAuditTool.spec.js` — 6 cas minimum :
  1. Détecte clé présente en `fr` et absente en `en` → reportée.
  2. Détecte clé inutilisée (présente dans json, jamais grep dans `.vue`/`.blade.php`) → reportée en CSV `dead_keys`.
  3. Détecte clé identique entre `fr` et `en` → reportée en CSV `identical`.
  4. Gestion clés imbriquées (dot notation `kiosk.cart.empty`).
  5. Gestion `$t('var.' + dynamic)` → marquée "dynamic, manual review" (pas de faux positif).
  6. Exit code 1 si clés manquantes en `en` (CI fail) ; exit 0 sinon (warning seulement pour `de|ar|bn`).
- **Pas de Playwright** pour W4.A (pure tooling).

### W4.B (P-MEGA-10)

- **Audit obligatoire (livrable hard)** : `reports/i18n/rtl_audit_findings_2026-04-20.md` listant par composant les findings (text-align hardcodé, padding-left/right, flex-direction, icon orientation, scrollbar, modal close button position).
- **Patches CSS additifs** : doivent passer la suite Vitest existante sans régression (cible **535/535** comme V14).
- **Playwright OPTIONNEL** : 1 spec `tests/Browser/kioskRtl.spec.js` qui :
  - charge kiosk en `?lang=ar`
  - assert `document.documentElement.dir === 'rtl'`
  - assert que le bouton primaire principal (validate cart) est visible dans le viewport
  - capture 4 screenshots (cart, wizard step 1, payment, order summary) pour revue humaine
  - **DÉCISION** : Playwright si infra Playwright déjà active dans testttt ; sinon **deferred** (mention dans REPORT_FILE) — pas de scope creep d'installer Playwright dans ce cycle.
- **Re-run W4.A** post W4.B : exécuter `npm run i18n:audit` à la fin de W4.B pour confirmer que P-MEGA-10 n'a PAS introduit de clé non traduite (méta-test).

---

## DoD précis par tâche

### W4.A — DoD (P-MEGA-11)

- [x] Script `tools/i18n/audit_locale_keys.mjs` créé, exécutable via `npm run i18n:audit`.
- [x] 3 CSV générés sous `reports/i18n/` : `missing_keys_per_locale_*.csv`, `dead_keys_*.csv`, `identical_fr_en_*.csv`.
- [x] 6/6 Vitest verts dans `tests/js/i18nAuditTool.spec.js`.
- [x] Suite Vitest existante reste verte (535/535).
- [x] `package.json` : 1 entrée `"i18n:audit"` (no other change).
- [x] Document W3 leftover : si W3 a introduit des clés non traduites, lister dans REPORT_FILE comme finding immédiat.
- [x] Si E1 déclenchée : ESCALATION dans REPORT_FILE, pas de traduction écrite.
- [x] REPORT_FILE conforme au template `auto-remediation.mdc`.

### W4.B — DoD (P-MEGA-10)

- [x] `reports/i18n/rtl_audit_findings_2026-04-20.md` complet (par composant : finding / reproduction / fix proposé / fix appliqué|deferred).
- [x] Patches CSS additifs sur composants kiosk identifiés. Les patches utilisent **logical properties** (`padding-inline-*`, `margin-inline-*`, `inset-inline-*`) ou sélecteur `:dir(rtl)` — aucune duplication de styles flippés.
- [x] `dir="rtl"` confirmé propagé via `KioskAppComponent.vue:305` (déjà existant — audit only confirme le câblage end-to-end).
- [x] Mirror SVG flèches "back" (transform scale-x ou nouveau set d'icons) sur composants kiosk modifiés uniquement.
- [x] Suite Vitest reste verte (535/535).
- [x] Re-run `npm run i18n:audit` (W4.A) post-fix : 0 nouvelle clé manquante introduite par W4.B.
- [x] Playwright spec livrée OU deferred (justifié dans REPORT_FILE).
- [x] Aucun composant pricing front modifié ; aucun rendu numérique altéré (assertion explicite dans audit).
- [x] REPORT_FILE conforme au template.

---

## Estimation LOC (par cycle, séparée impl/tests)

### W4.A
- Script `audit_locale_keys.mjs` : ~150 LOC
- Spec Vitest : ~80 LOC
- `package.json` : 1 ligne
- Reports CSV : générés (LOC = 0 humaines)
- **Total impl : ~150 / tests : ~80 / config : ~1 → ~231 LOC**

### W4.B
- Audit MD livrable : ~80 lignes (texte humain)
- Patches CSS scoped sur 6-10 composants : ~150 LOC CSS
- 1 helper composable `useDir.js` (optionnel) : ~20 LOC si extrait
- Playwright spec (si livré) : ~80 LOC
- **Total impl : ~170 / tests : ~80 / audit doc : ~80 → ~330 LOC**

**Total cumulé W4 : ~560 LOC** — dans la fenêtre routine 2 cycles.

---

## Risques principaux (résumé pour output)

1. **R1 — Drift de clés massif** (W4.A) : si W3 + cycles antérieurs ont accumulé des clés non traduites en `de|ar|bn`, le rapport peut afficher des centaines d'items. Mitigé par E1.
2. **R2 — RTL profond requis** (W4.B) : composants peuvent avoir des `style="left: 12px"` inline ou JS `getBoundingClientRect` qui résiste aux logical properties. Mitigé par E2 (gate vers complex).
3. **R3 — Risque pricing display invariant 1** (W4.B) : tentation d'ajouter `toLocaleString` ou inversion de format prix pour ar. Mitigé par G3 + audit explicite.

---

## Ordre d'exécution

1. **W4.A** d'abord (foodking-routine-implementer) → livre le tool + rapport diagnostic.
2. Lecture du REPORT_FILE W4.A par planner-orchestrator → décision (close W4.A + autoriser W4.B, ou déclencher E1).
3. **W4.B** ensuite (foodking-routine-implementer) → audit + patches CSS bornés.
4. **Post-W4.B** : re-run W4.A (méta-test) → audit final orchestrator → close cycle global.

---

## ACTIVE_CYCLE update prévu

À l'ouverture de W4.A : mettre à jour `.cursor/ACTIVE_CYCLE.md` avec :
- TASK_ID = `P_MEGA_W4_A_I18N_AUDIT_TOOL_2026-04-20`
- PHASE = EXECUTE pending delegation
- PRIMARY_MODEL = Composer
- PLAN_FILE = ce fichier
- REPORT_FILE = `reports/execution/RUN_P_MEGA_W4_A_I18N_AUDIT_TOOL_2026-04-20.md`

À l'ouverture de W4.B : update analogue avec ID `P_MEGA_W4_B_RTL_AUDIT_FIX_2026-04-20`.

---

## Manifeste

> P-MEGA-11 = **infrastructure de qualité réutilisable** : c'est un détecteur de drift que toutes les vagues suivantes utiliseront. P-MEGA-10 = **audit visuel borné** avec fix CSS additifs uniquement, derrière le détecteur. La séquence W4.A → W4.B garantit qu'on ne peut pas fermer le cycle sans avoir prouvé que les patches RTL n'ont pas introduit de nouvelle clé non traduite. Tout dépassement de scope (refactor JS positionnel, traduction de masse, modification d'un rendu prix) déclenche escalation immédiate vers humain ou nouveau cycle dédié.

# PLAN_P_MEGA_W7_2026-04-20 — Resilience kiosk + Hardware fallback + Branch theming (P-MEGA-17 + P-MEGA-18 + P-MEGA-19)

**Cycle parent** : `plans/PLAN_MEGA_FUNCTIONAL_CORRECTNESS_2026-04-20.md` Vague 7
**Date** : 2026-04-20
**Mode** : RUNNER_MODE single-session
**Auto-remediation** : **conditionnelle** (ACTIVÉE pour W7.A et W7.B sauf basculement critical zone détecté à l'audit ; **DÉSACTIVÉE** pour W7.C)
**HEAD baseline** : commit récent post-W6-REM (à confirmer `git log -1` à l'ouverture)
**Vitest baseline** : 616/616 (post W6 REM)

**Précédents immédiats** :
- W6 + W6 REM closed PASSED (a11y WCAG AA + perf lazy chunks)
- W5 closed PASSED — **3 GATES OUVERTES toujours pendantes** (P-MEGA-12 TVA / P-MEGA-13 TPE / P-MEGA-14 NF525 receipt) → contrainte transverse W7 : **ne PAS toucher** les composants gated W5
- V14 worktree (POS) en cours, modifs non commités à respecter (cf. `git status`)

---

## TASK_ID

`P_MEGA_W7_RESILIENCE_HARDWARE_BRANCH_2026-04-20`

---

## STRATÉGIE GÉNÉRALE — pourquoi 3 sous-cycles avec parallélisation partielle

| Sous-cycle | Sujet | Critical zone potentielle | Auto-remediation |
|---|---|---|---|
| **W7.A** | P-MEGA-17 offline queue (IDB + jitter + listener + conflict UX) | OUI conditionnel : si payload diffère de `FrontendOrderService::pay` ou si dispatch hors `afterCommit` → HARD GATE | **ACTIVÉE** sauf bascule détectée à l'audit A.1 (alors STOP) |
| **W7.B** | P-MEGA-18 hardware fallback (printer / TPE / scanner / buzzer) | OUI conditionnel : si fallback TPE altère séquence paiement / NF525 → HARD GATE | **ACTIVÉE** sauf bascule détectée à l'audit B.1 (alors STOP) |
| **W7.C** | P-MEGA-19 branch theming (logo, couleurs, idle video) | **OUI confirmé** : schema migration `branches.theme_*` + HUMAN_GATE business pré-déclaré par plan source | **DÉSACTIVÉE** — AUDIT readonly + GATE_BRIEF only, **0 LOC code prod** |

**Principe orchestration** :
- W7.A et W7.B partagent runtime kiosk (`KioskToastComponent`, `kioskHardware.js`, error states, retry logic) → **séquentiel obligatoire** (W7.A complet → commit → W7.B) pour éviter merge conflicts garantis sur fichiers communs.
- W7.C est readonly (audit + brief) avec scope distinct (DB schema + asset pipeline + CSS variables) → **peut démarrer en parallèle** avec W7.A (aucun risque conflit fichiers).

**Halt humain** déclenché sur :
1. Touche d'un fichier listé `SUBSYSTEMS_OFF_LIMITS` (gates W5 + critical zones routing)
2. Régression Vitest scope-pertinent (chute < 615 verts hors le 1 untracked posNormalizeIds connu)
3. Compteur d'essais ≥ 3 par bug_signature (règle MAX 3 `auto-remediation.mdc`)
4. Audit A.1 ou B.1 conclut "fix nécessite modification critical zone" → bascule HARD GATE
5. ESCALATION pré-déclarée déclenchée

---

## DECOUPAGE — 3 sous-cycles, séquence W7.A → W7.B + W7.C en parallèle de W7.A

```
        ┌─── W7.A (P-MEGA-17 offline queue)  [séquentiel]
        │       A.1 audit → A.2 EXECUTE → A.3 verify
START ──┤
        └─── W7.C (P-MEGA-19 branch theming) [parallèle, readonly]
                C.1 audit → C.2 GATE_BRIEF (no execute)

         puis (W7.A commité) ──► W7.B (P-MEGA-18 hardware fallback)
                                     B.1 audit → B.2 EXECUTE → B.3 verify
```

### Justification choix parallélisation W7.C ‖ W7.A

1. **Scope fichiers disjoint** : W7.A touche `helpers/kioskOfflineQueue.js`, store, KioskToastComponent ; W7.C est readonly (audit DB schema + admin form + CSS variables existantes). Aucun overlap.
2. **W7.C est purement audit + GATE_BRIEF** : pas de write code, donc 0 risque conflit même théorique.
3. **Gain wall-clock** : W7.C livre le brief business en parallèle → humain peut décider P-MEGA-19 pendant que W7.A est en cours d'EXECUTE.

### Justification choix séquentiel W7.A → W7.B

1. **Conflit fichiers prévisible** : `KioskToastComponent.vue` est touché par W7.A (toast conflit item dispo) ET W7.B (toast hardware fallback). `kioskOfflineQueue.js` peut interagir avec `kioskHardware.js` (queue → tentative print → fallback). Parallèle = merge conflicts garantis sur ≥3 fichiers.
2. **Dépendance fonctionnelle** : si W7.A introduit le listener `ItemAvailabilityChanged` côté front, W7.B (printer fallback offline) peut s'appuyer sur la même infrastructure de retry/jitter/queue.
3. **Audit B.1 informé par A.3** : la cartographie hardware-status faite en B.1 doit savoir si l'offline queue de A.2 est déjà en place pour décider du couplage `printer KO → queue replay async vs fallback synchrone`.

### 8 phases au total

| Phase | Nom court | Type | Bloque |
|---|---|---|---|
| **A.1** | Audit P-MEGA-17 + cartographie delta T14a/b → T14c + scope routine vs complex | READ-ONLY | rien |
| **A.2** | EXECUTE offline queue v2 (IDB + jitter + listener + conflict UX) | WRITE code | A.1 |
| **A.3** | Verify A.2 200% (IDB mock, race conditions, retry, listener) | READ-ONLY | A.2 |
| **C.1** | Audit P-MEGA-19 branch theming (DB schema + assets + CSS variables) | READ-ONLY | rien (parallèle A.1) |
| **C.2** | GATE_BRIEF P-MEGA-19 (HUMAN_GATE business + schema) | READ-ONLY (Claude write brief) | C.1 |
| **B.1** | Audit P-MEGA-18 + cartographie 4 hardware × 4 scenarios + classification routine/complex | READ-ONLY | A.3 commit OK |
| **B.2** | EXECUTE hardware fallback (selon classification B.1) | WRITE code | B.1 |
| **B.3** | Verify B.2 200% (mocks hardware, fallback flows, NF525 non altéré) | READ-ONLY | B.2 |

---

## RUNNER_MODE / PRIMARY_MODEL / SUBAGENT — par phase

### Phase A.1 — Audit P-MEGA-17 offline queue
- **PRIMARY_MODEL** : Claude (orchestration) → délégué `explore` very thorough
- **SUBAGENT** : `explore` (thoroughness "very thorough")
- **Justification routing** : pure lecture statique. Inventaire `kioskOfflineQueue.js` existant, store `kioskCart`, `eventContract.js`, `app/Events/ItemAvailabilityChanged.php` + 3 listeners backend, `bootstrap.js` (présence Echo/Pusher), `webpack.mix.js` (broadcast lib). Détecter si payload offline = payload `FrontendOrderService::pay` strict → bascule HARD GATE si divergence prévue.
- **REPORT_FILE** : `reports/execution/AUDIT_P_MEGA_17_OFFLINE_QUEUE_BASELINE_2026-04-20.md`
- **LOC report** : ~220 lignes
- **Output requis** :
  - matrice T14a/b livré vs T14c attendu : storage layer, backoff strategy, listener, conflict UX
  - inventaire dépendances Pusher/Echo (présent ? câblé ? channel naming ?)
  - décision routing A.2 : **complex obligatoire** (IDB + race + listener) sauf preuve qu'un sous-set est purement helper utilitaire
  - liste fichiers candidats avec read/write intent
  - **DÉCISION CRITICAL ZONE** : si audit révèle que A.2 doit éditer `FrontendOrderService.php`, `OrderService.php`, ou modifier dispatch → **STOP, GATE_BRIEF immédiat (E_A1_FISCAL)**

### Phase A.2 — EXECUTE offline queue v2
- **PRIMARY_MODEL** : GPT-5.4 (`foodking-complex-implementer`)
- **SUBAGENT** : `foodking-complex-implementer`
- **Justification routing** : IndexedDB persistence layer + WebSocket listener (si Echo câblé) ou polling fallback + race conditions queue↔online + conflict resolution UX modal = catégorie "non-trivial algorithms + API contracts" → GPT-5.4 selon `routing.md`. Composer interdit pour cette zone (race conditions, sérialisation orders complexes).
- **CODE_FILES (autorisés — à confirmer A.1)** :
  - `resources/js/helpers/kioskOfflineQueue.js` (write — refacto vers IDB)
  - `resources/js/services/kioskOfflineQueueIDB.js` (NEW — wrapper IDB)
  - `resources/js/services/kioskAvailabilityListener.js` (NEW — listener Pusher OU polling)
  - `resources/js/components/frontend/kiosk/KioskOfflineConflictModal.vue` (NEW — UI conflict)
  - `resources/js/store/modules/kioskCart.js` (write minimal — invalidation snapshot)
  - `resources/js/components/frontend/kiosk/KioskAppComponent.vue` (write minimal — mount listener)
  - `resources/js/components/frontend/kiosk/KioskToastComponent.vue` (write minimal — toast conflit)
  - `resources/js/languages/{ar,en,fr,de,bn}.json` (write — clés conflit/queue/dispo)
- **TEST_FILES (nouveaux)** :
  - `tests/js/kioskOfflineQueueIDB.spec.js` (mock IDB via fake-indexeddb, scénarios persist/restore/quota)
  - `tests/js/kioskOfflineQueueJitter.spec.js` (backoff exponentiel + randomization, MAX retry)
  - `tests/js/kioskAvailabilityListener.spec.js` (mock Echo/Pusher OR polling, invalidation snapshot)
  - `tests/js/kioskOfflineConflictModal.spec.js` (UI conflict resolution, replace/cancel)
- **DEPS dev** : ajout `fake-indexeddb` en devDependency (mock IDB pour Vitest jsdom). **ESCALATION E1 si refusé.**
- **REPORT_FILE** : `reports/execution/RUN_P_MEGA_17_OFFLINE_QUEUE_EXECUTE_2026-04-20.md`
- **LOC code estimées** : 350-450 (helpers/services ~200, components ~80, i18n ~30, tests ~120)

### Phase A.3 — Verify A.2 200%
- **PRIMARY_MODEL** : Claude → délégué `explore` very thorough
- **SUBAGENT** : `explore` (thoroughness "very thorough" — IDB + race conditions justifient un audit fort)
- **Justification routing** : audit indépendant du diff A.2 + check invariants `OrderService::pay` symétrie kiosk/POS + check `branch_id` propagation post-replay + check pas de `dispatch(...)` hors `afterCommit` introduit.
- **REPORT_FILE** : `reports/execution/VERIFY_P_MEGA_17_OFFLINE_QUEUE_200_2026-04-20.md`
- **LOC report** : ~150 lignes
- **DoD** : voir §DoD A.3

### Phase C.1 — Audit P-MEGA-19 branch theming
- **PRIMARY_MODEL** : Claude → délégué `explore` very thorough
- **SUBAGENT** : `explore` (thoroughness "very thorough")
- **Justification routing** : lecture statique `app/Models/Branch.php` + migrations existantes branches + `resources/css/kiosk-wizard.css` + design tokens kiosk + admin BranchController + flux upload assets existant (storage public ? S3 ?).
- **REPORT_FILE** : `reports/execution/AUDIT_P_MEGA_19_BRANCH_THEMING_BASELINE_2026-04-20.md`
- **LOC report** : ~200 lignes
- **Output requis** :
  - état schéma `branches` actuel (colonnes existantes : `name`, `address`, `available_locales`, `zone`, etc. ; **PAS** de `theme_*`/`logo_path`/`idle_video_path` confirmé baseline)
  - inventaire CSS variables kiosk actuelles (tokens design system) + faisabilité override `:root` vs `[data-branch-id]`
  - flux upload assets existant (storage driver, taille max, validation)
  - délimitation : qu'est-ce qui pourrait être "routine pure" (ajout colonnes via migration GPT-5.4 + admin form Composer + CSS variables) si business confirme
  - **questions précises** à poser au humain dans GATE_BRIEF (assets attendus, formats, fallback, S3 vs local)

### Phase C.2 — GATE_BRIEF P-MEGA-19
- **PRIMARY_MODEL** : Claude (orchestrateur) — pas de subagent
- **OUTPUT** : `docs/gates/GATE_P_MEGA_19_BRANCH_THEMING_2026-04-20.md` selon format `human-gates.mdc`
- **LOC report** : ~120 lignes
- **Contenu obligatoire** : Trigger / Affected Subsystems / Invariants at Risk / Decision Required / 3 Options + Cancel / Approval block vide
- **Pas d'EXECUTE prod en W7.C** — implementation ne démarre qu'après décision humaine en cycle séparé

### Phase B.1 — Audit P-MEGA-18 hardware fallback
- **PRIMARY_MODEL** : Claude → délégué `explore` very thorough
- **SUBAGENT** : `explore` (thoroughness "very thorough")
- **Justification routing** : lecture statique `resources/js/services/kioskHardware.js` + `resources/js/config/kioskHardware.js` + composants kiosk consommateurs (`KioskPaymentComponent` GATED, `KioskOrderSummaryComponent` GATED, autres flows). Cartographier 4 périphériques (printer / TPE / camera scanner / buzzer) × 4 scénarios (offline / timeout / KO / absent). Identifier ce qui touche fiscal (TPE → BREACH si modifié sans gate W5).
- **REPORT_FILE** : `reports/execution/AUDIT_P_MEGA_18_HARDWARE_BASELINE_2026-04-20.md`
- **LOC report** : ~220 lignes
- **Output requis** :
  - matrice 4×4 (16 cas) avec état actuel (déjà géré ? UX dégradé existant ? non géré ?)
  - **classification par scénario** : ROUTINE (UI fallback message + détection status) vs COMPLEX (modification flux retry/payment) vs **OFF-LIMITS** (toute modification séquence TPE/payment réelle → BREACH gate W5)
  - décision routing B.2 : par défaut routine, bascule complex si scénario classé COMPLEX, **STOP gate brief si OFF-LIMITS détecté nécessaire** (E_B1_FISCAL)

### Phase B.2 — EXECUTE hardware fallback
- **PRIMARY_MODEL** : par défaut Composer (`foodking-routine-implementer`) ; **conditional escalation** GPT-5.4 si B.1 classifie ≥1 scénario COMPLEX
- **SUBAGENT** : `foodking-routine-implementer` OU `foodking-complex-implementer` selon décision B.1 documentée
- **Justification routing** : détection hardware status + UI fallback messages + toast notification = scope routine. Bascule complex uniquement si modification flux retry kiosk (sans toucher payment fiscal). **TPE séquence fiscale = OFF-LIMITS absolu** (gate W5 P-MEGA-13).
- **CODE_FILES (autorisés — à confirmer B.1)** :
  - `resources/js/services/kioskHardware.js` (write — détection + fallback dispatcher)
  - `resources/js/config/kioskHardware.js` (write — seuils timeout, fallback policies)
  - `resources/js/components/frontend/kiosk/KioskHardwareFallbackBanner.vue` (NEW — bandeau dégradé)
  - `resources/js/components/frontend/kiosk/KioskBarcodeManualInput.vue` (NEW — saisie manuelle si scanner KO)
  - `resources/js/components/frontend/kiosk/KioskToastComponent.vue` (write minimal — toast hardware)
  - `resources/js/languages/{ar,en,fr,de,bn}.json` (write — messages dégradés)
- **TEST_FILES (nouveaux)** :
  - `tests/js/kioskHardwareFallback.spec.js` (mock 4 périphériques × 4 scenarios)
  - `tests/js/kioskBarcodeManualInput.spec.js` (UI saisie manuelle)
- **REPORT_FILE** : `reports/execution/RUN_P_MEGA_18_HARDWARE_EXECUTE_2026-04-20.md`
- **LOC code estimées** : 200-280 (services ~80, components ~100, i18n ~20, tests ~80)

### Phase B.3 — Verify B.2 200%
- **PRIMARY_MODEL** : Claude → délégué `explore` medium
- **SUBAGENT** : `explore` (thoroughness "medium" — focus diff B.2 + check NF525 invariant non touché)
- **Justification routing** : audit indépendant du diff B.2. Vérification que séquence TPE/payment/Z report n'est pas altérée + 0 fichier gated W5 modifié.
- **REPORT_FILE** : `reports/execution/VERIFY_P_MEGA_18_HARDWARE_200_2026-04-20.md`
- **LOC report** : ~130 lignes

---

## SUBSYSTEMS_TOUCHED — par sous-cycle

### W7.A (offline queue) — fichiers autorisés en WRITE

| Path | Phase | Intent | branch_id ? | dispatch ? |
|---|---|---|---|---|
| `resources/js/helpers/kioskOfflineQueue.js` | A.2 | write (refacto IDB) | propage `branch_id` payload existant ; **pas de modif logique branch_id** | non |
| `resources/js/services/kioskOfflineQueueIDB.js` | A.2 | write NEW (wrapper IDB) | aucun | non |
| `resources/js/services/kioskAvailabilityListener.js` | A.2 | write NEW (listener ou polling) | écoute event server-side, ne modifie pas filter | non |
| `resources/js/components/frontend/kiosk/KioskOfflineConflictModal.vue` | A.2 | write NEW | aucun | non |
| `resources/js/components/frontend/kiosk/KioskToastComponent.vue` | A.2 | write minimal | aucun | non |
| `resources/js/components/frontend/kiosk/KioskAppComponent.vue` | A.2 | write minimal (mount listener) | aucun | non |
| `resources/js/store/modules/kioskCart.js` | A.2 | write minimal (invalidation snapshot) | conserve filtre existant | non |
| `resources/js/languages/{ar,en,fr,de,bn}.json` | A.2 | write (clés i18n) | aucun | non |
| `tests/js/kioskOfflineQueueIDB.spec.js` | A.2 | write NEW | — | — |
| `tests/js/kioskOfflineQueueJitter.spec.js` | A.2 | write NEW | — | — |
| `tests/js/kioskAvailabilityListener.spec.js` | A.2 | write NEW | — | — |
| `tests/js/kioskOfflineConflictModal.spec.js` | A.2 | write NEW | — | — |
| `package.json` + `package-lock.json` | A.2 | write (devDep `fake-indexeddb`) | — | — |
| `bootstrap.js` ou `resources/js/bootstrap.js` | A.2 | **conditional write** si Echo doit être ajouté → **STOP gate brief** si non câblé (E2) | — | — |

### W7.B (hardware fallback) — fichiers autorisés en WRITE

| Path | Phase | Intent | Touche fiscal ? |
|---|---|---|---|
| `resources/js/services/kioskHardware.js` | B.2 | write (détection + dispatcher fallback) | NON (status detection only) |
| `resources/js/config/kioskHardware.js` | B.2 | write (seuils timeout, policies) | NON |
| `resources/js/components/frontend/kiosk/KioskHardwareFallbackBanner.vue` | B.2 | write NEW | NON |
| `resources/js/components/frontend/kiosk/KioskBarcodeManualInput.vue` | B.2 | write NEW | NON |
| `resources/js/components/frontend/kiosk/KioskToastComponent.vue` | B.2 | write minimal (toast hardware) | NON |
| `resources/js/languages/{ar,en,fr,de,bn}.json` | B.2 | write (messages dégradés) | NON |
| `tests/js/kioskHardwareFallback.spec.js` | B.2 | write NEW | — |
| `tests/js/kioskBarcodeManualInput.spec.js` | B.2 | write NEW | — |

### W7.C (branch theming) — **AUCUN fichier en WRITE prod**

Phase C.1 + C.2 = readonly (audit + GATE_BRIEF). Seuls writes autorisés :
- `reports/execution/AUDIT_P_MEGA_19_BRANCH_THEMING_BASELINE_2026-04-20.md` (write)
- `docs/gates/GATE_P_MEGA_19_BRANCH_THEMING_2026-04-20.md` (write)

---

## SUBSYSTEMS_OFF_LIMITS (toutes phases)

### Critical zones `auto-remediation.mdc` (toute touche logique = HALT immédiat)

- `database/migrations/**` — **OFF-LIMITS W7.A + W7.B** (W7.C uniquement audit ce qui manquerait)
- `app/Http/Middleware/Auth*`, `routes/auth*`
- `app/Services/OrderService.php`, `app/Services/FrontendOrderService.php` — **TOTAL OFF LIMITS** (W7 = front kiosk only)
- `app/Services/PaymentService.php`, `app/Services/PaymentManagerService.php` — **TOTAL OFF LIMITS**
- `app/Services/Pricing/**`
- Tout `dispatch(...)` ajouté hors `afterCommit`
- Logique de prix côté frontend (interdiction absolue)
- `branch_id` filtering logic (consommation OK, modification interdite)

### Zones gated W5 (3 GATES OUVERTES — interdiction d'éditer même cosmétique)

- `resources/js/components/frontend/kiosk/KioskPaymentComponent.vue` — gated P-MEGA-13
- `resources/js/components/frontend/kiosk/KioskOrderSummaryComponent.vue` — gated P-MEGA-14
- `resources/js/components/frontend/kiosk/KioskConfirmationComponent.vue` — gated W5
- `app/Http/Resources/OrderDetailsResource.php` — gated P-MEGA-14

### Zones V14 worktree (ne pas écraser modifs en cours non commitées)

- `resources/js/components/admin/pos/**` — **OFF-LIMITS W7** (worktree V14 actif, cf. git status initial)
- `resources/js/store/modules/posCart.js`, `posParked.js` — OFF-LIMITS
- `resources/js/helpers/posBarcode.js`, `posNormalizeIds.js` — OFF-LIMITS
- `app/Http/Controllers/Admin/Pos/**` — OFF-LIMITS
- `app/Models/PosParkedOrder.php`, `app/Services/PosParkedOrderService.php` — OFF-LIMITS

### Zones admin (out of scope kiosk)

- `app/Http/Controllers/Admin/**` (sauf audit readonly C.1 sur BranchController) — OFF-LIMITS write

---

## INVARIANTS_AT_RISK

### W7.A (offline queue)

1. **`OrderService::pay()` symétrie kiosk/POS** : la queue offline doit submettre un payload **strictement identique** à celui qu'un kiosk online enverrait. Toute remap/transformation = BREACH symétrie. Mitigation : A.1 audit confirme structure payload conservée bit-à-bit.
2. **`dispatch-after-commit`** : si A.2 introduit un dispatch côté front (ex : `OfflineOrderReplayed`), il **ne doit pas** affecter dispatch backend — front front-only. Si A.2 trigger backend dispatch via API, le backend (déjà testé) garantit afterCommit.
3. **`branch_id` propagation post-replay** : lorsque la queue replay une commande, le `branch_id` du payload original doit être préservé (pas de réinjection contextuelle qui pourrait pointer vers une autre branche si l'opérateur kiosk a switché).
4. **NF525 séquence fiscale** : l'offline queue ne doit JAMAIS générer un numéro fiscal côté front. Le numéro est attribué backend post-pay. Mitigation : A.1 audit confirme aucune logique de numérotation côté queue.
5. **Idempotency** : commande `replayed` ne doit pas créer doublon backend. Mitigation : A.1 vérifie usage `idempotency_key` (déjà en place backend selon migration `add_fks_to_item_branch_availability`).

### W7.B (hardware fallback)

1. **NF525 séquence fiscale** : fallback printer KO **ne doit pas** affecter génération Z report ni numérotation. Le fallback = re-render écran + email après-coup, jamais bypass de la séquence fiscale. Mitigation : B.1 audit confirme délimitation strict UI fallback ≠ fiscal flow.
2. **TPE timeout idempotency** : un fallback TPE = "réessayer avec idempotency_key existant" → modifier ce flux = BREACH gate W5 (P-MEGA-13). Mitigation : B.1 classifie tout scénario TPE comme **OFF-LIMITS** par défaut, sauf décision humaine séparée.
3. **Pas de payment fallback créant commande sans confirmation TPE réelle** : INVARIANT absolu. Mitigation : B.2 ne touche aucun fichier payment ; B.3 vérifie diff vide sur `app/Services/Payment*`.
4. **Hardware status read-only** : la détection de status (printer offline, TPE timeout) doit être pure observation ; aucune modification d'état périphérique côté front. Mitigation : B.1 vérifie API `kioskHardware.js` actuel = read-only.

### W7.C (branch theming)

1. **Aucun payload prix dans le theme** : le branding est visuel pur. Toute idée d'ajouter `branches.theme_price_modifier` ou similaire = BREACH pricing SSOT (P-MEGA-06/07 gated). Mitigation : C.2 GATE_BRIEF interdit explicitement toute logique pricing dans theming.
2. **Isolation branche stricte** : le theme branche A ne doit pas leak sur kiosk branche B. Mitigation : C.1 audit confirme `KioskContextResource` (à créer) doit scoper par `branch_id` authentifié.
3. **Fallback design system** : si `theme_logo_url` vide, kiosk doit fallback sur logo générique (jamais broken image). Mitigation : C.2 GATE_BRIEF requiert décision policy fallback.
4. **Pas de schema migration sans HUMAN_GATE écrit** : `database/migrations/**` est hard gate. Mitigation : aucune migration en W7.C, brief uniquement.

---

## GATE_CONDITIONS

### Hard gates pré-déclarées : **1 confirmé + 2 conditionnels**

| ID | Sous-cycle | Trigger | Status |
|---|---|---|---|
| **GATE_P_MEGA_19_BRANCH_THEMING** | W7.C | Confirmation business multi-branch white label + schema migration `branches.theme_*` | **PRÉ-DÉCLARÉ** (plan source) — brief produit en C.2 |
| **GATE_W7_A_FISCAL_BREACH** | W7.A | Si audit A.1 conclut que A.2 doit toucher `OrderService` / `FrontendOrderService` / dispatch fiscal | **CONDITIONNEL** (déclenché si A.1 le détecte) |
| **GATE_W7_B_TPE_BREACH** | W7.B | Si audit B.1 conclut que B.2 doit modifier flux retry TPE / séquence paiement | **CONDITIONNEL** (déclenché si B.1 le détecte) |

### Soft gates W7 (forcent halt même en auto-remediation)

| ID | Trigger | Action |
|---|---|---|
| SOFT_W7_VITEST | Régression Vitest scope-pertinent (chute < 615 verts hors le 1 untracked posNormalizeIds connu) | HALT, diagnostic + remediation (compteur MAX 3) |
| SOFT_W7_GATED_W5 | Détection qu'un composant gated W5 (KioskPayment/Order/Confirmation) a été modifié dans diff A.2 ou B.2 | HALT immédiat, revert, gate brief obligatoire |
| SOFT_W7_V14_OVERLAP | Détection touche fichier worktree V14 non commité (`resources/js/components/admin/pos/**`, `posParked.js`, `posBarcode.js`) | HALT, revert, escalade orchestration V14 ↔ W7 |
| SOFT_W7_IDB_QUOTA | Test `kioskOfflineQueueIDB.spec.js` révèle pas de gestion quota IDB (5MB-50MB selon navigateur) | HALT remediation max 3 |
| SOFT_W7_BACKEND_TOUCH | Diff A.2 ou B.2 contient ≥1 fichier `app/**`, `database/**`, `routes/**` | HALT immédiat, revert, gate brief |

### Soft gates héritées W5 (rappel — toujours ouvertes)

- GATE_P_MEGA_12 (TVA), GATE_P_MEGA_13 (TPE), GATE_P_MEGA_14 (NF525 receipt) — W7 **ne lève pas** ces gates ; tout chevauchement = STOP.

---

## ESCALATIONS pré-déclarées (count = 6)

- **E1 (Phase A.2) — Ajout devDep `fake-indexeddb`** : nécessaire pour mock IDB en jsdom (pas d'IDB native). Catégorie devDep d'outillage tests = même que `axe-core` ajoutée en W6 → autorisée par défaut, mais flagué.
- **E2 (Phase A.1 / A.2) — Echo / Pusher non câblé côté front** : baseline confirmé : aucun fichier `echo.js`, `pusher.js` côté front malgré présence `app/Events/ItemAvailabilityChanged.php` côté serveur. Le listener kiosk doit donc soit (a) nécessiter ajout Echo/Pusher (touche `bootstrap.js`, ajoute lib runtime, modifie WebSocket infra → hors scope W7) soit (b) implémenter en **polling** (intervalle configurable, fallback). **Recommandation orchestrateur** : option (b) polling (scope contenu W7), option (a) déclenche **gate brief séparé** "infra broadcast frontend".
- **E3 (Phase A.2) — Sérialisation orders complexes** : variations + extras + allergens + customer_allergens dans payload IDB → format JSON déjà serializable, mais attention à la profondeur (Symbol, Date, Map → undefined). Mitigation : test `kioskOfflineQueueIDB.spec.js` couvre cas concret avec payload complet kiosk-checkout.
- **E4 (Phase A.2) — Quota IDB dépassé** : kiosk avec 100s de commandes en queue → quota atteint (Chrome 60% disque, WebKit 1GB, etc.). **Policy** : si quota atteint → drop oldest + alert toast critique + ne pas accepter nouvelle commande offline. Documenté dans `RUN_P_MEGA_17_*.md`.
- **E5 (Phase B.1) — Hardware mock obligatoire** : aucune infra Playwright + jsdom n'a pas d'API printer/TPE/scanner. Tests = mocks JS pure. Validation device = hors scope W7 (différée à QA manuelle). Documenté dans `AUDIT_P_MEGA_18_*.md`.
- **E6 (Phase C.1) — KioskContextResource n'existe pas en testttt** : confirmé par baseline (aucun match `theme_primary` etc.). Création de cette resource = code prod → **HORS SCOPE W7.C** (W7.C = audit + brief seulement). L'implementation est délégué à cycle séparé post-décision business.

---

## Test strategy

| Phase | Stratégie | Détail |
|---|---|---|
| A.1 | `no-test` (audit) | Lecture statique. Aucun nouveau test. |
| A.2 | `vitest:idb-mock + vitest:jitter + vitest:listener + vitest:conflict-modal` | 4 nouveaux fichiers tests, ≥18 cas total. Mocks : `fake-indexeddb`, mock Echo/Pusher OR mock setInterval polling, mock `Math.random` pour jitter déterministe. |
| A.3 | `no-test` + audit | Run global Vitest pour confirmer 616 + 18 = 634 verts attendus. Vérification manuelle invariants 1-5 W7.A. |
| B.1 | `no-test` (audit) | Lecture statique. Aucun nouveau test. |
| B.2 | `vitest:hardware-fallback + vitest:barcode-manual` | 2 nouveaux fichiers tests, ≥10 cas total (4 hardware × 4 scenarios + 2 barcode manual). Mocks JS pure pour 4 périphériques. |
| B.3 | `no-test` + audit | Run global Vitest pour confirmer 634 + 10 = 644 verts attendus + check diff vide sur `app/**`, `database/**`, `routes/**`, fichiers gated W5. |
| C.1 | `no-test` (audit) | Lecture statique DB schema + admin + CSS. Aucun nouveau test. |
| C.2 | `no-test` (gate brief) | Aucun test. Brief markdown. |

**PHPUnit** : aucun test PHPUnit en W7 (front-only par périmètre). Si C.1 audit révèle qu'un test sentinel isolation branche serait pertinent → noté dans GATE_BRIEF C.2 pour cycle implementation futur, **pas dans W7**.

**Pas de Playwright en W7** : infra non confirmée stable (idem W6). Tests Vitest = proxy local-validation, pas substitut au QA device.

**Nouveau total Vitest attendu** : `616 baseline + 28 W7 = 644` (et 1 untracked posNormalizeIds toujours rouge — pas dans scope W7).

---

## DoD précis par phase

### A.1 — DoD audit P-MEGA-17
- [ ] `AUDIT_P_MEGA_17_OFFLINE_QUEUE_BASELINE_2026-04-20.md` produit
- [ ] Inventaire `kioskOfflineQueue.js` actuel (T14a/b livré) avec citation `file:line`
- [ ] Inventaire dépendances broadcast frontend (Echo/Pusher câblé ? OUI/NON + justif)
- [ ] Décision routing A.2 : complex implementer (justif IDB + race + listener)
- [ ] Liste fichiers à toucher avec read/write intent + check vs OFF_LIMITS
- [ ] **Verdict critical zone** : SAFE pour auto-remediation OU **STOP gate brief** (E_A1_FISCAL)
- [ ] 0 fichier modifié (vérifié `git status`)

### A.2 — DoD EXECUTE P-MEGA-17
- [ ] `RUN_P_MEGA_17_OFFLINE_QUEUE_EXECUTE_2026-04-20.md` produit
- [ ] 4 nouveaux fichiers test verts (≥18 cas total)
- [ ] Vitest global ≥ 634 verts (616 + 18, marge 0)
- [ ] 0 fichier `app/**`, `database/**`, `routes/**` modifié (vérifié `git diff --name-only`)
- [ ] 0 fichier gated W5 modifié (KioskPayment/Order/Confirmation)
- [ ] 0 fichier worktree V14 modifié (`admin/pos/**`, `posParked.js`, `posBarcode.js`, etc.)
- [ ] devDep `fake-indexeddb` ajoutée (E1 pré-validée)
- [ ] Listener implémenté en polling OU décision documentée pour Echo (E2)
- [ ] Quota IDB géré avec policy explicite (E4)
- [ ] i18n 5 langues complète (ar/en/fr/de/bn)

### A.3 — DoD verify P-MEGA-17
- [ ] `VERIFY_P_MEGA_17_OFFLINE_QUEUE_200_2026-04-20.md` produit (audit indépendant)
- [ ] Confirmation invariants 1-5 W7.A (symétrie payload, dispatch-after-commit, branch_id, NF525, idempotency)
- [ ] Confirmation 0 régression Vitest scope-pertinent
- [ ] Recommendation CLOSED ou REMEDIATION_NEEDED avec bug_signature explicite

### C.1 — DoD audit P-MEGA-19
- [ ] `AUDIT_P_MEGA_19_BRANCH_THEMING_BASELINE_2026-04-20.md` produit
- [ ] Inventaire schéma `branches` actuel (citation `database/migrations/2022_11_17_*.php` + suivantes)
- [ ] Inventaire CSS variables / design tokens kiosk actuels
- [ ] Inventaire flux upload assets existant (storage driver, validation)
- [ ] Liste questions précises à poser au humain
- [ ] 0 fichier modifié

### C.2 — DoD GATE_BRIEF P-MEGA-19
- [ ] `docs/gates/GATE_P_MEGA_19_BRANCH_THEMING_2026-04-20.md` produit selon format `human-gates.mdc`
- [ ] Trigger / Affected Subsystems / Invariants at Risk / Decision Required précis
- [ ] ≥3 options + Cancel
- [ ] Approval block vide (pas de self-approval)
- [ ] Update `.cursor/ACTIVE_CYCLE.md` : W7.C = `BLOCKED_HUMAN_GATE`

### B.1 — DoD audit P-MEGA-18
- [ ] `AUDIT_P_MEGA_18_HARDWARE_BASELINE_2026-04-20.md` produit
- [ ] Inventaire `kioskHardware.js` (config + service) actuel avec citation `file:line`
- [ ] Matrice 4×4 (16 cas) avec état actuel + classification {ROUTINE / COMPLEX / OFF-LIMITS fiscal}
- [ ] Décision routing B.2 (Composer vs GPT-5.4 vs **STOP gate brief**)
- [ ] 0 fichier modifié

### B.2 — DoD EXECUTE P-MEGA-18
- [ ] `RUN_P_MEGA_18_HARDWARE_EXECUTE_2026-04-20.md` produit
- [ ] 2 nouveaux fichiers test verts (≥10 cas total)
- [ ] Vitest global ≥ 644 verts (634 + 10)
- [ ] 0 fichier `app/Services/Payment*`, `app/Services/Order*` modifié
- [ ] 0 fichier gated W5 modifié
- [ ] 0 fichier worktree V14 modifié
- [ ] i18n 5 langues complète

### B.3 — DoD verify P-MEGA-18
- [ ] `VERIFY_P_MEGA_18_HARDWARE_200_2026-04-20.md` produit
- [ ] Confirmation invariants 1-4 W7.B (NF525 séquence intacte, TPE non modifié, no payment fallback, hardware read-only)
- [ ] Confirmation 0 régression Vitest
- [ ] Recommendation CLOSED ou REMEDIATION_NEEDED

---

## Estimation LOC par sous-cycle

| Sous-cycle | Phase | Type | LOC |
|---|---|---|---|
| W7.A | A.1 | Markdown audit | ~220 |
| W7.A | A.2 | Code prod helpers/services | ~200 |
| W7.A | A.2 | Code prod components | ~80 |
| W7.A | A.2 | Code prod i18n (5 langues) | ~30 |
| W7.A | A.2 | Code tests | ~120 |
| W7.A | A.3 | Markdown verify | ~150 |
| W7.A | **Sous-total** | — | **~800** |
| W7.B | B.1 | Markdown audit | ~220 |
| W7.B | B.2 | Code prod services + config | ~80 |
| W7.B | B.2 | Code prod components | ~100 |
| W7.B | B.2 | Code prod i18n | ~20 |
| W7.B | B.2 | Code tests | ~80 |
| W7.B | B.3 | Markdown verify | ~130 |
| W7.B | **Sous-total** | — | **~630** |
| W7.C | C.1 | Markdown audit | ~200 |
| W7.C | C.2 | Markdown gate brief | ~120 |
| W7.C | **Sous-total** | — | **~320** (0 LOC code prod) |
| **TOTAL W7** | | | **~1750** (dont ~610 LOC code prod) |

Plan source estime "Wave 7 = ~850 LOC". Notre estimation totale dépasse car incluit audits + verifies + brief markdown ; le **code prod seul ~610 LOC** est aligné avec plan source (P-MEGA-17 ~400 + P-MEGA-18 ~250).

---

## METRICS BASELINE à mesurer en A.1, B.1, C.1 AVANT EXECUTE

### A.1 baseline P-MEGA-17 offline queue

| Metric | Méthode mesure | Cible post-A.2 |
|---|---|---|
| `t14ab_storage_layer` | Lecture `kioskOfflineQueue.js` actuel : in-memory ? localStorage ? IDB ? | IDB (durable cross-reload) |
| `t14ab_backoff_strategy` | grep retry/backoff dans `kioskOfflineQueue.js` | Exponentiel + jitter randomization |
| `echo_pusher_wired` | grep `Echo`, `Pusher`, `laravel-echo` dans `bootstrap.js` + `package.json` | Documenté (OUI = use Echo / NON = polling) |
| `item_availability_event_backend` | Confirmer présence `app/Events/ItemAvailabilityChanged.php` + listeners | Confirmé baseline (existe + 3 listeners) |
| `idempotency_key_usage` | grep `idempotency_key` dans helpers payment kiosk | Préservé dans replay queue |
| `payload_serialization_completeness` | Mock un order checkout complet, sérialiser, désérialiser, comparer égalité profonde | 100% fidélité (variations + extras + allergens) |

### B.1 baseline P-MEGA-18 hardware fallback

| Metric | Méthode mesure | Cible post-B.2 |
|---|---|---|
| `kioskHardware_service_api` | Lecture `services/kioskHardware.js` + `config/kioskHardware.js` (déjà présents baseline) | API étendue : detect status + dispatch fallback |
| `printer_fallback_existing` | grep printer in services/components | Fallback écran + email implémenté |
| `tpe_timeout_handling` | grep TPE / payment timeout dans kiosk | **NON modifié** (gate W5) — fallback purement UI |
| `scanner_manual_input` | grep barcode / camera dans kiosk | Composant saisie manuelle ajouté |
| `buzzer_visual_fallback` | grep buzzer / notification | Notification visuelle écran fallback |
| `nf525_seq_untouched` | git diff sur `app/Services/Order*`, `app/Services/Payment*`, `ZReportService.php` | DIFF VIDE confirmé |

### C.1 baseline P-MEGA-19 branch theming

| Metric | Méthode mesure | Cible post-C.2 |
|---|---|---|
| `branches_schema_current` | Lecture migrations + Branch model | Documenté : colonnes existantes, **AUCUN** `theme_*` baseline |
| `kiosk_design_tokens` | grep CSS variables `--kiosk-*` dans `resources/css/kiosk-wizard.css` + ds tokens | Documenté : tokens existants |
| `branch_id_authenticated_kiosk` | grep `branch_id` dans `KioskAppComponent.vue` + middleware | Documenté flux d'authent kiosk → branch_id |
| `assets_storage_driver` | Lecture `config/filesystems.php` + admin upload existant (logo branche ?) | Documenté : local public/storage ou S3 |
| `kiosk_context_resource_exists` | grep `KioskContextResource` | NON (à créer en cycle séparé post-décision) |

---

## Pattern auto-remediation par sous-cycle

| Sous-cycle | Auto-remediation | Justification |
|---|---|---|
| **W7.A** | **ACTIVÉE** par défaut | Front kiosk only — IDB + listener polling + UI conflict modal = scope front. Bascule désactivée si A.1 conclut critical zone (E_A1_FISCAL → STOP). Compteur MAX 3 par bug_signature. |
| **W7.B** | **ACTIVÉE** par défaut | Hardware fallback purement UI + détection status read-only. Bascule désactivée si B.1 classifie ≥1 scénario OFF-LIMITS fiscal (E_B1_TPE → STOP). |
| **W7.C** | **DÉSACTIVÉE** | HUMAN_GATE business pré-déclaré + schema migration potentielle. AUDIT readonly + GATE_BRIEF only. Aucune remediation possible (pas de code prod). |

---

## Risques principaux (3 lignes max)

1. **R1 Echo/Pusher non câblé front** (E2) : confirmé baseline. W7.A devra implémenter `kioskAvailabilityListener.js` en **polling** (intervalle 30s) plutôt qu'attendre le câblage Echo (cycle séparé). UX dégradé acceptable car seules quelques minutes de latence sur invalidation snapshot.
2. **R2 Bascule HARD GATE conditionnelle A.1 ou B.1** : si l'audit révèle qu'un fix nécessite de toucher `OrderService` / `FrontendOrderService` / `PaymentService` → STOP gate brief immédiat. Probabilité faible (P-MEGA-17 = front kiosk only par définition) mais non-nulle.
3. **R3 Worktree V14 conflit** : 25+ fichiers V14 non-commités touchent POS (posParked, posBarcode, ItemComponent, PaymentComponent admin). W7 doit absolument **ne pas overlap**. Mitigation : whitelist OFF_LIMITS explicite + check `git status` avant chaque EXECUTE.

---

## Ordre d'exécution

1. **Confirmer HEAD baseline** (`git log -1`) + Vitest baseline (`npm test 2>&1 | tail -3`) → noter dans `ACTIVE_CYCLE.md`.
2. **Démarrage parallèle A.1 ‖ C.1** : invoquer 1× `explore` very thorough sur scope offline queue + 1× `explore` very thorough sur scope branch theming. Outputs séparés.
3. **Lecture résumés A.1 + C.1** par orchestrateur. Décisions :
   - A.1 → routing A.2 (complex implementer attendu) + verdict critical zone (SAFE ou GATE_BRIEF E_A1_FISCAL).
   - C.1 → préparer GATE_BRIEF C.2.
4. **C.2** — orchestrateur écrit `docs/gates/GATE_P_MEGA_19_BRANCH_THEMING_2026-04-20.md` selon format `human-gates.mdc`. **Update ACTIVE_CYCLE.md : W7.C = BLOCKED_HUMAN_GATE**. Pas d'attente : on continue W7.A en parallèle.
5. **A.2** — invoquer 1× `foodking-complex-implementer` (GPT-5.4) avec scope strict (whitelist fichiers + OFF_LIMITS gated W5 + worktree V14) + DoD précis. Boucle auto-remediation si KO normal (MAX 3 tentatives par bug_signature).
6. **A.3** — invoquer 1× `explore` very thorough sur diff A.2 → produire VERIFY. Si REMEDIATION_NEEDED → boucle vers A.2.
7. **Commit atomique W7.A** (suggéré, après validation user — orchestrateur ne commit pas seul).
8. **B.1** — invoquer 1× `explore` very thorough sur scope hardware fallback. Décision routing B.2 (Composer routine ou GPT-5.4 complex selon classification 16 cas).
9. **B.2** — invoquer subagent décidé en B.1 avec scope + DoD. Boucle auto-remediation si KO normal.
10. **B.3** — invoquer 1× `explore` medium sur diff B.2 → produire VERIFY.
11. **Synthèse W7** — orchestrateur écrit `reports/execution/SYNTHESE_P_MEGA_W7_2026-04-20.md` agrégeant les 8 phases + final report per `auto-remediation.mdc` template.
12. **Commit atomique W7.B** (suggéré, après validation user).
13. **Update `.cursor/ACTIVE_CYCLE.md`** : PHASE W7.A et W7.B = `CLOSED PASSED` (ou `BLOCKED_HUMAN_GATE` si gate W7 hit) ; W7.C = `BLOCKED_HUMAN_GATE` (attente décision business).

---

## ACTIVE_CYCLE update prévu

À l'ouverture du cycle :
- TASK_ID = `P_MEGA_W7_RESILIENCE_HARDWARE_BRANCH_2026-04-20`
- PHASE = `EXECUTE — sous-cycle A (offline queue) ‖ AUDIT C (branch theming)`
- PRIMARY_MODEL = `Claude (orchestration) + explore (audits A.1/C.1/B.1 + verifies A.3/B.3) + foodking-complex-implementer (A.2) + foodking-routine-implementer OR foodking-complex-implementer (B.2 conditionnel)`
- PLAN_FILE = `plans/PLAN_P_MEGA_W7_2026-04-20.md` (ce fichier)
- REPORT_FILES = 7 (3 audit + 2 verify + 2 execute) + 1 GATE_BRIEF + 1 synthèse
- GATE_FILES = 1 pré-déclaré (`GATE_P_MEGA_19_BRANCH_THEMING_2026-04-20.md`) + 2 conditionnels
- RUNNER_MODE = single-session
- AUTO_REMEDIATION = ACTIVÉE W7.A/B (sauf bascule critical zone) ; DÉSACTIVÉE W7.C

À la fin de B.3 :
- W7.A PHASE = `CLOSED PASSED` (cas nominal) OU `BLOCKED_HUMAN_GATE` si soft gate W7 hit
- W7.B PHASE = `CLOSED PASSED` OU `BLOCKED_HUMAN_GATE`
- W7.C PHASE = `BLOCKED_HUMAN_GATE` (toujours, par design)
- NEXT = "humain commit W7.A + W7.B atomiques + décide P-MEGA-19 (W7.C brief) ; W8 (P-MEGA-20 + 22) ou retour W5 décisions gates"

---

## Manifeste

> Vague 7 = **3 sous-cycles** : 2 séquentiels (W7.A offline queue → W7.B hardware fallback) + 1 parallèle readonly (W7.C branch theming → GATE_BRIEF). Auto-remediation **conditionnelle** : ACTIVÉE pour W7.A/B (sauf bascule critical zone fiscale détectée à l'audit, alors STOP gate brief), DÉSACTIVÉE pour W7.C (HUMAN_GATE business + schema migration). 1 GATE pré-déclaré + 2 conditionnels + 6 ESCALATIONs. Le scope est délibérément **front-only** : aucun fichier `app/`, `database/`, `routes/` ne doit être touché en EXECUTE (W7.A et W7.B). Les 3 GATES OUVERTES W5 (TVA / TPE / NF525 receipt) restent strictement intouchées par W7. Le worktree V14 actif (POS) est OFF-LIMITS absolu. Le but : (a) durcir K-3 offline queue (IDB + jitter + listener polling + conflict UX), (b) compléter hardware fallback non-fiscal (printer/scanner/buzzer + UI banner), (c) cartographier P-MEGA-19 et préparer décision business sans implémenter. L'implementation P-MEGA-19 sera un cycle séparé post-décision humaine.

# GOAL — WIZARD E2E PARITY (Borne ↔ Caisse) — Deep-E2E Review & Heal

> **STATUS: PLAN-ONLY.** N'exécute RIEN tant que l'owner n'a pas dit `lance le GOAL`.
> Date : 2026-06-09 · Branche : `heal/cms-pr1-quickwins-2026-05-18` (worktree `pre-cloud-exec`)
> Méthodo : `ultra-architect-planify` (anchor-first) + boucle `test-e2e` (GStack + adversaire)
> Prédécesseurs : `GOAL_WIZARD_DYNAMIC_BUILDER_2026-06-08.md` (builder, technique livré),
> `reports/test-e2e/wizard-dynamic-2026-06-08/` (W5 collision-guard saga + W567 convergence).

---

## §0 — PREAMBLE

### §0.1 — Working-tree decision
- Worktree partagé `pre-cloud-exec` — **HAZARD** : plusieurs jobs bg y vivent (cf.
  `memory/feedback_shared_worktree_git_commit_collision_2026-06-09.md`). Avant toute
  exécution : `git status` + vérifier qu'aucun autre job n'écrit. **JAMAIS `git add -A`** ;
  commits avec paths explicites uniquement. Commit promptement pour réduire la fenêtre de sweep.
- Le builder (backend create + PUT re-edit + 16 tests) est **déjà landé** (commits `df278124f`,
  `fc66025c0`). Ce GOAL ne ré-écrit pas ce code : il le **valide en E2E réel** et wire l'UI manquante.

### §0.2 — Ce que ce GOAL EST / N'EST PAS
- **EST** : un audit deep-E2E (visuel-first + adversaire, boucle jusqu'à 2 cycles propres) des
  wizards de composition **dynamiques** sur **borne (kiosk)** ET **caisse (POS)**, couvrant :
  (a) tous les wizards des **catégories réelles** enregistrés/provisionnés ; (b) le flow
  **créer** une page perso ; (c) le flow **modifier** une page perso ; (d) la **mise à jour
  d'image** d'option ; (e) la **synchronisation** définition + runtime borne↔caisse↔KDS.
- **N'EST PAS** : un re-design des wizards frozen ; un go-live (provision sur la DB opérante =
  gate séparé §G) ; un wireup mobile/web standalone (hors-scope V1, owner mandate).

### §0.3 — Critère de convergence (hérité `test-e2e`)
DONE = **deux cycles consécutifs** avec **P0+P1 = 0** ET **findings sets identiques** (set-equality
tue les flakes). P2 = disclose sans boucler. P3 = info. Tout label brut visible / layout cassé /
erreur console / NF525 chain modifiée = REJECT immédiat (Axis 6).

### §0.4 — Pipeline par tâche
Chaque tâche délègue son exécution au pipeline `ultra-audit-profond` (audit→test→visual→
self-correct→RED→converge). Ce GOAL ne ré-décrit pas les 14 étapes — une référence suffit.

### §0.5 — Harnais E2E (DB-safe, NON-négociable)
- **:8765 = `foodking` OPÉRANTE** → **READ-ONLY** strict. Toute écriture navigateur frappe la
  vraie chaîne NF525. Interdit pour tout flow mutant.
- **:8766/:8767 = `foodking_e2e` clone jetable** (`APP_ENV=e2e php artisan serve --port=8766`,
  `.env.e2e` gitignored, mêmes secrets fiscaux, resettable par re-clone). **TOUS** les flows
  order/builder/publish/modify s'exécutent ICI. (Réf : `memory/reference_e2e_harness_foodking_e2e_2026-06-07.md`.)
- **JAMAIS `php artisan test`** (wipe la DB partagée — cf. DEVDB-GUARD). Tests = `vendor/bin/phpunit --filter`.

### §0.6 — La CONTRADICTION (§12 anti-drift) — à trancher AVANT W3
L'owner a demandé (verbatim) « réaliste, possible et **synchronisé et bien fait pour le système
de la borne ainsi que la caisse** » — ce qui exige la **parité caisse**. Or l'owner a aussi, le
2026-06-08, répondu **« Defer »** à GATE-W6 (le travail caisse frozen). Ces deux instructions se
contredisent. **Résolution adoptée** (validée advisor) : ce GOAL prend la parité borne+caisse
comme **colonne vertébrale**, mais la **vague caisse (W3) est GATED** sur un LOCK explicite
GATE-W6 + waiver « design parfait » (frozen `pos-wizard.js`). On **ne pré-tranche pas** ; tout
ce qui est en amont de W3 (W0/W1/W2/W4-borne) tourne **quelle que soit** la décision. Le « Defer »
est traité comme **superseded-pending-confirmation** : l'owner reconfirme au moment du gate.

---

## §1 — MAP PRINCIPAL (systèmes touchés, ancrés)

> Vérifié 2026-06-09 via grep/find/ls (sorties réelles ci-dessous).

| Système | Maturité | Anchor vérifié (file:line) | Rôle dans ce GOAL |
|---|---|---|---|
| **Builder CMS (admin)** | livré technique | `ProductComposerEditorComponent.vue` (1270 l.) ; `ComposerProfileController.php:205` create, `:328` update | source de vérité des wizards — créer/modifier pages |
| **Borne / Kiosk** | production, FROZEN | `KioskWizardComponent.vue` (119 747 o., FROZEN §7) ; `KioskStepMenuComponent.vue` | rend les wizards composer-first **auto** |
| **Caisse / POS** | production, FROZEN | `public/js/pos-wizard.js` render dispatch `:1131-1152` (PAS de branche `generic_choices`) ; flag `config/catalog_v15.php:104` default **false** | rend les wizards **seulement si flag ON** + renderer à écrire (GATE-W6) |
| **Backend composer** | livré | routes `api.php:787` (POST create), `:791` (PUT/PATCH update) ; `ProvisionCayenneWizardsCommand.php:24` | persiste options vers ItemExtra (NF525 SSOT, jamais de prix sur step) |
| **Sync (publish + runtime)** | production | `ComposerPublishSyncTest.php` (definition) ; `SYNC_CONTRACT.md` (runtime borne→KDS→caisse) | propage edits + ordres entre surfaces |

### §1.1 — Provision réelle = 6 catégories / 2 templates (PAS « 10 wizards »)
`ProvisionCayenneWizardsCommand.php:29-37` (vérifié) :
```
'Sandwich Cayenne' => 'sandwich',  'Sandwich Classique' => 'sandwich',
'Galette'          => 'sandwich',  'Burgers'            => 'sandwich',
'Tacos'            => 'tacos',     'Bols Gourmands'     => 'sandwich',
```
→ **6 catégories** mappées sur **2 templates** (`sandwich`, `tacos`). Le « 10 pages » évoqué par
l'owner est un **objectif d'extension de la MAP** (ajouter des entrées catégorie→template — tâche
**non-frozen, data/config**), pas un état actuel. **DÉCISION OWNER requise** (§G G-PROV) : la liste
exacte des ~10 wizards à enregistrer + leur template. On ne **devine pas** les catégories (anti-fiction).

---

## §2 — MAP SÉPARÉ (hors-scope, listé pour disjonction)
- **Mobile RN** (`mobile/`) + **Web standalone** (`/Downloads/web/`) : STANDALONE, NO wireup V1
  (owner mandate). **Exclus** de ce GOAL. Tout wizard mobile/web est un miroir hardcodé, pas câblé.

---

## §3 — SYSTÈME : Builder CMS (admin) — créer & modifier les pages
### Contract
Le builder présente une UI CMS, mais **chaque option persiste vers un construct catalogue**
(ItemExtra), **JAMAIS** de prix sur un step (NF525 SSOT — `price` `prohibited` dans
`ComposerPersonalPageRequest`). Branche-scope writable enforced.
### Frozen zones — AUCUNE ici (composer admin = non-frozen).
### Anchors (vérifiés)
- `ProductComposerEditorComponent.vue` : create modal `openPersonalPage` `:943`, step PATCH générique
  `axios.patch('admin/composer/steps/${id}')` `:1045`, normalize `:872`. **Pas de re-edit du jeu
  d'options** (le PATCH générique édite label/min/max du step, pas les ItemExtra).
- Backend : `createPersonalPage` `:205`, `updatePersonalPage` `:328` (server-trusted step PK,
  collision-free by construction), import `ItemWizardStep` `:13`.
- Tests : `tests/Feature/Composer/ComposerPersonalPageTest.php` (16/16 vert) ;
  `tests/js/categoryComposerEditorContract.spec.js` (Vitest editor).

### Sub 3.1 — Re-edit UI (le manque) → **W1**
**Tasks**
- T-3.1.1 Ajouter une affordance « modifier les options » sur un step `source_type==='extra_group'`
  → rouvre le modal **pré-rempli** (label/options/min/max/visible) depuis le profil chargé.
  • anchor : `ProductComposerEditorComponent.vue:943` (modal), `:872` (normalizeStep), `:1045` (PATCH pattern)
- T-3.1.2 Brancher la soumission : si step existant → `axios.put('admin/composer/profiles/${profile}/personal-page/${step.id}', body)` ; sinon POST create.
  • anchor : route `api.php:791`
- T-3.1.3 Garde-fou client : `price` jamais envoyé (cohérent avec `prohibited` backend) ; label
  collision = afficher l'erreur 422 du serveur dans `composer-personal-page-error`.
**Acceptance**
- `tests/js/categoryComposerEditorContract.spec.js` étendu PASS (re-edit pré-fill + PUT branch)
  **OU** `(test TO BE CREATED at tests/js/composerPersonalPageReedit.spec.js)`.
- `tests/Feature/Composer/ComposerPersonalPageTest.php` reste 16/16 (backend inchangé).
- Visual : modal pré-rempli capturé sur :8766 (admin composer editor).

---

## §4 — SYSTÈME : Borne / Kiosk — rendu wizard dynamique
### Contract
Rend les wizards **composer-first automatiquement** dès qu'un `composer_profile` publié existe
sur l'item (pas de flag). Palette Cayenne `#F4501E`, light mode 100%.
### Frozen zones (strict-no-touch §7)
- `KioskWizardComponent.vue`, `KioskAppComponent.vue`, `KioskUpsellComponent.vue`, `KioskStepMenuComponent.vue`
### Anchors (vérifiés)
- `KioskWizardComponent.vue` (119 747 o., FROZEN) — data-driven du composer_profile.
- Tests : `tests/js/kioskWizardGenericComposer.spec.js`, `tests/js/kioskWizardComposerProfile.spec.js`,
  `tests/js/kioskComposerProfileChangeHandling.spec.js`.
### Sub 4.1 — Builder→Borne E2E (compose→image→publish→render) → **W2**
**Tasks**
- T-4.1.1 Créer une page perso (builder) → publier → ouvrir l'item borne sur :8766 → la page perso
  s'affiche avec ses options, libellés résolus (0 label brut), images chargées.
- T-4.1.2 Mettre à jour l'image d'une option (builder) → re-publier → la borne reflète la nouvelle
  image (cache-bust vérifié).
- T-4.1.3 Modifier une page perso (W1) → re-publier → la borne reflète le nouveau jeu d'options
  (ajout/suppression/renommage), pas de fantôme de l'ancien.
**Acceptance**
- `tests/js/kioskWizardGenericComposer.spec.js` PASS + E2E borne :8766 capturé (visual-first) :
  page perso rendue, image OK, min/max respecté, ajout panier prix = backend.
- `ComposerPublishSyncTest.php` PASS (definition propagée à la publication).

---

## §5 — SYSTÈME : Caisse / POS — parité **[GATED — GATE-W6]**
### Contract
Rend les wizards **uniquement si** `FK_POS_WIZARD_COMPOSER_AWARE_ENABLED=true`
(`config/catalog_v15.php:104`, default **false**). Wizard Vanilla JS **FROZEN** (« design parfait »).
### Frozen zones (strict-no-touch §7)
- `public/js/pos-wizard.js`, `public/css/pos-wizard.css`, `resources/views/admin-pos-v4.blade.php`
### Anchor CRITIQUE (vérifié 2026-06-09)
Le dispatch de rendu `pos-wizard.js:1131-1152` liste les branches : viande, sauce, sauce_single,
accompagnement, garnitures, supplements, menu_choice, frites_options, sauce_frites, boisson_choice,
menu, pain, viande_sauce, perso, sauce_garnitures, supplements_menu, sauce_accompagnement,
sauce_supplements, recap — **AUCUNE branche `generic_choices`**. Le type est résolu en
`'generic_choices'` en fallback à `:515` mais **jamais rendu** → step blanc/crash.
→ **GATE-W6 = « flipper le flag ET ÉCRIRE un renderer `generic_choices` » dans un fichier FROZEN**,
PAS un simple flag-flip. Ampleur honnête : nouveau `renderGenericChoicesStep(step)` + branche dispatch
+ `composerAddonTotal` (addon pricing affiché) — **modification logique d'une zone frozen**.
### Sub 5.1 — Caisse parity E2E → **W3 [GATED]**
**Tasks (n'exécuter qu'après LOCK)**
- T-5.1.1 [LOCK] Écrire `renderGenericChoicesStep` + branche dispatch (commit séparé, `lock-plan`).
- T-5.1.2 Flag ON sur :8766 uniquement → ouvrir l'item caisse → page perso rendue identique borne.
- T-5.1.3 Parité numérique : addon total caisse = borne = backend = ticket.
**Acceptance**
- `tests/js/posWizardComposerAware.spec.js` PASS (étendu generic_choices) + E2E caisse :8766 visual.
- Frozen diff documenté dans le LOCK (zéro ligne hors périmètre LOCK).
- Coordonner avec tout CAISSE-01 touchant le même fichier frozen (un seul LOCK, un seul commit).

---

## §6 — SYSTÈME : Sync — definition + runtime
### Contract
- **Definition sync** : une publication de profil propage la nouvelle définition aux surfaces
  (borne auto, caisse si flag). Anchor : `ComposerPublishSyncTest.php`.
- **Runtime sync** : un ordre construit via wizard suit borne→KDS→caisse de façon cohérente
  (cf. `SYNC_CONTRACT.md`, `memory/reference_e2e_harness_...` SYNC-WS-01 : ws:6001 échoue→polling).
### Sub 6.1 — Sync borne↔caisse↔KDS → **W4**
**Tasks**
- T-6.1.1 Publier un edit de page perso → vérifier que borne (et caisse si LOCK) le voient sans
  redéploiement (definition sync).
- T-6.1.2 Construire un ordre via wizard borne → vérifier KDS + caisse affichent la composition
  exacte (line-items, options, total identiques — numeric integrity P0).
- T-6.1.3 Dégradation : si ws échoue, polling prend le relais (pas de perte d'ordre).
**Acceptance**
- `ComposerPublishSyncTest.php` PASS + E2E cross-surface :8766 : même composition/total sur borne,
  KDS, caisse (capture des 3 surfaces, set-equality numérique).

---

## §A — AGENT ARMY MAP

| Rôle | Subagent type | Tools | Quand |
|---|---|---|---|
| Architect | `Plan` | Read-only | cartographie W0, sizing GATE-W6 |
| Implementer | `code-editor` / `general-purpose` | Edit+Write+Bash | W1 UI, W3 renderer (sous LOCK) |
| QA Visual | `general-purpose` | Read+Playwright | capture quartet (PNG+DOM+console+network) /wave sur :8766 |
| RED Visual | `general-purpose` | Read | re-analyse les screenshots indépendamment, dispute |
| RED-team | `general-purpose` | Read-only | findings JSON P0..P3 après chaque implementer commit |
| DBA/Fiscal | `general-purpose` | Read | NF525 : 0 prix sur step, chain unchanged, ItemExtra SSOT |

### Fan-out matrix (par type de tâche)
| Type | Architect | Implementer | QA Vis | RED Vis | RED | Fiscal |
|---|:-:|:-:|:-:|:-:|:-:|:-:|
| W1 UI (Vue non-frozen) | x | x | x | x | x | . |
| W2 borne E2E | x | . | x | x | x | x |
| W3 caisse renderer (frozen) | x | x | x | x | x | x |
| W4 cross-surface sync | x | . | x | x | x | x |

### Dispatch discipline
- 5 read-only specialists = **un seul message**, N Agent calls (parallèle).
- Implementer **jamais** en parallèle d'un autre implementer (write conflict — worktree partagé !).
- QA Visual + RED Visual = parallèle OK (read-only screenshots).
- RED-team dispute **toujours** après commit implementer, **avant** DONE.
- **Agents épinglés au worktree path** `…/.claude/worktrees/pre-cloud-exec` (leçon parallel-safe).
- Subagents persistent leurs findings sur disque (`reports/test-e2e/wizard-parity-2026-06-09/<round>/wave-<W>-<role>.json`) — synthèse depuis disque, survit aux interrupts. Cap ~1200-1500 mots/agent.

---

## §X — VAGUES DE CONVERGENCE

> Séquentiel par défaut (worktree partagé = risque write-conflict). Parallèle **seulement** en
> phase audit read-only intra-vague.

### W0 — Préconditions (BLOQUANTE, aucune écriture code)
- Disk > 1 GiB libre ; aucun autre job bg n'écrit (`git status` propre côté autres jobs).
- Cloner/rafraîchir `foodking_e2e` ; `APP_ENV=e2e php artisan serve --port=8766` UP.
- **Provision sur le CLONE** : `foodking:provision-cayenne-wizards` sur :8766 (PAS sur l'opérante).
- **Gate G-PROV** (owner) : valider la liste des ~10 wizards + leur template (étendre la MAP si besoin).
- **Gate G-CAISSE** (owner) : décider GATE-W6 (LOCK + waiver) ou maintenir Defer.
- **Checkpoint (HARD STOP avant W2)** : :8766 répond ; **les catégories cibles existent dans le clone**
  (`ItemCategory::whereIn('name', [liste G-PROV])->count()` == attendu — sinon le clone a été bâti par
  `migrate:fresh` qui **réverte le catalogue Tacos**, cf. mémoire) ; **la sortie de provision affiche
  `done` pour CHAQUE catégorie et `skip`/`failed` pour AUCUNE** (`ProvisionCayenneWizardsCommand` skippe
  silencieusement une catégorie absente → W2 rendrait 0 wizard = faux-vert P0). Wizards provisionnés
  visibles en admin ; décisions gates loggées dans BRAIN §2.

### W1 — Re-edit UI wiring (non-frozen) — **AVANT tout E2E modify**
- Implémenter Sub 3.1 (edit affordance + pré-fill + PUT branch + Vitest).
- **Checkpoint** : Vitest re-edit PASS ; `ComposerPersonalPageTest` 16/16 ; visual modal pré-rempli :8766 ; 0 frozen diff ; BRAIN §2/§3.

### W2 — Builder→Borne E2E (visual-first)
- Sub 4.1 : compose→image→publish→render + modify→re-publish→reflect, **par catégorie réelle** (la liste G-PROV).
- **Checkpoint** : chaque catégorie capturée (page perso rendue, image OK, min/max, prix=backend) ; `kioskWizardGenericComposer.spec.js` PASS ; RED dispute clos.

### W3 — Caisse parité **[GATED G-CAISSE]**
- **Si LOCK** : Sub 5.1 (écrire `generic_choices` renderer sous `lock-plan`, flag ON sur :8766, parité visuelle+numérique). Commit frozen séparé, owner countersign.
- **Si Defer maintenu** : W3 **skippée**, documentée comme dette V1.0.X ; le GOAL converge sur borne seule + note explicite « parité caisse non livrée par décision owner ».
- **Checkpoint** : (LOCK) `posWizardComposerAware.spec.js` PASS + parité numérique borne=caisse=backend=ticket + frozen diff dans LOCK ; (Defer) entrée dette + BRAIN.

### W4 — Sync borne↔caisse↔KDS
- Sub 6.1 : definition sync (publish→surfaces) + runtime sync (wizard order→KDS→caisse, numeric integrity P0) + dégradation polling.
- **Checkpoint** : `ComposerPublishSyncTest` PASS + 3 surfaces même composition/total (set-equality) ; si W3 Defer, parité runtime testée borne+KDS seulement.

### W5 — Convergence adversariale
- Boucle `test-e2e` : 2 cycles consécutifs P0+P1=0, findings sets **identiques**.
- **Checkpoint final** : `CONVERGENCE_FINAL.md` + commit (paths explicites) + BRAIN §2/§3/§7 + Graphiti episode si significatif.

### Wave-interrupt protocol
Si interruption (usage/space/owner pause) : commit partiel `wip(<wave>): …` ; écrire
`reports/test-e2e/wizard-parity-2026-06-09/INTERRUPT_<wave>_<ts>.md` (last green SHA, last task,
next task, sub-agent reports) ; BRAIN §2 ; au resume → lire manifest + smoke 1-task.

### Convergence-failure protocol
3e heal-loop sur le même cluster → STOP, spawn `Plan` (« pourquoi le heal échoue ? pivot ou
escalation »), écrire `STUCK_<wave>_<ts>.md`, surfacer A/accept-doc B/pivot C/defer D/human — **ne pas auto-pick**.

---

## §G — OWNER GATES (WHO / WHAT / WHERE)

| Gate | Description | WHO | WHAT (artefact qui débloque) | WHERE | Status |
|---|---|---|---|---|---|
| **G-PROV** | Liste exacte des ~10 wizards à enregistrer + template chacun (MAP actuelle = 6/2) | Owner | liste catégorie→template confirmée (étend `ProvisionCayenneWizardsCommand::MAP` si besoin) | commit message + BRAIN §2 | **PENDING** |
| **G-CAISSE / GATE-W6** | Parité caisse = écrire renderer `generic_choices` dans `pos-wizard.js` FROZEN | Owner (physique) | LOCK doc countersigné (`GATE-W6-LOCK.md`) + waiver « design parfait » | LOCK §10 sign-off + commit tag | **PENDING** (Defer 2026-06-08, à reconfirmer §0.6) |
| **G-PROV-OP** | Provision sur la DB **opérante** (go-live) — **HORS scope E2E** | Owner | go décision go-live séparée | gate go-live distinct | **N/A ce GOAL** |

### Owner-gate-waiting protocol
- G-PROV PENDING → bloque W2 (besoin de la liste) mais **pas** W1 (UI générique). Lancer W1 d'abord.
- G-CAISSE PENDING → bloque **uniquement** W3. W0/W1/W2/W4-borne tournent en parallèle de la décision.
- Lister dans BRAIN §2 quelles vagues sont bloquées vs en cours.

---

## §R — RÉFÉRENCES
- Skills : `ultra-architect-planify`, `test-e2e`, `ultra-audit-profond`, `lock-plan` (frozen override).
- Docs : `CONSTITUTION.md`, `SYSTEM_MAP.md`, `SYNC_CONTRACT.md`, `PARALLEL_PROTOCOL.md`, `CLAUDE.md §7/§8/§12`.
- Mémoire : `reference_composer_wizard_hinge_2026-06-07.md` (HINGE + gate G-POS-COMPOSER-FLAG),
  `reference_e2e_harness_foodking_e2e_2026-06-07.md` (:8766 safe), `feedback_shared_worktree_git_commit_collision_2026-06-09.md`.
- Prédécesseur : `plans/GOAL_WIZARD_DYNAMIC_BUILDER_2026-06-08.md` + `reports/test-e2e/wizard-dynamic-2026-06-08/`.

## §F — FINAL RULE
DONE = **production-perfect**, pas « presque ». La borne rend chaque wizard de catégorie réelle
(créé ET modifié, image à jour), 0 label brut, parité numérique borne=backend=KDS, sync définition
+ runtime prouvée. La caisse atteint la **même parité SI** GATE-W6 est LOCKé ; sinon la non-parité
caisse est une **dette V1.0.X explicitement documentée par décision owner** — jamais un faux-vert.
Convergence = 2 cycles propres, findings identiques. Aucun retour avec état cassé.

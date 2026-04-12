# FoodKing — Doctrine des Gates de Qualite

**Statut :** Doctrine officielle — non implementee. Prete a brancher.
**Portee :** Les 5 gates decrits ci-dessous s'inserent dans la boucle
Claude -> Cursor -> Playwright definie par `AGENTS.md` et le pipeline
`cursor-executor-strict`. Ils completent les gates existants
(`vision-keeper`, `code-clarity-graph`, `playwright-smoke-gate`,
`integration-gate`) sans les remplacer.
**Regle :** Aucun gate ne peut etre implemente sans validation humaine
prealable de cette doctrine.

---

## Positionnement dans la boucle

```text
                         BOUCLE CLAUDE -> CURSOR -> PLAYWRIGHT
                         =====================================

  Claude                    Cursor (executor-strict)              Playwright / E2E
  ------                    ----------------------------          ----------------
  Plan JSON                 Step 1: Parse plan
                            Step 2: vision-keeper pre-flight
                            +---------------------------------+
                            | NOUVEAUX GATES (cette doctrine) |
                            | Step 2a: architecture-drift-gate|
                            | Step 2b: data-contract-gate     |
                            | Step 2c: security-regression-gate|
                            +---------------------------------+
                            Step 3: Implementation + code-clarity-graph
                            Step 4: Validations (lint, unit tests)
                            +---------------------------------+
                            | NOUVEAUX GATES (cette doctrine) |
                            | Step 4a: ux-heuristic-gate      |
                            | Step 4b: sync-consistency-gate  |
                            +---------------------------------+
                            Step 5: playwright-smoke-gate
                            Step 6: output_from_cursor.json        Tests E2E
                            Step 7: status-sync
  Review + verdict
```

Chaque gate produit un verdict autonome. Un seul verdict `BLOCKED`
suffit a empecher `status: done` dans `output_from_cursor.json`.

---

## Gate 1 — architecture-drift-gate

### Mission

Detecter toute deviation architecturale introduite par un changement
avant que le code ne soit ecrit. Compare la modification prevue contre
les contraintes figees dans `docs/ARCHITECTURE.md`, `docs/CORE_MODULES.md`
et les zones gelees.

Ce gate repond a la question : **"Est-ce que ce changement respecte les
frontieres, couches et dependances documentees ?"**

### Trigger

- Automatique : `cursor-executor-strict` Step 2a, apres `vision-keeper`.
- Manuel : quand `blast_radius` touche plus de 3 fichiers dans des
  modules differents, ou quand un fichier dans une zone gelee apparait
  dans `files_allowed`.

### Input

```json
{
  "task_id": "T-XXX",
  "files_allowed": ["..."],
  "blast_radius_modules": ["OrderService", "FrontendOrderService", "..."],
  "architecture_doc_hash": "sha256 de docs/ARCHITECTURE.md au moment du check",
  "frozen_zones_touched": []
}
```

### Output

```json
{
  "gate": "architecture-drift-gate",
  "task_id": "T-XXX",
  "timestamp": "ISO-8601",
  "verdict": "PASS | WARN | BLOCKED",
  "drift_detected": [],
  "frozen_zone_violations": [],
  "layer_boundary_violations": [],
  "dependency_violations": [],
  "recommendation": "",
  "escalate_to": "claude | human | none"
}
```

### Niveau de blocage

| Verdict | Effet sur le pipeline |
|---------|----------------------|
| `PASS` | Continuer normalement |
| `WARN` | Continuer, mais mentionner dans `output_from_cursor.json.risks_detected` |
| `BLOCKED` | Arret immediat. `status: blocked`. Escalade Claude obligatoire |

### Regles de detection

1. **Couche incorrecte** : un Controller contient de la logique metier
   (calcul prix, requete Eloquent complexe) → `WARN` minimum.
2. **Zone gelee touchee** : Stripe, Paypal, PushNotificationService,
   DashboardController, Delivery Boy → `BLOCKED`.
3. **Dependance critique modifiee** : Sanctum remplace par JWT, Spatie
   supprime, Pusher payload non retro-compatible → `BLOCKED`.
4. **Cross-module non declare** : le plan touche OrderService ET
   FrontendOrderService mais `blast_radius` ne les liste pas tous
   → `WARN`.
5. **Nouveau module sans plan** : creation d'un nouveau Service ou
   Model non mentionne dans `docs/ARCHITECTURE.md` → `WARN`.

### Relation avec Claude

- Claude definit `files_allowed` et `blast_radius` dans le plan.
- Si `BLOCKED` : Claude recoit le rapport de drift et doit reecrire
  le plan ou demander une exception humaine.
- Claude peut pre-approuver un drift dans le plan JSON via un champ
  `architecture_exception: "reason"`.

### Relation avec Cursor

- Cursor execute le gate avant toute edition de code (Step 2a).
- Cursor ne peut pas ignorer un `BLOCKED`.
- Cursor propage le verdict dans `output_from_cursor.json`.

### Place exacte

**Step 2a** — apres `vision-keeper` (Step 2), avant implementation (Step 3).

---

## Gate 2 — data-contract-gate

### Mission

Verifier que les contrats de donnees (schemas DB, payloads API, structures
Eloquent) restent coherents apres un changement. Detecte les ruptures
de contrat entre backend et frontend, entre `Order` et `FrontendOrder`,
et entre l'API documentee et l'API reelle.

Ce gate repond a la question : **"Est-ce que les donnees qui entrent,
sortent et sont stockees respectent les contrats documentes ?"**

### Trigger

- Automatique : Step 2b, quand `files_allowed` contient des fichiers
  dans `app/Models/`, `app/Http/Requests/`, `app/Rules/`,
  `database/migrations/`, `app/Http/Resources/`, ou `routes/api.php`.
- Manuel : quand un changement modifie `$fillable`, `$casts`,
  `$hidden`, une migration, une FormRequest, ou un Resource.

### Input

```json
{
  "task_id": "T-XXX",
  "models_touched": ["Order", "FrontendOrder", "OrderItem"],
  "migrations_added": [],
  "requests_touched": ["PosOrderRequest"],
  "resources_touched": [],
  "api_routes_touched": false,
  "api_map_doc_hash": "sha256 de docs/API_MAP.md"
}
```

### Output

```json
{
  "gate": "data-contract-gate",
  "task_id": "T-XXX",
  "timestamp": "ISO-8601",
  "verdict": "PASS | WARN | BLOCKED",
  "fillable_drift": [],
  "cast_drift": [],
  "migration_risks": [],
  "request_validation_gaps": [],
  "api_contract_breaks": [],
  "cross_model_inconsistencies": [],
  "recommendation": "",
  "escalate_to": "claude | human | none"
}
```

### Niveau de blocage

| Verdict | Effet |
|---------|-------|
| `PASS` | Continuer |
| `WARN` | Continuer + documenter dans `risks_detected` + mettre a jour `docs/API_MAP.md` ou `docs/DATABASE_SCHEMA_CORE.md` |
| `BLOCKED` | Migration destructive sans rollback, ou rupture de contrat API public → arret + Claude |

### Regles de detection

1. **`$fillable` desynchronise** : `FrontendOrder.$fillable` n'inclut
   pas un champ present dans `Order.$fillable` (ou inversement) quand
   ils partagent la meme table → `WARN`.
2. **Migration sans rollback** : `down()` vide ou absent → `WARN`.
   Migration destructive (`dropColumn` sur colonne utilisee) → `BLOCKED`.
3. **Validation relaxee** : `ValidJsonOrder` ou `PosOrderRequest` perd
   une regle `required` ou `numeric` → `BLOCKED` (risque corruption).
4. **API non documentee** : nouvelle route dans `routes/api.php` sans
   entree correspondante dans `docs/API_MAP.md` → `WARN`.
5. **`$casts` incoherent** : `source` caste differemment entre
   `Order` et `FrontendOrder` → `WARN`.

### Relation avec Claude

- Sur `BLOCKED` : Claude analyse la rupture de contrat et decide si
  elle est intentionnelle (mise a jour docs) ou accidentelle (revert).
- Claude peut declarer une `breaking_change: true` dans le plan pour
  autoriser une rupture controlee.

### Relation avec Cursor

- Cursor execute le gate apres `architecture-drift-gate` (Step 2b).
- Cursor ajoute les `WARN` dans `risks_detected`.
- Sur `BLOCKED` : Cursor emet `status: blocked` et n'edite aucun fichier.

### Place exacte

**Step 2b** — apres `architecture-drift-gate` (Step 2a), avant
implementation (Step 3).

---

## Gate 3 — security-regression-gate

### Mission

Empecher toute regression de securite par rapport aux protections
documentees dans `docs/SECURITY_NOTES.md`, `docs/AUTHZ_MATRIX.md`
et la liste des corrections majeures de `docs/PROJECT_CONTINUITY_AND_VISION.md`.

Ce gate repond a la question : **"Est-ce que ce changement ne casse pas
une protection de securite deja en place et testee ?"**

### Trigger

- Automatique : Step 2c, quand `files_allowed` contient des fichiers
  dans `app/Http/Middleware/`, `app/Http/Controllers/Auth/`,
  `routes/api.php`, `config/sanctum.php`, `config/auth.php`,
  `app/Services/OrderService.php`, `app/Services/FrontendOrderService.php`,
  `app/Rules/`.
- Manuel : quand `blast_radius` touche auth, pricing, abilities ou
  isolation branche.

### Input

```json
{
  "task_id": "T-XXX",
  "security_zones_touched": ["auth", "pricing", "branch_isolation"],
  "corrections_at_risk": [1, 2, 5, 11],
  "security_notes_hash": "sha256 de docs/SECURITY_NOTES.md",
  "authz_matrix_hash": "sha256 de docs/AUTHZ_MATRIX.md"
}
```

`corrections_at_risk` fait reference aux numeros de la section
"Corrections majeures a ne pas regresser" de `docs/PROJECT_CONTINUITY_AND_VISION.md`.

### Output

```json
{
  "gate": "security-regression-gate",
  "task_id": "T-XXX",
  "timestamp": "ISO-8601",
  "verdict": "PASS | WARN | BLOCKED",
  "regressions_detected": [],
  "corrections_violated": [],
  "authz_boundary_violations": [],
  "ssot_pricing_risk": false,
  "branch_isolation_risk": false,
  "recommendation": "",
  "mandatory_tests": [],
  "escalate_to": "claude | human | none"
}
```

### Niveau de blocage

| Verdict | Effet |
|---------|-------|
| `PASS` | Continuer |
| `WARN` | Continuer + tests obligatoires specifies dans `mandatory_tests` |
| `BLOCKED` | Regression confirmee sur une correction documentee → arret + Claude + Human |

### Regles de detection

1. **SSOT prix casse** : `OrderService.posOrderStore` ou
   `FrontendOrderService.myOrderStore` utilise un prix du payload client
   au lieu de `Item::find()` → `BLOCKED`.
2. **Notification dans transaction** : dispatch de Job ou Event
   a l'interieur d'un `DB::transaction()` → `BLOCKED`.
3. **Auth relaxee** : route admin accessible sans `auth:sanctum` ou
   sans `x-api-key` → `BLOCKED`.
4. **Isolation branche cassee** : suppression de `BranchScope`,
   `withoutGlobalScope` sur une route non-admin → `BLOCKED`.
5. **Transition de statut non unidirectionnelle** : ajout d'un chemin
   retour (ex: DELIVERED → PREPARING) → `BLOCKED`.
6. **`env()` nu dans middleware** : utilisation de `env()` au lieu
   de `config()` dans un middleware → `WARN`.
7. **Abilities kiosk etendues** : ajout d'abilities au-dela de
   `kiosk:order` sur un token machine → `BLOCKED`.
8. **Correction documentee supprimee** : une des 12 corrections de
   `docs/PROJECT_CONTINUITY_AND_VISION.md` n'est plus dans le code → `BLOCKED`.

### Relation avec Claude

- `BLOCKED` = Claude doit analyser la regression et produire un
  correctif ou une justification avant que Cursor ne reprenne.
- Claude peut declarer `security_exception: "reason"` dans le plan
  pour un cas conscient et documente.

### Relation avec Cursor

- Cursor execute le gate en Step 2c, apres les deux gates precedents.
- Sur `WARN` : Cursor ajoute les tests listes dans `mandatory_tests`
  a sa liste de validations Step 4.
- Sur `BLOCKED` : arret total, aucune edition.

### Place exacte

**Step 2c** — apres `data-contract-gate` (Step 2b), avant
implementation (Step 3).

---

## Gate 4 — ux-heuristic-gate

### Mission

Verifier que les modifications UI respectent les heuristiques UX
validees pour FoodKing : wizard fonctionnel, totaux visibles, feedback
utilisateur present, flux coherent par surface (POS, KDS, OSS, Kiosk).

Ce gate repond a la question : **"Est-ce que l'interface reste
utilisable et coherente apres ce changement ?"**

### Trigger

- Automatique : Step 4a (apres implementation), quand `files_changed`
  contient des fichiers dans `resources/js/components/`,
  `public/js/pos-wizard.js`, `public/css/`.
- Aucun trigger si le changement est purement backend.

### Input

```json
{
  "task_id": "T-XXX",
  "surface": "pos | kds | oss | kiosk | admin",
  "components_touched": ["PosComponent.vue", "pos-wizard.js"],
  "ui_changes_description": "..."
}
```

### Output

```json
{
  "gate": "ux-heuristic-gate",
  "task_id": "T-XXX",
  "timestamp": "ISO-8601",
  "verdict": "PASS | WARN | BLOCKED",
  "heuristic_violations": [],
  "surface_consistency_issues": [],
  "accessibility_issues": [],
  "playwright_level_override": "none | smoke | critical-flow",
  "recommendation": "",
  "escalate_to": "claude | human | none"
}
```

### Niveau de blocage

| Verdict | Effet |
|---------|-------|
| `PASS` | Continuer |
| `WARN` | Continuer + `playwright_level_override` remonte le niveau de test E2E si necessaire |
| `BLOCKED` | Flux utilisateur casse (wizard innavigable, paiement impossible, KDS illisible) → arret |

### Heuristiques evaluees

**POS (caisse) :**
1. Le wizard affiche l'etape courante (badge X/Y).
2. Le total se met a jour en temps reel pendant le wizard.
3. Le bouton paiement est inactif si le panier est vide.
4. Le token est valide avant de soumettre le paiement.
5. L'instruction ticket contient VIANDES, SUPPLEMENTS, FORMULE.

**KDS (cuisine) :**
1. Les commandes sont visibles dans les 30 secondes.
2. Les boutons de changement de statut sont clairement identifies.
3. Le scope branche est affiche.

**OSS (ecran client) :**
1. L'ecran est en lecture seule (aucun bouton d'action).
2. Le `queue_number` est affiche en grand.
3. Les statuts PREPARING et PREPARED sont visuellement distincts.

**Kiosk (borne) :**
1. Le type de commande (Emporter/Sur place) est demande en premier.
2. Le wizard affiche une barre de progression.
3. La confirmation apres paiement est claire et visible.
4. L'ecran d'idle se reinitialise apres 3 minutes.
5. L'upsell dessert apparait si absent du panier.

**Transversal :**
1. Les zones tactiles mesurent au moins 44x44px.
2. Les contrastes sont suffisants (pas de texte clair sur fond clair).
3. Les messages d'erreur sont comprehensibles (pas de stacktrace).

### Relation avec Claude

- `WARN` avec `playwright_level_override` : Claude prend en compte
  la recommandation dans sa review. Si Playwright confirme le probleme,
  Claude demande un correctif.
- `BLOCKED` sur un flux casse : Claude analyse si c'est une regression
  ou un changement intentionnel.

### Relation avec Cursor

- Cursor execute le gate apres implementation (Step 4a).
- Le `playwright_level_override` peut elever le niveau du
  `playwright-smoke-gate` mais jamais le baisser.
- Sur `BLOCKED` : `status: partial` dans `output_from_cursor.json`.

### Place exacte

**Step 4a** — apres validations techniques (Step 4), avant
`playwright-smoke-gate` (Step 5).

---

## Gate 5 — sync-consistency-gate

### Mission

Verifier la coherence de synchronisation entre les surfaces apres un
changement : POS ↔ KDS ↔ OSS ↔ Kiosk. S'assurer que les events,
broadcasts et polling restent fonctionnels et coherents.

Ce gate repond a la question : **"Est-ce que toutes les surfaces
verront les bonnes donnees au bon moment apres ce changement ?"**

### Trigger

- Automatique : Step 4b, quand `files_changed` contient des fichiers
  dans `app/Events/`, `app/Jobs/`, `app/Services/OrderService.php`,
  `app/Services/FrontendOrderService.php`,
  `app/Services/KitchenDisplaySystemOrderService.php`,
  ou des composants Vue qui ecoutent des events (`CustomEvent`, Echo).
- Manuel : quand un changement touche les transitions de statut,
  `queue_number`, ou les broadcasts.

### Input

```json
{
  "task_id": "T-XXX",
  "event_chain_modified": true,
  "events_touched": ["OrderCreated", "OrderStatusChanged"],
  "jobs_touched": ["SendOrderGotPush"],
  "services_touched": ["OrderService"],
  "broadcast_channels_affected": [],
  "polling_endpoints_affected": []
}
```

### Output

```json
{
  "gate": "sync-consistency-gate",
  "task_id": "T-XXX",
  "timestamp": "ISO-8601",
  "verdict": "PASS | WARN | BLOCKED",
  "event_chain_breaks": [],
  "orphan_dispatches": [],
  "missing_dispatches": [],
  "transaction_boundary_violations": [],
  "cross_surface_inconsistencies": [],
  "queue_number_coherence": "intact | at_risk | broken",
  "recommendation": "",
  "mandatory_tests": [],
  "escalate_to": "claude | human | none"
}
```

### Niveau de blocage

| Verdict | Effet |
|---------|-------|
| `PASS` | Continuer |
| `WARN` | Continuer + tests de sync obligatoires specifies dans `mandatory_tests` |
| `BLOCKED` | Chaine de sync cassee (KDS ne voit plus les commandes POS, OSS desynchronise) → arret |

### Regles de detection

1. **Event orphelin** : `OrderStatusChanged` dispatche mais aucun
   listener ne le consomme → `WARN`.
2. **Dispatch manquant** : `posOrderStore` cree une commande sans
   dispatcher `OrderCreated` → `BLOCKED`.
3. **Dispatch dans transaction** : un Event ou Job est dispatche a
   l'interieur de `DB::transaction()` → `BLOCKED`.
4. **Broadcast payload modifie** : le payload d'un event broadcast
   change de structure sans retro-compatibilite → `BLOCKED`.
5. **`queue_number` incoherent** : la logique `lockForUpdate` est
   modifiee ou contournee → `BLOCKED`.
6. **Polling endpoint casse** : un endpoint KDS ou OSS retourne une
   structure differente → `WARN`.
7. **CustomEvent non emis** : un composant Vue attend un
   `realtime-order-update` mais le backend ne l'emet plus → `BLOCKED`.
8. **Flux unidirectionnel casse** : l'OSS tente d'ecrire ou le KDS
   bypass une transition → `BLOCKED`.

### Relation avec Claude

- `BLOCKED` sur une chaine de sync cassee : Claude doit mapper la
  chaine complete (source → event → job → broadcast → listener) et
  identifier le maillon manquant.
- Claude peut declarer `sync_exception: "reason"` pour un changement
  intentionnel du flux de synchronisation.

### Relation avec Cursor

- Cursor execute le gate apres `ux-heuristic-gate` (Step 4b).
- Les `mandatory_tests` sont ajoutes a la validation local-validation.
- Sur `BLOCKED` : `status: blocked`, aucun test ne compense une
  chaine de sync cassee.

### Place exacte

**Step 4b** — apres `ux-heuristic-gate` (Step 4a), avant
`playwright-smoke-gate` (Step 5).

---

## Resume : matrice des 5 gates

| Gate | Position | Phase | PASS | WARN | BLOCKED |
|------|----------|-------|------|------|---------|
| architecture-drift-gate | Step 2a | Pre-implementation | Continuer | Risque note | Arret + Claude |
| data-contract-gate | Step 2b | Pre-implementation | Continuer | Risque note + docs | Arret + Claude |
| security-regression-gate | Step 2c | Pre-implementation | Continuer | Tests obligatoires | Arret + Claude + Human |
| ux-heuristic-gate | Step 4a | Post-implementation | Continuer | Playwright eleve | Arret (flux casse) |
| sync-consistency-gate | Step 4b | Post-implementation | Continuer | Tests sync obligatoires | Arret (chaine cassee) |

---

## Integration avec les gates existants

| Gate existant | Relation avec les nouveaux gates |
|---------------|----------------------------------|
| `vision-keeper` | S'execute AVANT tous les nouveaux gates (Step 2). Les nouveaux gates ne contredisent jamais vision-keeper. Si vision-keeper bloque, les nouveaux gates ne s'executent pas. |
| `code-clarity-graph` | S'execute PENDANT l'implementation (Step 3). Independant des nouveaux gates. |
| `playwright-smoke-gate` | S'execute APRES les nouveaux gates (Step 5). `ux-heuristic-gate` peut elever le niveau Playwright mais jamais le baisser. |
| `integration-gate` | Invoque via S5 si un gate detecte une dependance non approuvee. Complementaire. |
| `cursor-executor-strict` | Orchestrateur de tous les gates. Les nouveaux gates s'inserent dans sa sequence numerotee. |

---

## Sequence complete avec tous les gates

```text
cursor-executor-strict — sequence complete
==========================================

Step 1:  Parse plan JSON
Step 2:  vision-keeper pre-flight
Step 2a: architecture-drift-gate        [NOUVEAU]
Step 2b: data-contract-gate             [NOUVEAU]
Step 2c: security-regression-gate       [NOUVEAU]
Step 3:  Implementation + code-clarity-graph
Step 4:  Validations (lint, unit tests)
Step 4a: ux-heuristic-gate              [NOUVEAU]
Step 4b: sync-consistency-gate          [NOUVEAU]
Step 5:  playwright-smoke-gate
Step 6:  output_from_cursor.json
Step 7:  status-sync
```

Tout verdict `BLOCKED` a n'importe quelle etape arrete le pipeline.
Un `WARN` est cumulatif : les risques notes et tests supplementaires
se propagent jusqu'a `output_from_cursor.json`.

---

## Champs ajoutes a output_from_cursor.json

Quand les gates sont actifs, les champs suivants s'ajoutent a la sortie
standard de `cursor-executor-strict` :

```json
{
  "gates_results": {
    "architecture_drift": { "verdict": "PASS", "drift_detected": [] },
    "data_contract": { "verdict": "PASS", "contract_breaks": [] },
    "security_regression": { "verdict": "PASS", "regressions": [] },
    "ux_heuristic": { "verdict": "PASS", "violations": [] },
    "sync_consistency": { "verdict": "PASS", "chain_breaks": [] }
  },
  "gates_blocked": false,
  "gates_warnings": []
}
```

`gates_blocked: true` force `status: blocked` dans la sortie principale.
`gates_warnings` est un tableau de strings resumes pour le rapport Claude.

---

## Regles d'implementation future

1. Chaque gate sera implemente comme un skill Cursor autonome dans
   `.cursor/skills/[gate-name]/SKILL.md`.
2. Chaque gate lira les documents de reference a chaque invocation
   (pas de cache de contenu — le hash suffit pour detecter les changements).
3. Les verdicts sont des strings strictes : `PASS`, `WARN`, `BLOCKED`.
   Pas de variantes, pas de scores numeriques.
4. Les gates pre-implementation (2a, 2b, 2c) sont statiques : ils
   analysent le plan et les fichiers concernes sans executer de code.
5. Les gates post-implementation (4a, 4b) peuvent lire les fichiers
   modifies pour verifier les heuristiques.
6. Aucun gate ne modifie de fichier. Lecture et verdict uniquement.
7. L'ordre des gates est fixe. Pas de reordonnancement sans mise a
   jour de cette doctrine et validation humaine.

---

*Doctrine officielle FoodKing. Ce document ne constitue pas une
implementation. Il definit les contrats, triggers, entrees, sorties et
niveaux de blocage de chaque gate pour branchement futur dans le
pipeline cursor-executor-strict.*

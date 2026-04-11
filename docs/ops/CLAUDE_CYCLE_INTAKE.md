# Claude Cycle Intake — FoodKing

**Statut :** Référence opérationnelle active
**Usage :** Standardise ce que `00_ORCHESTRATOR` doit recevoir avant de planifier ou juger un cycle
**Complète :** `CLAUDE.md`, `MEMORY.md`, `docs/roles/00_ORCHESTRATOR_ROLE.md`
**Ne remplace pas :** `AGENTS.md`, `workflows/qa-loop.md`, `workflows/task-routing.md`

---

## 1. But

Ce document définit le **paquet d'entrée minimal** qu'un cycle Claude doit recevoir
pour être traité correctement sur FoodKing.

Sans intake structuré :
- le plan dérive
- le blast radius est sous-estimé
- le type de test est mal choisi
- la review finale est faible

L'intake doit rester **compact**, **actionnable**, **orienté risque**.

---

## 2. Quand l'utiliser

Utiliser ce format :
- avant tout nouveau plan Claude
- avant toute reprise d'un cycle interrompu
- avant toute review d'une exécution Cursor/Kimi
- avant toute convocation Claude après rapport Playwright / E2E
- avant toute analyse Bugbot significative

Ne pas l'utiliser pour :
- simple question ponctuelle sans effet sur le cycle
- clarification triviale sans plan ni verdict

---

## 3. Sources minimales à charger avant l'intake

### Toujours
- `CLAUDE.md`
- `MEMORY.md`
- `AGENTS.md`

### Selon la zone touchée
- `docs/PROJECT_CONTINUITY_AND_VISION.md`
- `docs/ARCHITECTURE.md`
- `docs/BUSINESS_RULES.md`
- `docs/ORDER_FLOW.md`
- `docs/DEVICE_FLOW.md`
- `docs/AUTHZ_MATRIX.md`
- `docs/SECURITY_NOTES.md`
- `docs/PLAYWRIGHT_MCP_OPS.md`

### Selon le cycle en cours
- `reports/planning/latest.md`
- `reports/execution/latest.md`
- `reports/review/latest.md`
- `reports/antigravity/latest.md`
- `reports/review/bugbot-latest.md` si présent

---

## 4. Paquet d'entrée obligatoire

Aucun cycle ne doit commencer sans ces champs.

| Champ | Obligatoire | Description |
|------|-------------|-------------|
| `cycle_mode` | Oui | `new-request` \| `post-execution-review` \| `post-playwright` \| `bugbot-review` \| `resume` |
| `human_request` | Oui | Demande exacte ou résumé fidèle |
| `orchestrator_question` | Oui | La question finale exacte à laquelle Claude doit répondre dans ce cycle |
| `objective` | Oui | Ce qui doit être vrai à la fin du cycle |
| `current_state` | Oui | État réel connu du système sur cette zone |
| `surfaces_touched` | Oui | `POS`, `Kiosk`, `KDS`, `OSS`, `Admin`, `Backend` |
| `critical_zones` | Oui | `pricing`, `auth`, `status`, `sync`, `ux`, `none` |
| `known_paths` | Oui | Fichiers/dossiers/services déjà identifiés |
| `constraints` | Oui | Contraintes métier, sécurité, architecture, UX |
| `out_of_scope` | Oui | Ce qui ne doit pas bouger |
| `required_docs` | Oui | Docs que Claude doit impérativement croiser |
| `available_evidence` | Oui | Tests, rapports, captures, logs déjà disponibles |
| `open_risks` | Oui | Risques déjà connus avant planification |
| `open_questions` | Oui | Questions non résolues qui peuvent bloquer le plan |
| `bugbot_status` | Oui | `absent` \| `present-not-reviewed` \| `reviewed` |
| `human_gate_needed` | Oui | `yes` \| `no` avec raison |
| `next_expected_output` | Oui | `plan` \| `review verdict` \| `clarification` |

### Exemples valides pour `orchestrator_question`

- `produce a plan`
- `judge execution quality`
- `decide if playwright-full-e2e or playwright-critical-flow is required`
- `review Bugbot findings`

---

## 5. Règles de remplissage

### 5.1 `current_state`
Doit décrire le **réel**, pas l'objectif.
Exemple correct :
- "Le POS gère déjà cash/card, mais la carte reste une note des 4 derniers chiffres."
Exemple incorrect :
- "Le POS devrait gérer le paiement correctement."

### 5.2 `orchestrator_question`
Ce champ doit être :
- **final** : il exprime la décision ou la production attendue à la fin du cycle
- **singulier** : une seule question principale, pas quatre objectifs mélangés
- **actionnable** : Claude doit pouvoir y répondre avec un seul type de sortie standard

Exemple correct :
- `judge execution quality and decide if the cycle can be approved`

Exemple incorrect :
- `analyze everything and maybe produce a plan or a verdict depending on what you think`

### 5.3 `critical_zones`
Utiliser seulement les zones réellement touchées :
- `pricing` : total, taxes, coupons, recalcul serveur
- `auth` : Sanctum, rôles, abilities, middleware, session
- `status` : transitions `PENDING -> ACCEPT -> PREPARING -> PREPARED -> DELIVERED`
- `sync` : POS/KDS/OSS/Kiosk, events/jobs, queue_number, refresh
- `ux` : navigation, wizard, panier, feedback, erreurs, cohérence cross-surface

### 5.4 `known_paths`
Toujours préférer des chemins réels :
- `app/Services/OrderService.php`
- `app/Services/FrontendOrderService.php`
- `routes/api.php`
- `resources/js/...`
- `reports/.../latest.md`

Pas de formulations vagues du type :
- "les fichiers backend"
- "les composants concernés"

### 5.5 `constraints`
Inclure au minimum quand applicable :
- prix recalculé côté serveur
- isolation stricte `branch_id`
- transitions de statuts non cassées
- notifications/jobs hors transaction DB
- pas de changement hors scope
- cohérence cross-surface

### 5.6 `bugbot_status`
Règle stricte :
- si `reports/review/bugbot-latest.md` existe, le champ ne peut pas être `absent`
- si présent et non traité, Claude doit le prendre en compte avant de poursuivre

---

## 6. Conditions de blocage immédiat à l'intake

Claude ne doit pas planifier tant que ces conditions ne sont pas résolues :

1. `objective` flou ou non vérifiable
2. `orchestrator_question` absente ou ambiguë
3. `surfaces_touched` absentes
4. `critical_zones` absentes ou incohérentes
5. aucune doc canonique identifiée pour un sujet critique
6. `known_paths` vides alors que la demande implique du code réel
7. preuves antérieures contradictoires non clarifiées
8. `bugbot_status = present-not-reviewed` sur une zone critique
9. contradiction manifeste avec `CLAUDE.md` ou `MEMORY.md`

Dans ces cas, la sortie attendue n'est pas un plan mais une **demande de clarification structurée**.

---

## 7. Questions d'intake obligatoires pour l'orchestrateur

Avant de produire un plan ou un verdict, `00_ORCHESTRATOR` doit répondre :

1. Quelle est la question finale exacte de ce cycle (`orchestrator_question`) ?
2. Quel est le vrai problème à résoudre ?
3. Quelle surface utilisateur est réellement touchée ?
4. Quelles zones critiques sont touchées ?
5. Quel invariant FoodKing pourrait casser si on se trompe ?
6. Le besoin est-il localisé ou cross-module ?
7. Quel type de test est probablement requis ?
8. Quelle preuve manque déjà avant même d'implémenter ?
9. Faut-il une validation humaine avant de continuer ?

---

## 8. Format standard d'intake — manuel

```text
Cycle mode: [new-request | post-execution-review | post-playwright | bugbot-review | resume]

Human request:
[texte exact ou résumé fidèle]

Orchestrator question:
[the exact final question Claude must answer in this cycle]

Objective:
[1 à 3 phrases maximum, vérifiables]

Current state:
- ...
- ...

Surfaces touched:
- [POS | Kiosk | KDS | OSS | Admin | Backend]

Critical zones:
- [pricing | auth | status | sync | ux | none]

Known paths:
- [path]
- [path]

Constraints:
- ...
- ...

Out of scope:
- ...
- ...

Required docs:
- [path]
- [path]

Available evidence:
- [tests]
- [report]
- [logs]
- [screenshots]

Open risks:
- ...
- ...

Open questions:
- ...
- ...

Bugbot status:
[present-not-reviewed | reviewed | absent]

Human gate needed:
[yes | no] — [why]

Next expected output:
[plan | review verdict | clarification]
```

---

## 9. Intake minimal par type de cycle

### `new-request`
Minimum :
- `human_request`
- `orchestrator_question`
- `objective`
- `surfaces_touched`
- `critical_zones`
- `constraints`
- `required_docs`

### `post-execution-review`
Minimum :
- `orchestrator_question`
- `reports/execution/latest.md`
- plan correspondant
- preuves disponibles
- risques résiduels
- `bugbot_status`

### `post-playwright`
Minimum :
- `orchestrator_question`
- `reports/antigravity/latest.md`
- plan précédent
- zones échouées
- preuves visuelles / flows concernés

### `bugbot-review`
Minimum :
- `orchestrator_question`
- `reports/review/bugbot-latest.md`
- diff ou portée concernée
- niveau critique ou non
- décision attendue : `ACCEPT`, `REQUEST_FIX`, `ESCALATE`

---

## 10. Règle de cohérence avec MEMORY.md

L'intake doit être cohérent avec `MEMORY.md`.

Si l'intake contredit :
- les priorités stables
- les risques ouverts
- les décisions importantes récentes

alors Claude doit :
1. signaler la contradiction
2. demander si `MEMORY.md` est obsolète ou si la demande crée une exception
3. refuser de planifier tant que le conflit n'est pas clarifié sur une zone critique

---

## 11. Résultat attendu d'un intake réussi

Un intake réussi permet à `00_ORCHESTRATOR` de produire immédiatement :
- soit un plan opérationnel exploitable dans `reports/planning/latest.md`
- soit une review structurée dans `reports/review/latest.md`
- soit une clarification courte et bloquante si les données sont insuffisantes

Sans improvisation.
Sans browsing inutile.
Sans dilution du problème.

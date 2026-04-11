# Claude Cycle Output — FoodKing

**Statut :** Référence opérationnelle active
**Usage :** Standardise ce que `00_ORCHESTRATOR` doit rendre dans un cycle manuel réel
**Complète :** `CLAUDE.md`, `MEMORY.md`, `docs/roles/00_ORCHESTRATOR_ROLE.md`
**Ne remplace pas :** `reports/planning/latest.md`, `reports/review/latest.md`

---

## 1. But

Ce document définit la **sortie standard de Claude** selon le moment du cycle.

Claude ne doit pas rendre des réponses vagues du type :
- "ça semble bon"
- "on peut tester"
- "il faudrait peut-être"

Claude doit rendre un objet exploitable immédiatement dans le cycle réel :
- un **plan**
- un **verdict**
- une **clarification bloquante**
- un **brief Anti-Gravity**
- une **décision Bugbot**

---

## 2. Les 5 types de sortie autorisés

| Type | Quand | Fichier cible |
|------|------|---------------|
| `plan` | après intake / après analyse / après Anti-Gravity | `reports/planning/latest.md` |
| `review-verdict` | après exécution ou post-fix | `reports/review/latest.md` |
| `clarification-block` | intake insuffisant ou contradiction critique | réponse Claude + éventuellement `reports/review/latest.md` |
| `antigravity-brief` | quand verdict = `NEEDS_ANTIGRAVITY` | `reports/planning/latest.md` ou brief dédié |
| `bugbot-decision` | quand `bugbot-latest.md` est présent | `reports/review/latest.md` |

Aucun autre type de sortie ne doit être utilisé pour piloter un cycle.

---

## 3. Sortie type `plan`

### Quand
- nouvelle demande validée
- reprise après audit
- reprise après Anti-Gravity
- correction après verdict `NEEDS_FIX`

### Contenu obligatoire
- `Cycle owner`
- objectif clair
- périmètre
- surfaces touchées
- zones critiques
- blast radius
- risques connus
- test strategy (`Kimi-test` / `Anti-Gravity` / `No-test`)
- tâches ordonnées
- definition of done
- si applicable : `files_allowed`

### Format standard

```text
Cycle: [date ISO]
Type: plan
Cycle owner: [Claude | Human | Cursor | Anti-Gravity]

Objective:
[1 à 3 phrases]

Current state:
- ...
- ...

Surfaces:
- [POS | Kiosk | KDS | OSS | Admin | Backend]

Critical zones:
- [pricing | auth | status | sync | ux | none]

Blast radius:
- [path or module]
- [path or module]

Key constraints:
- ...
- ...

Risks to protect:
- ...
- ...

Test strategy:
[Kimi-test | Anti-Gravity | No-test]

Definition of done:
- ...
- ...
- ...

Execution tasks:
1. ...
2. ...
3. ...

files_allowed:
- [path]
- [path]

Human gate:
[GO required | clarification required | human exception required]

Next:
[Cursor/Kimi | Anti-Gravity | Human]
```

### Règles
- pas de plan sans type de test
- pas de plan cross-module flou
- pas de plan qui mélange implémentation, review et benchmark dans un seul bloc
- si le sujet touche auth/pricing/status/sync : le plan doit citer explicitement le risque

---

## 4. Sortie type `review-verdict`

### Quand
- après lecture de `reports/execution/latest.md`
- après correction
- après retour Kimi
- après traitement Bugbot
- après preuves complémentaires

### Contenu obligatoire
- exécution lue
- preuves disponibles
- scoring complet
- verdict
- `Confidence`
- axes faibles
- risques résiduels
- actions minimales
- prochain acteur

### Format standard

```text
Cycle: [date ISO]
Type: review-verdict

Execution read:
- reports/execution/latest.md
- [autres sources si applicables]

Evidence reviewed:
- [tests]
- [Playwright]
- [logs]
- [screenshots]
- [bugbot report if present]

Scoring:
  Architecture integrity:      [0-100]
  UX / flow quality:           [0-100]
  Business logic completeness: [0-100]
  Security / validation:       [0-100]
  Evidence strength:           [0-100]
  ---
  Global score:                [0-100]

Verdict:
[APPROVED | NEEDS_FIX | NEEDS_ANTIGRAVITY]

Confidence:
[high | medium | low]

Why:
- ...
- ...

Weak axes:
- [axis] — [score] — [reason]

Residual risks:
- ...
- ...

Minimal actions:
1. ...
2. ...

Next:
[Human validation | Cursor fix | Anti-Gravity | stop]
```

### Règles
- pas de verdict sans scoring
- pas de APPROVED si score global < 85
- pas de APPROVED si un axe < 70
- si evidence faible : l'impact sur le score doit être explicite
- si besoin de test critique non exécuté : pas de APPROVED
- `Confidence` mesure la confiance de Claude dans son verdict, pas la qualité intrinsèque du code

---

## 5. Sortie type `clarification-block`

### Quand
- intake incomplet
- contradictions documentaires
- bugbot critique non analysé
- preuves incohérentes
- objectif non vérifiable

### Format standard

```text
Cycle: [date ISO]
Type: clarification-block

Block reason:
- ...

Missing inputs:
- ...

Contradictions:
- ...

Cannot continue because:
- ...

Needed from human/orchestrator:
1. ...
2. ...

Next expected output:
[clarified intake | updated docs | explicit exception]
```

### Règle
Ce format bloque volontairement la progression.  
Il ne doit pas être utilisé comme un simple "besoin de plus d'infos" paresseux.  
Le blocage doit être **précis**, **limité**, **résoluble**.

---

## 6. Sortie type `antigravity-brief`

### Quand
- `NEEDS_ANTIGRAVITY`
- plan initial exige Anti-Gravity
- échec de preuve locale insuffisante sur flow critique

### Format standard

```text
Cycle: [date ISO]
Type: antigravity-brief

Why Anti-Gravity is required:
- ...

Flows to test:
1. ...
2. ...
3. ...

Expected proof:
- [state transitions]
- [screenshots]
- [console/network cleanliness]
- [visible outcome]

Regression risks to focus:
- ...
- ...

Pass condition:
- ...

Fail condition:
- ...

Back to Claude when:
- reports/antigravity/latest.md is written
```

### Règles
- citer des flows réels FoodKing, pas "tester l'application"
- préciser ce qui constitue une preuve suffisante
- préciser ce qui invalide immédiatement le flow

---

## 7. Sortie type `bugbot-decision`

### Quand
- `reports/review/bugbot-latest.md` existe
- Claude est convoqué pour décider

### Format standard

```text
Cycle: [date ISO]
Type: bugbot-decision

Bugbot file:
- reports/review/bugbot-latest.md

Decision:
[ACCEPT | REQUEST_FIX | ESCALATE]

Findings retained:
- [id] — [why retained]

Findings rejected:
- [id] — [why rejected]

Impact on current cycle:
- ...

Next:
[continue | fix plan | Anti-Gravity | human]
```

### Règle
Bugbot n'a aucune autorité.  
Claude conserve le dernier mot.  
Mais aucun finding critique pertinent ne doit être ignoré sans justification.

---

## 8. Couplage avec MEMORY.md

Après une sortie `review-verdict` ou `clarification-block`, Claude doit se demander :

Faut-il mettre à jour `MEMORY.md` parce que le cycle a révélé :
- un nouveau risque stable ?
- une décision stable ?
- une question désormais fermée ?
- une contradiction structurelle importante ?

Si oui, le signaler explicitement dans la sortie :
- `Memory update suggested: yes`
- `Memory update suggested: no`

---

## 9. Règle de style

Les sorties Claude doivent être :
- courtes mais complètes
- orientées décision
- sans théorie hors sujet
- sans longues narrations historiques
- sans vocabulaire vague

Mauvais :
- "ça semble correct"
- "globalement OK"
- "on verra après test"

Bon :
- "NEEDS_FIX — score global 78, evidence strength 62, sync non prouvée sur KDS/OSS."

---

## 10. Résultat attendu

Si ce document est suivi, chaque cycle manuel réel produit une sortie
Claude immédiatement exploitable par :
- l'humain
- Cursor/Kimi
- Anti-Gravity
- le futur bot pipeline

Sans ambiguïté de format.
Sans dilution de responsabilité.
Sans confusion entre plan, test et verdict.

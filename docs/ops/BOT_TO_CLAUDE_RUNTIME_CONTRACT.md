# Bot → Claude runtime contract — FoodKing

**Statut :** Référence opérationnelle — couplage repo local / bot / projet Claude  
**But :** Définir le contrat d’exécution exact de la boucle autonome, sans confondre mémoire stable et état de cycle.

---

## 1. Stable project knowledge vs runtime cycle state

| Catégorie | Rôle | Fraîcheur |
|-----------|------|-----------|
| **Stable project knowledge** | Invariants, vision, rôles, procédures, architecture documentée | Évolue par commits / mises à jour explicites de docs |
| **Runtime cycle state** | Où en est le cycle courant (plan, exécution, verdict) | Doit refléter le dépôt **maintenant**, pour ce cycle |

Claude ne doit pas inférer l’état du cycle à partir du seul contenu figé du projet Claude sans injection bot des fichiers runtime du cycle.

---

## 2. Stable project knowledge (in scope)

Le bot et les humains traitent comme **base de connaissance projet** (à lire / croiser, pas comme “état live du cycle”) :

- `CLAUDE.md`
- `MEMORY.md`
- `docs/**` (y compris sous-arbres ci-dessous)
- `docs/roles/**`
- `docs/ops/**`

---

## 3. Runtime cycle state (in scope)

Le bot doit traiter comme **état de cycle** (prioritaires pour la vérité opérationnelle du tour en cours) :

- `reports/planning/latest.md`
- `reports/execution/latest.md`
- `reports/review/latest.md`
- **Optionnel mais recommandé :** résumé de diff / liste de fichiers modifiés depuis le dernier point de vérité connu du cycle (ou depuis la base convenue), pour éviter les dérives de contexte.

---

## 4. Transport et source de vérité (obligatoire à respecter)

1. **La synchronisation GitHub Project n’est pas la couche de transport runtime** entre le dépôt local et Claude pour un cycle. Elle peut suivre le travail humain ou l’ordonnancement ; elle ne remplace pas l’injection des artefacts de cycle.
2. **Le bot doit injecter, à chaque cycle, les derniers fichiers runtime** (`reports/.../latest.md` et, si disponible, le résumé de changements) **directement dans le contexte Claude** (prompt, pièces jointes, ou mécanisme équivalent prévu par le pipeline).
3. **Les fichiers du projet Claude (Project knowledge / fichiers attachés au projet)** constituent une **mémoire de référence (baseline)**, pas l’état live du cycle : ils peuvent être en retard sur le working tree ou sur `main` sans que Claude le voie automatiquement.

---

## 5. Per-cycle payload to Claude

Ordre strict recommandé pour ce que le bot envoie **à chaque cycle** (du plus “vérité immédiate” au plus “cadre stable”) :

1. **Résumé de diff / fichiers touchés** (si produit par le bot pour ce cycle)  
2. **`reports/review/latest.md`** (verdict et scoring du tour précédent, si pertinent au mode de cycle)  
3. **`reports/execution/latest.md`** (preuves et résultat d’exécution du tour courant ou précédent, selon l’étape)  
4. **`reports/planning/latest.md`** (plan actif ou dernier plan valide, selon l’étape)  
5. **Intake / question orchestrateur** pour ce cycle (champs normalisés, ex. `docs/ops/CLAUDE_CYCLE_INTAKE.md`)  
6. **`MEMORY.md`** (décisions et risques stables récents)  
7. **`CLAUDE.md`** (principes et garde-fous)  
8. **Extraits ciblés de `docs/**`** uniquement si le cycle le exige (zones critiques, liens fournis explicitement dans le payload ou dans le plan)

> Règle : si une étape n’a pas de fichier (ex. pas encore de `execution`), le bot envoie les étapes disponibles dans le même ordre, sans inventer de contenu.

---

## 6. What Claude must trust first

Ordre de confiance **décroissant** (en l’absence de contradiction documentée résolue par l’humain) :

1. **Fichiers runtime injectés pour ce cycle** (`reports/planning/latest.md`, `reports/execution/latest.md`, `reports/review/latest.md`, diff résumé si fourni)  
2. **`CLAUDE.md`**  
3. **`MEMORY.md`**  
4. **`docs/**` (dont `docs/roles/**`, `docs/ops/**`)**  
5. **Ancienne mémoire de conversation / contexte chat** (indices seulement ; jamais source de vérité sur l’état repo ou le dernier verdict)

---

## 7. What must never be assumed

- **Le dépôt a changé ≠ Claude “sait” le changement** sans injection explicite ou relecture dans ce tour.  
- **Push vers GitHub ≠ synchronisation automatique dans le runtime projet Claude** pour le cycle en cours.  
- **GitHub connecté au projet ≠ conscience autonome et à jour des fichiers** ; pas de présupposé de fraîcheur des `latest.md` ni du working tree.  
- **Un plan ou un verdict “dans l’historique de chat” ≠ plan/verdict actuel** ; la vérité opérationnelle est dans les fichiers runtime injectés.

---

## 8. Compatibilité automation future

Ce contrat est volontairement **agnostique du détail d’implémentation** du bot (CLI, webhook, job CI, script local) : toute automatisation doit respecter la séparation §1, l’ordre §5, la hiérarchie de confiance §6, et les interdits §7. Les chemins peuvent être étendus (ex. rapports additionnels) **sans** retirer les trois `latest.md` du cœur runtime.

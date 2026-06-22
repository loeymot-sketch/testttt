# Office-Hours STOP — Checklist obligatoire avant CODE

> Adaptation FoodKing du YC playbook : "Si tu peux pas répondre, tu codes pas."
> Avant tout `Edit` / `Write` sur fichier business (`.php`, `.vue`, `.js`, `.ts`, `.blade.php`),
> répondre mentalement aux 6 questions ci-dessous. Si une seule réponse est "je ne sais pas"
> → retour à l'étape Plan avant de coder.

## Les 6 questions STOP

### 1. Mauvaise hypothèse de départ ?
- Le bug/feature est-il bien là où je le pense ? Ai-je vérifié `file:line` indépendamment ?
- L'API/contrat existant est-il vraiment celui que je crois ?

### 2. Sur-complexité inutile ?
- Cette feature/fix peut-elle être plus simple ? Ai-je évité la sur-abstraction ?

### 3. Edits orthogonaux ?
- Mon edit touche-t-il UNIQUEMENT le scope du Plan ? Ai-je rajouté du polishing non demandé ?

### 4. Impératif ou déclaratif ?
- Mon code décrit-il QUOI (déclaratif, ex: config flag) ou COMMENT (impératif, ex: chaîne if/else) ?
- Préférer déclaratif quand possible.

### 5. Feedback loop en place ?
- Comment vais-je savoir que mon fix marche ? Spec phpunit/vitest/playwright EXISTE ou écriture en parallèle ?

### 6. Scope minimal défini ?
- LOC ? Fichiers ? Si > 30 LOC OU > 3 fichiers → générer plan dédié pour Codex/Cursor (cf. memory `feedback_orchestrator_inline_edit_exception.md`).
- Hors frozen-zone (cf. memory `reference_frozen_zones.md`) ?

## Application

- Avant Edit/Write : mentalement répondre. Si "je ne sais pas" → STOP.
- Si > 30 LOC ou frozen-zone : créer plan Codex à la place d'edit direct.
- Si l'hypothèse est faible : faire 1 vérification (grep, curl, tinker) AVANT.

## Référence
- `docs/methodology/GSTACK_PIPELINE_2026-05-08.md` (méthodologie complète)
- garrytan/gstack (GitHub) inspiration

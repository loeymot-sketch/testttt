# EXECUTE V4 #5 — P13_AUDIT_REPORT_HYGIENE

TASK_ID: P13_AUDIT_REPORT_HYGIENE
WAVE: V4 (low-risk hygiene, no human gate)
RUNNER_MODE: single-session
PRIMARY_MODEL: composer (foodking-routine-implementer)
SOURCE_FINDING: F-VERIFY-10-02 (cf. `reports/review/VERIFY_10_BRANCH_ISOLATION_2026-04-20.md`)
RELATED_FINDING_HISTORY: cycle de revue où `reports/review/AUDIT_POS_110_ISOLATION_PERMS_SM_2026-04-19.md` a été commité à 0 octet et a dû être restauré (`AUDIT_POS_110_ISOLATION_PERMS_SM_2026-04-19.RESTORED.md`).

---

## Goal (résultat attendu)

Mettre en place une **garde de gouvernance** réutilisable qui empêche un humain (ou un agent) de commiter / pousser silencieusement un rapport d'audit `reports/review/AUDIT_*.md` ou `reports/review/VERIFY_*.md` à **0 octet**, et qui fournit en plus une commande manuelle de check à exécuter avant tout commit batch.

C'est de la **gouvernance pure / scripts versionnés**. Pas de modification d'application, pas de modification de schéma, pas de modification de hook git utilisateur (les hooks git restent locaux à la machine de l'utilisateur).

---

## Scope (FILES TOUCHED)

| Fichier | Type | Action |
|---|---|---|
| `scripts/check-audit-report-integrity.sh` | NEW | Script bash exécutable — pattern identique à `scripts/check-invariants.sh`. |
| `.cursor/skills/project-handoff/SKILL.md` | EDIT | Ajouter une section courte "Hygiène des rapports d'audit" qui pointe vers le nouveau script et explique l'usage. |

**SUBSYSTEMS_TOUCHED**: governance, scripts CI helpers, skill MD.
**SUBSYSTEMS_OFF_LIMITS**: `app/`, `routes/`, `database/migrations/`, `resources/js/`, `tests/`, `.git/hooks/` (NE PAS toucher), `package.json` (NE PAS toucher), composer.json (NE PAS toucher).
**INVARIANTS_AT_RISK**: aucun. Pas de code applicatif. Pas de hook git automatique installé.

---

## Pourquoi pas de hook git automatique

Le projet a déjà des hooks locaux (`.git/hooks/post-checkout`, `pre-push`, …) installés manuellement par l'utilisateur. Versionner un dossier `.githooks/` puis basculer `core.hooksPath` écraserait ses hooks personnels. Husky n'est pas dans `package.json`. Donc on livre **un script invoquable manuellement** que l'utilisateur peut intégrer dans son hook local s'il le souhaite — la décision d'auto-installation reste humaine.

---

## Spécification du script `scripts/check-audit-report-integrity.sh`

### Comportement

1. Cherche **tous** les fichiers correspondant à :
   - `reports/review/AUDIT_*.md`
   - `reports/review/VERIFY_*.md`
   - `reports/audit-orchestration/*.md`
2. Pour chacun, vérifie :
   - taille **>= 200 octets** (un rapport vraiment vide = 0 octet ; un en-tête markdown minimal = ~150 octets ; on garde 200 comme seuil utile).
3. Si **au moins un** fichier est sous le seuil → exit 1 avec liste des fichiers fautifs.
4. Sinon → exit 0 silencieux (sauf en mode `-v`).
5. Mode `-v` ou `--verbose` : afficher la liste des fichiers checked + leur taille.
6. Compatible bash 3.2 (macOS default) — pas de `mapfile`, pas de `readarray`.

### Pattern à reproduire

Suivre **exactement** le style de `scripts/check-invariants.sh` :
- en-tête commentaire avec source + usage
- `set -uo pipefail`
- détection TTY pour couleurs
- `REPO_ROOT` calculé depuis `${BASH_SOURCE[0]}`
- exit codes 0 / 1
- pas de dépendances exotiques (`stat -c` n'existe pas sur macOS — utiliser `wc -c < "$f"` qui marche partout)

### Squelette attendu

```bash
#!/usr/bin/env bash
# ------------------------------------------------------------------------------
# [P13_AUDIT_REPORT_HYGIENE / F-VERIFY-10-02] Audit/Verify report integrity guard
# ------------------------------------------------------------------------------
# Refuse de laisser passer un rapport reports/review/AUDIT_*.md (ou VERIFY_*.md
# ou reports/audit-orchestration/*.md) à 0 octet ou quasi-vide. Pattern observé
# le 2026-04-19 : AUDIT_POS_110_ISOLATION_PERMS_SM_2026-04-19.md commité vide.
#
# Usage:
#   bash scripts/check-audit-report-integrity.sh          # silencieux si OK
#   bash scripts/check-audit-report-integrity.sh -v       # verbeux
#
# Intégration optionnelle (utilisateur, manuel) : ajouter au pre-commit hook local.
# ------------------------------------------------------------------------------

set -uo pipefail
# ... (suivre pattern check-invariants.sh)

MIN_BYTES=200
```

---

## Spécification de l'édit `SKILL.md`

Ajouter, **à la fin** du fichier (après les lignes existantes, sans en supprimer aucune), une nouvelle section :

```markdown
## Hygiène des rapports d'audit

Avant tout commit qui ajoute / modifie un rapport sous `reports/review/AUDIT_*.md`,
`reports/review/VERIFY_*.md` ou `reports/audit-orchestration/*.md`, exécuter :

```bash
bash scripts/check-audit-report-integrity.sh -v
```

Le script échoue si un rapport est < 200 octets (cas observé : un rapport
restauré depuis un swap vide). Référence : `F-VERIFY-10-02` (cf.
`reports/review/VERIFY_10_BRANCH_ISOLATION_2026-04-20.md`).

Optionnel : intégrer dans le pre-commit hook local de l'utilisateur. Pas
d'auto-installation versionnée pour ne pas écraser les hooks personnels.
```

---

## VALIDATE (auto, le subagent l'exécute)

1. `chmod +x scripts/check-audit-report-integrity.sh` puis `bash scripts/check-audit-report-integrity.sh -v` → exit 0 attendu (état actuel : tous les rapports ont du contenu non trivial).
2. Test négatif rapide :
   ```bash
   touch /tmp/__fake_empty_audit.md
   cp /tmp/__fake_empty_audit.md reports/review/AUDIT_TEST_FAKE_EMPTY_DELETE_ME.md
   bash scripts/check-audit-report-integrity.sh ; echo "exit=$?"
   rm reports/review/AUDIT_TEST_FAKE_EMPTY_DELETE_ME.md
   ```
   → exit attendu = 1, fichier `AUDIT_TEST_FAKE_EMPTY_DELETE_ME.md` listé. **Bien penser à supprimer le fichier de test à la fin.**
3. `git status --short` après tout doit montrer **uniquement** :
   - `?? scripts/check-audit-report-integrity.sh` (nouveau)
   - ` M .cursor/skills/project-handoff/SKILL.md`
   Aucun autre fichier touché. Aucun fichier de test résiduel.
4. `bash scripts/check-invariants.sh` doit toujours passer (non-régression sur le script frère).

---

## REPORT_FILE attendu

`reports/execution/RUN_P13_AUDIT_REPORT_HYGIENE_2026-04-20.md`

Sections obligatoires :
- TASK_ID, WAVE, MODEL, START / END timestamps
- FILES TOUCHED (liste exacte avec lignes ajoutées / supprimées)
- VALIDATE OUTPUT (sortie réelle des 2 tests + git status final)
- AUDIT_PENDING : oui (Claude orchestrateur fera l'audit après)

---

## SCOPE_PRESSURE — interdits absolus

- ❌ Modifier `.git/hooks/`
- ❌ Créer `.githooks/`
- ❌ Modifier `package.json` ou installer `husky` / `lint-staged`
- ❌ Modifier `composer.json`
- ❌ Modifier `.github/workflows/`
- ❌ Toucher à un seul fichier sous `reports/review/` ou `reports/audit-orchestration/` (autre que création / suppression du fichier de test temporaire)
- ❌ Supprimer ou modifier le contenu existant de `SKILL.md` — uniquement **append** la nouvelle section.
- ❌ Faire un `git add` ou `git commit`. C'est l'utilisateur qui commitera.

Si tu détectes le besoin d'un de ces interdits → STOP, écris pourquoi dans le RUN report et termine en `BLOCKED_NEED_GATE`.

---

## SUCCESS CRITERIA

- Script créé, exécutable, exit 0 sur l'état actuel du repo.
- Test négatif (fichier vide) → exit 1, sortie listant le fichier fautif.
- `SKILL.md` enrichi d'une section au format demandé, sans suppression de contenu existant.
- Aucun autre fichier touché.
- Report écrit avec les 2 sorties de test inline.

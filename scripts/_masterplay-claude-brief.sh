#!/usr/bin/env bash
# Lance Claude terminal (Opus high) pour rédiger un execute_brief.md ultra-détaillé.
# Usage: bash scripts/_masterplay-claude-brief.sh <TASK_ID> <DESCRIPTION_COURTE>
set -uo pipefail
REPO_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$REPO_ROOT"

TASK_ID="${1:?Usage: TASK_ID DESCRIPTION}"
DESC="${2:-}"
MDIR="missions/$TASK_ID"
OUT="$MDIR/execute_brief.md"
INP="$MDIR/input.json"

if [[ ! -f "$INP" ]]; then
  echo "[FAIL] $INP missing"; exit 1
fi

PROMPT_FILE="$(mktemp -t fk-claude-brief-XXXXXX)"
trap 'rm -f "$PROMPT_FILE"' EXIT

cat > "$PROMPT_FILE" <<'PROMPT_END'
Tu es Claude, orchestrateur FoodKing. Ta mission : RÉDIGER en STDOUT le contenu Markdown d'un execute_brief ULTRA-DÉTAILLÉ destiné à un agent GPT-5.5-pro (codex-extension).

⚠️ IMPÉRATIF SORTIE : tu DOIS imprimer le contenu Markdown final directement dans ta réponse texte (qui sera capturée en stdout). N'utilise PAS la tool Write pour créer un fichier — le fichier sera écrit par le shell à partir de ta sortie. Utilise UNIQUEMENT Read et Grep pour lire le repo. Réponds par le Markdown final, rien d'autre, pas de phrase d'introduction, pas de "Voici le brief :".

CONTEXTE — Repo FoodKing (Laravel + Vue 3) :

CONTEXTE OBLIGATOIRE (lis-les avec ta tool Read avant d'écrire) :
- Plan parent : plans/PLAN_CAISSE_V1_GPT_MASTERPLAY_2026-04-25.md (cartographie file:line + invariants + structure des missions)
- Plan DAG autoritaire : plans/PLAN_CAISSE_V1_SUPER_MASTER_2026-04-25.md
- Discipline boucle : plans/masterplay/MASTERPLAY_DISCIPLINE.md
- Mission input courante : __MISSION_INPUT__
- Briefs de référence (pour STRUCTURE et DENSITÉ) : missions/CV1-M01-TRACEABILITY-MATRIX/execute_brief.md ET missions/CV1-M02-SENTINEL-BASELINE/execute_brief.md
- Invariants : .cursor/rules/project-invariants.mdc
- Format gates (si mission gates) : docs/gates/GATE_VERIFY_P0_FROZEN_CONSOLIDATED_2026-04-20.md + .cursor/rules/human-gates.mdc

TÂCHE : génère SEULEMENT le contenu Markdown final du brief (pas de bloc ``` englobant, pas de préambule du type "Voici le brief :", pas de signature).

DESCRIPTION COURTE DE LA MISSION : __DESCRIPTION__

EXIGENCES STRUCTURE (sections OBLIGATOIRES dans cet ordre) :
1. Titre : # EXECUTE BRIEF — <TASK_ID> (<MISSION_ID>)
2. ## INVIOLABLE — lectures obligatoires en ordre, allowlist stricte, off_limits, interdictions de gates
3. ## OBJECTIF EXACT — 3-6 lignes très denses, mesurable
4. ## CARTOGRAPHIE PRÉ-ANALYSÉE (file:line) — quand la mission touche du code, lister les ancrages réels (utilise Read et Grep pour vérifier les numéros de ligne actuels avant d'écrire)
5. ## SPÉCIFICATION DÉTAILLÉE — étape par étape, exhaustive, ce que GPT doit produire ligne par ligne
6. ## RÈGLES DE QUALITÉ — invariants applicables, qualité de diff, conventions
7. ## LIVRABLES dans output_codex.json — squelette JSON attendu (files_to_modify, code_blocks, risks, notes, execution_trace.delegation = "codex-extension", invariants_considered)
8. ## INTERDITS — liste explicite (frozen zones, hors allowlist, gate auto-approve, etc.)
9. ## SI BLOCAGE — quoi faire si test impossible / fichier absent / ambiguïté

EXIGENCES QUALITÉ :
- Densité : 5000-12000 caractères, pas de remplissage, pas de prose vague.
- Ancrages code réel obligatoires (file:line) chaque fois que la mission touche du code (UTILISE Read pour vérifier les numéros de ligne avant de les citer).
- Aucune décision de gate, aucune approbation auto.
- Respecter STRICTEMENT l'allowlist du input.json (citer les chemins exacts).
- Si la mission est CV1-M21A-QUICKWINS-LOT0 : LIS resources/js/components/admin/pos/PosComponent.vue ET resources/js/components/admin/kitchenDisplaySystem/KitchenDisplaySystemComponent.vue pour confirmer les line:numbers exacts (l'ancien plan dit L423-425 v-model='discount', L1668 this.discountReason, L732 import focustrap, L130 dir='ltr' Swiper) — vérifie avant d'écrire.
- Si la mission est CV1-M03-GATES-DRAFT : LIS docs/gates/GATE_VERIFY_P0_FROZEN_CONSOLIDATED_2026-04-20.md pour le format ; pour CHACUN des 8 gates (frozen_zones, fiscal_kiosk, payment_ledger A/B, kds_bump_authority, schema_migrations, offline_scope, web_payment_scope, stripe_cents_active) : trigger précis, options techniques chiffrées (2-3 par gate), recommandation technique non-décisive.
- Si la mission est CV1-M20-RUNBOOKS-SKELETON : pour chaque runbook, utilise Grep pour trouver les services/jobs/scripts FoodKing réels impliqués (app/Jobs, app/Services, scripts/, app/Listeners) et cite-les. Pas d'invention.

ANTI-PATTERN INTERDIT :
- Ne pas dupliquer le contenu d'input.json dans le brief.
- Ne pas inclure de phrases du type "Cette mission consiste à...".
- Ne pas écrire de code dans le brief sauf squelettes structurels (le brief PILOTE GPT, ne le remplace pas).

SORTIE : pure Markdown UTF-8, sans préambule.
PROMPT_END

# Substitute placeholders (sed-safe: TASK_ID/DESC ne contient pas de /)
sed -i.bak -e "s|__MISSION_INPUT__|$INP|g" -e "s|__DESCRIPTION__|$DESC|g" "$PROMPT_FILE"
rm -f "$PROMPT_FILE.bak"

echo "[claude] $TASK_ID → $OUT (prompt=$(wc -c < "$PROMPT_FILE")B)"

set +e
claude -p \
  --model "${FOODKING_CLAUDE_TERMINAL_MODEL:-claude-opus-4-7}" \
  --effort "${FOODKING_CLAUDE_TERMINAL_EFFORT:-high}" \
  --add-dir "$REPO_ROOT" \
  < "$PROMPT_FILE" \
  > "$OUT" 2>"$MDIR/_claude_brief.err.log"
RC=$?
set -e

SIZE=$(wc -c < "$OUT" 2>/dev/null | tr -d ' ' || echo 0)
echo "[claude] $TASK_ID rc=$RC bytes=$SIZE"
if [[ $RC -ne 0 || $SIZE -lt 2000 ]]; then
  echo "[FAIL] brief trop court ou erreur — voir $MDIR/_claude_brief.err.log"
  exit 1
fi
exit 0

/**
 * Génère le 2e prompt (auto-audit post-impl, sortie attendue Markdown) pour `codex exec`.
 * Usage: node scripts/codex-extension-self-audit-prompt.mjs <TASK_ID>
 */
import fs from "node:fs";
import path from "node:path";
import { fileURLToPath } from "node:url";

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), "..");
const taskId = process.argv[2] || "";
if (!taskId) {
  console.error("Usage: node scripts/codex-extension-self-audit-prompt.mjs <TASK_ID>");
  process.exit(1);
}
const p = path.join(root, "missions", taskId, "output_codex.json");
if (!fs.existsSync(p)) {
  console.error(`Missing ${p} — run the implementation step first.`);
  process.exit(1);
}
const json = fs.readFileSync(p, "utf8");
const pPlan = process.env.CODEX_PLAN_FILE || "";
const planHint = pPlan ? `\nPlan de référence (fichier) : ${pPlan}\n` : "";
const u = `Tu effectues l’**auto-audit GPT** (2e passe) d’un cycle FoodKing — **pas** l’audit Claude terminal.

**Contexte** : le JSON ci-dessous est la proposition d’implémentation (format agents/codex.prompt.txt) pour la mission \`${taskId}\`.
${planHint}

**JSON d’implémentation (à recouper)** :
\`\`\`json
${json}
\`\`\`

**Livrable** (Markdown uniquement, en français) :
# AUTO_AUDIT_GPT — ${taskId}

## 1. Conformité au plan / scope
(Énumérer manques ou dérives ; si scope élargi sans escale → **ESCALATE**)

## 2. Invariants FoodKing
Pour chacun : OK / RISQUE / N/A
- pricing_ssot (backend seul)
- order_status (enum, pas de strings)
- branch_id
- commit_before_dispatch
- frozen_zones
- order_service_symmetry (si un des deux services touché)

## 3. Verdict
Une ligne : \`VERDICT: PASS\` | \`VERDICT: NEEDS_FIX\` | \`VERDICT: ESCALATE\` + 1–3 phrases.
`;
process.stdout.write(u);

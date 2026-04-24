import fs from "node:fs";
import path from "node:path";
import { fileURLToPath } from "node:url";

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const root = path.resolve(__dirname, "..");
const taskId = process.argv[2] || "CHANGE_ME";

if (taskId === "CHANGE_ME" || !/^[A-Za-z0-9._-]+$/.test(taskId)) {
  console.error("Usage: npm run codex:prepare -- <TASK_ID>");
  process.exit(1);
}

const mdir = path.join(root, "missions", taskId);
fs.mkdirSync(mdir, { recursive: true });

const input = {
  instruction: "Décrire ici le périmètre d’implémentation complexe (aligné sur le plan) — sous-systèmes, critères d’acceptation, fichiers cibles.",
  plan_file: "plans/PLAN_<TASK_ID>_<date>.md",
  subsystems: [],
  acceptance: [],
};

const pInput = path.join(mdir, "input.json");
if (fs.existsSync(pInput)) {
  console.log(`(conserve) ${pInput} existe déjà.`);
} else {
  fs.writeFileSync(pInput, JSON.stringify(input, null, 2), "utf8");
  console.log(`OK ${pInput}`);
}

const stubs = {
  "graphiti_context.md":
    "Coller ici (session Cursor) le résumé des `search_memory_facts` / invariants (group `foodking`).\n" +
    "2–5 lignes suffisent en général, ou le plan ## PRIOR_CONTEXT recopié.\n",
  "plan_excerpt.md":
    "Coller ici l’extrait autorisant cette implé (SUBSYSTEMS_TOUCHED, objectifs, gates résolus le cas échéant).\n",
  "execute_brief.md":
    "Optionnel : contraintes EXECUTE courtes (références à execute-context) si utile au modèle API.\n",
  "cycle_snapshot.md":
    "Optionnel : extrait de `.cursor/ACTIVE_CYCLE.md` (PHASE, PRIMARY_MODEL, RUNNER_MODE).\n",
  "README.md": `Mission **${taskId}**

1. Remplir \`input.json\` + fichiers de contexte optionnels (Graphiti, plan, brief).
2. Lancer : \`npm run codex:complex -- ${taskId}\` (CLI \`codex\` + compte ChatGPT Pro). Legacy proxy : \`npm run codex:complex:proxy-legacy\`.
3. Appliquer le JSON produit : \`output_codex.json\` + lire \`reports/audit/GPT_SELF_AUDIT_${taskId}.md\` ; tracer \`EXECUTE_DELEGATION: codex-extension\` dans le rapport.
4. Voir : \`docs/orchestration/CODEX_API_DELEGATION.md\` ; instructions Codex : \`agents/codex-extension-instructions.md\`.\n`,
};

for (const [name, body] of Object.entries(stubs)) {
  const f = path.join(mdir, name);
  if (!fs.existsSync(f)) fs.writeFileSync(f, body, "utf8");
}

console.log(`Dossier mission: ${mdir}`);
console.log("Ensuite: npm run codex:complex -- " + taskId + "  (binaire 'codex' requis — compte ChatGPT Pro)");

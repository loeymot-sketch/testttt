/**
 * Construit le prompt unique pour `codex exec` (extension) : template agents/codex.prompt.txt
 * + missions/{TASK_ID}/input.json + fichiers aux.
 * Usage: node scripts/codex-extension-build-prompt.mjs <TASK_ID>
 */
import fs from "node:fs";
import path from "node:path";
import { fileURLToPath } from "node:url";

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const root = path.resolve(__dirname, "..");
const taskId = process.argv[2] || "";

if (!taskId || !/^[A-Za-z0-9._-]+$/.test(taskId)) {
  console.error("Usage: node scripts/codex-extension-build-prompt.mjs <TASK_ID>");
  process.exit(1);
}

const mdir = path.join(root, "missions", taskId);
const inputPath = path.join(mdir, "input.json");
if (!fs.existsSync(inputPath)) {
  console.error(`Missing: ${inputPath} — run: npm run codex:prepare -- ${taskId}`);
  process.exit(1);
}

const templatePath = path.join(root, "agents", "codex.prompt.txt");
const template = fs.readFileSync(templatePath, "utf8");

const auxNames = [
  "graphiti_context.md",
  "plan_excerpt.md",
  "execute_brief.md",
  "cycle_snapshot.md",
  "AGENTS_INVARIANTS_SNIP.md",
];
let aux = "";
for (const name of auxNames) {
  const p = path.join(mdir, name);
  if (fs.existsSync(p)) {
    aux += `### ${name}\n\n${fs.readFileSync(p, "utf8")}\n\n`;
  }
}
if (!aux.trim()) aux = "(Aucun fichier de contexte auxiliare — remplir graphiti_context.md / plan_excerpt.md recommandé.)\n";

const taskInput = fs.readFileSync(inputPath, "utf8");
const out = template.replace("{{AUX_CONTEXT}}", aux).replace("{{TASK_INPUT}}", taskInput);
process.stdout.write(out);

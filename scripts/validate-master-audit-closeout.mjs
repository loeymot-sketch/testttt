/**
 * Vérifie que les trois dernières lignes non vides d'un rapport Markdown sont
 * le bloc contractuel MASTER_AUDIT / SOFTWARE_DECISION / NEXT_CODEX.
 *
 * Usage :
 *   node scripts/validate-master-audit-closeout.mjs <rapport.md>
 *
 * Exit 0 = conforme, 1 = manquant ou valeur non autorisée.
 */
import fs from "node:fs";
import path from "node:path";
import { fileURLToPath } from "node:url";

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const root = path.resolve(__dirname, "..");

const f = process.argv[2];
if (!f) {
  console.error(
    "Usage: node scripts/validate-master-audit-closeout.mjs <rapport.md>"
  );
  process.exit(2);
}
const abs = path.isAbsolute(f) ? f : path.join(root, f);
if (!fs.existsSync(abs)) {
  console.error(`Fichier introuvable: ${abs}`);
  process.exit(2);
}
const lines = fs
  .readFileSync(abs, "utf8")
  .split(/\r?\n/)
  .map((line) => line.trim())
  .filter(Boolean);
const tail = lines.slice(-3);
const [verdict = "", decision = "", next = ""] = tail;

const v = /^MASTER_AUDIT_VERDICT:\s*(PASS|REWORK)$/.test(verdict);
const s =
  /^SOFTWARE_DECISION:\s*(READY_FOR_VA_SYS_00_05_EXECUTION|HOLD)$/.test(decision);
const n = /^NEXT_CODEX_MISSION:\s*\S.+$/.test(next);

const ok = v && s && n;
if (!ok) {
  console.error(
    JSON.stringify(
      {
        MASTER_AUDIT_VERDICT: v,
        SOFTWARE_DECISION: s,
        NEXT_CODEX_MISSION_nonempty: n,
        expected_position: "last_three_nonempty_lines",
        actual_tail: tail,
      },
      null,
      2
    )
  );
  process.exit(1);
}
console.log("closeout_ok");
process.exit(0);

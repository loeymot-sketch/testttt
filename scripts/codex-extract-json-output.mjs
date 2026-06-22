/**
 * Extrait l'artefact JSON attendu (schema codex.prompt.txt) depuis la sortie brute de `codex exec`.
 *
 * `codex exec` renvoie : prompt (echo) + raisonnement + bloc JSON final.
 * Stratégie (du plus fiable au plus permissif) :
 * 1) Dernier bloc ```json ... ``` (markdown fence)
 * 2) Dernier objet JSON balancé `{...}` qui parse ET contient une clé attendue
 *    (files_to_modify | code_blocks | implementation_steps | execution_trace)
 *    → cherche en remontant depuis la fin pour ignorer le prompt en tête.
 * 3) Si la sortie entière (trim) est du JSON, parse-la.
 *
 * Exit codes :
 *   0  = succès, fichier écrit
 *   2  = aucun JSON trouvé
 *   3  = JSON trouvé mais invalide
 *   4  = JSON valide mais sans clé attendue (probable artefact de prompt)
 */
import fs from "node:fs";

const rawPath = process.argv[2];
const outPath = process.argv[3];
if (!rawPath || !outPath) {
  console.error("Usage: node scripts/codex-extract-json-output.mjs <raw_path> <out_json_path>");
  process.exit(1);
}

const EXPECTED_KEYS = ["files_to_modify", "code_blocks", "implementation_steps", "execution_trace", "risks", "notes"];

function looksLikeOutput(obj) {
  if (!obj || typeof obj !== "object") return false;
  return EXPECTED_KEYS.some((k) => k in obj);
}

function writeAndExit(obj) {
  fs.writeFileSync(outPath, JSON.stringify(obj, null, 2), "utf8");
  process.exit(0);
}

const text = fs.readFileSync(rawPath, "utf8");

// 1) Last ```json ... ``` fence
const fenceRegex = /```json\s*([\s\S]*?)```/gi;
let lastFence = null;
let m;
while ((m = fenceRegex.exec(text)) !== null) lastFence = m[1];
if (lastFence) {
  try {
    const obj = JSON.parse(lastFence.trim());
    if (looksLikeOutput(obj)) writeAndExit(obj);
  } catch {/* try next */}
}

// 2) Find ALL balanced JSON objects, keep the LAST one that has expected keys.
//    Walk forward, stack-based, but collect candidates and pick the last valid one.
function findAllBalancedObjects(s) {
  const out = [];
  for (let i = 0; i < s.length; i++) {
    if (s[i] !== "{") continue;
    let depth = 0;
    let inStr = false;
    let esc = false;
    let q = "";
    for (let j = i; j < s.length; j++) {
      const c = s[j];
      if (inStr) {
        if (esc) { esc = false; continue; }
        if (c === "\\") { esc = true; continue; }
        if (c === q) inStr = false;
        continue;
      }
      if (c === '"') { inStr = true; q = c; continue; }
      if (c === "{") depth++;
      else if (c === "}") {
        depth--;
        if (depth === 0) {
          out.push({ start: i, end: j + 1, slice: s.slice(i, j + 1) });
          i = j; // continue scanning after this object
          break;
        }
      }
    }
  }
  return out;
}

const candidates = findAllBalancedObjects(text);
// Walk from last to first; first that parses AND looks like output wins.
for (let k = candidates.length - 1; k >= 0; k--) {
  try {
    const obj = JSON.parse(candidates[k].slice);
    if (looksLikeOutput(obj)) writeAndExit(obj);
  } catch {/* try previous */}
}

// 3) Whole-file JSON?
const t = text.trim();
if (t.startsWith("{") && t.endsWith("}")) {
  try {
    const obj = JSON.parse(t);
    if (looksLikeOutput(obj)) writeAndExit(obj);
  } catch {/* drop */}
}

// 4) Last-ditch: write the LAST parseable object even without expected keys (debug aid).
for (let k = candidates.length - 1; k >= 0; k--) {
  try {
    const obj = JSON.parse(candidates[k].slice);
    console.error(`[WARN] Found JSON without expected keys (${Object.keys(obj).slice(0,5).join(",")}). Writing anyway for inspection.`);
    fs.writeFileSync(outPath, JSON.stringify(obj, null, 2), "utf8");
    process.exit(4);
  } catch {/* */}
}

console.error("No usable JSON object in raw output.");
process.exit(2);

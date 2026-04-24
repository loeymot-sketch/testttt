/**
 * Extrait l’artefact JSON d’attendu (schema codex.prompt.txt) depuis la sortie brute.
 * 1) Bloc ```json ... ```
 * 2) parse du fichier entier si c’est du JSON
 * 3) Premier objet { ... } avec balance simple (peut échouer si chaînes contiennent { })
 */
import fs from "node:fs";

const rawPath = process.argv[2];
const outPath = process.argv[3];
if (!rawPath || !outPath) {
  console.error("Usage: node scripts/codex-extract-json-output.mjs <raw_path> <out_json_path>");
  process.exit(1);
}

const text = fs.readFileSync(rawPath, "utf8");

function tryParse(s) {
  return JSON.parse(s);
}

const fence = /```json\s*([\s\S]*?)```/i.exec(text);
if (fence) {
  try {
    const obj = tryParse(fence[1].trim());
    fs.writeFileSync(outPath, JSON.stringify(obj, null, 2), "utf8");
    process.exit(0);
  } catch {
    /* next */
  }
}

const t = text.trim();
if (t.startsWith("{")) {
  try {
    const obj = tryParse(t);
    fs.writeFileSync(outPath, JSON.stringify(obj, null, 2), "utf8");
    process.exit(0);
  } catch {
    /* next */
  }
}

const start = text.indexOf("{");
if (start < 0) {
  console.error("No JSON object found in raw output (no {).");
  process.exit(2);
}

let depth = 0;
let inStr = false;
let esc = false;
const quote = ['"', "'"];
let q = "";
for (let i = start; i < text.length; i++) {
  const c = text[i];
  if (inStr) {
    if (esc) {
      esc = false;
      continue;
    }
    if (c === "\\") {
      esc = true;
      continue;
    }
    if (c === q) inStr = false;
    continue;
  }
  if (c === '"' || c === "'") {
    inStr = true;
    q = c;
    continue;
  }
  if (c === "{") depth++;
  if (c === "}") {
    depth--;
    if (depth === 0) {
      const slice = text.slice(start, i + 1);
      try {
        const obj = tryParse(slice);
        fs.writeFileSync(outPath, JSON.stringify(obj, null, 2), "utf8");
        process.exit(0);
      } catch (e) {
        console.error("JSON parse error:", e.message);
        process.exit(3);
      }
    }
  }
}
console.error("Unbalanced { } or invalid JSON; éditer manuellement le fichier .raw");
process.exit(4);

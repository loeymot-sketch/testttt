import fs from "node:fs";
import path from "node:path";

/**
 * Snapshot pris à l'import (avant lecture des fichiers) : protège `export VAR=...` en shell.
 * Ordre : `.env` d'abord, puis `.env.codex` surcharges toute clé **non** présente au lancement
 * du processus (Laravel + secrets dans `.env`, profil fournisseur / Codex affiné dans `.env.codex`).
 */
const envAtProcessStart = { ...process.env };

function parseLine(t) {
  if (!t || t.startsWith("#") || !t.includes("=")) return null;
  const i = t.indexOf("=");
  const k = t.slice(0, i).trim();
  let v = t.slice(i + 1).trim();
  if ((v.startsWith('"') && v.endsWith('"')) || (v.startsWith("'") && v.endsWith("'")))
    v = v.slice(1, -1);
  return k ? { k, v } : null;
}

function loadEnvFile(f, { allowOverride = false } = {}) {
  if (!fs.existsSync(f)) return;
  const raw = fs.readFileSync(f, "utf8");
  for (const line of raw.split("\n")) {
    const t = line.trim();
    const p = parseLine(t);
    if (!p) continue;
    const { k, v } = p;
    if (allowOverride) {
      if (Object.prototype.hasOwnProperty.call(envAtProcessStart, k)) continue;
      process.env[k] = v;
    } else if (process.env[k] === undefined) {
      process.env[k] = v;
    }
  }
}

/**
 * @param {string} root - racine du dépôt (dossier contenant `.env` et `.env.codex`)
 */
export function loadProjectEnvForCodex(root) {
  loadEnvFile(path.join(root, ".env"), { allowOverride: false });
  loadEnvFile(path.join(root, ".env.codex"), { allowOverride: true });
}

/** Que le script soit dans `agents/`, `dist/codex-portable/` ou ailleurs : trouve le dépôt (package.json). */
export function resolveRepoRootFromScriptDir(scriptDir) {
  for (const rel of ["..", "../..", "../../..", "../../../.."]) {
    const p = path.resolve(scriptDir, rel);
    if (fs.existsSync(path.join(p, "package.json"))) return p;
  }
  return path.resolve(scriptDir, "..");
}

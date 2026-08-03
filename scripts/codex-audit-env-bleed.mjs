#!/usr/bin/env node
/**
 * Affiche (sans clés) quelles variables d’environnement peuvent rediriger
 * le client OpenAI / Codex vers un relais (ex. 401 sur hôte non OpenAI).
 *
 * Usage: node scripts/codex-audit-env-bleed.mjs
 *        npm run codex:audit-bleed
 */
import fs from "node:fs";
import path from "node:path";
import os from "node:os";
import { fileURLToPath } from "node:url";

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const repoRoot = path.join(__dirname, "..");

const KEYS = [
  "OPENAI_BASE_URL",
  "OPENAI_API_URL",
  "OPENAI_API_BASE",
  "OPENAI_API_KEY",
  "CODEX_API_KEY",
  "CODEX_API_BASE",
  "AZURE_OPENAI_ENDPOINT",
  "ANTHROPIC_BASE_URL",
];

function hostHint(val) {
  if (!val || val.length < 8) return "(vide ou trop court)";
  const v = val.trim();
  if (/^https?:\/\//i.test(v)) {
    try {
      return new URL(v).hostname;
    } catch {
      return "(URL invalide)";
    }
  }
  return "(non-URL)";
}

function maskKey(name) {
  if (!KEYS.includes(name) && !name.toUpperCase().includes("API_KEY")) return false;
  return true;
}

function scanEnv() {
  console.log("=== Variables d’environnement (processus courant) ===\n");
  const hit = [];
  for (const k of KEYS) {
    const v = process.env[k];
    if (v != null && String(v).length > 0) {
      hit.push([k, v]);
    }
  }
  if (hit.length === 0) {
    console.log("Aucune des clés ciblées n’est définie dans ce processus.\n");
  } else {
    for (const [k, v] of hit) {
      if (/_API_KEY$/.test(k) || k === "CODEX_API_KEY") {
        console.log(
          `${k} est défini → pour le PRIMARY (codex login + Pro) : **unset** (clé héritée = souvent /responses ou mauvaise portée)`
        );
        continue;
      }
      const h = hostHint(v);
      console.log(`${k}=…  → hôte détecté: ${h}`);
    }
    console.log("");
  }

  const other = Object.keys(process.env)
    .filter(
      (k) =>
        /OPENAI|CODEX_API|AZURE_OPENAI|GEMINI|ANTHROPIC|BASE_URL|API_KEY/i.test(k) &&
        !KEYS.includes(k)
    )
    .sort();
  if (other.length) {
    console.log("Autres noms (présents, valeur masquée) :", other.join(", "), "\n");
  }
}

function scanFileHint(p) {
  if (!fs.existsSync(p)) return null;
  const raw = fs.readFileSync(p, "utf8");
  const lines = raw.split("\n");
  const out = [];
  for (const line of lines) {
    const t = line.trim();
    if (!t || t.startsWith("#")) continue;
    for (const key of [
      "OPENAI_BASE_URL",
      "OPENAI_API_URL",
      "CODEX_API_BASE",
      "OPENAI_API_KEY",
      "CODEX_API_KEY",
    ]) {
      if (t.startsWith(`${key}=`)) {
        const isKey = /_API_KEY$/.test(key) || key === "CODEX_API_KEY";
        const val = t.slice(key.length + 1).replace(/^["']|["']$/g, "");
        if (isKey && !val) continue;
        out.push([
          key,
          isKey
            ? "(ligne non commentée : supprime si tu veux uniquement codex login)"
            : hostHint(val),
          path.basename(p),
        ]);
      }
    }
  }
  return out;
}

const localEnv = path.join(repoRoot, ".env");
const localCodex = path.join(repoRoot, ".env.codex");
const codexConfig = path.join(os.homedir(), ".codex", "config.toml");

console.log("FoodKing — audit « bleed » (routing OpenAI / Codex) — **aucune clé affichée**\n");
scanEnv();

console.log("=== Fichiers (si présents) : URL dérivée = hostname seulement ===\n");
for (const f of [localEnv, localCodex]) {
  const r = scanFileHint(f);
  if (r == null) {
    console.log(`${path.basename(f)} : (absent ou non lisible ici)\n`);
  } else if (r.length === 0) {
    console.log(`${path.basename(f)} : pas de OPENAI_*/CODEX_API_BASE en clair (ou commenté).\n`);
  } else {
    for (const [k, h, _b] of r) {
      console.log(`  ${f}: ${k} → ${h}`);
    }
    console.log("");
  }
}

if (fs.existsSync(codexConfig)) {
  const raw = fs.readFileSync(codexConfig, "utf8");
  const suspicious = /base_url|api\.openai|openai|http/i.test(raw);
  console.log(`~/.codex/config.toml : présent (${suspicious ? "contient 'base'/'url' — ouvre le fichier et repère un override" : "pas de mot-clé évident dans l’échantillon"}).`);
  const urlLike = raw.match(/https?:\/\/[^\s"'<>`]+/gi);
  if (urlLike) {
    for (const u of [...new Set(urlLike)].slice(0, 6)) {
      try {
        console.log(`  URL aperçue : ${new URL(u).hostname}`);
      } catch {
        /* ignore */
      }
    }
  }
} else {
  console.log("~/.codex/config.toml : absent\n");
}

console.log("\nRemède côté repo :  npm run codex:doctor  (terminal) + avant exec les scripts");
console.log("unset OPENAI_BASE_URL, CODEX_API_BASE, etc. (voir scripts/codex-sanitize-env-for-codex-cli.sh).");
console.log("Panneau Cursor « Reconnecting » : réglage Codex **dans Cursor**, pas seulement Models API.\n");

const bad =
  /tokenclub|subtp7eu3nc8|_tokenclub\./i;
let fatal = 0;
function fileContainsBad(p) {
  if (!fs.existsSync(p)) return false;
  return bad.test(fs.readFileSync(p, "utf8"));
}
if (fileContainsBad(codexConfig) || fileContainsBad(localEnv) || fileContainsBad(localCodex)) {
  console.log(
    "┌─ ACTION OBLIGATOIRE — relais hérité ───────────────────────────"
  );
  console.log("│ 1) ~/.codex/config.toml  2) .env / .env.codex  3) codex logout+login, npm run codex:doctor");
  console.log("└──────────────────────────────────────────────────────────────────\n"
  );
  fatal = 1;
}
function fileHasUncommentedApiKey(p) {
  if (!fs.existsSync(p)) return false;
  for (const line of fs.readFileSync(p, "utf8").split("\n")) {
    const t = line.trim();
    if (!t || t.startsWith("#")) continue;
    if (/^(OPENAI_API_KEY|CODEX_API_KEY)\s*=/i.test(t)) return true;
  }
  return false;
}
if (
  process.env.OPENAI_API_KEY ||
  process.env.CODEX_API_KEY ||
  fileHasUncommentedApiKey(localEnv) ||
  fileHasUncommentedApiKey(localCodex)
) {
  console.log(
    "┌─ ACTION OBLIGATOIRE — clé API en direct (hérite vers /v1/…, souvent conflit avec *codex login* Pro) —"
  );
  console.log("│ Supprime or commente OPENAI_API_KEY / CODEX_API_KEY dans .env et .env.codex ; `unset` dans le shell actuel.");
  console.log("│ Puis: codex auth logout  &&  codex login  (ChatGPT) — npm run codex:doctor");
  console.log("└──────────────────────────────────────────────────────────────────\n"
  );
  fatal = 1;
}
process.exit(fatal);

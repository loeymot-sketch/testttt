/**
 * Vérifie clé + endpoint + modèle : une requête minimale, succès = assistant avec texte.
 * Ne loggue pas la clé. Exit 0 si le proxy renvoie un contenu non vide.
 * Usage: npm run codex:smoke
 */
import fs from "node:fs";
import path from "node:path";
import { fileURLToPath } from "node:url";

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const root = path.resolve(__dirname, "..");

function loadFileEnv(f) {
  if (!fs.existsSync(f)) return;
  for (const line of fs.readFileSync(f, "utf8").split("\n")) {
    const t = line.trim();
    if (!t || t.startsWith("#") || !t.includes("=")) continue;
    const i = t.indexOf("=");
    const k = t.slice(0, i).trim();
    let v = t.slice(i + 1).trim();
    if ((v.startsWith('"') && v.endsWith('"')) || (v.startsWith("'") && v.endsWith("'")))
      v = v.slice(1, -1);
    if (k && process.env[k] === undefined) process.env[k] = v;
  }
}
loadFileEnv(path.join(root, ".env"));
loadFileEnv(path.join(root, ".env.codex"));

const API_BASE = (process.env.CODEX_API_BASE || "").replace(/\/$/, "");
const API_KEY = process.env.CODEX_API_KEY || "";
const MODEL = process.env.CODEX_MODEL_COMPLEX || "gpt-5.4";

if (!API_BASE || !API_KEY) {
  console.error("[codex:smoke] CODEX_API_BASE + CODEX_API_KEY requis ( .env / .env.codex ).");
  process.exit(2);
}

const headers = {
  Authorization: `Bearer ${API_KEY}`,
  "Content-Type": "application/json",
};

const body = {
  model: MODEL,
  messages: [{ role: "user", content: "Reply with exactly: OK" }],
};

const sleep = (ms) => new Promise((r) => setTimeout(r, ms));
const maxAttempts = 4;

for (let attempt = 0; attempt < maxAttempts; attempt++) {
  if (attempt > 0) {
    const w = 1500 * attempt;
    console.error(`[codex:smoke] reprise ${attempt + 1}/${maxAttempts} (réponse vide) — attente ${w}ms…`);
    await sleep(w);
  }
  const r = await fetch(`${API_BASE}/chat/completions`, { method: "POST", headers, body: JSON.stringify(body) });
  const t = await r.text();
  let j;
  try {
    j = JSON.parse(t);
  } catch {
    console.error("[codex:smoke] non-JSON", r.status, t.slice(0, 300));
    process.exit(1);
  }
  if (!r.ok) {
    if ((r.status === 502 || r.status === 503 || r.status === 429) && attempt < maxAttempts - 1) continue;
    console.error("[codex:smoke] HTTP", r.status, JSON.stringify(j?.error || j, null, 0));
    process.exit(1);
  }
  const content = j?.choices?.[0]?.message?.content;
  const ok = typeof content === "string" && content.trim().length > 0;
  if (ok) {
    const note = attempt > 0 ? ` (tentative ${attempt + 1})` : "";
    console.log(
      "[codex:smoke] OK | modèle:",
      j?.model || MODEL,
      "| extrait:",
      JSON.stringify(content).slice(0, 80),
      note
    );
    process.exit(0);
  }
  if (attempt < maxAttempts - 1) continue;
  console.error(
    "[codex:smoke] RÉPONSES 200 SANS CONTENU d’assistant après",
    maxAttempts,
    "tentative(s) — le dashboard peut quand même afficher des tokens. Variables : autres modèles, fournisseur, période. Modèle :",
    MODEL
  );
  process.exit(1);
}

/**
 * Vérifie clé + endpoint + modèle : une requête minimale, succès = assistant avec texte.
 * Ne loggue pas la clé. Exit 0 si le proxy renvoie un contenu non vide.
 * Usage: npm run codex:smoke
 */
import path from "node:path";
import { fileURLToPath } from "node:url";
import { loadProjectEnvForCodex, resolveRepoRootFromScriptDir } from "./codex-load-env.mjs";

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const root = resolveRepoRootFromScriptDir(__dirname);
loadProjectEnvForCodex(root);

const API_BASE = (process.env.CODEX_API_BASE || "").replace(/\/$/, "");
const API_KEY = (process.env.CODEX_API_KEY || process.env.OPENAI_API_KEY || "").trim();
const MODEL = process.env.CODEX_MODEL_COMPLEX || "gpt-5.5";

if (!API_BASE || !API_KEY) {
  console.error(
    "[codex:smoke] CODEX_API_BASE + (CODEX_API_KEY ou OPENAI_API_KEY) requis ( .env / .env.codex )."
  );
  process.exit(2);
}

const headers = {
  Authorization: `Bearer ${API_KEY}`,
  "Content-Type": "application/json",
};

/** Aligné sur le runner : plafond par défaut = max supporté 2M (désactiver: CODEX_NO_DEFAULT_OUTPUT_BUDGET=1). */
function smokeOutputBudget() {
  const cap = 2_000_000;
  const mct = (process.env.CODEX_MAX_COMPLETION_TOKENS || "").trim();
  if (mct) {
    const n = Math.min(cap, Math.max(1, parseInt(mct, 10) || 0));
    if (n) return { max_completion_tokens: n };
  }
  const mt = (process.env.CODEX_MAX_TOKENS || "").trim();
  if (mt) {
    const n = Math.min(cap, Math.max(1, parseInt(mt, 10) || 0));
    if (n) return { max_tokens: n };
  }
  if ((process.env.CODEX_NO_DEFAULT_OUTPUT_BUDGET || "").toLowerCase() === "1") return {};
  const d = Math.min(
    cap,
    Math.max(1, parseInt(process.env.CODEX_DEFAULT_MAX_COMPLETION_TOKENS || "2000000", 10) || 2_000_000)
  );
  return { max_completion_tokens: d };
}

const body = {
  model: MODEL,
  messages: [{ role: "user", content: "Reply with exactly: OK" }],
  ...smokeOutputBudget(),
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
    const b = body.max_completion_tokens ?? body.max_tokens ?? "—";
    console.log(
      "[codex:smoke] OK | modèle:",
      j?.model || MODEL,
      "| plafond sortie (requête):",
      b,
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

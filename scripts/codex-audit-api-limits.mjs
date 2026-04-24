/**
 * Audit reproductible : comparaison « ce qu’on envoie à l’API » vs « ce que l’API retourne »
 * (usage, finish_reason, taille) — **sans** loguer de clé.
 *
 * Usage: node scripts/codex-audit-api-limits.mjs
 * Env: .env / .env.codex (CODEX_API_BASE, CODEX_API_KEY, CODEX_MODEL_COMPLEX, etc.)
 * Option: AUDIT_STRESS=1  → lance en plus un appel exigeant une longue génération (coût ++).
 */
import fs from "node:fs";
import path from "node:path";
import { fileURLToPath } from "node:url";
import { loadProjectEnvForCodex, resolveRepoRootFromScriptDir } from "../agents/codex-load-env.mjs";

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const root = resolveRepoRootFromScriptDir(__dirname);
loadProjectEnvForCodex(root);

const API_BASE = (process.env.CODEX_API_BASE || "").replace(/\/$/, "");
const API_KEY = (process.env.CODEX_API_KEY || process.env.OPENAI_API_KEY || "").trim();
const MODEL = process.env.CODEX_MODEL_COMPLEX || "gpt-5.4";
const MAX_C = (() => {
  const t = (process.env.CODEX_MAX_COMPLETION_TOKENS || "262144").trim();
  const n = Math.min(2_000_000, Math.max(1, parseInt(t, 10) || 262144));
  return n;
})();

if (!API_BASE || !API_KEY) {
  console.error("CODEX_API_BASE + CODEX_API_KEY requis.");
  process.exit(2);
}

const headers = {
  Authorization: `Bearer ${API_KEY}`,
  "Content-Type": "application/json",
  "User-Agent": "FoodKing-codex-audit-limits/1.0 (Node)",
};

function textFromMessage(msg) {
  if (!msg) return "";
  const c = msg.content;
  if (typeof c === "string") return c;
  if (Array.isArray(c)) {
    return c
      .map((p) => {
        if (typeof p === "string") return p;
        if (p?.type === "text" && p.text) return p.text;
        return "";
      })
      .join("");
  }
  return "";
}

async function oneShot({ user, label }) {
  const body = {
    model: MODEL,
    messages: [{ role: "user", content: user }],
    stream: false,
    max_completion_tokens: MAX_C,
  };
  const t0 = Date.now();
  const res = await fetch(`${API_BASE}/chat/completions`, {
    method: "POST",
    headers,
    body: JSON.stringify(body),
  });
  const ms = Date.now() - t0;
  const text = await res.text();
  let d;
  try {
    d = JSON.parse(text);
  } catch {
    return { label, http: res.status, ms, error: "non-json", text_head: text.slice(0, 400) };
  }
  const ch0 = d.choices?.[0];
  const fr = ch0?.finish_reason ?? d.finish_reason;
  const content = textFromMessage(ch0?.message);
  return {
    label,
    http: res.status,
    ms,
    request: { model: MODEL, max_completion_tokens: MAX_C, user_chars: user.length },
    usage: d.usage || null,
    finish_reason: fr,
    id: d.id,
    response_model: d.model,
    content_chars: content.length,
    content_non_ws: content.replace(/\s/g, "").length,
    error: d.error || (res.ok ? null : d),
  };
}

const withStress = (process.env.AUDIT_STRESS || "").toLowerCase() === "1";

const results = {
  foodking_conclusion: {
    local_repo: "Aucun plafond d’usage côté FoodKing: ce script et agents/codex.runner.mjs ne tronquent pas le corps assistant ; seuls max_completion_tokens (explicite ici) et le prompt comptent.",
    cursor_note:
      "L’indicateur Cursor (session 5–20k) mesure le produit éditeur+contexte ; il n’est pas le même compteur qu’un POST /chat/completions vers ton proxy (mesure A/B ci-dessous).",
  },
  model: MODEL,
  max_completion_tokens_sent: MAX_C,
  tests: [],
};

results.tests.push(
  await oneShot({
    label: "A_minimal_1_token_style",
    user: "Reply with only the two letters OK, nothing else.",
  })
);

results.tests.push(
  await oneShot({
    label: "B_baseline_prompt_tiny",
    user:
      "Give exactly one short sentence in French confirming the API is reachable, under 20 words.",
  })
);

if (withStress) {
  results.tests.push(
    await oneShot({
      label: "C_stress_long_generation_request",
      user: [
        "Rédige en Markdown un document d’architecture **fictif** pour un SaaS restauration (POS, KDS, multi-tenant).",
        "Exigences: **au minimum 60 sections** `## N. Titre` (N=1..60) ; chaque section: **au moins 120 mots** de texte utile, sans blocs de code, sans tronature volontaire au milieu d’une phrase.",
        "Ferme par une seule ligne comptage: `STATS: mots=... | caractères=... | sections=...`",
      ].join("\n"),
    })
  );
}

const outDir = path.join(root, "reports", "audit");
fs.mkdirSync(outDir, { recursive: true });
const outFile = path.join(outDir, `codex_api_audit_payload_${new Date().toISOString().replace(/[:.]/g, "-")}.json`);
fs.writeFileSync(outFile, JSON.stringify(results, null, 2), "utf8");
console.log(JSON.stringify(results, null, 2));
console.error(`\n[audit] Fichier JSON: ${outFile}\n`);
process.exit(0);

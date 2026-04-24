/**
 * STRESS TEST réel — 5 requêtes utiles par modèle, juge :
 *  - HTTP 200 ?
 *  - choices[0].message.content non vide ?
 *  - JSON parseable quand on a demandé du JSON ?
 *  - latence acceptable ?
 * Sortie machine + verdict simple.
 */
import fs from "node:fs";
import path from "node:path";
import { fileURLToPath } from "node:url";
import { loadProjectEnvForCodex, resolveRepoRootFromScriptDir } from "./codex-load-env.mjs";

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const root = resolveRepoRootFromScriptDir(__dirname);
loadProjectEnvForCodex(root);

const API_BASE = (process.env.CODEX_API_BASE || "").replace(/\/$/, "");
const API_KEY = (process.env.CODEX_API_KEY || process.env.OPENAI_API_KEY || "").trim();
if (!API_BASE || !API_KEY) {
  console.error("[stress] manque CODEX_API_BASE + (CODEX_API_KEY ou OPENAI_API_KEY)");
  process.exit(2);
}

const headers = { Authorization: `Bearer ${API_KEY}`, "Content-Type": "application/json" };

const TASKS = [
  {
    id: "echo",
    prompt: "Réponds exactement par le mot OK et rien d'autre.",
    judge: (s) => s.trim() === "OK",
  },
  {
    id: "json-design",
    prompt:
      'Renvoie UNIQUEMENT un objet JSON (pas de markdown) {"pattern":"…","fields":["email","password"],"a11y":["…","…","…"]} pour un écran de connexion SaaS B2B.',
    judge: (s) => {
      try {
        const j = JSON.parse(s.trim().replace(/^```json|```$/g, ""));
        return !!(j && j.pattern && Array.isArray(j.fields) && Array.isArray(j.a11y));
      } catch {
        return false;
      }
    },
  },
  {
    id: "code-vue",
    prompt:
      "Renvoie UNIQUEMENT un bloc <template> Vue 3 minimal pour un écran Login (email, password, bouton Se connecter). 20 lignes max, pas d'explication.",
    judge: (s) => /<template>/.test(s) && /input/i.test(s) && /button/i.test(s),
  },
  {
    id: "rule-laravel",
    prompt:
      "En 5 lignes max, donne une règle Laravel pour valider email + mot de passe (8 chars min). Aucune fioriture.",
    judge: (s) => /email/i.test(s) && /(min|8)/.test(s),
  },
  {
    id: "long",
    prompt:
      "Liste 8 risques UI courants d'un écran de connexion SaaS, un par ligne, sans numérotation.",
    judge: (s) => s.split("\n").filter((l) => l.trim()).length >= 6,
  },
];

async function ask(model, prompt) {
  const t0 = Date.now();
  const r = await fetch(`${API_BASE}/chat/completions`, {
    method: "POST",
    headers,
    body: JSON.stringify({ model, messages: [{ role: "user", content: prompt }] }),
  });
  const ms = Date.now() - t0;
  const text = await r.text();
  let j;
  try {
    j = JSON.parse(text);
  } catch {
    return { http: r.status, ms, err: "non-json", raw: text.slice(0, 200) };
  }
  if (!r.ok) return { http: r.status, ms, err: j?.error?.message || "http_err" };
  const content = j?.choices?.[0]?.message?.content;
  return { http: r.status, ms, content: typeof content === "string" ? content : null, model: j?.model };
}

async function runModel(model) {
  const results = [];
  for (const t of TASKS) {
    const r = await ask(model, t.prompt);
    const ok = !!(r.content && r.content.trim().length && t.judge(r.content));
    results.push({ task: t.id, ok, http: r.http, ms: r.ms, empty: !r.content, len: r.content?.length || 0, err: r.err });
    process.stderr.write(`[${model}] ${t.id} → ${ok ? "OK" : "KO"} (http ${r.http}, ${r.ms}ms, ${r.content ? r.content.length + "ch" : "vide"})\n`);
  }
  const okCount = results.filter((x) => x.ok).length;
  return { model, okCount, total: results.length, results };
}

const models = process.argv.slice(2);
const list = models.length ? models : ["gpt-5.4", "gpt-5.5-high", "gpt-5.5-pro", "gpt-5.5"];
const summary = [];
for (const m of list) summary.push(await runModel(m));

console.log("\n=== VERDICT ===");
for (const s of summary) {
  const pct = Math.round((s.okCount / s.total) * 100);
  const verdict = pct >= 80 ? "INTÉGRER" : pct >= 40 ? "INSTABLE — repli requis" : "NE PAS INTÉGRER";
  console.log(`${s.model}: ${s.okCount}/${s.total} (${pct}%) → ${verdict}`);
}

const best = summary.reduce((a, b) => (b.okCount > a.okCount ? b : a));
console.log(`\nDécision : utiliser ${best.model} (${best.okCount}/${best.total}).`);
const rep = path.join(root, "reports/execution/CODEX_STRESS_" + new Date().toISOString().slice(0, 10) + ".md");
fs.mkdirSync(path.dirname(rep), { recursive: true });
fs.writeFileSync(
  rep,
  "# Stress test API codex\n\n" +
    summary
      .map(
        (s) =>
          `## ${s.model}\n\n` +
          s.results
            .map(
              (r) =>
                `- ${r.task}: ${r.ok ? "OK" : "KO"} | http ${r.http} | ${r.ms}ms | ${r.empty ? "vide" : r.len + " caractères"}${r.err ? " | err " + r.err : ""}`
            )
            .join("\n") +
          `\n\n**Score : ${s.okCount}/${s.total}**\n`
      )
      .join("\n") +
    `\n\n**Modèle retenu : ${best.model}**\n`,
  "utf8"
);
console.log("Rapport :", rep);
process.exit(best.okCount >= Math.ceil(best.total * 0.6) ? 0 : 1);

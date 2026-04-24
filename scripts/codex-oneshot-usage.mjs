import path from "node:path";
import { fileURLToPath } from "node:url";
import { loadProjectEnvForCodex, resolveRepoRootFromScriptDir } from "../agents/codex-load-env.mjs";

const root = resolveRepoRootFromScriptDir(path.dirname(fileURLToPath(import.meta.url)));
loadProjectEnvForCodex(root);
const base = (process.env.CODEX_API_BASE || "").replace(/\/$/, "");
const k = (process.env.CODEX_API_KEY || process.env.OPENAI_API_KEY || "").trim();
const m = process.env.CODEX_MODEL_COMPLEX || "gpt-5.5";
const body = {
  model: m,
  messages: [
    {
      role: "user",
      content:
        "Rédige 6 paragraphes denses (100–130 mots chacun) sur la sécurité d’un SaaS POS multi-branches, sans brefs bullet points seuls. Langue: français.",
    },
  ],
  stream: false,
  max_completion_tokens: 8000,
  reasoning: { effort: "high" },
};
const r = await fetch(`${base}/chat/completions`, {
  method: "POST",
  headers: { Authorization: `Bearer ${k}`, "Content-Type": "application/json" },
  body: JSON.stringify(body),
});
const t = await r.text();
let d;
try {
  d = JSON.parse(t);
} catch {
  console.log("non-json", t.slice(0, 500));
  process.exit(1);
}
console.log(
  JSON.stringify(
    {
      model: m,
      http: r.status,
      response_model: d.model,
      usage: d.usage,
      content_len: (d.choices?.[0]?.message?.content || "").length,
    },
    null,
    2
  )
);

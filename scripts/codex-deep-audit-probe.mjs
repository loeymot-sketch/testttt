/**
 * Boucle de configurations + audit « lourd » (gros contexte + longue génération + stream + reasoning)
 * Objectif: prouver des milliers de tokens (prompt + complétion) et fixer le couple modèle / fil.
 *
 * Usage: npm run codex:deep-audit-probe
 * Env: .env + .env.codex (même clé / base que le runner)
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

function optionalReasoning() {
  const raw = (process.env.CODEX_REASONING_EFFORT || "xhigh").trim().toLowerCase();
  if (!raw) return {};
  const eff = raw === "xhigh" ? "high" : raw;
  if (!/^(low|medium|high|minimal|none)$/.test(eff)) return {};
  return { reasoning: { effort: eff } };
}

function safeRead(f, max = 20_000) {
  try {
    if (!fs.existsSync(f)) return "";
    const s = fs.readFileSync(f, "utf8");
    return s.length > max ? s.slice(0, max) + "\n[…tronqué…]\n" : s;
  } catch {
    return "";
  }
}

function findFiles(dir, exts, cap = 400) {
  const out = [];
  function walk(d) {
    if (out.length >= cap) return;
    let st;
    try {
      st = fs.readdirSync(d, { withFileTypes: true });
    } catch {
      return;
    }
    for (const e of st) {
      if (out.length >= cap) return;
      const p = path.join(d, e.name);
      if (e.isDirectory()) {
        if (e.name === "node_modules" || e.name === "vendor" || e.name === ".git") continue;
        walk(p);
      } else {
        const ext = path.extname(e.name);
        if (exts.includes(ext)) out.push(p);
      }
    }
  }
  walk(dir);
  return out;
}

function buildContextDigest() {
  const parts = [];
  const push = (title, s) => {
    if (s) parts.push(`\n## ${title}\n${s}\n`);
  };
  push("README", safeRead(path.join(root, "README.md"), 12_000));
  push("AGENTS", safeRead(path.join(root, "AGENTS.md"), 8_000));
  push("package.json", safeRead(path.join(root, "package.json"), 6_000));
  push("composer.json", safeRead(path.join(root, "composer.json"), 6_000));
  push("memory/INDEX", safeRead(path.join(root, "memory/INDEX.md"), 4_000));
  const compact = path.join(root, "reports/compact_snapshot.md");
  push("compact_snapshot", safeRead(compact, 8_000));

  let nPhp = 0;
  let nVue = 0;
  let nTs = 0;
  try {
    nPhp = findFiles(path.join(root, "app"), [".php"], 2_000).length;
    nVue = findFiles(path.join(root, "resources/js"), [".vue"], 1_000).length;
    nTs = findFiles(path.join(root, "tests"), [".ts"], 200).length;
  } catch {
    /* */
  }
  parts.push(
    `\n## Compteurs (échantillonnage interne)\n- fichiers .php (app) parcourus: ${nPhp}\n- fichiers .vue (resources/js): ${nVue}\n- .ts (tests): ${nTs}\n`
  );

  const samplePhp = findFiles(path.join(root, "app/Http/Controllers/Admin"), [".php"], 3).map(
    (f) => `// ${f}\n` + safeRead(f, 1_200)
  );
  if (samplePhp.length) {
    parts.push("## Extraits (échantillons)\n" + samplePhp.join("\n---\n"));
  }

  let t = parts.join("\n");
  const MAX = 72_000;
  if (t.length > MAX) t = t.slice(0, MAX) + "\n[…contexte tronqué…]\n";
  return t;
}

function parseSseLine(line, onDelta, onChunkJson) {
  const s = line.trim();
  if (!s || s.startsWith(":") || !s.startsWith("data: ")) return;
  const data = s.slice(6);
  if (data === "[DONE]") return;
  try {
    const j = JSON.parse(data);
    if (onChunkJson) onChunkJson(j);
    const c = j.choices?.[0]?.delta?.content;
    if (typeof c === "string" && c.length) onDelta(c);
    else if (Array.isArray(c)) {
      for (const p of c) {
        if (typeof p === "string") onDelta(p);
        if (p?.type === "text" && p.text) onDelta(p.text);
      }
    }
  } catch {
    /* */
  }
}

async function readSseDeltas(r) {
  if (!r.body) return { text: "", usage: undefined };
  const reader = r.body.getReader();
  const dec = new TextDecoder();
  let carry = "";
  let out = "";
  let lastUsage;
  const onChunk = (j) => {
    if (j && typeof j.usage === "object") lastUsage = j.usage;
  };
  for (;;) {
    const { done, value } = await reader.read();
    if (done) break;
    if (!value) continue;
    carry += dec.decode(value, { stream: true });
    const j = carry.lastIndexOf("\n");
    if (j === -1) continue;
    for (const line of carry.slice(0, j + 1).split("\n")) parseSseLine(line, (c) => (out += c), onChunk);
    carry = carry.slice(j + 1);
  }
  if (carry.length) for (const line of carry.split("\n")) parseSseLine(line, (c) => (out += c), onChunk);
  return { text: out, usage: lastUsage };
}

const headers = {
  Authorization: `Bearer ${API_KEY}`,
  "Content-Type": "application/json",
  "User-Agent": "FoodKing-codex-deep-probe/1.0 (Node)",
};

const AUDIT_BRIEF = `Tu produis un AUDIT LOGICIEL GLOBAL du dépôt décrit dans le contexte (FoodKing, Laravel+Vue, POS/KDS, SaaS).
Exigences strictes (pour valider l’infrastructure côté API) :
- Minimum **18 sections** avec titre Markdown exactement au format: ## N. Titre (N de 1 à 18+).
- Chaque section: **au moins 90 mots** en français, contenu concret (risques, recommandations, invariants) — pas de remplissage stéréotypé d’une seule phrase répétée.
- Sujets à couvrir: architecture backend/frontend, auth & tenants, KDS/flux cuisine, Paiements/POS, tests & CI, i18n, dette technique, 5 quick wins, 3 risques P0, conclusion.
- Dernière ligne seule, format exact: STATS: mots=XXXX|chars=XXXX|sections=YY

Ne refuse pas d’écrire en volume: l’objectif est une **longue** réponse structurée.`;

async function tryStreamModel(model, userContent, maxC) {
  const body = {
    model,
    messages: [{ role: "user", content: userContent }],
    stream: true,
    max_completion_tokens: maxC,
    ...optionalReasoning(),
  };
  const t0 = Date.now();
  const res = await fetch(`${API_BASE}/chat/completions`, {
    method: "POST",
    headers: { ...headers, Accept: "application/json" },
    body: JSON.stringify(body),
  });
  if (!res.ok) {
    const t = await res.text();
    return {
      model,
      http: res.status,
      ms: Date.now() - t0,
      error: t.slice(0, 1_200),
      usage: null,
      text_len: 0,
    };
  }
  const ct = (res.headers.get("content-type") || "").toLowerCase();
  if (!ct.includes("event-stream") && !ct.includes("text/event") && res.ok) {
    const t = await res.text();
    let d;
    try {
      d = JSON.parse(t);
    } catch {
      return { model, http: 200, ms: Date.now() - t0, error: "non-json", usage: null, text_len: 0 };
    }
    return {
      model,
      http: 200,
      ms: Date.now() - t0,
      error: null,
      usage: d.usage || null,
      text_len: (d.choices?.[0]?.message?.content || "").length,
      one_shot_json: true,
    };
  }
  const s = await readSseDeltas(res);
  return {
    model,
    http: 200,
    ms: Date.now() - t0,
    error: null,
    usage: s.usage || null,
    text_len: s.text.length,
    text_head: s.text.slice(0, 4_000),
  };
}

async function main() {
  try {
    const u = await import("node:undici");
    if (u?.setGlobalDispatcher && u?.Agent) {
      u.setGlobalDispatcher(
        new u.Agent({ connectTimeout: 600_000, headersTimeout: 600_000, bodyTimeout: 0 })
      );
    }
  } catch {
    /* */
  }

  if (!API_BASE || !API_KEY) {
    console.error("Manque CODEX_API_BASE + (CODEX_API_KEY|OPENAI_API_KEY).");
    process.exit(2);
  }

  const context = buildContextDigest();
  const userMessage = `CONTEXTE DÉPÔT (fichiers internes, extrait) :\n${context}\n\n---\nTÂCHE :\n${AUDIT_BRIEF}`;

  const modelsToTry = [
    (process.env.CODEX_PROBE_MODEL || "").trim(),
    "gpt-5.5",
    "gpt-5.4",
    "gpt-5.5-high",
    "gpt-5.5-pro",
  ].filter(Boolean);

  const unique = [...new Set(modelsToTry)];
  const maxOut = Math.min(120_000, Math.max(4_000, parseInt(process.env.CODEX_MAX_COMPLETION_TOKENS || "48000", 10) || 48_000));

  const results = {
    at: new Date().toISOString(),
    api_base: API_BASE.replace(/\/v1$/, "/v1"),
    reasoning_effort: (process.env.CODEX_REASONING_EFFORT || "xhigh").trim(),
    context_chars: context.length,
    user_message_chars: userMessage.length,
    max_completion_tokens: maxOut,
    attempts: [],
    winner: null,
  };

  const MIN_TOTAL = parseInt(process.env.CODEX_PROBE_MIN_TOTAL_TOKENS || "800", 10) || 800;

  for (const model of unique) {
    const r = await tryStreamModel(model, userMessage, maxOut);
    results.attempts.push(r);
    const u = r.usage;
    const total = u && typeof u.total_tokens === "number" ? u.total_tokens : 0;
    const comp = u && typeof u.completion_tokens === "number" ? u.completion_tokens : 0;
    const promptT = u && typeof u.prompt_tokens === "number" ? u.prompt_tokens : 0;
    const strong = total >= MIN_TOTAL || comp >= 400 || (r.text_len > 6_000 && r.http === 200);
    if (r.http === 200 && strong) {
      results.winner = {
        model,
        usage: u,
        text_len: r.text_len,
        ms: r.ms,
        prompt_tokens: promptT,
        completion_tokens: comp,
        total_tokens: total,
      };
      break;
    }
    if (r.http === 200 && !u && r.text_len > 10_000) {
      results.winner = {
        model,
        usage: u,
        text_len: r.text_len,
        ms: r.ms,
        note: "usage manquant côté stream, mais texte long",
      };
      break;
    }
  }

  if (!results.winner && results.attempts.length) {
    const last = results.attempts[results.attempts.length - 1];
    if (last.http === 200) {
      results.winner = {
        model: last.model,
        usage: last.usage,
        text_len: last.text_len,
        note: "aucun seuil atteint, meilleur essai",
      };
    }
  }

  const outDir = path.join(root, "reports", "audit");
  fs.mkdirSync(outDir, { recursive: true });
  const stamp = new Date().toISOString().replace(/[:.]/g, "-");
  const jsonPath = path.join(outDir, `DEEP_AUDIT_PROBE_${stamp}.json`);
  fs.writeFileSync(jsonPath, JSON.stringify(results, null, 2), "utf8");

  const md = [];
  md.push(`# Audit profond + preuve de consommation tokens — ${results.at.split("T")[0]}`);

  md.push(`\n## Synthèse\n`);

  if (results.winner) {
    const w = results.winner;
    md.push(`- **Modèle retenu (meilleur essai)**: \`${w.model}\``);
    if (w.usage) {
      md.push(
        `- **usage (API)** — prompt: ${w.usage.prompt_tokens ?? "—"} | completion: ${w.usage.completion_tokens ?? "—"} | total: ${w.usage.total_tokens ?? "—"}`
      );
    } else {
      md.push(`- **usage API**: non renvoyé en stream (fréquent) ; longueur texte: ${w.text_len ?? "—"} car.`);
    }
    if (w.note) md.push(`- **Note**: ${w.note}`);
  } else {
    md.push(`- Aucun appel 200 valide. Voir JSON machine.`);
  }

  md.push(`\n## Paramètres de la preuve\n`);
  md.push(
    `- \`CODEX_REASONING_EFFORT\` effectif: **${results.reasoning_effort}** (corps: \`reasoning.effort\` mappé xhigh→high si besoin).`
  );
  md.push(`- Taille **contexte** injecté: **${results.context_chars}** car.`);
  md.push(`- Taille **message utilisateur** total: **${results.user_message_chars}** car. (contexte + consigne d’audit).`);
  md.push(`- \`max_completion_tokens\` demandé: **${results.max_completion_tokens}**.`);
  md.push(
    `- Fil: **\`/v1/chat/completions\` en stream: true** (recommandé pour générations longues ; évite souvent 504 sur one-shot lourd).`
  );

  md.push(`\n## Détails des essais (machine)\n`);
  for (const a of results.attempts) {
    md.push(`### \`${a.model}\``);
    md.push(`- HTTP: ${a.http} | ${a.ms} ms | chars réponse: ${a.text_len ?? 0}`);
    if (a.usage) md.push(`- usage: \`\`\`json\n${JSON.stringify(a.usage, null, 2)}\n\`\`\``);
    if (a.error) md.push(`- erreur: \`${String(a.error).slice(0, 400).replace(/`/g, "'")}\``);
    if (a.text_head) {
      md.push(`- extrait (4k) :\n\n` + "```\n" + a.text_head + "\n```\n");
    }
  }

  md.push(`\n## Pourquoi les « 10 tokens » auparavant\n`);
  md.push(
    `- Un **smoke** du type \`Reply with OK\` est volontairement **minimal** côté complétion (tâche triviale — le modèle s’arrête tôt, peu de tokens de sortie).`
  );
  md.push(
    `- Ici, le scénario impose **gros contexte (prompt)** + consigne d’**audit long** : la consommation **prompt** + **complétion** est censée monter (vérif. tableau *usage* si le proxy le remplit).`
  );

  md.push(`\n## Artifacts\n`);
  md.push(`- JSON: \`${path.relative(root, jsonPath)}\`\n`);

  const mdPath2 = path.join(outDir, `AUDIT_DEEP_TOKEN_PROOF_${new Date().toISOString().slice(0, 10)}.md`);
  fs.writeFileSync(mdPath2, md.join("\n"), "utf8");

  console.error(`[deep-probe] JSON: ${jsonPath}\n[deep-probe] MD:  ${mdPath2}\n`);
  console.log(JSON.stringify({ winner: results.winner, attempts: results.attempts.length }, null, 2));
}

main().catch((e) => {
  console.error(e);
  process.exit(1);
});

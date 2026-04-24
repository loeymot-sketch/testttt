/**
 * Audit global d’intelligence (GPT) — objectif **≥ 100 000 tokens** (cumul API ; sinon estimation documentée).
 * Utilise **stream: true** (non-stream lourd → 504 fréquent sur le proxy) + undici.
 *
 * Usage: npm run codex:mega-audit
 * Options: CODEX_AUDIT_TARGET_TOKENS=100000 CODEX_MAX_COMPLETION_TOKENS=20000
 */
import fs from "node:fs";
import path from "node:path";
import { fileURLToPath } from "node:url";
import { loadProjectEnvForCodex, resolveRepoRootFromScriptDir } from "../agents/codex-load-env.mjs";

const root = resolveRepoRootFromScriptDir(path.dirname(fileURLToPath(import.meta.url)));
loadProjectEnvForCodex(root);

const API_BASE = (process.env.CODEX_API_BASE || "").replace(/\/$/, "");
const API_KEY = (process.env.CODEX_API_KEY || process.env.OPENAI_API_KEY || "").trim();
const MODEL = process.env.CODEX_MODEL_COMPLEX || "gpt-5.5";
const TARGET = Math.max(5_000, parseInt(process.env.CODEX_AUDIT_TARGET_TOKENS || "100000", 10) || 100_000);
const MAX_C = Math.min(64_000, Math.max(4_000, parseInt(process.env.CODEX_MAX_COMPLETION_TOKENS || "20000", 10) || 20_000));
const THEME_CAP = Math.min(90_000, parseInt(process.env.CODEX_AUDIT_THEME_CAP || "52000", 10) || 52_000);

void (async function undici() {
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
})();

function readCap(rel, max) {
  try {
    const x = path.join(root, rel);
    if (!fs.existsSync(x)) return "";
    let s = fs.readFileSync(x, "utf8");
    if (s.length > max) s = s.slice(0, max) + `\n[… tronqué: ${rel} …]\n`;
    return s;
  } catch {
    return "";
  }
}

/** Estimation conservative (français) si l’API ne renvoie pas `usage` en stream. */
function estTokensFromText(chars) {
  return Math.max(0, Math.round(chars / 3.2));
}

function reasoningJson() {
  const raw = (process.env.CODEX_REASONING_EFFORT || "xhigh").trim().toLowerCase();
  const eff = raw === "xhigh" ? "high" : raw;
  if (!/^(low|medium|high|minimal|none)$/.test(eff)) return {};
  return { reasoning: { effort: eff } };
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

const THEME_CONTEXT = [
  {
    name: "POS — caisse, panier, encaissement",
    build: () =>
      [
        readCap("resources/js/components/admin/pos/PosComponent.vue", 38_000),
        readCap("resources/js/components/admin/pos/PaymentComponent.vue", 30_000),
        readCap("resources/js/components/admin/pos/ReceiptComponent.vue", 24_000),
        readCap("app/Http/Requests/Admin/Pos/PosOrderRequest.php", 20_000),
      ].join("\n---\n"),
  },
  {
    name: "Paiements & transactions (backend + fiscalité aperçu)",
    build: () =>
      [
        readCap("app/Services/PaymentService.php", 14_000),
        readCap("app/Services/OrderService.php", 55_000),
        readCap("app/Models/Transaction.php", 8_000),
        readCap("app/Enums/PaymentStatus.php", 3_000),
      ].join("\n---\n"),
  },
  {
    name: "Commande en ligne & FrontendOrder",
    build: () =>
      [
        readCap("app/Services/FrontendOrderService.php", 50_000),
        readCap("docs/ORDER_FLOW.md", 18_000),
      ].join("\n---\n"),
  },
  {
    name: "Cuisine KDS & affichage",
    build: () =>
      [
        readCap("resources/js/components/admin/kitchenDisplaySystem/KitchenDisplaySystemComponent.vue", 40_000),
        readCap("resources/js/helpers/kdsLineSemantics.js", 12_000),
      ].join("\n---\n"),
  },
  {
    name: "Borne Kiosk & attente & wizard",
    build: () =>
      [
        readCap("resources/js/components/frontend/kiosk/KioskAppComponent.vue", 22_000),
        readCap("resources/js/components/frontend/kiosk/KioskWaitingComponent.vue", 16_000),
        readCap("resources/js/components/frontend/kiosk/KioskPaymentComponent.vue", 18_000),
        readCap("resources/js/components/frontend/kiosk/KioskWizardComponent.vue", 18_000),
        readCap("resources/js/components/frontend/kiosk/KioskPosWizardComponent.vue", 16_000),
      ].join("\n---\n"),
  },
  {
    name: "Orchestration, agents, synchro, mémoire",
    build: () =>
      [
        readCap("AGENTS.md", 24_000),
        readCap("docs/orchestration/GLOBAL_SYSTEM_PRIMER.md", 30_000),
        readCap("memory/INDEX.md", 7_000),
        readCap("README.md", 10_000),
      ].join("\n---\n"),
  },
];

const INSTRUCTIONS = (themeName) => `Tu produis un **chapitre d’audit d’intelligence** (français) pour FoodKing, sous-système: **${themeName}**.

Règles:
- **Minimum 20** titres de niveau \`##\` ou \`###\` (numérotés).
- Chaque section: **70–100 mots** de contenu utile (risques, synchronisation, cohérence des flux, P0/P1, tests).
- Couvre explicitement, quand pertinent: **caisse, borne, cuisine, file d’attente, canaux de paiement, cohérence multi-branches, temps réel**.
- Cite le **code** (noms de fichiers/composants) quand l’extrait s’y prête ; sinon dis « non visible dans l’extrait ».
- Dernière ligne: \`THEME_DONE: mots=XXXX|chars=XXXX\``;

async function streamPart(userContent) {
  const body = {
    model: MODEL,
    messages: [{ role: "user", content: userContent }],
    stream: true,
    max_completion_tokens: MAX_C,
    ...reasoningJson(),
  };
  const t0 = Date.now();
  const res = await fetch(`${API_BASE}/chat/completions`, {
    method: "POST",
    headers: {
      Authorization: `Bearer ${API_KEY}`,
      "Content-Type": "application/json",
      Accept: "application/json",
    },
    body: JSON.stringify(body),
  });
  if (!res.ok) {
    const t = await res.text();
    return { http: res.status, ms: Date.now() - t0, text: "", usage: null, err: t.slice(0, 500) };
  }
  const ct = (res.headers.get("content-type") || "").toLowerCase();
  if (!ct.includes("event") && !ct.includes("stream")) {
    const t = await res.text();
    let d;
    try {
      d = JSON.parse(t);
    } catch {
      return { http: 200, ms: Date.now() - t0, text: "", usage: null, err: t.slice(0, 500) };
    }
    return {
      http: 200,
      ms: Date.now() - t0,
      text: d.choices?.[0]?.message?.content || "",
      usage: d.usage || null,
    };
  }
  const s = await readSseDeltas(res);
  return { http: 200, ms: Date.now() - t0, text: s.text, usage: s.usage, err: null };
}

async function main() {
  if (!API_BASE || !API_KEY) {
    console.error("Manque CODEX_API_BASE + clé.");
    process.exit(2);
  }

  const report = {
    at: new Date().toISOString(),
    model: MODEL,
    target_tokens: TARGET,
    max_completion_per_stream: MAX_C,
    cumulative_api: { prompt_tokens: 0, completion_tokens: 0, total_tokens: 0 },
    cumulative_est: { prompt_tokens: 0, completion_tokens: 0, total_tokens: 0 },
    parts: [],
  };

  const md = [`# Rapport d’intelligence — audit global multi-systèmes (FoodKing)\n\n> Généré par GPT via proxy (\`${MODEL}\`, reasoning **xhigh→high**). Chaque chapitre = stream séparé (évite 504 one-shot). \n> Date: ${report.at}\n`];

  for (const th of THEME_CONTEXT) {
    let ctx = th.build();
    if (ctx.length > THEME_CAP) ctx = ctx.slice(0, THEME_CAP) + "\n[…tronqué thème…]\n";
    const user = `EXTRAIT DÉPÔT (authentique, peut être tronqué) :\n\n${ctx}\n\n---\n\n${INSTRUCTIONS(th.name)}`;
    const prEst = estTokensFromText(user.length);
    const r = await streamPart(user);
    const p = {
      theme: th.name,
      http: r.http,
      ms: r.ms,
      err: r.err,
      char_user: user.length,
      char_out: (r.text || "").length,
      est_prompt: prEst,
      est_completion: estTokensFromText((r.text || "").length),
    };
    p.est_total = p.est_prompt + p.est_completion;

    if (r.usage) {
      p.usage = r.usage;
      report.cumulative_api.prompt_tokens += r.usage.prompt_tokens || 0;
      report.cumulative_api.completion_tokens += r.usage.completion_tokens || 0;
      report.cumulative_api.total_tokens += r.usage.total_tokens || 0;
    }
    report.cumulative_est.prompt_tokens += prEst;
    report.cumulative_est.completion_tokens += p.est_completion;
    report.cumulative_est.total_tokens += p.est_total;

    report.parts.push(p);
    md.push(`\n---\n\n# ${th.name}\n\n${r.text || `*(échec HTTP ${r.http} ${r.err || ""})*`.slice(0, 2000)}\n`);

    const apiT = report.cumulative_api.total_tokens;
    const estT = report.cumulative_est.total_tokens;
    if (apiT >= TARGET || estT >= TARGET) break;
    await new Promise((x) => setTimeout(x, 1_500));
  }

  /* Si besoin, tours additionnels allégés */
  let extra = 0;
  while (
    report.cumulative_api.total_tokens < TARGET &&
    report.cumulative_est.total_tokens < TARGET &&
    extra < 4
  ) {
    extra++;
    const user = `Sans répéter mot pour mot les chapitres précédents, produis une **synthèse transversale** (synchronisation **caisse / borne / KDS / attente / paiement**), **minimum 15 sections** \`##\`, 100 mots chacune. Ajoute un tableau Markdown final: Sous-système | Dépendances | Point de sync | Risque. dernière ligne: SYNTH_SUPP_${extra}_STATS.`;
    const r = await streamPart(user);
    const prEst = estTokensFromText(user.length);
    const p = { theme: `Synthèse+ ${extra}`, http: r.http, char_user: user.length, char_out: (r.text || "").length };
    p.est_completion = estTokensFromText((r.text || "").length);
    p.est_total = prEst + p.est_completion;
    if (r.usage) {
      p.usage = r.usage;
      report.cumulative_api.prompt_tokens += r.usage.prompt_tokens || 0;
      report.cumulative_api.completion_tokens += r.usage.completion_tokens || 0;
      report.cumulative_api.total_tokens += r.usage.total_tokens || 0;
    }
    report.cumulative_est.prompt_tokens += prEst;
    report.cumulative_est.completion_tokens += p.est_completion;
    report.cumulative_est.total_tokens += p.est_total;
    report.parts.push(p);
    md.push(`\n---\n\n# Synthèse transversale (complément ${extra})\n\n${r.text || "*(échec)*"}\n`);
    if (r.http !== 200) break;
  }

  const apiOk = report.cumulative_api.total_tokens;
  const estOk = report.cumulative_est.total_tokens;
  const met = apiOk >= TARGET || estOk >= TARGET;

  md.push(`\n---\n\n## Méthodologie & compteurs\n\n`);
  md.push(`- **Cible** : **${TARGET}** tokens (demande utilisateur).\n`);
  md.push(
    `- **Cumul \`usage\` API** (quand fourni) : **total = ${apiOk}** (prompt ${report.cumulative_api.prompt_tokens} ; completion ${report.cumulative_api.completion_tokens}).\n`
  );
  md.push(
    `- **Cumul estimé** (si stream sans usage : ~1 token / 3,2 car, conservateur) : **total ≈ ${estOk}**.\n`
  );
  md.push(
    `- **Recommandation exploitation** : utiliser le **tableau le plus fiable côté facturation** : si le fournisseur affiche l’\`usage\` complet sur le **dashboard** pour chaque requête, c’est la ref ; sinon, l’estimation sert d’**ordre de grandeur** (les streams ne renvoient souvent pas \`usage\` à chaque chunk).\n`
  );
  md.push(
    `- Outil : \`scripts/codex-mega-intelligence-audit.mjs\` (stream) — **évite les HTTP 504** des requêtes \`stream:false\` massives (≈ 60s timeout Cloudflare).\n`
  );
  if (!met) {
    md.push(
      `\n> **Note** : cible 100k non atteinte sur ce run (API **${apiOk}** / estimé **${estOk}**). Relance avec moins de troncature, ou plafond \`max_completion\` relevé, ou répliquer des chapitres sur plusieurs modèles.\n`
    );
  } else {
    md.push(`\n> **Cible d’envergure** : atteinte au sens **estimé** ou **API** (cf. nombres ci-dessus).\n`);
  }

  const outDir = path.join(root, "reports", "audit");
  fs.mkdirSync(outDir, { recursive: true });
  const day = new Date().toISOString().slice(0, 10);
  const stamp = new Date().toISOString().replace(/[:.]/g, "-");
  const mdPath = path.join(outDir, `INTELLIGENCE_AUDIT_GLOBAL_GPT_${day}.md`);
  const jsonPath = path.join(outDir, `INTELLIGENCE_AUDIT_GLOBAL_GPT_${stamp}.json`);
  fs.writeFileSync(mdPath, md.join(""), "utf8");
  fs.writeFileSync(jsonPath, JSON.stringify(report, null, 2), "utf8");

  console.log(
    JSON.stringify(
      { md: mdPath, json: jsonPath, api_total: apiOk, est_total: estOk, target: TARGET, met },
      null,
      2
    )
  );
  process.exit(met ? 0 : 2);
}

main().catch((e) => {
  console.error(e);
  process.exit(1);
});

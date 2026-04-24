/**
 * @deprecated **Chemin EXÉCUTE par défaut** = extension Codex (compte ChatGPT Pro) via
 * `bash scripts/codex-extension-execute.sh <TASK_ID>` / `npm run codex:complex` (package.json pointe
 * le wrapper, pas ce fichier). Conserver ce runner **uniquement** pour
 * `npm run codex:complex:proxy-legacy` (proxy HTTP + clé) si un environnement d’urgence
 * le réactive volontairement. Voir `docs/orchestration/CODEX_API_DELEGATION.md`.
 */
import fs from "node:fs";
import path from "node:path";
import { fileURLToPath } from "node:url";
import { loadProjectEnvForCodex, resolveRepoRootFromScriptDir } from "./codex-load-env.mjs";

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const root = resolveRepoRootFromScriptDir(__dirname);
loadProjectEnvForCodex(root);

const API_BASE = (process.env.CODEX_API_BASE || "").replace(/\/$/, "");
/** Clé : `CODEX_API_KEY` (prioritaire) ou `OPENAI_API_KEY` (équivalent provider OpenAI). */
const API_KEY = (process.env.CODEX_API_KEY || process.env.OPENAI_API_KEY || "").trim();
/**
 * Défaut `gpt-5.4` (aligné fournisseur tokenclub / profil type OpenAI) ; override : `CODEX_MODEL_COMPLEX`.
 * `CODEX_REASONING_EFFORT=...` → `reasoning: { effort }` (xhigh → high côté JSON) sur `/chat/completions` et fusionné sur `/responses` si supporté.
 */
const MODEL = process.env.CODEX_MODEL_COMPLEX || "gpt-5.5";

function optionalChatReasoning() {
  const raw = (process.env.CODEX_REASONING_EFFORT || "").trim().toLowerCase();
  if (!raw) return {};
  const eff = (raw === "xhigh" ? "high" : raw);
  if (!/^(low|medium|high|minimal|none)$/.test(eff)) return {};
  return { reasoning: { effort: eff } };
}

/**
 * Plafond de **sortie** (génération). L’API n’accepte pas `Infinity` en JSON — le plafond **le plus haut géré
 * ici** est **2_000_000** (≈ « le max que le connecteur autorise »), pas une économie de crédit.
 * Désactiver l’envoi d’un plafond : `CODEX_NO_DEFAULT_OUTPUT_BUDGET=1` (délègue 100% au fournisseur).
 * Surcharge explicite : `CODEX_MAX_COMPLETION_TOKENS` / `CODEX_MAX_TOKENS` (cette dernière → `max_tokens`).
 */
const MAX_COMPLETION_CAP = 2_000_000;
const DEFAULT_MAX_COMPLETION_TOKENS = Math.min(
  MAX_COMPLETION_CAP,
  Math.max(1, parseInt(process.env.CODEX_DEFAULT_MAX_COMPLETION_TOKENS || "2000000", 10) || 2_000_000)
);
function optionalOutputTokenBudget() {
  const mct = (process.env.CODEX_MAX_COMPLETION_TOKENS || "").trim();
  const mt = (process.env.CODEX_MAX_TOKENS || "").trim();
  if (mct) {
    const n = Math.min(MAX_COMPLETION_CAP, Math.max(1, parseInt(mct, 10) || 0));
    if (n) return { max_completion_tokens: n };
  }
  if (mt) {
    const n = Math.min(MAX_COMPLETION_CAP, Math.max(1, parseInt(mt, 10) || 0));
    if (n) return { max_tokens: n };
  }
  if ((process.env.CODEX_NO_DEFAULT_OUTPUT_BUDGET || "").toLowerCase() === "1") return {};
  return { max_completion_tokens: DEFAULT_MAX_COMPLETION_TOKENS };
}

function logTokenUsageIfRequested(usage) {
  if (!usage || (process.env.CODEX_LOG_USAGE || "").toLowerCase() !== "1") return;
  if (typeof usage !== "object") return;
  const c = (k) => (k in usage ? usage[k] : "—");
  console.error(
    "[codex] usage brut API — prompt_tokens:",
    c("prompt_tokens"),
    "completion_tokens:",
    c("completion_tokens"),
    "total_tokens:",
    c("total_tokens"),
    "(+ évent. raison / cache selon le fournisseur; comparer au dashboard.)"
  );
}

function logChatUsageIfRequested(d) {
  if (d?.usage) logTokenUsageIfRequested(d.usage);
}
/** Fournisseur type OpenAI : `wire_api=responses` → défaut `responses` ; `chat` pour `/chat/completions` + stream. */
const WIRE = (process.env.CODEX_WIRE || "responses").toLowerCase();
const RAW_PROMPT = (process.env.CODEX_RAW_PROMPT || "").toLowerCase() === "1";
const DISABLE_STREAM = (process.env.CODEX_DISABLE_STREAM || "").toLowerCase() === "1";
/** 1 = ne jamais repasser en one-shot (évite 504/timeout gateway sur gros prompts ; à combiner avec stream, défaut). */
const NO_ONESHOT_FALLBACK = (process.env.CODEX_NO_ONESHOT_FALLBACK || "").toLowerCase() === "1";
const RETRY_MAX = Math.min(12, Math.max(1, parseInt(process.env.RETRY_MAX || "8", 10) || 8));
const SLEEP_BASE_MS = Math.max(200, parseInt(process.env.SLEEP_BASE_MS || "2000", 10) || 2000);
const taskId = process.argv[2] || "PING";
const missionDir = path.join(root, "missions", taskId);
const inPath = path.join(missionDir, "input.json");
const promptPath = path.join(__dirname, "codex.prompt.txt");
const outPath = path.join(missionDir, "output_codex.json");
const APPEND_AUX_WITH_RAW = (process.env.CODEX_APPEND_AUX_WITH_RAW || "1").toLowerCase() !== "0";
const defaultAux = "graphiti_context.md,plan_excerpt.md,execute_brief.md,cycle_snapshot.md";
const AUX_GLOB = (process.env.CODEX_AUX_CONTEXT_FILES || defaultAux)
  .split(",")
  .map((s) => s.trim())
  .filter(Boolean);

if (!API_BASE || !API_KEY) {
  console.error(
    "Définis CODEX_API_BASE + (CODEX_API_KEY ou OPENAI_API_KEY) dans .env et/ou .env.codex."
  );
  process.exit(1);
}
if (!fs.existsSync(inPath)) {
  console.error(`Fichier manquant : ${inPath}`);
  process.exit(1);
}
fs.mkdirSync(path.dirname(outPath), { recursive: true });

const input = fs.readFileSync(inPath, "utf-8");
const noNormalizeM = (process.env.CODEX_NO_NORMALIZE_M || "").toLowerCase() === "1";

/**
 * Le proxy tokenclub (OpenAI-compatible) renvoie parfois une `message` sans
 * `content` si le user envoie un JSON dont la seule/une clé top-level est `m`.
 * On renomme en `instruction` (équivalents internes) pour garder la même sémantique.
 */
function normalizeForProxyMKey(s) {
  if (noNormalizeM) return s;
  const t = s.trim();
  if (!t.startsWith("{")) return s;
  let o;
  try {
    o = JSON.parse(t);
  } catch {
    return s;
  }
  if (!o || typeof o !== "object" || Array.isArray(o) || !("m" in o)) return s;
  const { m, ...rest } = o;
  const out = { ...rest };
  if (out.instruction == null) out.instruction = m;
  return JSON.stringify(out);
}

/**
 * Fichiers optionnels sous missions/<TASK_ID>/ (Graphiti, plan, brief cycle).
 * Remplis par l’orchestrateur en session Cursor (MCP search_*, copier/coler).
 */
function readMissionAux(mdir, fileNames) {
  const out = [];
  for (const name of fileNames) {
    const p = path.join(mdir, name);
    if (fs.existsSync(p)) {
      out.push(`### ${name}\n\n${fs.readFileSync(p, "utf8")}`);
    }
  }
  return out.join("\n\n");
}
const auxEmpty =
  "(Aucun des fichiers optionnels n’a été fourni. Ordre de fusion par défaut : " +
  defaultAux.replace(/,/g, ", ") +
  " — l’orchestrateur prépare ce bloc depuis Graphiti / le plan / execute-context ; voir `docs/orchestration/CODEX_API_DELEGATION.md`.)";
const auxText = (() => {
  const t = readMissionAux(missionDir, AUX_GLOB);
  return t.length ? t : auxEmpty;
})();

let basePrompt;
if (RAW_PROMPT) {
  basePrompt = input;
  if (APPEND_AUX_WITH_RAW) basePrompt = `${input}\n\n## Prior context (fichiers mission, si présents)\n\n${auxText}`;
} else {
  const tpl = fs.readFileSync(promptPath, "utf-8");
  let t = tpl.replace("{{TASK_INPUT}}", input);
  if (t.includes("{{AUX_CONTEXT}}")) t = t.replace("{{AUX_CONTEXT}}", auxText);
  else t = `${t}\n\n## Prior context\n\n${auxText}\n\n## Task payload\n\n${input}`;
  basePrompt = t;
}
const prompt = normalizeForProxyMKey(basePrompt);

const headers = {
  Authorization: `Bearer ${API_KEY}`,
  "Content-Type": "application/json",
  "User-Agent": "FoodKing-codex-runner/2.2 (Node)",
};

/** Texte assistant (chat/completions), formats OpenAI v1/variants. */
function textFromChatCompletion(d) {
  if (!d) return null;
  const c0 = d.choices?.[0];
  const msg = c0?.message;
  if (!msg) {
    if (typeof c0?.text === "string" && c0.text.length) return c0.text;
    return null;
  }
  const c = msg.content;
  if (typeof c === "string" && c.length) return c;
  if (Array.isArray(c)) {
    const out = c
      .map((p) => {
        if (typeof p === "string") return p;
        if (p && typeof p === "object" && p.type === "text" && p.text) return p.text;
        if (p && typeof p === "object" && p.type === "output_text" && p.text) return p.text;
        return "";
      })
      .filter(Boolean);
    if (out.length) return out.join("");
  }
  return null;
}

const sleep = (ms) => new Promise((r) => setTimeout(r, ms));
const isRetry = (s) => s === 502 || s === 503 || s === 504 || s === 429;

function makeApiError(status, data) {
  const e = new Error("api");
  e.status = status;
  e.data = data;
  return e;
}

function extractFromResponses(d) {
  if (d?.error) return null;
  if (typeof d?.output_text === "string" && d.output_text.length) return d.output_text;
  if (Array.isArray(d?.output)) {
    const out = [];
    for (const block of d.output) {
      if (block.type === "message" && Array.isArray(block.content)) {
        for (const c of block.content) {
          if (c.type === "output_text" && c.text) out.push(c.text);
          if (c.type === "text" && c.text) out.push(c.text);
        }
      }
    }
    if (out.length) return out.join("\n");
  }
  return d?.text?.value || (d && JSON.stringify(d, null, 2)) || "";
}

function parseSseLine(line, onDelta, onChunkJson) {
  const t = line.trim();
  if (!t || t.startsWith(":") || !t.startsWith("data: ")) return;
  const data = t.slice(6);
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
    /* ignore */
  }
}

/** Dernier `usage` observé (souvent sur un chunk final avant [DONE] ; pas tous les fournisseurs l’émettent en stream). */
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
    for (const line of carry.slice(0, j + 1).split("\n"))
      parseSseLine(line, (c) => (out += c), onChunk);
    carry = carry.slice(j + 1);
  }
  if (carry.length) for (const line of carry.split("\n")) parseSseLine(line, (c) => (out += c), onChunk);
  return { text: out, usage: lastUsage };
}

async function doResponses() {
  const res = await fetch(`${API_BASE}/responses`, {
    method: "POST",
    headers: { ...headers, Accept: "application/json" },
    body: JSON.stringify({ model: MODEL, input: prompt, ...optionalChatReasoning() }),
  });
  const t = await res.text();
  let d;
  try {
    d = JSON.parse(t);
  } catch {
    throw new Error(`HTTP ${res.status} — non-JSON: ${t.slice(0, 400)}`);
  }
  if (!res.ok) throw makeApiError(res.status, d);
  const textOut = extractFromResponses(d);
  if (textOut && String(textOut).trim().length) return textOut;
  throw new Error("Réponse /responses sans texte exploitable.");
}

async function doOneShot() {
  const res = await fetch(`${API_BASE}/chat/completions`, {
    method: "POST",
    headers: { ...headers, Accept: "application/json" },
    body: JSON.stringify({
      model: MODEL,
      messages: [{ role: "user", content: prompt }],
      ...optionalChatReasoning(),
      ...optionalOutputTokenBudget(),
    }),
  });
  const t = await res.text();
  let d;
  try {
    d = JSON.parse(t);
  } catch {
    if (res.status === 504 || res.status === 502 || res.status === 503) {
      throw makeApiError(res.status, { error: { message: `HTML gateway error ${res.status}` } });
    }
    throw new Error(`HTTP ${res.status} — non-JSON: ${t.slice(0, 500)}`);
  }
  if (!res.ok) throw makeApiError(res.status, d);
  logChatUsageIfRequested(d);
  const out = textFromChatCompletion(d);
  if (out && String(out).trim().length) return out;
  const err = new Error("api");
  err.status = 200;
  err.data = { error: { message: "Réponse chat sans texte assistant (vérif. JSON input / modèle / proxy).", body: d } };
  throw err;
}

async function doStream() {
  const res = await fetch(`${API_BASE}/chat/completions`, {
    method: "POST",
    headers: { ...headers, Accept: "application/json" },
    body: JSON.stringify({
      model: MODEL,
      messages: [{ role: "user", content: prompt }],
      stream: true,
      ...optionalChatReasoning(),
      ...optionalOutputTokenBudget(),
    }),
  });
  const ct = (res.headers.get("content-type") || "").toLowerCase();
  const tErr = !res.ok ? await res.text() : null;
  if (tErr) {
    let d;
    try {
      d = JSON.parse(tErr);
    } catch {
      d = { error: tErr };
    }
    throw makeApiError(res.status, d);
  }
  if (ct.includes("application/json") && !ct.includes("event-stream") && !ct.includes("text/event")) {
    const t = await res.text();
    const d = JSON.parse(t);
    if (d?.error) throw makeApiError(400, d);
    logChatUsageIfRequested(d);
    const t2 = textFromChatCompletion(d);
    if (t2 && String(t2).trim().length) return t2;
    const err = new Error("api");
    err.status = 200;
    err.data = { error: { message: "Stream en JSON sans texte assistant", raw: t?.slice(0, 2000) } };
    throw err;
  }
  const s = await readSseDeltas(res);
  if (s.usage) logTokenUsageIfRequested(s.usage);
  if (s.text && s.text.length) return s.text;
  if (NO_ONESHOT_FALLBACK) {
    const err = new Error("api");
    err.status = 200;
    err.data = { error: { message: "Stream sans texte assistant (repli one-shot désactivé par CODEX_NO_ONESHOT_FALLBACK=1).", hint: "Réessayer, ou vérifier le proxy / le modèle." } };
    throw err;
  }
  return doOneShot();
}

/**
 * Node 20+ : `node:undici` permet de désactiver/étirer le timeout de lecture du **corps** (SSE),
 * pratique si le modèle fait une longue pause **entre** des morceaux de flux. Sur Node 18, ignore silencieusement.
 */
async function installLongStreamDispatcher() {
  if ((process.env.CODEX_UNDICI_LONG_STREAM || "1").toLowerCase() === "0") return;
  try {
    const u = await import("node:undici");
    if (!u?.setGlobalDispatcher || !u?.Agent) return;
    const num = (v, def) => {
      if (v === undefined || v === "" || v == null) return def;
      const n = Math.max(0, parseInt(String(v), 10) || 0);
      return Number.isFinite(n) && n > 0 ? n : def;
    };
    const bRaw = process.env.CODEX_UNDICI_BODY_TIMEOUT_MS;
    const bodyTimeout = bRaw === undefined || bRaw === "" ? 0 : Math.max(0, parseInt(String(bRaw), 10) || 0);
    u.setGlobalDispatcher(
      new u.Agent({
        connectTimeout: num(process.env.CODEX_UNDICI_CONNECT_TIMEOUT_MS, 600_000),
        headersTimeout: num(process.env.CODEX_UNDICI_HEADERS_TIMEOUT_MS, 600_000),
        bodyTimeout,
      })
    );
    if ((process.env.CODEX_UNDICI_DEBUG || "").toLowerCase() === "1")
      console.error(
        "[codex] undici: bodyTimeout_ms=",
        bodyTimeout,
        "(0=désactive ; délai max entre 2 morceaux du flux), Node",
        process.version
      );
  } catch {
    /* Node<20 : pas de `node:undici` intégré */
  }
}

async function withRetry(name, fn) {
  for (let i = 0; i < RETRY_MAX; i++) {
    try {
      return await fn();
    } catch (e) {
      const st = e?.status;
      if (e?.message === "api" && isRetry(st) && i < RETRY_MAX - 1) {
        const w = SLEEP_BASE_MS * Math.min(1 << i, 20);
        console.error(
          `[codex] ${name} — HTTP ${st} (tentative ${i + 1}/${RETRY_MAX}) — relance dans ${w}ms…`
        );
        await sleep(w);
        continue;
      }
      throw e;
    }
  }
  throw new Error("withRetry: exhaust");
}

void (async () => {
  try {
    await installLongStreamDispatcher();
    if (WIRE === "responses" || WIRE === "r") {
      const t = await withRetry("responses", () => doResponses());
      fs.writeFileSync(outPath, String(t), "utf8");
      console.log("✅ Codex — wire=responses");
      console.log(outPath);
      return;
    }
    if (DISABLE_STREAM) {
      const t = await withRetry("chat/one-shot", () => doOneShot());
      fs.writeFileSync(outPath, t, "utf8");
      console.log("✅ Codex — chat/one-shot");
      console.log(outPath);
      return;
    }
    let t;
    try {
      t = await withRetry("stream", () => doStream());
    } catch (e) {
      if (NO_ONESHOT_FALLBACK) {
        if (e?.data) console.error(JSON.stringify(e.data, null, 2));
        else console.error("❌", e?.message || e);
        process.exit(1);
      }
      console.error("[codex] stream a échoué après reprises, repli chat (sans stream)…", e?.status || e?.message);
      t = await withRetry("chat/one-shot", () => doOneShot());
    }
    fs.writeFileSync(outPath, t, "utf8");
    console.log("✅ Codex — terminé");
    console.log(outPath);
  } catch (e) {
    if (e?.data) console.error(JSON.stringify(e.data, null, 2));
    else console.error("❌", e?.message || e);
    process.exit(1);
  }
})();

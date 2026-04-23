import fs from "node:fs";
import path from "node:path";
import { fileURLToPath } from "node:url";

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const root = path.resolve(__dirname, "..");

function loadFileEnv(f) {
  if (!fs.existsSync(f)) return;
  const raw = fs.readFileSync(f, "utf8");
  for (const line of raw.split("\n")) {
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
/** Défaut `gpt-5.4` (souvent plus stable sur le proxy) ; `gpt-5.4-pro` en override si ton fournisseur renvoie du texte. */
const MODEL = process.env.CODEX_MODEL_COMPLEX || "gpt-5.4";
const WIRE = (process.env.CODEX_WIRE || "chat").toLowerCase();
const RAW_PROMPT = (process.env.CODEX_RAW_PROMPT || "").toLowerCase() === "1";
const DISABLE_STREAM = (process.env.CODEX_DISABLE_STREAM || "").toLowerCase() === "1";
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
  console.error("Définis CODEX_API_BASE + CODEX_API_KEY dans .env et/ou .env.codex.");
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

function parseSseLine(line, onDelta) {
  const t = line.trim();
  if (!t || t.startsWith(":") || !t.startsWith("data: ")) return;
  const data = t.slice(6);
  if (data === "[DONE]") return;
  try {
    const j = JSON.parse(data);
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

async function readSseDeltas(r) {
  if (!r.body) return "";
  const reader = r.body.getReader();
  const dec = new TextDecoder();
  let carry = "";
  let out = "";
  for (;;) {
    const { done, value } = await reader.read();
    if (done) break;
    if (!value) continue;
    carry += dec.decode(value, { stream: true });
    const j = carry.lastIndexOf("\n");
    if (j === -1) continue;
    for (const line of carry.slice(0, j + 1).split("\n")) parseSseLine(line, (c) => (out += c));
    carry = carry.slice(j + 1);
  }
  if (carry.length) for (const line of carry.split("\n")) parseSseLine(line, (c) => (out += c));
  return out;
}

async function doResponses() {
  const res = await fetch(`${API_BASE}/responses`, {
    method: "POST",
    headers: { ...headers, Accept: "application/json" },
    body: JSON.stringify({ model: MODEL, input: prompt }),
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
    body: JSON.stringify({ model: MODEL, messages: [{ role: "user", content: prompt }] }),
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
    const t2 = textFromChatCompletion(d);
    if (t2 && String(t2).trim().length) return t2;
    const err = new Error("api");
    err.status = 200;
    err.data = { error: { message: "Stream en JSON sans texte assistant", raw: t?.slice(0, 2000) } };
    throw err;
  }
  const s = await readSseDeltas(res);
  if (s && s.length) return s;
  return doOneShot();
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

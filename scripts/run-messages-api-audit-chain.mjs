/**
 * Audit Messages API en **1 ou 2 tours** :
 * - Si le prompt ≤ CHAIN_MAX_USER_CHARS → un seul appel (identique à l’usage simple).
 * - Sinon → tour 1 : première moitié + consigne d’ancrage ; tour 2 : historique + suite + audit final.
 *
 * Même variables que `run-messages-api-audit.mjs` (ANTHROPIC_*, AUDIT_API_*).
 *
 * Usage :
 *   node scripts/run-messages-api-audit-chain.mjs <prompt.md> [sortie.md]
 *
 * Env :
 *   CHAIN_MAX_USER_CHARS   défaut 120000 — au-dessus, découpe en deux messages user.
 *   CHAIN_SPLIT_AT         index de découpe (caractères) ; défaut = floor(length/2).
 */
import fs from "node:fs";
import https from "node:https";
import path from "node:path";
import { fileURLToPath } from "node:url";

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const root = path.resolve(__dirname, "..");

for (const f of [".env.orcai", ".env.anthropic.local", ".env", ".env.local"]) {
  const p = path.join(root, f);
  if (!fs.existsSync(p)) continue;
  for (const line of fs.readFileSync(p, "utf8").split("\n")) {
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

const BASE = (process.env.ANTHROPIC_BASE_URL || "https://api.anthropic.com").replace(/\/$/, "");
const TOKEN = (
  process.env.ANTHROPIC_AUTH_TOKEN ||
  process.env.ANTHROPIC_API_KEY ||
  ""
).trim();
const MODEL = (process.env.ANTHROPIC_MODEL || "claude-opus-4-7-20250514").trim();
const MAX_TOK = Math.min(
  200_000,
  Math.max(1024, parseInt(process.env.AUDIT_API_MAX_TOKENS || "32768", 10) || 32768)
);
const FETCH_TIMEOUT_MS = Math.max(
  60_000,
  parseInt(process.env.CTOK_FETCH_TIMEOUT_MS || "900000", 10) || 900_000
);
const CHAIN_MAX = Math.max(
  10_000,
  parseInt(process.env.CHAIN_MAX_USER_CHARS || "120000", 10) || 120_000
);

function httpsPostJson(urlStr, headers, body) {
  const u = new URL(urlStr);
  return new Promise((resolve, reject) => {
    const req = https.request(
      {
        hostname: u.hostname,
        path: u.pathname,
        method: "POST",
        port: 443,
        timeout: FETCH_TIMEOUT_MS,
        headers: { ...headers, "Content-Length": Buffer.byteLength(body) },
      },
      (res) => {
        const chunks = [];
        res.on("data", (c) => chunks.push(c));
        res.on("end", () => {
          resolve({
            status: res.statusCode || 0,
            text: Buffer.concat(chunks).toString("utf8"),
          });
        });
      }
    );
    req.on("error", reject);
    req.on("timeout", () => req.destroy(new Error(`timeout ${FETCH_TIMEOUT_MS}ms`)));
    req.write(body);
    req.end();
  });
}

async function messagesOnce(bodyObj) {
  const url = `${BASE}/v1/messages`;
  const body = JSON.stringify(bodyObj);
  const r = await httpsPostJson(
    url,
    {
      "content-type": "application/json",
      "anthropic-version": "2023-06-01",
      "x-api-key": TOKEN,
    },
    body
  );
  let j;
  try {
    j = JSON.parse(r.text);
  } catch {
    return { http: r.status, err: "non-json", raw: r.text.slice(0, 2000) };
  }
  if (r.status < 200 || r.status >= 300) {
    return { http: r.status, err: j.error || j, raw: r.text.slice(0, 2000) };
  }
  let out = "";
  for (const b of j.content || []) {
    if (b.type === "text" && b.text) out += b.text;
  }
  return {
    http: r.status,
    usage: j.usage,
    model: j.model,
    stop_reason: j.stop_reason,
    content: out,
    err: null,
  };
}

function noToolsPrefix(user) {
  if (process.env.AUDIT_API_NO_TOOLS_PREFIX === "0") return user;
  return (
    "Réponds **uniquement** en Markdown (texte dense). **Interdiction** d’outils / d’appels d’outil / d’exécution : aucun accès hors ce message.\n\n" +
    "- Si le prompt contient des blocs avec le **texte intégral** des fichiers (méga-contexte), traite ce texte comme **déjà fourni** et cite-le ; ne dis pas que tu ne peux pas lire les fichiers.\n" +
    "- Si le prompt ne contient que des **chemins** sans contenu, les chemins sont des références seules : marque **NOT_VALIDATED** et la mission pour obtenir le contenu.\n\n" +
    "---\n\n" +
    user
  );
}

async function callWithEffort(body) {
  let res = await messagesOnce(body);
  if (res.http === 400 && body.output_config) {
    delete body.output_config;
    console.error(
      "[run-messages-api-audit-chain] 400 avec output_config — nouvel essai sans effort explicite."
    );
    res = await messagesOnce(body);
  }
  return res;
}

async function main() {
  const promptFile = process.argv[2];
  const outFile =
    process.argv[3] ||
    path.join(root, "reports", "audit", "CLAUDE_MESSAGES_API_AUDIT_CHAIN_LATEST.md");

  if (!TOKEN) {
    console.error(
      "Manque ANTHROPIC_AUTH_TOKEN ou ANTHROPIC_API_KEY (env ou .env.orcai / .env.anthropic.local)."
    );
    process.exit(2);
  }
  if (!promptFile) {
    console.error(
      "Usage: node scripts/run-messages-api-audit-chain.mjs <prompt.md> [sortie.md]"
    );
    process.exit(2);
  }

  const absPrompt = path.isAbsolute(promptFile)
    ? promptFile
    : path.join(root, promptFile);
  if (!fs.existsSync(absPrompt)) {
    console.error(`Fichier prompt introuvable: ${absPrompt}`);
    process.exit(2);
  }

  const full = fs.readFileSync(absPrompt, "utf8");
  const effort = (process.env.AUDIT_API_OUTPUT_EFFORT || "max").trim();
  const effortBlock =
    effort && !["0", "false", "none", ""].includes(effort.toLowerCase())
      ? { output_config: { effort } }
      : {};

  const bridge1 =
    "\n\n---\n\n## MÉTA (tour 1)\n\nTu reçois **la première moitié** d’un méga-contexte. Réponds par :\n" +
    "1. Une ligne `REÇU_TOUR_1`.\n" +
    "2. Un **résumé d’ancrage** (15–25 lignes max) : invariants, décisions, risques déjà visibles dans **ce** bloc.\n" +
    "3. **Aucun** audit final pour l’instant.\n";

  const bridge2 =
    "\n\n---\n\n## MÉTA (tour 2)\n\nVoici **la suite** du même méga-contexte (tour 1 dans l’historique). " +
    "Produis maintenant **l’audit final complet** demandé dans le document (section instruction / questions), " +
    "en intégrant **explicitement** ce que tu as vu au tour 1 et ce bloc. Format Markdown structuré.\n";

  let mode;
  let tour1Text = "";
  let tour2Res;
  let usageLine = "";

  if (full.length <= CHAIN_MAX) {
    mode = "single";
    const body = {
      model: MODEL,
      max_tokens: MAX_TOK,
      messages: [{ role: "user", content: noToolsPrefix(full) }],
      ...effortBlock,
    };
    tour2Res = await callWithEffort(body);
    usageLine = JSON.stringify(tour2Res.usage);
  } else {
    mode = "chain";
    const splitRaw = process.env.CHAIN_SPLIT_AT;
    const splitAt = splitRaw
      ? Math.min(full.length - 1, Math.max(1, parseInt(splitRaw, 10) || Math.floor(full.length / 2)))
      : Math.floor(full.length / 2);
    const a = full.slice(0, splitAt);
    const b = full.slice(splitAt);
    const u1 = noToolsPrefix(a + bridge1);
    const body1 = {
      model: MODEL,
      max_tokens: Math.min(MAX_TOK, 8192),
      messages: [{ role: "user", content: u1 }],
      ...effortBlock,
    };
    const r1 = await callWithEffort(body1);
    if (r1.err || r1.http !== 200) {
      console.error(JSON.stringify(r1, null, 2));
      process.exit(1);
    }
    tour1Text = r1.content || "";
    const u2 = b + bridge2;
    const body2 = {
      model: MODEL,
      max_tokens: MAX_TOK,
      messages: [
        { role: "user", content: u1 },
        { role: "assistant", content: tour1Text },
        { role: "user", content: u2 },
      ],
      ...effortBlock,
    };
    tour2Res = await callWithEffort(body2);
    usageLine = JSON.stringify({ tour1: r1.usage, tour2: tour2Res.usage });
  }

  if (tour2Res.err || tour2Res.http !== 200) {
    console.error(JSON.stringify(tour2Res, null, 2));
    process.exit(1);
  }

  const absOut = path.isAbsolute(outFile) ? outFile : path.join(root, outFile);
  fs.mkdirSync(path.dirname(absOut), { recursive: true });

  const header = `# Audit API Messages — chaîne (${mode})

- **Base** : \`${BASE}\`
- **Modèle** : \`${MODEL}\`
- **max_tokens** (dernier tour) : ${MAX_TOK}
- **usage** : \`${usageLine}\`
- **stop_reason** : \`${tour2Res.stop_reason || ""}\`
- **CHAIN_MAX_USER_CHARS** : ${CHAIN_MAX}
- **prompt_chars** : ${full.length}

---

`;

  let bodyOut = "";
  if (mode === "chain") {
    bodyOut += `## Tour 1 (ancrage)\n\n${tour1Text}\n\n---\n\n## Tour 2 (audit final)\n\n`;
  }
  bodyOut += tour2Res.content || "";

  fs.writeFileSync(absOut, header + bodyOut, "utf8");
  console.log(absOut);
}

main().catch((e) => {
  console.error(e);
  process.exit(1);
});

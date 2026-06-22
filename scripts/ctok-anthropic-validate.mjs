/**
 * Valide le proxy Anthropic (ex. https://api.ctok.ai) via l’API Messages **native** (pas le binaire `claude` obligatoire).
 * Variables :
 *   ANTHROPIC_BASE_URL  (défaut: https://api.anthropic.com) — ici: https://api.ctok.ai
 *   ANTHROPIC_AUTH_TOKEN ou ANTHROPIC_API_KEY
 *   ANTHROPIC_MODEL     (défaut: tente un opus / sonnet 4, puis repli 3.5)
 *   CTOK_MAX_OUTPUT     (cible max_tokens sortie, défaut: 20000) — 50k peut être refusé par le plan/proxy
 *   CTOK_CHAIN_UNTIL_TOTAL  (défaut: 50000) — boucles /v1/messages jusqu’à cumul usage in+out ≥ ce seuil
 *   CTOK_CHAIN_MAX_ROUNDS  (défaut: 12, ou 24 si CTOK_CHAIN_UNTIL_TOTAL ≥ 50000) — plafond de tours
 *   CTOK_FETCH_TIMEOUT_MS (défaut: 900000) — délai socket https (évite le timeout ~300s du fetch undici)
 *   CLAUDE_CODE_DISABLE_NONESSENTIAL_TRAFFIC=1 (informatif — pas consommé par ce script Node)
 */
import fs from "node:fs";
import https from "node:https";
import path from "node:path";
import { fileURLToPath, URL } from "node:url";

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const root = path.resolve(__dirname, "..");

for (const f of [".env", ".env.anthropic.local", ".env.local"]) {
  const p = path.join(root, f);
  if (fs.existsSync(p)) {
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
}

const BASE = (process.env.ANTHROPIC_BASE_URL || "https://api.anthropic.com").replace(/\/$/, "");
const TOKEN = (process.env.ANTHROPIC_AUTH_TOKEN || process.env.ANTHROPIC_API_KEY || "").trim();
const TARGET_OUT = Math.min(
  100_000,
  Math.max(1_000, parseInt(process.env.CTOK_MAX_OUTPUT || "20000", 10) || 20_000)
);
/** Délai max socket (générations longues, max_tokens 50k) — le fetch/undici par défaut ~300s peut couper. */
const FETCH_TIMEOUT_MS = Math.max(
  60_000,
  parseInt(process.env.CTOK_FETCH_TIMEOUT_MS || "900000", 10) || 900_000
);

/**
 * @param {string} urlStr
 * @param {Record<string, string>} headers
 * @param {string} body
 * @returns {Promise<{ status: number, text: string, ms: number }>}
 */
function httpsPostJson(urlStr, headers, body) {
  const u = new URL(urlStr);
  const t0 = Date.now();
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
          const text = Buffer.concat(chunks).toString("utf8");
          resolve({ status: res.statusCode || 0, text, ms: Date.now() - t0 });
        });
      }
    );
    req.on("error", reject);
    req.on("timeout", () => {
      req.destroy(
        new Error(
          `Socket timeout after ${FETCH_TIMEOUT_MS}ms — augmenter CTOK_FETCH_TIMEOUT_MS si besoin.`
        )
      );
    });
    req.write(body);
    req.end();
  });
}

const CANDIDATE_MODELS = (process.env.ANTHROPIC_MODEL
  ? [process.env.ANTHROPIC_MODEL]
  : [
      "claude-opus-4-1-20250514",
      "claude-3-opus-20240229",
      "claude-sonnet-4-5-20250929",
      "claude-sonnet-4-20250514",
      "claude-3-5-sonnet-20241022",
    ]
).filter(Boolean);

const AUDIT_PROMPT = `Tu es architecte de plateforme SaaS (FoodKing, Laravel+Vue, POS, KDS, kiosk, attente, paiement multi-pistes).

Livrable : **audit textuel** en français, structuré en Markdown, couvrant **A→Z** :
- Caisse POS, borne kiosk, file d'attente / reprise, cuisine KDS, synchronisation temps réel, paiements (cash, carte, intégrations), branches/tenants, risques P0, dette, observabilité.
- Section dédiée **synchronisation** (incl. orchestre multi-agents, agents-activity, pas de conflit).
Chaque point doit être **exploitable** (recommandation ou risque) — pas de remplissage.
Termine par une ligne : AUDIT_CLAUDE_DONE: sections=N|mots=approx`;

if (!TOKEN) {
  console.error("Manque ANTHROPIC_AUTH_TOKEN ou ANTHROPIC_API_KEY (env ou .env.anthropic.local).");
  process.exit(2);
}

async function messagesOnce({ model, max_tokens, user }) {
  const url = `${BASE}/v1/messages`;
  const body = {
    model,
    max_tokens,
    messages: [{ role: "user", content: user }],
  };
  let r;
  try {
    r = await httpsPostJson(url, {
      "content-type": "application/json",
      "anthropic-version": "2023-06-01",
      "x-api-key": TOKEN,
    }, JSON.stringify(body));
  } catch (e) {
    return { http: 0, ms: 0, err: String(e && e.message ? e.message : e), text_head: "" };
  }
  const { status: http, text, ms } = r;
  let j;
  try {
    j = JSON.parse(text);
  } catch {
    return { http, ms, err: "non-json", text_head: text.slice(0, 600) };
  }
  if (http < 200 || http >= 300) {
    return { http, ms, err: j.error || j, usage: null, content: "" };
  }
  const blocks = j.content || [];
  let out = "";
  for (const b of blocks) {
    if (b.type === "text" && b.text) out += b.text;
  }
  return {
    http,
    ms,
    usage: j.usage || null,
    model: j.model,
    content: out,
    stop_reason: j.stop_reason,
    err: null,
  };
}

async function tryWithBearer({ model, max_tokens, user }) {
  const url = `${BASE}/v1/messages`;
  const body = JSON.stringify({ model, max_tokens, messages: [{ role: "user", content: user }] });
  let r;
  try {
    r = await httpsPostJson(url, {
      "content-type": "application/json",
      "anthropic-version": "2023-06-01",
      Authorization: `Bearer ${TOKEN}`,
    }, body);
  } catch (e) {
    return { http: 0, err: String(e && e.message ? e.message : e) };
  }
  const { status: http, text } = r;
  let j;
  try {
    j = JSON.parse(text);
  } catch {
    return { http, err: { raw: text.slice(0, 500) } };
  }
  if (http < 200 || http >= 300) return { http, err: j };
  return { http: 200, usage: j.usage, model: j.model, content: (j.content || []).map((b) => b.text || "").join("\n") };
}

const CHAIN_UNTIL = Math.max(
  0,
  parseInt(process.env.CTOK_CHAIN_UNTIL_TOTAL || "50000", 10) || 50_000
);
const CHAIN_MAX_ROUNDS = Math.min(
  64,
  Math.max(
    1,
    parseInt(
      process.env.CTOK_CHAIN_MAX_ROUNDS ||
        (CHAIN_UNTIL >= 50_000 ? "24" : "12"),
      10
    ) || (CHAIN_UNTIL >= 50_000 ? 24 : 12)
  )
);

const result = {
  at: new Date().toISOString(),
  base: BASE,
  target_output_tokens: TARGET_OUT,
  chain_target_total_tokens: CHAIN_UNTIL,
  chain_max_rounds: CHAIN_MAX_ROUNDS,
  steps: [],
  chain: [],
};

// 1) Ping (court)
let ping = null;
for (const model of CANDIDATE_MODELS) {
  ping = await messagesOnce({ model, max_tokens: 64, user: "Reply with only: CTOK_PING_OK" });
  if (ping.http === 200) {
    result.ping_model = model;
    break;
  }
  if (ping.http === 503 && JSON.stringify(ping.err || "").includes("No available accounts")) {
    result.opus_or_upstream_full = true;
  }
  result.steps.push({ phase: "ping", model, ...ping });
  ping = await tryWithBearer({ model, max_tokens: 64, user: "Reply with only: CTOK_PING_OK" });
  if (ping.http === 200) {
    result.ping_model = model;
    result.ping_header = "bearer";
    break;
  }
  result.steps.push({ phase: "ping_bearer", model, http: ping.http, err: ping.err });
  ping = null;
}
if (!ping || ping.http !== 200) {
  result.severity = "NO_GO";
  result.reason = "Aucun modèle n’a accepté l’appel /v1/messages (auth, base, ou modèles). Voir `steps` dans le JSON export.";
} else {
  result.steps.push({ phase: "ping_ok", model: result.ping_model, usage: ping.usage, stop: ping.stop_reason });
}

// 2) Long run (plafond demandé, avec repli binaire)
let long = null;
const model = result.ping_model || CANDIDATE_MODELS[0];
for (const cap of [TARGET_OUT, 16000, 8000, 4096].filter((v, i, a) => a.indexOf(v) === i)) {
  long = await messagesOnce({ model, max_tokens: cap, user: AUDIT_PROMPT });
  if (long.http === 200) {
    result.long_max_tokens_used = cap;
    result.long_usage = long.usage;
    result.long_chars = long.content?.length || 0;
    result.long_excerpt = (long.content || "").slice(0, 1_200);
    result.long_stop_reason = long.stop_reason;
    break;
  }
  result.steps.push({ phase: "long", max_tokens: cap, http: long.http, err: long.err, stop: long.stop_reason });
}
if (!long || long.http !== 200) {
  result.severity = result.severity || "NO_GO";
  result.reason = (result.reason || "") + " Échec audit long.";
} else {
  const outTok0 = long.usage?.output_tokens || 0;
  // Si la sortie est anormalement courte (p.ex. le modèle annonce une « exploration » sans produire l’audit), relancer en mode prose forcée.
  if (outTok0 < 1_000) {
    const FORCED = `N’inclus aucune phrase du type « je vais explorer » ou attente. Réponse **seule** : un audit long en **Markdown** sur FoodKing (caisse, kiosk, KDS, attente, paiement, multi-branche, sync temps réel). Minimum **25** titres \`## N. …\` ; chaque section **8 à 10 phrases** denses en français. Aucun outil, aucune explication méta.`;
    const long2 = await messagesOnce({
      model,
      max_tokens: Math.min(16_000, TARGET_OUT),
      user: FORCED,
    });
    if (long2.http === 200) {
      result.long2_usage = long2.usage;
      result.long2_chars = long2.content?.length || 0;
      result.long2_stop = long2.stop_reason;
      result.long2_excerpt = (long2.content || "").slice(0, 1_200);
      long = long2;
    } else {
      result.long2_error = { http: long2?.http, err: long2?.err, text: long2?.text_head };
    }
  }
  const outTok = long.usage?.output_tokens || 0;
  const inTok = long.usage?.input_tokens || 0;
  result.output_tokens = outTok;
  result.input_tokens = inTok;
  const totalM = inTok + outTok;
  result.severity =
    outTok >= 50_000
      ? "GO_OUTPUT_50K"
      : totalM >= 50_000
        ? "GO_TOTAL_50K"
        : totalM > 8_000
          ? "GO_CAPPED"
          : "GO_WEAK";
  if (outTok < 50_000 && totalM < 50_000) {
    result.note =
      "Plafond effectif (sortie) ou budget plan : sortie < 50k *tokens* et total < 50k. Vérifier **dashboard** ctok (plafond `max_tokens`, modèle) ; `Opus` peut renvoyer 503 (pas de compte disponible) — utiliser le modèle qui **passe** le ping. Réessayer `CTOK_MAX_OUTPUT=50000` si le plan l’autorise.";
  } else if (outTok < 50_000 && totalM >= 50_000) {
    result.note =
      "Objectif **50k tokens au sens cumul (entrée+sortie)** atteint via `usage` ; la **sortie seule** reste < 50k (normal pour un long prompt). Comparer au dashboard facturé.";
  }

  let sumIn = inTok;
  let sumOut = outTok;
  const sumTotal = () => sumIn + sumOut;
  if (CHAIN_UNTIL > 0 && sumTotal() < CHAIN_UNTIL && long.http === 200) {
    let round = 0;
    while (sumTotal() < CHAIN_UNTIL && round < CHAIN_MAX_ROUNDS) {
      round++;
      const cont = await messagesOnce({
        model,
        max_tokens: Math.min(16_000, TARGET_OUT),
        user: `Poursuis l’audit FoodKing (nouvelles sections **##** numérotées), focus: synchronisation temps réel (Pusher/Echo), files d’attente, cohérence entre caisse et cuisine, bornes offline. **Pas** de phrase d’introduction — contenu dense immédiat. Round ${round}.`,
      });
      result.chain.push({
        round,
        usage: cont.usage,
        stop: cont.stop_reason,
        chars: (cont.content || "").length,
      });
      if (cont.http !== 200) break;
      sumIn += cont.usage?.input_tokens || 0;
      sumOut += cont.usage?.output_tokens || 0;
      result.chained_total_in = sumIn;
      result.chained_total_out = sumOut;
      result.chained_total = sumIn + sumOut;
      if (sumTotal() >= CHAIN_UNTIL) {
        result.chain_target_met = true;
        break;
      }
    }
    result.chain_rounds_executed = round;
    if ((result.chained_total || 0) >= CHAIN_UNTIL || sumTotal() >= CHAIN_UNTIL) {
      result.severity = "GO_CHAIN_50K_TOTAL";
      result.note = `Chaînage \`CTOK_CHAIN_UNTIL_TOTAL\` : cumul **input+output** (champs \`usage\`) ≥ **${CHAIN_UNTIL}** (voir \`chained_total\` / \`chain\`).`;
    } else if (CHAIN_UNTIL > 0 && (result.chained_total || 0) > 0) {
      result.chain_incomplete = true;
      result.chain_stopped_at_tokens = result.chained_total;
      if (round >= CHAIN_MAX_ROUNDS) {
        result.chain_stop_reason = "max_rounds";
        result.note = `Chaînage : **${result.chained_total}** / ${CHAIN_UNTIL} tokens (in+out, champs \`usage\` du 1er appel + tours). Plafond \`CTOK_CHAIN_MAX_ROUNDS=${CHAIN_MAX_ROUNDS}\` atteint — augmenter la variable pour viser 50k+ (certains tours peuvent être courts si \`stop_reason\` = \`tool_use\` côté proxy).`;
      }
    }
  }
}

const outDir = path.join(root, "reports", "audit");
fs.mkdirSync(outDir, { recursive: true });
const stamp = new Date().toISOString().replace(/[:.]/g, "-");
const jsonPath = path.join(outDir, `CTOK_ANTHROPIC_VALIDATE_${stamp}.json`);
fs.writeFileSync(jsonPath, JSON.stringify(result, null, 2), "utf8");

const md = [];
md.push(`# Validation proxy Anthropic (ctok) — ${result.at.split("T")[0]}\n\n`);
md.push(`- **Base URL** : \`${BASE}\` (appel \`POST /v1/messages\`, header \`x-api-key\` + \`anthropic-version\`)\n`);
md.push(
  `- **Verdict** : **${
    result.severity || "INCONNU"
  }** — ${result.note || (result.severity === "GO" ? "Objectif 50k+ tokens de sortie atteint (usage API)." : "Voir détail JSON.")}\n`
);
if (result.ping_model) md.push(`- **Modèle ping** : \`${result.ping_model}\`\n`);
if (long?.usage) {
  md.push(
    `- **Usage (audit long)** : input **${result.input_tokens}** | output **${result.output_tokens}** (total comptage API, pas une estimation)\n`
  );
  md.push(`- **max_tokens effectivement demandé** (dernier essai gagnant) : **${result.long_max_tokens_used}**\n`);
  md.push(`- **Longueur texte (car.)** : **${result.long_chars}**\n`);
}
if (result.reason) md.push(`- **Détail échec** : ${String(result.reason).slice(0, 800)}\n`);
md.push(`\n## Go / No-Go orchestration (usage principal du projet)\n\n`);
const goLike = result.severity !== "NO_GO";
if (goLike) {
  md.push(
    `- **GO conditionnel** : l’API répond 200, les tokens sont comptés. Pour **production** : vérifier SLA, taux d’erreur, et cohérence avec **\`claude\` CLI** (même base + même clé en \`ANTHROPIC_API_KEY\`).\n`
  );
  if (result.chained_total != null) {
    md.push(
      `- **Cumul chaîne (si présent)** : in+out = **${result.chained_total}** (cible \`CTOK_CHAIN_UNTIL_TOTAL\` : **${result.chain_target_total_tokens}**)\n`
    );
  }
  if (result.ping_model?.includes("opus") === false && result.opus_or_upstream_full) {
    md.push(
      `- **Opus** : les variantes \`claude-opus-*\` ont renvoyé **503** (pas de compte disponible) sur ce test — **orchestrateur** : cibler explicitement le modèle qui **passe** le ping (ex. Sonnet) ou négocier l’ajout d’**Opus** côté proxy.\n`
    );
  }
  md.push(
    `- Mappez **\`ANTHROPIC_AUTH_TOKEN\` → \`ANTHROPIC_API_KEY\`** si un outil ne lit que la clé « officielle » Anthropic.\n`
  );
} else {
  md.push(`- **NO-GO** : corriger auth, modèle, ou reachability du proxy avant d’y brancher l’orchestrateur.\n`);
}
md.push(`\n## Sécurité\n\n- Ne jamais committer de jeton ; rotation si exposé (chat, capture).\n\n`);
md.push(`JSON machine : \`${path.relative(root, jsonPath)}\`\n`);

const mdPath = path.join(outDir, `CTOK_ANTHROPIC_GO_NOGO_${result.at.slice(0, 10)}.md`);
fs.writeFileSync(mdPath, md.join(""), "utf8");

console.log(
  JSON.stringify(
    { json: jsonPath, md: mdPath, severity: result.severity, outTok: result.output_tokens },
    null,
    2
  )
);
process.exit(result.severity && result.severity.startsWith("NO") ? 1 : 0);

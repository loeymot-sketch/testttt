/**
 * Audit via API Messages (Anthropic-compatible) — **pas** le binaire `claude -p`.
 *
 * Utile quand le terminal Claude Code est bloqué (quota abonnement) mais qu’une clé
 * **API** (Anthropic direct, Orcai, ctok, etc.) fonctionne encore.
 *
 * Variables (env ou fichiers .env chargés ci-dessous) :
 *   ANTHROPIC_BASE_URL     défaut https://api.anthropic.com
 *   ANTHROPIC_AUTH_TOKEN   ou ANTHROPIC_API_KEY (obligatoire)
 *   ANTHROPIC_MODEL        défaut claude-opus-4-7-20250514 (adapter selon proxy)
 *   AUDIT_API_MAX_TOKENS   défaut 32768
 *   AUDIT_API_OUTPUT_EFFORT  ex. max | xhigh — envoyé dans output_config ; retiré auto si 400
 *   AUDIT_API_CLOSEOUT_SECOND_TURN  défaut 1 — si la réponse ne se termine pas par les trois lignes
 *     MASTER_AUDIT_VERDICT / SOFTWARE_DECISION / NEXT_CODEX_MISSION, second appel API (historique)
 *     pour les ajouter. Mettre à 0 pour désactiver.
 *   CTOK_FETCH_TIMEOUT_MS  défaut 900000
 *
 * Fichiers .env chargés (première occurrence gagne si déjà défini dans process.env) :
 *   .env.orcai, .env.anthropic.local, .env, .env.local
 *
 * Usage :
 *   node scripts/run-messages-api-audit.mjs <prompt.txt> [sortie.md]
 *
 * Exemple (prompt court) :
 *   node scripts/run-messages-api-audit.mjs reports/audit/_CENTRAL_SYNC_ORCHESTRATION_CLAUDE_AUDIT_PROMPT_2026-04-30.txt reports/audit/CLAUDE_CENTRAL_SYNC_API_AUDIT_2026-04-30.md
 *
 * Méga-contexte (fichiers collés + instruction) :
 *   node scripts/assemble-mega-api-context-va-sys.mjs
 *   node scripts/run-messages-api-audit.mjs reports/audit/_MEGA_API_CONTEXT_VA_SYS_FULL.md reports/audit/CLAUDE_VA_SYS_MEGA_API_AUDIT.md
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

/**
 * @param {string} urlStr
 * @param {Record<string, string>} headers
 * @param {string} body
 */
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

/** @param {string} text */
function hasMasterCloseout(text) {
  const lines = String(text || "")
    .split(/\r?\n/)
    .map((line) => line.trim())
    .filter(Boolean);
  if (lines.length < 3) return false;
  const [verdict, decision, next] = lines.slice(-3);

  if (!/^MASTER_AUDIT_VERDICT:\s*(PASS|REWORK)$/.test(verdict)) return false;
  if (
    !/^SOFTWARE_DECISION:\s*(READY_FOR_VA_SYS_00_05_EXECUTION|HOLD)$/.test(
      decision
    )
  )
    return false;
  if (!/^NEXT_CODEX_MISSION:\s*\S.+$/.test(next)) return false;
  return true;
}

async function main() {
  const promptFile = process.argv[2];
  const outFile =
    process.argv[3] ||
    path.join(root, "reports", "audit", "CLAUDE_MESSAGES_API_AUDIT_LATEST.md");

  if (!promptFile) {
    console.error(
      "Usage: node scripts/run-messages-api-audit.mjs <prompt.txt> [sortie.md]"
    );
    process.exit(2);
  }
  if (!TOKEN) {
    console.error(
      "Manque ANTHROPIC_AUTH_TOKEN ou ANTHROPIC_API_KEY (env ou .env.orcai / .env.anthropic.local)."
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
  let user = fs.readFileSync(absPrompt, "utf8");
  /* Orcai / certains proxies renvoient stop_reason=tool_use avec quasi vide si le modèle tente des outils absents du corps. */
  if (process.env.AUDIT_API_NO_TOOLS_PREFIX !== "0") {
    user =
      "Réponds **uniquement** en Markdown (texte dense). **Interdiction** d’outils / d’appels d’outil / d’exécution : aucun accès hors ce message.\n\n" +
      "- Si le prompt contient des blocs avec le **texte intégral** des fichiers (méga-contexte), traite ce texte comme **déjà fourni** et cite-le ; ne dis pas que tu ne peux pas lire les fichiers.\n" +
      "- Si le prompt ne contient que des **chemins** sans contenu, les chemins sont des références seules : marque **NOT_VALIDATED** et la mission pour obtenir le contenu.\n" +
      "- Si le prompt exige un **bloc final contractuel** (ex. MASTER_AUDIT_VERDICT / SOFTWARE_DECISION / NEXT_CODEX_MISSION), inclus-le **dans** le même Markdown d’audit, tel quel, en fin de livrable.\n\n" +
      "---\n\n" +
      user;
  }
  const effort = (process.env.AUDIT_API_OUTPUT_EFFORT || "max").trim();

  let body = {
    model: MODEL,
    max_tokens: MAX_TOK,
    messages: [{ role: "user", content: user }],
  };
  if (effort && !["0", "false", "none", ""].includes(effort.toLowerCase())) {
    body.output_config = { effort };
  }

  let res = await messagesOnce(body);
  if (res.http === 400 && body.output_config) {
    delete body.output_config;
    console.error(
      "[run-messages-api-audit] 400 avec output_config — nouvel essai sans effort explicite."
    );
    res = await messagesOnce(body);
  }

  if (res.err || res.http !== 200) {
    console.error(JSON.stringify(res, null, 2));
    process.exit(1);
  }

  const closeoutSecond =
    String(process.env.AUDIT_API_CLOSEOUT_SECOND_TURN || "1").trim() !== "0";
  let bodyText = res.content || "";
  let usageNote = JSON.stringify(res.usage);
  let stopNote = res.stop_reason || "";
  let closeoutTour2 = "";

  if (!hasMasterCloseout(bodyText) && closeoutSecond) {
    const closeoutUser =
      "Le message **assistant** précédent est ton audit complet, mais le livrable ne contient pas le bloc contractuel final exigé par le prompt.\n\n" +
      "Réponds **uniquement** avec **exactement trois lignes** (pas de titre, pas de liste, pas de code fence). " +
      "Chaque ligne commence par la clé indiquée, suivie de **deux-points**, d’un **espace**, puis **une seule** valeur autorisée :\n\n" +
      "Ligne 1 — soit `MASTER_AUDIT_VERDICT: PASS` soit `MASTER_AUDIT_VERDICT: REWORK` (choisir une).\n" +
      "Ligne 2 — soit `SOFTWARE_DECISION: READY_FOR_VA_SYS_00_05_EXECUTION`, soit `SOFTWARE_DECISION: HOLD` (choisir une). `READY_FOR_VA_SYS_00_05_EXECUTION` est le libellé canonique parce que VA-SYS-00 existe dans la tasklist.\n" +
      "Ligne 3 — `NEXT_CODEX_MISSION:` suivi d’un TASK_ID concret (ex. `VA-SYS-00`).\n\n" +
      "Rien d’autre avant ou après ces trois lignes.";

    let body2 = {
      model: MODEL,
      max_tokens: Math.min(MAX_TOK, 1024),
      messages: [
        { role: "user", content: user },
        { role: "assistant", content: bodyText },
        { role: "user", content: closeoutUser },
      ],
    };
    if (effort && !["0", "false", "none", ""].includes(effort.toLowerCase())) {
      body2.output_config = { effort };
    }
    let res2 = await messagesOnce(body2);
    if (res2.http === 400 && body2.output_config) {
      delete body2.output_config;
      res2 = await messagesOnce(body2);
    }
    if (!res2.err && res2.http === 200 && (res2.content || "").trim()) {
      closeoutTour2 = (res2.content || "").trim();
      bodyText +=
        "\n\n---\n\n## Bloc contractuel (complété — tour 2 API)\n\n" + closeoutTour2 + "\n";
      usageNote += ` ; tour2: ${JSON.stringify(res2.usage)}`;
      stopNote += ` ; tour2_stop: ${res2.stop_reason || ""}`;
    } else {
      usageNote += ` ; tour2_failed: ${JSON.stringify(res2)}`;
    }
  }

  const closeoutOk = hasMasterCloseout(bodyText);
  const header = `# Audit API Messages (sans CLI \`claude\`)

- **Base** : \`${BASE}\`
- **Modèle demandé** : \`${MODEL}\`
- **max_tokens** : ${MAX_TOK}
- **usage** (API) : \`${usageNote}\`
- **stop_reason** : \`${stopNote}\`
- **closeout_conforme** : ${closeoutOk ? "oui" : "non — vérifier manuellement ou relancer avec AUDIT_API_CLOSEOUT_SECOND_TURN=1"}
- **Limite** : pas d’outils — le modèle ne peut pas ouvrir le disque ; tout contenu utile doit être **dans** le prompt (ex. assemble-mega-api-context-va-sys.mjs).

---

`;

  const absOut = path.isAbsolute(outFile) ? outFile : path.join(root, outFile);
  fs.mkdirSync(path.dirname(absOut), { recursive: true });
  fs.writeFileSync(absOut, header + bodyText, "utf8");
  console.log(absOut);
  if (!closeoutOk) process.exit(3);
  process.exit(0);
}

main().catch((e) => {
  console.error(e);
  process.exit(1);
});

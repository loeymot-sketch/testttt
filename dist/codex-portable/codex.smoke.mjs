import fs from "node:fs";
import path from "node:path";
import { fileURLToPath } from "node:url";

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const root = path.resolve(__dirname, "..", "..");

function loadEnv(f) {
  if (!fs.existsSync(f)) return;
  for (const line of fs.readFileSync(f, "utf8").split("\n")) {
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
loadEnv(path.join(root, ".env"));
loadEnv(path.join(root, ".env.codex"));
loadEnv(path.join(__dirname, ".env"));
loadEnv(path.join(__dirname, ".env.codex"));

const API_BASE = (process.env.CODEX_API_BASE || "").replace(/\/$/, "");
const API_KEY = process.env.CODEX_API_KEY || "";
const MODEL = process.env.CODEX_MODEL_COMPLEX || "gpt-5.4";

if (!API_BASE || !API_KEY) {
  console.error("[smoke] Missing CODEX_API_BASE / CODEX_API_KEY (.env or .env.codex).");
  process.exit(1);
}

const sleep = (ms) => new Promise((r) => setTimeout(r, ms));
const headers = { Authorization: `Bearer ${API_KEY}`, "Content-Type": "application/json" };
const body = {
  model: MODEL,
  messages: [{ role: "user", content: "Reply with exactly: OK" }],
};

for (let attempt = 0; attempt < 4; attempt++) {
  try {
    const r = await fetch(`${API_BASE}/chat/completions`, {
      method: "POST",
      headers,
      body: JSON.stringify(body),
    });
    const t = await r.text();
    let j;
    try {
      j = JSON.parse(t);
    } catch {
      console.error(`[smoke] HTTP ${r.status} non-JSON (proxy gateway?). Body head:`, t.slice(0, 200));
      if (attempt < 3) {
        await sleep(2000 * (1 + attempt));
        continue;
      }
      process.exit(1);
    }
    const c = j?.choices?.[0]?.message?.content;
    if (typeof c === "string" && c.trim().length) {
      console.log(`[smoke] OK | model=${j?.model || MODEL} | reply=${JSON.stringify(c).slice(0, 80)}`);
      process.exit(0);
    }
    console.error(`[smoke] HTTP ${r.status} but assistant content was empty (attempt ${attempt + 1}/4).`);
  } catch (e) {
    console.error(`[smoke] network error (attempt ${attempt + 1}/4):`, e.message);
  }
  if (attempt < 3) await sleep(2000 * (1 + attempt));
}
process.exit(1);

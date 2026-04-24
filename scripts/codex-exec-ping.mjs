/**
 * Un seul `codex exec` court avec timeout (évite hang en sandbox / TTY manquant).
 * argv[2] = chemin absolu du binaire codex
 * argv[3] = timeout ms (défaut 120000)
 */
import { spawn } from "node:child_process";
import path from "node:path";
import { fileURLToPath } from "node:url";

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const repoRoot = path.join(__dirname, "..");
const bin = process.argv[2] || path.join(repoRoot, "node_modules", ".bin", "codex");
const timeoutMs = Number.parseInt(process.argv[3] || "120000", 10);
const prompt = "Reply with only the word: CTOK_PING_OK";

// Même logique que codex-sanitize-env-for-codex-cli.sh (OAuth / Pro : pas de sk-* héritée)
const strip = [
  "OPENAI_BASE_URL",
  "OPENAI_API_URL",
  "OPENAI_API_BASE",
  "OPENAI_API_KEY",
  "CODEX_API_KEY",
  "CODEX_API_BASE",
  "AZURE_OPENAI_ENDPOINT",
  "AZURE_OPENAI_KEY",
  "AZURE_OPENAI_API_KEY",
];
const env = { ...process.env };
for (const k of strip) delete env[k];
const child = spawn(bin, ["exec", prompt], { stdio: ["ignore", "pipe", "pipe"], env });
let out = "";
let err = "";
child.stdout?.on("data", (c) => {
  out += c.toString();
});
child.stderr?.on("data", (c) => {
  err += c.toString();
});
const t = setTimeout(() => {
  child.kill("SIGTERM");
  console.error(`[codex-ping] TIMEOUT après ${timeoutMs}ms — TTY / réseau / premier cold start ? Teste en Terminal.app interactif.`);
  process.exit(2);
}, timeoutMs);
child.on("close", (code) => {
  clearTimeout(t);
  const combined = out + err;
  if (combined.includes("CTOK_PING_OK")) {
    console.log("[codex-ping] OK (exec a répondu CTOK_PING_OK)");
    process.exit(0);
  }
  console.error("[codex-ping] FAIL (pas de CTOK_PING_OK dans la sortie):");
  console.error(combined.slice(0, 2000) || "(vide)");
  process.exit(code && code > 0 ? code : 1);
});

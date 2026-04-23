/** Défaut : stream désactivé (moins de 503 sur certains proxies). Pass-through des argv. */
import { pathToFileURL } from "node:url";
import path from "node:path";
import { fileURLToPath } from "node:url";

process.env.CODEX_DISABLE_STREAM = "1";
const self = fileURLToPath(import.meta.url);
const runner = pathToFileURL(path.join(path.dirname(self), "codex.runner.mjs")).href;
await import(runner);

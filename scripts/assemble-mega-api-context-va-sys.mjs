/**
 * Assemble un **seul** fichier Markdown « parrain » : tout le contexte disque utile
 * pour un audit API (Orcai / Anthropic Messages) **sans** outils — équivalent à
 * « coller tous les fichiers » dans le message.
 *
 * Graphiti : le MCP n’est pas appelable depuis ce script. On injecte à la place
 * `memory/INDEX.md` + **queues** courtes des JSONL canoniques (02, 03, 12).
 *
 * Usage :
 *   node scripts/assemble-mega-api-context-va-sys.mjs [sortie.md]
 *
 * Défaut sortie : reports/audit/_MEGA_API_CONTEXT_VA_SYS_FULL.md
 */
import fs from "node:fs";
import path from "node:path";
import { fileURLToPath } from "node:url";

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const root = path.resolve(__dirname, "..");

const OUT_DEFAULT = path.join(
  root,
  "reports",
  "audit",
  "_MEGA_API_CONTEXT_VA_SYS_FULL.md"
);

const FILES = [
  ["missions/VERSION-A-SYSTEM-FINISHING/TASKLIST.md", "missions/VERSION-A-SYSTEM-FINISHING/TASKLIST.md"],
  [
    "reports/audit/CODEX_VA_SYS_06_STOCKABLE_CHOICES_EXECUTION_2026-04-29.md",
    "reports/audit/CODEX_VA_SYS_06_STOCKABLE_CHOICES_EXECUTION_2026-04-29.md",
  ],
  [
    "reports/audit/CODEX_VA_SYS_07B_EXTENDED_AUTHZ_EXECUTION_2026-04-30.md",
    "reports/audit/CODEX_VA_SYS_07B_EXTENDED_AUTHZ_EXECUTION_2026-04-30.md",
  ],
  [
    "reports/audit/CODEX_VA_SYS_08_REALTIME_OUTBOX_EXECUTION_2026-04-30.md",
    "reports/audit/CODEX_VA_SYS_08_REALTIME_OUTBOX_EXECUTION_2026-04-30.md",
  ],
  [
    "reports/audit/CODEX_VA_SYS_09_DOCS_RUNBOOK_MEMORY_2026-04-30.md",
    "reports/audit/CODEX_VA_SYS_09_DOCS_RUNBOOK_MEMORY_2026-04-30.md",
  ],
  [
    "reports/audit/CODEX_VA_SYS_10_FINAL_MASSIVE_VALIDATION_2026-04-30.md",
    "reports/audit/CODEX_VA_SYS_10_FINAL_MASSIVE_VALIDATION_2026-04-30.md",
  ],
  ["docs/sync/CATALOG_COMPOSER_DATA_FLOW.md", "docs/sync/CATALOG_COMPOSER_DATA_FLOW.md"],
  ["docs/sync/WIZARD_PRODUCT_MODEL.md", "docs/sync/WIZARD_PRODUCT_MODEL.md"],
  ["docs/sync/STOCK_SYNC_AND_AVAILABILITY.md", "docs/sync/STOCK_SYNC_AND_AVAILABILITY.md"],
  ["docs/sync/API_VS_MCP_DECISION.md", "docs/sync/API_VS_MCP_DECISION.md"],
  ["docs/sync/CENTRAL_MANAGEMENT_RUNBOOK.md", "docs/sync/CENTRAL_MANAGEMENT_RUNBOOK.md"],
  [
    "docs/sync/VERSION_A_SYNC_VALIDATION_MATRIX_2026-04-30.md",
    "docs/sync/VERSION_A_SYNC_VALIDATION_MATRIX_2026-04-30.md",
  ],
];

const AGENTS_MAX_CHARS = parseInt(process.env.MEGA_CONTEXT_AGENTS_MAX || "120000", 10);

function readRel(rel) {
  const p = path.join(root, rel);
  if (!fs.existsSync(p)) return `__MISSING_FILE__: ${rel}\n`;
  return fs.readFileSync(p, "utf8");
}

function tailLines(rel, n) {
  const p = path.join(root, rel);
  if (!fs.existsSync(p)) return `(absent) ${rel}\n`;
  const lines = fs.readFileSync(p, "utf8").split("\n");
  return lines.slice(-n).join("\n") + "\n";
}

function main() {
  const outPath = path.resolve(root, process.argv[2] || OUT_DEFAULT);
  const auditInstruction = path.join(
    root,
    "reports",
    "audit",
    "_CENTRAL_SYNC_ORCHESTRATION_CLAUDE_AUDIT_PROMPT_2026-04-30.txt"
  );

  const parts = [];
  parts.push(`# MEGA CONTEXTE — Version A central sync / VA-SYS (assemblage disque)\n\n`);
  parts.push(
    `> Généré par \`node scripts/assemble-mega-api-context-va-sys.mjs\`. **Pas** d’appel Graphiti : extraits JSONL locaux ci-dessous.\n\n`
  );
  parts.push(`---\n\n## 0) Mémoire projet (secours Graphiti — index + extraits JSONL)\n\n`);
  parts.push(`### memory/INDEX.md\n\n\`\`\`\n${readRel("memory/INDEX.md")}\n\`\`\`\n\n`);
  parts.push(`### Dernières lignes — memory/episodes/02_architecture_invariants.jsonl\n\n\`\`\`\n`);
  parts.push(tailLines("memory/episodes/02_architecture_invariants.jsonl", 22));
  parts.push(`\`\`\`\n\n`);
  parts.push(`### Dernières lignes — memory/episodes/03_domain_events_sync.jsonl\n\n\`\`\`\n`);
  parts.push(tailLines("memory/episodes/03_domain_events_sync.jsonl", 22));
  parts.push(`\`\`\`\n\n`);
  parts.push(`### Dernières lignes — memory/episodes/12_decisions_log.jsonl\n\n\`\`\`\n`);
  parts.push(tailLines("memory/episodes/12_decisions_log.jsonl", 22));
  parts.push(`\`\`\`\n\n---\n\n`);

  const agentsFull = readRel("AGENTS.md");
  const agentsBody =
    agentsFull.length > AGENTS_MAX_CHARS
      ? agentsFull.slice(0, AGENTS_MAX_CHARS) +
        `\n\n… [TRONQUÉ — AGENTS.md complet fait ${agentsFull.length} car.; augmenter MEGA_CONTEXT_AGENTS_MAX ou lire le fichier dans le dépôt] …\n`
      : agentsFull;
  parts.push(`## 1) AGENTS.md\n\n\`\`\`\n${agentsBody}\n\`\`\`\n\n---\n\n`);

  for (const [title, rel] of FILES) {
    parts.push(`## ${title}\n\n\`\`\`\n${readRel(rel)}\n\`\`\`\n\n---\n\n`);
  }

  parts.push(`# INSTRUCTION D’AUDIT (à exécuter sur la base du contexte ci-dessus)\n\n`);
  if (fs.existsSync(auditInstruction)) {
    parts.push(fs.readFileSync(auditInstruction, "utf8"));
  } else {
    parts.push(`__MISSING__: ${auditInstruction}\n`);
  }

  fs.mkdirSync(path.dirname(outPath), { recursive: true });
  fs.writeFileSync(outPath, parts.join(""), "utf8");
  const st = fs.statSync(outPath);
  console.log(outPath);
  console.log(JSON.stringify({ bytes: st.size, approxTokens: Math.ceil(st.size / 4) }, null, 0));
}

main();

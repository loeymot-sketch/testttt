---
name: test-e2e
description: >
  FoodKing Grok E2E loop: real browser, screenshot every step, analyze
  technical then UI then UX then optimize, adversary + reasoner each
  round, fix and re-test until two consecutive clean rounds.
  Use whenever the user says test e2e, test E2E complet, audit visuel,
  test en boucle, jusqu'à vert, capture d'écran, adversarial, or /test-e2e.
---

# FoodKing — Grok `test e2e`

Claude’s skill at `~/.claude/skills/test-e2e/` is protocol SSOT. **Read it. Do not edit it.**
This skill is the Grok executor (Grok agents + Grok Playwright).

## 0. Fence

Do not touch Claude’s environment. Frozen zones stay frozen (`CLAUDE.md` §7) unless a lock already exists.

## 1. Preflight

- `curl -s -o /dev/null -w "%{http_code}" http://127.0.0.1:8000/login` → 200
- Playwright MCP: Node **20+**. If `node -v` is 18, export PATH=`$HOME/.nvm/versions/node/v22.23.2/bin:$PATH`
- Read `CLAUDE.md` §6 for surfaces. Do not invent product names (query items table first).
- Create `reports/test-e2e/grok-<date>/round-1/`
- Copy is unnecessary: point adversary at `~/.claude/skills/test-e2e/references/REVIEWER_PROTOCOL.md` (read-only)

## 2. Loop (no cap unless user sets `iteration_cap`)

Parent orchestrator owns the loop (subagents cannot nest, parent must see PNGs).

```
for each round:
  plan waves from CLAUDE.md §6 + user scope
  spawn e2e-hunter × waves (parallel) — real clicks
  parent read_file every PNG
  spawn adversary × waves + ux-critic × waves (parallel)
    analysis order: technical → UI → UX → optimize
  spawn reasoner once
  P0/P1 or reasoner ≠ PROCEED → sequential code-editor fixes → next round
  P0+P1 = 0 → confirmation round
  two consecutive clean rounds with identical finding ids → CONVERGENCE_FINAL.md
```

Optional one-round fan-out: `/workflow test-e2e` with `args.waves` and `args.run_dir`. Still re-run until two clean rounds.

## 3. Browser

1. Playwright MCP (`search_tool` / `use_tool`) if `playwright` is connected
2. Else repo CLI: `npx playwright test` with `PLAYWRIGHT_BASE_URL=http://127.0.0.1:8000`
3. Localhost only

## 4. Close

Deliver only with two consecutive rounds at open P0+P1 = 0. Disclose leftover P2/P3. Never “green enough”.

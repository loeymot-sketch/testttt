# Wave A — Pre-flight & Baseline Gate

**Baseline HEAD:** `a928ee88d` · branch `heal/cms-pr1-quickwins-2026-05-18` · 2026-05-31

## A0 Hygiene
| Check | Result |
|---|---|
| Disk free | **2.1Gi → 2.5Gi** after reclaim (truncated `storage/logs` 452M + transient `/tmp/foodking-*`). ⚠️ Tight → visual matrix scoped to key surfaces, prune-as-go (R-09 mitigation). |
| `.playwright-mcp` | absent (already clean) |
| Parallel backend session (this worktree) | **none** — only 1 untracked leftover report artifact; backend source tree clean |
| Dev server `:8000` | **HTTP 200** in 0.107s on `/kiosk/idle` |
| DB pollution baseline | orders=414 · fiscal_numbered=169 · audit_logs=437 · z_reports=5 |
| **DB snapshot (pre-rush safety net)** | `/tmp/foodking_snapshot_baseline_a928ee88d.sql.gz` (252K). Restore: `gunzip -c … \| mysql -uroot foodking` |

## A0 Rush command signatures — PLAN CORRECTIONS (review fix #4)
The plan assumed `kiosk:simulate-orders --rate=10/min --duration=5min` — **WRONG**. Real signatures:
- `kiosk:simulate-orders {count=50}` — positional count, no rate/duration.
- `foodking:e2e:stress --orders=N --branches=N --concurrency=N --type=pos|kiosk|mixed --base-url= --output=` — batch-count + concurrency, **not** rate/min.

## A1 Baseline gates (captured-then-locked, NOT asserted)
| Gate | Locked value |
|---|---|
| PHP suite (`php artisan test`, sqlite :memory:) | **2755 passed / 0 failed** (1 risky, 2 incomplete, 29 skipped) |
| Vitest (`npx vitest run`) | **1879 passed / 0 failed** (3 skipped, 275 files) |
| NF525 chain (`fiscal:verify-chain --all`) | **CHAIN OK** on every active branch (1 total) |
| Z-membership (`fiscal:verify-z-membership`) | **OK** — no cross-Z-window orphan |
| Frozen-zone diff (15 §7 files vs HEAD) | **0 lines** |

> Note: phpunit uses `DB_CONNECTION=sqlite :memory:` → the suite never touches dev MySQL `foodking`. The mysqldump snapshot is the safety net for the **rush** (Wave D, hits live MySQL), not the suite.

**Gate A → B: PASS.** All baselines green, chain OK, frozen 0.

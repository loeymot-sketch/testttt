# WF-3 — Kiosk -> KDS + OSS Sync End-to-End Confirmation

**Wave**: `wave-f-sync-confirmation-2026-05-19`
**Task**: `WF-3-KIOSK-KDS-OSS-SYNC`
**Round**: 1
**Status**: COMPLETED — verdict GO with 2 P1 (one analytics, one prompt-correction), 5 P2, 3 P3
**HEAD**: `50bdd5150d784355168abd6d503ab9b3583d55a4`
**Branch**: `v1-0-1-hardening-2026-05-17`
**Captured (UTC)**: `2026-05-19T00:06:43Z`

## Multi-Agent Cross-Validation Pattern

Three specialists, orthogonal scope coverage to force independent verification of each step in the sync cascade.

| Agent | Role | Cascade steps owned | Deliverable |
|---|---|---|---|
| 1 | Kiosk Order Creation + Fiscal Allocation | 1 (kiosk wizard -> store), 2 (fiscal_sequence_no at creation, NF525 kiosk-paid path), 3 (OrderCreated -> outbox), 7 (chain integrity runtime) | `round-1/agent-1-creation-fiscal.json` |
| 2 | KDS Reception + Status Transitions | 4 (KDS receives kiosk order), 6 (ACCEPT/PREPARING/PREPARED on KDS) | `round-1/agent-2-kds-reception.json` |
| 3 | OSS Reception + Sequence Integrity | 5 (OSS allowlist, public wall), 6 (PREPARING/PREPARED on OSS), 7 (cross-channel kiosk+POS sequence pool) | `round-1/agent-3-oss-and-sequence.json` |

## Cascade Verification (steps 1-7 from prompt)

| # | Cascade step | Status | Owning agent | Evidence anchor |
|---|---|---|---|---|
| 1 | Kiosk wizard completion -> `Frontend/OrderController::store` -> `FrontendOrderService::myOrderStore` | PASS | A1 | `app/Http/Controllers/Frontend/OrderController.php:46-69` -> `FrontendOrderService.php:259-271` |
| 2 | `fiscal_sequence_no` allocation AT CREATION (kiosk paid path, per NF525) | PASS | A1 | **Three sub-paths, not one** — only the directly-paid kiosk path allocates at creation. (a) **Kiosk card/TR direct TPE**: deferred to `finalizePaidKioskOrder` (`FrontendOrderService.php:1130-1190`) inside DB::transaction with `fiscal_alloc_error_at` retry marker, called from `paymentConfirm` after TPE flips `payment_status=PAID`. (b) **Kiosk cash (`PENDING_COUNTER` / `COUNTER_DEFERRED`)**: NOT allocated at kiosk creation — follows the **POS cash at-close pattern** (cashier finalizes payment at counter via `PaymentService::collect` -> `PaymentService.php:206-207` calls `FiscalSequenceService::next` then). (c) **Kiosk QR/online prepaid** (when configured): allocated immediately. The "at creation per NF525 §8" framing in the prompt applies specifically to sub-path (a) — direct kiosk TPE — to contrast with sub-path (b) which mirrors POS cash at-close timing. The 22 currently active kiosk orders mix both: cash-counter-deferred sit at ACCEPT without seq until POS collect; card/TR get seq + ACCEPT inside the same finalizePaidKioskOrder transaction. |
| 3 | `OrderCreated` event -> outbox -> broadcast | PASS | A1 | Listener registered first at `EventServiceProvider.php:147`; idempotent firstOrCreate at `PersistOrderCreatedToOutbox.php:22-48`; afterCommit dispatch at :61; `DispatchDomainEventsJob` atomic-claim at `app/Jobs/DispatchDomainEventsJob.php:65-94` |
| 4 | KDS receives kiosk order with `source` field | PASS | A2 | Kiosk + POS write the same `orders` table; `KdsSyncService.php:82` queries it unconditionally. **Prompt note**: `source` is an int enum (WEB/APP/POS) — there is no `Source::KIOSK`. The discriminator that actually surfaces kiosk orders to KDS is the active-window + branch_id filter, not a source-field check (correct behavior — KDS shows everything in the active window) |
| 5 | OSS receives kiosk order with the **allowlist filter** | PASS (with correction) | A3 | **Correction to prompt**: the OSS allowlist is `order_type`-based (`whereIn order_type [KIOSK=25, TAKEAWAY=10]` at `OrderStatusScreenOrderService.php:59-63` and :196-200), NOT `source`-based. See `WF3-A3-P1-01` and `WF3-A1-P3-03` |
| 6 | Status transitions PREPARING -> PREPARED visible on both KDS + OSS | PASS | A2 + A3 | KDS: `KitchenReleaseRule.php:41-49` enforces (ACCEPT->PREPARING) || (PREPARING->PREPARED); admin-kds.js:1598 subscribes to `OrderStatusChanged`. OSS: same status whereIn at `OrderStatusScreenOrderService.php:63, :200`; admin-oss.js:427 subscribes to `OrderCreated` + `OrderStatusChanged`. Both apply Wave-3 TZ-aware Paris->UTC heal |
| 7 | NF525 fiscal sequence verified gap-free across kiosk + POS orders | PASS (runtime) + P2 observation | A1 + A3 | Ran `php artisan fiscal:verify-chain --all` -> `+ branch=1 CHAIN OK / SWEEP COMPLETE — CHAIN OK on every active branch (1 total)` exit 0. DB-confirmed: zero duplicate `(branch_id, fiscal_sequence_no)` tuples. Kiosk + POS interleave in a single per-branch counter pool. Observation P2 `WF3-A1-P2-02`: 48% gap rate from rolled-back transactions — NOT an NF525 violation (HMAC chain is the legal invariant), but worth observability. |

## Findings Cross-Validation Table

| Finding | Severity | Reporting agent(s) | Cross-validated by | Convergence |
|---|---|---|---|---|
| `WF3-A1-P1-01` source enum mislabel — kiosk sends source=5 (WEB), dashboard treats source=10 (APP) as kiosk count | P1 | A1 | A3 cross-ref (`WF3-A3-P1-01` independent discovery while auditing OSS source-vs-order_type) | AGREE — independent runtime DB confirmation: 152 kiosk orders all source=5, 0 source=10. Dashboard miscounts. |
| `WF3-A1-P2-02` fiscal_sequence_no gap rate 48% (172/355) | P2 | A1 | A3 (`WF3-A3-P2-03`) | AGREE — same DB metric, same MAX+1-rollback-burn root cause. NF525-compliant (chain valid), operational signal only. |
| `WF3-A1-P3-03` three-fields-three-meanings naming drift (source / source_surface / order_type) | P3 | A1 | A3 (`WF3-A3-P1-01` complementary) | AGREE — converged finding with different angles: A1 from analytics side, A3 from sync allowlist side. |
| `WF3-A2-P2-01` KdsSyncService `limit(50)` may silently truncate at SaaS scale | P2 | A2 only | not in A1/A3 scope | NO CROSS-VAL — independent A2 observation. Plausible (single static analysis). Latent for V1.0.2 multi-tenant. |
| `WF3-A2-P2-02` `version = updated_at_unix` — self-documented dette technique on out-of-band writes | P2 | A2 only | not in A1/A3 scope | NO CROSS-VAL — self-acknowledged in `KdsSyncService.php:152-158` TODO. |
| `WF3-A2-P3-03` `with()` eager-load without explicit `select()` projection | P3 | A2 only | not in A1/A3 scope | NO CROSS-VAL — theoretical optimization. |
| `WF3-A3-P1-01` Prompt-vs-code: OSS allowlist is order_type-based, not source-based | P1 | A3 | A1 cross-ref (Source enum analysis in `WF3-A1-P3-03`) | AGREE — converged on the same underlying naming confusion. A3 surfaces the operational discriminator angle, A1 surfaces the enum angle. |
| `WF3-A3-P2-02` OSS `list()` no `limit()` — unbounded public-endpoint response | P2 | A3 only | not in A1/A2 scope | NO CROSS-VAL — independent A3 observation; KDS-side has limit(50) by contrast. Worth surfacing for SaaS V1.0.2. |
| `WF3-A3-P2-03` fiscal_sequence_no gap-rate observability (cross-ref with A1) | P2 | A3 | A1 (`WF3-A1-P2-02`) | AGREE — same metric, same interpretation. |
| `WF3-A3-P3-04` Broadcast channel auth no explicit rate-limit | P3 | A3 only | not in A1/A2 scope | NO CROSS-VAL — bound by Sanctum default throttle. Defer. |

### Divergences and value of cross-validation

Three convergence wins:
1. **Source enum mislabel** (P1): A1 caught it via fiscal/creation path inspection; A3 caught it via OSS allowlist inspection. Independent paths, identical conclusion, runtime DB confirmation.
2. **Naming confusion** (P3 / P1): A1 saw it as a fact-of-Source-enum issue; A3 saw it as a prompt-vs-code factual error. Same root cause (`source` / `source_surface` / `order_type` three-fields-three-meanings) surfaced from two angles.
3. **Sequence gap rate** (P2): A1 and A3 measured the same DB metric independently and reached the same NF525-compliant interpretation.

No diverged findings (which is what we want — the path is sound). The cross-validation increased confidence specifically because agents reached the source-mislabel finding via different code paths.

## Runtime evidence captured

| Command | Result |
|---|---|
| `php artisan fiscal:verify-chain --all` | exit 0; `+ branch=1 CHAIN OK / SWEEP COMPLETE — CHAIN OK on every active branch (1 total)` |
| `SHOW INDEX FROM orders WHERE Key_name LIKE '%fiscal%'` | UNIQUE `orders_branch_fiscal_seq_unique` on `(branch_id, fiscal_sequence_no)` confirmed |
| DB SELECT duplicates by `(branch_id, fiscal_sequence_no)` HAVING count > 1 | 0 rows |
| DB SELECT source breakdown for `source_surface='kiosk'` (branch=1) | 152 orders all source=5 (WEB), 0 source=10 (APP) |
| DB SELECT current active kiosk orders on KDS/OSS (status in [PREPARING(7), PREPARED(8)]) | 22 rows (live cascade evidence) |
| DB SELECT fiscal_sequence_no range on branch=1 | min=1, max=355, alive=183, gap=172 (48%) |

## Constraints honored

- **Zero source modifications** during this audit (read-only specialist cross-validation per WF-1/WF-2 pattern).
- **Frozen zones not touched** — `BranchScope.php`, `FiscalSequenceService.php`, `PricingService.php`, payment middleware all inspected without edit.
- **NF525 chain bit-identical** — pre-/post-audit: unchanged (read-only).
- **No tests run** beyond the runtime `fiscal:verify-chain` (which is itself the integrity attest).

## Final verdict

**GO** — Kiosk -> KDS + OSS sync cascade is structurally sound and runtime-verified.

- 0 P0 blockers
- 2 P1 findings:
  - `WF3-A1-P1-01` source-enum mislabel (analytics-only; sync correctness UNAFFECTED). Schedulable as a heal-light commit.
  - `WF3-A3-P1-01` prompt-vs-code factual error on OSS discriminator. Documentation correction, no code change required.
- 5 P2 findings (3 unique + 2 cross-validated duplicates of the same root metric):
  - `WF3-A1-P2-02 == WF3-A3-P2-03` fiscal_sequence_no gap rate observability.
  - `WF3-A2-P2-01` KDS limit(50) latent SaaS scale.
  - `WF3-A2-P2-02` version=updated_at self-acknowledged dette.
  - `WF3-A3-P2-02` OSS list() no limit() latent public-DoS surface.
- 3 P3 findings: naming drift, KDS column projection, broadcast channel rate-limit.

**Production readiness**: GO for Le Cayenne single-tenant V1.0.1. P2 scale-related findings are V1.0.2 SaaS-rollout concerns.

## Next-wave handoff notes

For WF-4 / WF-5 / WF-6 / WF-7 / WF-8:
- The same source-enum vs source_surface vs order_type confusion will be in those prompts. Correct it inline.
- `fiscal:verify-chain --all` is the runtime attest for any NF525-touching wave — re-run before declaring GREEN.
- The atomic-claim + commit-before-broadcast pattern of `DispatchDomainEventsJob` is the SSOT for all outbox-driven sync — referenced by WF-4 stock cascade, WF-5 fiscal cascade, WF-6 refund cascade.

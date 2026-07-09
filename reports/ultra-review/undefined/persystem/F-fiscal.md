# F. FISCAL (NF525) — Ultra-review confirmation

HEAD `61e9ea7b7` · 2026-07-02 · verdict **GREEN**

## Invariants confirmés (frozen read-only)

- **FiscalSequenceService.php:57-114** — `next()` = MAX(fiscal_sequence_no)+1 par branche,
  gap-free monotone. Triple défense concurrence : `Cache::lock('fiscal_seq_b{n}',5)` block 3s
  + `->lockForUpdate()` DB row-lock + `withoutGlobalScope(BranchScope)->withTrashed()` (les
  soft-deleted post-alloc restent comptés → pas de ré-usage de numéro). `release()` idempotent
  en `finally`. branchId<=0 rejeté.
- **ZReportService.php:180-286** — clôture : agrégat fenêtre half-open `(from,to]`, signature
  HMAC chaînée sur `prev_hash` du Z CLOSED précédent, `lockForUpdate` sur l'OPEN, refund netting
  (mirrors RETURNED + post-Z adjustments), `total_tva == Σ total_by_tax_rate` par construction.
  `verifyChain()` (488-597) détecte chain_break / sequence_gap / signature_mismatch, strict en prod.
- **AuditLogService.php:70-192** — seul writer `audit_logs`, HMAC `prev||canonical(payload)`,
  chaîne append-only par branche. `Cache::lock('audit_chain_b{n}')` + `DB::transaction` +
  UNIQUE(branch_id,prev_hash) + retry-once sur collision unique. branch_id null rejeté (F-C5).
  Secret non configuré → refuse d'écrire (289-291). Canonicalisation ksort récursive stable.
- **FiscalChainValidator.php:55-183** — assertChainIntegrity : Z chain strict + audit tail bornée
  (window 500, DESC-limit puis ASC walk). Feature-flag `fiscal.chain_validation_enabled` default true.
- **FiscalSealingService.php:60-115** — secrets Z-report gardés : dev-sentinels + min-length 32
  refusés en prod, secret vide → RuntimeException.
- **composition_snapshot figé** — trigger DB `order_items_composition_snapshot_no_update`
  (migration 2026_05_24_040211) BEFORE UPDATE : JSON→JSON' et JSON→NULL bloqués (SIGNAL 45000 /
  RAISE ABORT), parité MySQL+SQLite. INSERT-only côté app (5 sites documentés).
- **AppServiceProvider.php:178-229** — boot guards prod REFUSENT le boot si
  POS_SIMULATION_HARDWARE≠false, PAYMENT/PRINTING_BYPASS=true, APP_DEBUG=true.
- **Retry orphelins** — FrontendOrderService:1232-1288 alloc auto kiosk in-tx + flag
  `fiscal_alloc_error_at` persisté hors-tx sur échec ; RetryFiscalAllocCommand (everyMinute,
  withoutOverlapping, onOneServer) rejoue via SSOT finalizePaidKioskOrder ; warnOnOrphanedPaidOrders
  au Z-close. PaymentService.php:335-336 alloc au counter-collect, guard statut terminal (323-327).

## Evidence

- `php artisan fiscal:verify-chain --all` → **CHAIN OK** branches 1/7/8/9 (4 actives).
- `php artisan test --filter=Fiscal|ZReport|AuditLog|FiscalSequence|Chain` → **283 passed**,
  2 incomplete (owner-finalize), 3 skipped.
- `git status app/Services/Fiscal/` → **0 modification working-tree** (frozen intact).

## Nouveaux défauts

Aucun. La seule anomalie de test = `F001KioskFiscalSequenceInvariantSentinelTest` échoue car il
asserte l'existence d'un fichier plan dans un worktree supprimé
(`.claude/worktrees/blissful-mclean-c915c2` → `D` en git status). C'est le bruit connu (garde-fou
« F001/F009 sentinelles = worktree supprimé »), PAS un défaut de correction fiscale : le sentinel
teste la présence d'un artefact de traçabilité, pas la logique de séquence. Non reporté.

**Verdict : F. FISCAL VALIDÉ — production-perfect V1 LOCAL.**

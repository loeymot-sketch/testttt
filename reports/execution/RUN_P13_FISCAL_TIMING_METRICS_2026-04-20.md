# RUN — P13_FISCAL_TIMING_METRICS

**Date:** 2026-04-20  
**TASK_ID:** P13_FISCAL_TIMING_METRICS  
**Statut:** SUCCESS

## Méthodes instrumentées (1 par service)

| Fichier | Méthode | Ligne (signature) |
|---------|---------|-------------------|
| `app/Services/Fiscal/ZReportService.php` | `close(int $branchId, User\|int\|null $closedBy = null): ZReport` | 103 |
| `app/Services/Fiscal/AuditLogService.php` | `write(array $data): AuditLog` | 70 |

## Channel de log

- **`stack`** — `Log::channel('stack')->info('[FISCAL_TIMING]', $context)`  
- Le channel `fiscal` existe dans `config/logging.php` ; le plan §Pattern attendu impose `stack` pour le tag timing (SLO / agrégation générique).

## Contexte loggué

- `op`: `z_report.close` | `audit_log.write`
- `branch_id` (paramètre ou résolu quand connu)
- `outcome`: `success` | `failure`
- `exception_class`: présent si `failure`
- `duration_ms`: entier, calculé dans un `finally`

Les appels `Log::info` sont dans un `try/catch (\Throwable)` interne au `finally` pour ne jamais interrompre le flux fiscal.

## Validation — `bash scripts/check-invariants.sh`

```
== POS invariants CI guard (POS_INVARIANTS_AND_GATES.md §3) ==
  [1/6 SSOT pricing (no payload pricing)] ... OK
  [2/6 branch_id server-side only] ... OK
  [3/6 status via OrderStateMachine] ... OK
  [4/6 App\Events\* dispatch afterCommit] ... OK
  [5/6 EventContract envelope] ... OK
  [6/6 audit log on sensitive actions] ... OK

==> All 6 POS invariants clean.
```

## Validation — `git diff --stat` (fichiers P13 uniquement)

```
 app/Services/Fiscal/AuditLogService.php |  87 +++++++++++++--------
 app/Services/Fiscal/ZReportService.php  | 133 ++++++++++++++++++--------------
 2 files changed, 129 insertions(+), 91 deletions(-)
```

_Remarque : le dépôt contient d’autres modifications non liées ; le stat ci-dessus est restreint aux deux services fiscal._

## Validation — `vendor/bin/phpunit --filter Fiscal`

```
PHPUnit 9.6.29 by Sebastian Bergmann and contributors.

................................................................. 65 / 95 ( 68%)
..............................                                    95 / 95 (100%)

Time: 00:10.532, Memory: 87.00 MB

OK (95 tests, 314 assertions)
```

## Diff inline (fichiers fiscal uniquement)

```diff
diff --git a/app/Services/Fiscal/AuditLogService.php b/app/Services/Fiscal/AuditLogService.php
index ebd6f3136..0219c2dad 100644
--- a/app/Services/Fiscal/AuditLogService.php
+++ b/app/Services/Fiscal/AuditLogService.php
@@ -69,46 +69,65 @@ class AuditLogService
 
     public function write(array $data): AuditLog
     {
-        if (empty($data['action'])) {
-            throw new \InvalidArgumentException('AuditLogService::write() requires a non-empty action.');
-        }
+        $started = microtime(true);
+        $context = ['op' => 'audit_log.write', 'branch_id' => null];
 
-        if (! array_key_exists('action', $data)) {
-            throw new \InvalidArgumentException('AuditLogService::write() requires an action.');
-        }
+        try {
+            if (empty($data['action'])) {
+                throw new \InvalidArgumentException('AuditLogService::write() requires a non-empty action.');
+            }
 
-        $branchId = $this->resolveBranchId($data);
-
-        // [POS-9-H.2.3 / F-C5]
-        // Reject null branch_id: a call that does not pin a branch would
-        // read the tail across ALL chains (lastHashFor(null) has no WHERE
-        // clause) and poison whichever chain happens to be latest. CLI
-        // jobs must pass branch_id=0 explicitly to write to the system
-        // chain, or a positive branch_id for a tenant chain.
-        if ($branchId === null) {
-            throw new \InvalidArgumentException(
-                'AuditLogService::write() requires an explicit branch_id. '
-                .'Pass branch_id=0 for system/CLI events, or a positive int for a tenant chain.'
-            );
-        }
+            if (! array_key_exists('action', $data)) {
+                throw new \InvalidArgumentException('AuditLogService::write() requires an action.');
+            }
 
-        $lockKey = 'audit_chain_b'.$branchId;
-        $lock = Cache::lock($lockKey, self::CHAIN_LOCK_TTL);
+            $branchId = $this->resolveBranchId($data);
+            $context['branch_id'] = $branchId;
+
+            // [POS-9-H.2.3 / F-C5]
+            // Reject null branch_id: a call that does not pin a branch would
+            // read the tail across ALL chains (lastHashFor(null) has no WHERE
+            // clause) and poison whichever chain happens to be latest. CLI
+            // jobs must pass branch_id=0 explicitly to write to the system
+            // chain, or a positive branch_id for a tenant chain.
+            if ($branchId === null) {
+                throw new \InvalidArgumentException(
+                    'AuditLogService::write() requires an explicit branch_id. '
+                    .'Pass branch_id=0 for system/CLI events, or a positive int for a tenant chain.'
+                );
+            }
 
-        if (! $lock->block(self::CHAIN_LOCK_WAIT)) {
-            throw new RuntimeException(
-                "AuditLogService: could not acquire chain lock '{$lockKey}' within "
-                .self::CHAIN_LOCK_WAIT.'s. Another writer is stuck or the cache '
-                .'driver is unavailable.'
-            );
-        }
+            $lockKey = 'audit_chain_b'.$branchId;
+            $lock = Cache::lock($lockKey, self::CHAIN_LOCK_TTL);
 
-        try {
-            return DB::transaction(function () use ($data, $branchId) {
-                return $this->performInsert($data, $branchId, /* attempt */ 1);
-            });
+            if (! $lock->block(self::CHAIN_LOCK_WAIT)) {
+                throw new RuntimeException(
+                    "AuditLogService: could not acquire chain lock '{$lockKey}' within "
+                    .self::CHAIN_LOCK_WAIT.'s. Another writer is stuck or the cache '
+                    .'driver is unavailable.'
+                );
+            }
+
+            try {
+                $result = DB::transaction(function () use ($data, $branchId) {
+                    return $this->performInsert($data, $branchId, /* attempt */ 1);
+                });
+                $context['outcome'] = 'success';
+
+                return $result;
+            } finally {
+                optional($lock)->release();
+            }
+        } catch (\Throwable $e) {
+            $context['outcome'] = 'failure';
+            $context['exception_class'] = get_class($e);
+            throw $e;
         } finally {
-            optional($lock)->release();
+            $context['duration_ms'] = (int) ((microtime(true) - $started) * 1000);
+            try {
+                Log::channel('stack')->info('[FISCAL_TIMING]', $context);
+            } catch (\Throwable $logEx) {
+            }
         }
     }
```

```diff
diff --git a/app/Services/Fiscal/ZReportService.php b/app/Services/Fiscal/ZReportService.php
index efa79ab25..74261e0cb 100644
--- a/app/Services/Fiscal/ZReportService.php
+++ b/app/Services/Fiscal/ZReportService.php
@@ -12,6 +12,7 @@ use Illuminate\Support\Carbon;
 use Illuminate\Support\Facades\Cache;
 use Illuminate\Support\Facades\Config;
 use Illuminate\Support\Facades\DB;
+use Illuminate\Support\Facades\Log;
 use RuntimeException;
@@ -101,71 +102,89 @@ class ZReportService
     public function close(int $branchId, User|int|null $closedBy = null): ZReport
     {
-        if ($branchId <= 0) {
-            throw new \InvalidArgumentException('ZReportService::close requires a positive branch_id.');
-        }
-
-        $closedById = $closedBy instanceof User ? $closedBy->id : $closedBy;
-        $lockKey    = sprintf('z_report_b%d', $branchId);
-        $lock       = Cache::lock($lockKey, self::LOCK_TTL_SECONDS);
+        $started = microtime(true);
+        $context = ['op' => 'z_report.close', 'branch_id' => $branchId];
 
         try {
-            if (!$lock->block(self::LOCK_ACQUIRE_SECONDS)) {
-                throw new RuntimeException("ZReportService: cannot acquire {$lockKey}.");
+            if ($branchId <= 0) {
+                throw new \InvalidArgumentException('ZReportService::close requires a positive branch_id.');
             }
 
-            return $this->connection->transaction(function () use ($branchId, $closedById) {
-                $open = ZReport::query()
-                    ->where('branch_id', $branchId)
-                    ->where('status', ZReport::STATUS_OPEN)
-                    ->lockForUpdate()
-                    ->first();
+            $closedById = $closedBy instanceof User ? $closedBy->id : $closedBy;
+            $lockKey    = sprintf('z_report_b%d', $branchId);
+            $lock       = Cache::lock($lockKey, self::LOCK_TTL_SECONDS);
 
-                if (!$open) {
-                    throw new RuntimeException("ZReportService: no open Z report to close for branch {$branchId}.");
+            try {
+                if (!$lock->block(self::LOCK_ACQUIRE_SECONDS)) {
+                    throw new RuntimeException("ZReportService: cannot acquire {$lockKey}.");
                 }
 
-                $closedAt   = Carbon::now();
-                $aggregates = $this->aggregate($branchId, $open->opened_at, $closedAt);
-
-                $prevHash = (string) (ZReport::query()
-                    ->where('branch_id', $branchId)
-                    ->where('status', ZReport::STATUS_CLOSED)
-                    ->orderByDesc('sequence_no')
-                    ->value('signature') ?? '');
-
-                $signature = $this->sign($branchId, $prevHash, $open->sequence_no, $aggregates, $closedAt);
-
-                $open->forceFill(array_merge($aggregates, [
-                    'closed_at' => $closedAt,
-                    'closed_by' => $closedById,
-                    'prev_hash' => $prevHash !== '' ? $prevHash : null,
-                    'signature' => $signature,
-                    'status'    => ZReport::STATUS_CLOSED,
-                ]))->save();
-
-                // [POS-9-H.3.2 / F-C7]
-                // Full numeric snapshot — the signature prefix is enough
-                // to cross-reference the HMAC without leaking the full
-                // secret-derived hash in logs.
-                \Illuminate\Support\Facades\Log::channel('fiscal')->info('z_report.close', [
-                    'z_report_id'     => $open->id,
-                    'branch_id'       => $branchId,
-                    'sequence_no'     => $open->sequence_no,
-                    'closed_by'       => $closedById,
-                    'total_ttc'       => (float) $aggregates['total_ttc'],
-                    'total_ht'        => (float) $aggregates['total_ht'],
-                    'total_tva'       => (float) $aggregates['total_tva'],
-                    'order_count'     => (int)  $aggregates['order_count'],
-                    'cancel_count'    => (int)  $aggregates['cancel_count'],
-                    'refund_count'    => (int)  $aggregates['refund_count'],
-                    'signature_prefix'=> substr($signature, 0, 12),
-                ]);
-
-                return $open->refresh();
-            });
+                $result = $this->connection->transaction(function () use ($branchId, $closedById) {
+                    $open = ZReport::query()
+                        ->where('branch_id', $branchId)
+                        ->where('status', ZReport::STATUS_OPEN)
+                        ->lockForUpdate()
+                        ->first();
+
+                    if (!$open) {
+                        throw new RuntimeException("ZReportService: no open Z report to close for branch {$branchId}.");
+                    }
+
+                    $closedAt   = Carbon::now();
+                    $aggregates = $this->aggregate($branchId, $open->opened_at, $closedAt);
+
+                    $prevHash = (string) (ZReport::query()
+                        ->where('branch_id', $branchId)
+                        ->where('status', ZReport::STATUS_CLOSED)
+                        ->orderByDesc('sequence_no')
+                        ->value('signature') ?? '');
+
+                    $signature = $this->sign($branchId, $prevHash, $open->sequence_no, $aggregates, $closedAt);
+
+                    $open->forceFill(array_merge($aggregates, [
+                        'closed_at' => $closedAt,
+                        'closed_by' => $closedById,
+                        'prev_hash' => $prevHash !== '' ? $prevHash : null,
+                        'signature' => $signature,
+                        'status'    => ZReport::STATUS_CLOSED,
+                    ]))->save();
+
+                    // [POS-9-H.3.2 / F-C7]
+                    // Full numeric snapshot — the signature prefix is enough
+                    // to cross-reference the HMAC without leaking the full
+                    // secret-derived hash in logs.
+                    \Illuminate\Support\Facades\Log::channel('fiscal')->info('z_report.close', [
+                        'z_report_id'     => $open->id,
+                        'branch_id'       => $branchId,
+                        'sequence_no'     => $open->sequence_no,
+                        'closed_by'       => $closedById,
+                        'total_ttc'       => (float) $aggregates['total_ttc'],
+                        'total_ht'        => (float) $aggregates['total_ht'],
+                        'total_tva'       => (float) $aggregates['total_tva'],
+                        'order_count'     => (int)  $aggregates['order_count'],
+                        'cancel_count'    => (int)  $aggregates['cancel_count'],
+                        'refund_count'    => (int)  $aggregates['refund_count'],
+                        'signature_prefix'=> substr($signature, 0, 12),
+                    ]);
+
+                    return $open->refresh();
+                });
+                $context['outcome'] = 'success';
+
+                return $result;
+            } finally {
+                try { $lock->release(); } catch (\Throwable $e) {}
+            }
+        } catch (\Throwable $e) {
+            $context['outcome'] = 'failure';
+            $context['exception_class'] = get_class($e);
+            throw $e;
         } finally {
-            try { $lock->release(); } catch (\Throwable $e) {}
+            $context['duration_ms'] = (int) ((microtime(true) - $started) * 1000);
+            try {
+                Log::channel('stack')->info('[FISCAL_TIMING]', $context);
+            } catch (\Throwable $logEx) {
+            }
         }
     }
 ```

## Risque résiduel / suivi

- Aucun impact sur la logique métier (agrégats, HMAC, persistance) : uniquement instrumentation et wrapping.
- Si besoin d’alerter uniquement sur `fiscal.log` en prod, une évolution future pourrait dupliquer vers `fiscal` **en plus** de `stack` — hors scope du présent EXECUTE.

---

## AUDIT (Claude orchestrateur) — 2026-04-20
**Verdict : CLOSED — PASSED — 0 remediation**

| # | Check | Résultat |
|---|---|---|
| 1 | Méthodes instrumentées | `ZReportService::close` (l.103) + `AuditLogService::write` (l.70) — exactement 1 par fichier |
| 2 | Channel log | `stack` (universel, présent dans `config/logging.php`) |
| 3 | try/catch autour de Log::... | **présent** dans le `finally` — invariant "ne jamais casser le flux fiscal" respecté |
| 4 | Signature publique inchangée | confirmé (subagent l'a explicité) |
| 5 | Logique métier inchangée | 95 tests Fiscal toujours OK, 314 assertions |
| 6 | Diff stat | `ZReportService.php` 133 lignes, `AuditLogService.php` 87 lignes — **plus volumineux qu'estimé** (~25 lignes attendues) |

**Note sur le diff volumineux** : les +/- élevés (129 ins / 91 del) viennent de l'**indentation +1 niveau** du corps de méthode pour entrer dans le `try` du wrapper de timing. Pas de scope creep réel — c'est le coût syntaxique inhérent au pattern try/finally enveloppant. Le subagent aurait pu factoriser via `private function _doClose(...)` pour minimiser le diff, mais le pattern actuel est lisible et conforme au plan §"Pattern attendu". Accepté.

**Valeur produite** : observability NF525 mesurable. Désormais possible de :
- Alerter si `z_report.close.duration_ms > 5000` (lock contention multi-branch)
- Alerter si `audit_log.write.duration_ms > 500` (DB pression)
- Tracer `outcome=failure` avec `exception_class` pour root-cause rapide

Pré-requis pour SLO production fiscal F-VERIFY-15-04.

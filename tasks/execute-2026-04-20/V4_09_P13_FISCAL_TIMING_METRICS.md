# EXECUTE V4 #9 — P13_FISCAL_TIMING_METRICS

TASK_ID: P13_FISCAL_TIMING_METRICS
WAVE: V4 salve 4b (logs structurés, faible risque)
PRIMARY_MODEL: composer (foodking-routine-implementer)
SOURCE_FINDING: F-VERIFY-15-04 (cf. `reports/review/VERIFY_15_OBSERVABILITY_PERF_2026-04-20.md`)

---

## Goal

Ajouter un timing structuré (`duration_ms`) aux opérations fiscales critiques pour permettre alerting SLO production.

Cibles :
1. `app/Services/Fiscal/ZReportService.php` — méthode publique principale de fermeture Z (probablement `close()` ou similaire).
2. `app/Services/Fiscal/AuditLogService.php` — méthode `write()` ou équivalent (création d'audit log scellé).

---

## Scope

| Fichier | Action |
|---|---|
| `app/Services/Fiscal/ZReportService.php` | EDIT — ajouter `microtime(true)` au début de la méthode publique principale, calculer `duration_ms` à la fin, logger via `\Illuminate\Support\Facades\Log::channel('fiscal')->info(...)` (ou channel `stack` si `fiscal` n'existe pas — vérifier `config/logging.php`). |
| `app/Services/Fiscal/AuditLogService.php` | EDIT — idem sur la méthode publique d'écriture. |

**SUBSYSTEMS_TOUCHED**: 2 services Fiscal, observability uniquement.
**SUBSYSTEMS_OFF_LIMITS**: OrderService (frozen LOCK_A+B), schéma DB, NF525 logic. Aucun changement de logique métier — pure instrumentation.
**INVARIANTS_AT_RISK**: NF525 sealed-Z **NE doit pas être affectée**. Si un Log::info échoue (driver indispo), il **NE doit PAS** faire échouer l'écriture d'audit log ou la fermeture Z. Wrapper `try { Log::... } catch (\Throwable $e) { /* never break fiscal */ }` autour des appels Log.

---

## Spécification

### Pattern attendu (pseudo-code)

```php
public function close(...): mixed  // ou la méthode existante
{
    $started = microtime(true);
    $context = ['op' => 'z_report.close', 'branch_id' => $branchId ?? null];

    try {
        $result = /* logique existante INCHANGÉE */;
        $context['outcome'] = 'success';
        return $result;
    } catch (\Throwable $e) {
        $context['outcome'] = 'failure';
        $context['exception_class'] = get_class($e);
        throw $e;
    } finally {
        $context['duration_ms'] = (int) ((microtime(true) - $started) * 1000);
        try {
            \Illuminate\Support\Facades\Log::channel('stack')->info('[FISCAL_TIMING]', $context);
        } catch (\Throwable $logEx) { /* never break fiscal flow */ }
    }
}
```

### Avant écriture
1. Lire `config/logging.php` pour identifier un channel approprié (`stack` est universel ; `fiscal` si existe).
2. Lire `app/Services/Fiscal/ZReportService.php` ENTIÈREMENT pour identifier la méthode principale (probablement `close($branchId)` ou `closeFor(...)` — pas la signature exacte).
3. Lire `app/Services/Fiscal/AuditLogService.php` ENTIÈREMENT pour identifier la méthode d'écriture (probablement `write(...)` ou `record(...)`).
4. Si une méthode est très courte (< 5 lignes), wrapper le timing autour de l'appel **sans** changer la signature ni le retour.

### Cible : 1 méthode par fichier (la plus critique). Pas de bombardement.

---

## VALIDATE
1. `bash scripts/check-invariants.sh` → reste OK 6/6.
2. `vendor/bin/phpunit tests/Feature/Fiscal/` (si dossier existe) ou `--filter Fiscal` → tests existants restent verts (pas de régression). Si pas de tests fiscal, documenter dans RUN report.
3. `git diff --stat` → 2 fichiers, ~10-15 lignes ajoutées au total.
4. Test manuel rapide via `php artisan tinker` (optionnel) :
   ```php
   app(\App\Services\Fiscal\AuditLogService::class)->write(/* args minimaux */);
   tail storage/logs/laravel.log | grep FISCAL_TIMING
   ```
   Si trop coûteux à setup, skip.

---

## REPORT_FILE

`reports/execution/RUN_P13_FISCAL_TIMING_METRICS_2026-04-20.md` — diff inline + sortie phpunit (ou note "no fiscal tests found").

---

## SCOPE_PRESSURE

- ❌ NE PAS toucher la logique métier (calculs Z, scellement audit log, signature HMAC).
- ❌ NE PAS modifier la signature publique ni le type de retour des méthodes.
- ❌ NE PAS ajouter de nouveau channel de log si `stack` suffit.
- ❌ NE PAS instrumenter d'autres méthodes que les 2 cibles.
- ❌ Pas de `git add/commit`.
- ⚠️ Le `try/catch` autour de `Log::...` est **OBLIGATOIRE** : un log défaillant ne doit jamais casser le flux fiscal (NF525).

# VERIFY-15 — Observability & Performance

**Date :** 2026-04-20  **Origine :** `AUDIT_POS_110_OBSERVABILITY_PERF_2026-04-19.md`  **Priorité :** P1  **Mode :** AUDIT-ONLY

## 1. Contexte
Vérifier logs structurés, métriques, healthcheck, perf POS/KDS sur charge moyenne, requêtes N+1, taille bundles front, throttle adapté.

## 2. Sources OBLIGATOIRES
- `app/Http/Controllers/HealthController.php`
- `app/Logging/*`, `config/logging.php`
- Tests : `tests/Feature/HealthControllerTest.php`
- Reports : `reports/execution/php_memory_profile_latest.md`, `reports/antigravity/*`
- Audit : `AUDIT_POS_110_OBSERVABILITY_PERF_2026-04-19.md`

## 3. Hypothèses à challenger
- H1 : Endpoint `/health` ne vérifie pas DB+queue+broadcast.
- H2 : Logs sans `request_id` / `correlation_id`.
- H3 : POS list query N+1 sur items / variations.
- H4 : Bundle JS POS > 1.5 MB.
- H5 : Pas de monitoring Outbox lag.
- H6 : Throttle `kiosk-menu` rate-limit non aligné avec usage réel.

## 4. Plan multi-agent
1. **Explore A** : back logs + health + queries.
2. **Explore B** : front bundles + lazy loading.
3. **GeneralPurpose** : checklist obs (logs, metrics, traces, alerts) + perf budget.

## 5. Vérifications obligatoires
- [ ] V1 : `/health` couvre DB, cache, queue, broadcast.
- [ ] V2 : Tous les logs critiques ont `branch_id`, `order_id`, `actor_id`, `correlation_id`.
- [ ] V3 : Eager loading sur listes POS / KDS.
- [ ] V4 : Bundle POS analysé (`vite build --report`) ou WARN.
- [ ] V5 : Outbox lag observé (count + age max).
- [ ] V6 : Métriques fiscal (Z generation time, audit log write time).

## 6. Critères d'acceptation
- ALL_GREEN si V1–V6 OK.
- WARN sur V4/V6 partiels.
- FAIL si N+1 critique ou logs sans corrélation.

## 7. Livrables
- `reports/review/VERIFY_15_OBSERVABILITY_PERF_2026-04-20.md`

## 8. Suite
- FAIL → `P11_LOGS_CORRELATION_ID`, `P12_POS_QUERY_OPTIM`.

---

### PROMPT À COLLER
```
Tu es orchestrateur AUDIT-ONLY.
Lis tasks/verify-2026-04-20/15_VERIFY_OBSERVABILITY_PERF.md, applique §4-§7.

OBLIGATIONS: 2 explore parallèles + 1 generalPurpose checklist obs + perf budget. 0 code modifié.
Livrable: reports/review/VERIFY_15_OBSERVABILITY_PERF_2026-04-20.md
Plan 5 lignes. Conclusion "GLOBAL: ..." + cycles P.
```

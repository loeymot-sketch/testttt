# AUDIT_POS_IDEMPOTENCY_RETRIES_007 — Idempotency + retries + double-submit POS

## Meta
- **Priority** : P1
- **PRIMARY_MODEL** : Claude
- **TEST_STRATEGY** : `static-inspection`
- **DEPENDS_ON** : AUDIT_POS_ORDER_CREATION_001
- **Estimation** : 0.5 j-h
- **Vague** : A7

## Contexte

Caissier clique deux fois "Valider" → deux commandes ? Wi-Fi POS instable → retry client → duplication ? `X-Idempotency-Key` + `Cache::lock` doivent garantir exactement-une-fois.

## Questions d'audit

1. Le header `X-Idempotency-Key` est-il requis sur les endpoints mutatifs critiques (POS create/update, payment) ?
2. La clé est-elle générée côté client avec entropie suffisante (UUID v4) ?
3. Le backend utilise-t-il `Cache::lock()` avec TTL raisonnable (ex 60s) + `Cache::remember()` pour la réponse ?
4. Une deuxième requête avec la même clé renvoie-t-elle la même réponse 200 (pas 2 commandes) ?
5. Les retries automatiques (axios interceptor) sont-ils configurés uniquement sur erreurs réseau / 5xx ?
6. Les jobs queue (DispatchDomainEventsJob) sont-ils idempotents (early-exit si `dispatched_at !== null`) ? Confirmé dans le code L37-39.
7. Les events outbox sont-ils dédupliqués (pas de redispatch en double sur retry listener) ?
8. Le POS Vue bloque-t-il le bouton "Valider" pendant la requête en vol (spinner + disabled) ?
9. Les logs capturent-ils les tentatives d'idempotency hit pour monitoring ?
10. Existe-t-il un test Feature simulant double-submit (fetch × 2 concurrent avec même Idempotency-Key) ?

## Scope

### SUBSYSTEMS_TOUCHED
- `app/Http/Middleware/*Idempotency*` (s'il existe)
- `app/Http/Controllers/Admin/Order/OrderController.php`
- `resources/js/services/api*.js` ou axios config
- `app/Jobs/DispatchDomainEventsJob.php` (confirmer early-exit)

### SUBSYSTEMS_OFF_LIMITS
- Flows kiosk (audit séparé)

## Invariants at Risk
- [x] Dispatch after DB commit (double-dispatch)
- [ ] Backend pricing SSOT
- [ ] OrderStatus enum

## Fichiers à lire
1. Middleware idempotency (grep `Idempotency`)
2. `app/Http/Controllers/Admin/Order/OrderController.php`
3. `resources/js/services/` axios config
4. `app/Jobs/DispatchDomainEventsJob.php`
5. Tests Feature existants (`tests/Feature/*Idempotency*`)

## Grep patterns

```
grep -rn "Idempotency-Key\|X-Idempotency-Key\|idempotency_key" app/ resources/js/
grep -rn "Cache::lock\|Cache::remember" app/
grep -rn "retry\|retries" resources/js/services/
grep -rn "dispatched_at" app/
grep -rn "disabled.*submitting\|v-if=\"!loading\"" resources/js/components/admin/pos/
```

## Evidence required
- Preuve (code) de présence d'un middleware idempotency OU absence.
- Configuration TTL du lock.
- Comportement Vue sur double-clic (disabled visible ?).
- Existence (ou non) de test de double-submit.

## Grille de verdict
- **PASS** : middleware + lock + Vue bloquant + test présent.
- **WARN** : pas de middleware dédié mais idempotency via unique constraint DB + Vue bloquant.
- **BLOCKED** : double-clic crée deux commandes, aucune protection.

## Livrable
`reports/review/AUDIT_POS_IDEMPOTENCY_RETRIES_007_<DATE>.md`

## Status
- [x] Brief rédigé
- [ ] Plan approuvé
- [ ] Audit exécuté
- [ ] Rapport
- [ ] Tasks correctrices
- [ ] Closed

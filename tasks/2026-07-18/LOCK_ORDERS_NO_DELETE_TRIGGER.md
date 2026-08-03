# LOCK — Trigger `orders_no_delete_when_fiscalized` (NF525, gate deploy owner)

**Statut : CODE PRÊT + TESTÉ (283/283 fiscal, 0 régression) — NON MIGRÉ EN OPÉRATIONNEL — attend sign-off owner avant deploy.**

## Finding
Audit intelligence 2026-07-18, P1-1 (`reports/goal-intelligence-2026-07-18/REGISTRE_FINAL.md`).
`FiscalSequenceService::next()` = `MAX(fiscal_sequence_no)+1` (avec `withTrashed`, donc soft-deletes couverts) mais un **HARD-delete** d'un order fiscalisé fait redescendre le MAX → réémission du même numéro NF525. Preuve chaîne signée : seq 2579 revendiqué par 6 orders distincts. Il existait `order_payments_no_delete` mais **aucun trigger équivalent sur `orders`** (la seule table portant les numéros).

## Fix (mirror du pattern immutabilité existant — pas de nouvelle archi)
- Migration `database/migrations/2026_07_18_130000_add_orders_no_delete_when_fiscalized_trigger.php` : trigger `BEFORE DELETE ON orders` → MySQL `SIGNAL SQLSTATE '45000'` / SQLite `RAISE(ABORT)` **si `OLD.fiscal_sequence_no IS NOT NULL`**.
- Compteur triggers immutabilité 9 → 10 : `FiscalInstallImmutabilityTriggersCommand`, `FiscalVerifyImmutabilityTriggersCommand`, leurs tests, `SqliteMysqlParitySentinel`.
- `FiscalSequenceService.php` **INTOUCHÉ** (frozen).

## Pourquoi GATE (human, §10)
Change le comportement de suppression en PROD : un order avec `fiscal_sequence_no` ne pourra **plus** être hard-deleté (c'est précisément l'exigence NF525).
- Impact « purge 186 cmd test » : les commandes de test **fabriquées avec un seq** seront bloquées = correct au sens NF525 ; les non-encaissées (seq NULL) restent purgeables.
- Purge RGPD post-6 ans d'un order fiscalisé → DROP explicite sous gate (même posture que `z_reports`/`order_payments` déjà immuables).

## Décision owner requise avant deploy
- [ ] OK pour installer le trigger en prod (bloque le hard-delete des orders fiscalisés).
- [ ] Confirmer qu'aucun workflow de purge prod ne hard-delete des orders AVEC seq (sinon adapter la procédure de purge à un soft-delete ou un DROP gaté).
- [ ] **[RED-team pre-deploy] Corriger les cleanups e2e avant de migrer le trigger** : ~36 specs (ex. `tests/e2e/wave-final-S2-pos.spec.js:148`) `forceDelete()` des orders PAID/fiscalisés au cleanup → échoueront (`SIGNAL 45000`) une fois le trigger live. Ajouter `whereNull('fiscal_sequence_no')` ou basculer en soft-delete dans ces cleanups. Non-bloquant tant que le trigger n'est pas migré (gate). Heal SAFE (specs non-frozen) — à faire dans le même lot que l'install prod du trigger.

Tant que non signé : le commit reste sur la branche mais **ne pas lancer `deploy-lecayenne.sh`** avec ce commit dans le range sans acter cette case.

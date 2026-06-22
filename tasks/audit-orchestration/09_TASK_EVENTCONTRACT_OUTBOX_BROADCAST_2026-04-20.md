# T09 — EventContract / Outbox / Broadcast (refactor K-10)

**Date** : 2026-04-20  **Statut** : PENDING  **Subagent** : `explore`

## Objectif unique

Vérifier le refactor K-10 de `DispatchDomainEventsJob` (`BroadcastManager::connection()->broadcast()`
au lieu de `pusher()->trigger()`), la propagation `correlation_id`, et le pattern
`DB::afterCommit` pour outbox.

## Subagent à lancer (prompt prêt à coller)

```
Tu es un sous-agent `explore`. Racine : /Users/1millnonstop/Downloads/projet/foodking-web/web/testttt-kiosk-p93.

Étapes :
1) Lire :
   - app/Jobs/DispatchDomainEventsJob.php (broadcast default vs pusher hardcoded ?)
   - app/Listeners/PersistOrderCreatedToOutbox.php (correlation_id propagation — AX12-02)
   - app/Models/Outbox.php / OutboxEvent.php
   - app/Contracts/EventContract.php (s'il existe)
2) Vérifier `phpunit.xml` :
   - BROADCAST_DRIVER=log
   - PUSHER_APP_* dummies
3) Tests :
   - tests/Feature/Outbox/OutboxTest.php
   - tests/Feature/Events/EventContractTest.php
   - tests/Feature/Events/CorrelationIdPropagationTest.php (s'il existe)
4) Audit cross : reports/review/AUDIT_KIOSK_110_OBSERVABILITY_PERF_2026-04-19.md (AX12-02
   marqué P1).
5) `Str::uuid()` vs `X-Correlation-ID` : où le listener prend-il son ID ?
   - Si `Str::uuid()` → bug AX12-02 confirmé.
   - Doit lire `request()->header('X-Correlation-ID')` ou contexte du job parent.
6) `DB::afterCommit` : tous les `event(...)` métier passent par `afterCommit` ?
   `rg -n "DB::afterCommit|afterCommit\(" app/`

Sortie : /Users/1millnonstop/Downloads/projet/foodking-web/web/testttt/reports/audit-orchestration/REPORT_TASK09_EVENTCONTRACT_OUTBOX_2026-04-20.md
```

## Lecture obligatoire

- `app/Jobs/DispatchDomainEventsJob.php`
- `app/Listeners/PersistOrderCreatedToOutbox.php`
- `phpunit.xml`
- `tests/Feature/Outbox/`, `tests/Feature/Events/`
- `reports/review/AUDIT_KIOSK_110_OBSERVABILITY_PERF_2026-04-19.md`

## Checklist multi-points

- [ ] V1. `DispatchDomainEventsJob` utilise broadcast par défaut (pas Pusher hardcodé)
- [ ] V2. Tests Outbox/EventContract attendent `broadcast`, pas `getPusher()->trigger`
- [ ] V3. `phpunit.xml` BROADCAST_DRIVER=log + PUSHER dummies
- [ ] V4. AX12-02 : correlation_id propagé depuis header HTTP → job → outbox
- [ ] V5. `DB::afterCommit` couvre toutes émissions d'events transactionnels
- [ ] V6. Aucun `event()` sans afterCommit dans pipeline order
- [ ] V7. Vitest échos / canaux Pusher mockés cohérents

## Critères PASS / FAIL

- **PASS** : 7 V cochées, AX12-02 résolu.
- **FAIL** : AX12-02 toujours ouvert OU events hors afterCommit.

## Output

`reports/audit-orchestration/REPORT_TASK09_EVENTCONTRACT_OUTBOX_2026-04-20.md`

## Si FAIL → action

→ T09b `generalPurpose` : patch listener correlation_id + test couvrant chaîne complète.

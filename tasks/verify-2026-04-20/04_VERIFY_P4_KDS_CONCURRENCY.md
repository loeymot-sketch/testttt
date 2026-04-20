# VERIFY-04 — P4 KDS concurrence (lock + 409 + ligne verrouillée + Vuex refresh)

**Date :** 2026-04-20  **Origine :** P4 (commit `e18344af4`)  **Priorité :** P0  **Mode :** AUDIT-ONLY

## 1. Contexte
P4 a verrouillé la transaction de `KitchenDisplaySystemOrderService::changeStatus` (`lockForUpdate`), ajouté un **409** sur dérive d'état, validé via `OrderStateMachine::allows` sur ligne verrouillée, propagation `HttpException`, et refresh Vuex. Test : `KdsChangeStatusConcurrencyTest`. Risques : événements émis avant commit, transactions imbriquées, KDS multi-staff (race), absence de retry côté front.

## 2. Sources OBLIGATOIRES
- `app/Services/KitchenDisplaySystemOrderService.php`
- `app/Http/Controllers/Admin/KitchenDisplaySystemController.php`
- `app/Domain/Order/OrderStateMachine.php`
- `resources/js/store/modules/kitchenDisplaySystemOrder.js`
- `resources/js/components/admin/orderStatusScreen/PreparingAndReadyComponent.vue`
- Tests : `tests/Feature/KdsChangeStatusConcurrencyTest.php`, autres KDS tests, OrderStateMachineTest
- Audit : `AUDIT_POS_110_KDS_OSS_DRAWER_2026-04-19.md`

## 3. Hypothèses à challenger
- H1 : Un dispatch d'event a lieu **avant** commit DB → consommateur voit un état stale.
- H2 : Le 409 est mal propagé (transformé en 500 par un middleware ou le front).
- H3 : `recordTransition` sort de la transaction → trace incohérente.
- H4 : Le refresh Vuex sur 409 ne purge pas tous les caches (multi-onglets, multi-stations).
- H5 : Le test couvre le service mais pas la route HTTP réelle (binding modèle frais).
- H6 : Pas de garde sur transitions interdites pour rôle non KDS.

## 4. Plan multi-agent
1. **Explore A** : back service + state machine + propagation HttpException.
2. **Explore B** : front Vuex + composant + UX retry/feedback.
3. **GeneralPurpose** : synthèse + scénarios (2 staff simultanés, refresh, multi-station, navigateur offline).

## 5. Vérifications obligatoires
- [ ] V1 : `lockForUpdate` couvre la lecture de l'order avant guard.
- [ ] V2 : 409 retourné dans tous les cas de dérive (état attendu ≠ état lu).
- [ ] V3 : Aucun `event(...)`, `dispatch(...)`, `Pusher::trigger` dans la transaction.
- [ ] V4 : `recordTransition` est dans la transaction.
- [ ] V5 : Le test `KdsChangeStatusConcurrencyTest` ne mocke pas la DB de manière à masquer le bug réel.
- [ ] V6 : Front affiche l'erreur 409 et déclenche le refresh sans flicker (UX preuve).
- [ ] V7 : Permissions Spatie sur l'endpoint KDS (rôle staff ↔ branch).
- [ ] V8 : Cas multi-onglets : un onglet réussit, l'autre voit 409 et se synchronise.

## 6. Critères d'acceptation
- ALL_GREEN si V1–V8 prouvés.
- WARN si UX 409 muet.
- FAIL si event hors transaction.

## 7. Livrables
- `reports/review/VERIFY_04_P4_KDS_CONCURRENCY_2026-04-20.md`

## 8. Suite
- FAIL events → cycle `P11_KDS_AFTER_COMMIT_DISPATCH`.

---

### PROMPT À COLLER
```
Tu es orchestrateur AUDIT-ONLY.
Lis tasks/verify-2026-04-20/04_VERIFY_P4_KDS_CONCURRENCY.md, applique §4-§7.

OBLIGATIONS:
- 2 subagents `explore` parallèles (A back, B front).
- 1 `generalPurpose` synthèse + scénarios race.
- 0 code modifié.
- Livrable: reports/review/VERIFY_04_P4_KDS_CONCURRENCY_2026-04-20.md

Plan en 5 lignes d'abord. Conclusion: "GLOBAL: ..." + cycles P.
```

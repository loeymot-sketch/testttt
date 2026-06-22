# Gate cleared — Frozen zone patch on `FrontendOrderService::finalizePaidKioskOrder`

**Date :** 2026-04-23  
**Gate :** Frozen zone exception, F-21 (P0 promu)  
**Décidé par :** Orchestrateur Claude Opus avec validation utilisateur explicite  
**Source décision :** `memory/episodes/12_decisions_log.jsonl` entrée `gate=frozen_zone_f21,decision=approved_with_documented_gate`

## Fichier impacté
`app/Services/FrontendOrderService.php` — méthode `finalizePaidKioskOrder` (lignes 776-832)

## Justification métier
Faille F-21 (audit massif POS↔Kiosk↔KDS, second-opinion GPT-5.4-pro) :
> Si `finalizePaidKioskOrder` est appelé sur une commande dont `payment_status !== PAID` (cas: retry job, appel direct service hors flux contrôleur, race avec rollback de la transaction de paiement), la commande passe en `ACCEPT` sans paiement confirmé. **Money-state corruption** : production cuisine engagée sans encaissement, réconciliation comptable corrompue.

Le contrôleur `OrderController::paymentConfirm` (ligne 106) vérifie déjà `payment_status === PAID` AVANT d'appeler le service, mais c'est une garantie *uniquement* du chemin contrôleur. Un retry de job, un appel direct depuis un autre service, ou une refonte future du flux pourraient contourner ce check. **Defense in depth** requise au niveau du service lui-même, dans la transaction lockée.

## Nature du patch (chirurgical, contenu)
Ajout d'une **assertion défensive** de 9 lignes à l'intérieur de la transaction `DB::transaction`, juste après le `lockForUpdate` et le check de status, avant l'écriture `$locked->status = ACCEPT`.

**Aucun changement de signature publique.** Aucun changement de comportement pour les callers qui appellent correctement (commande déjà PAID). Seuls les appels incorrects (commande non payée) sont désormais rejetés silencieusement avec log warning et retour `false`.

## Justification de l'approbation
- Patch 9 lignes, 1 méthode, zéro changement de signature.
- Aucun caller existant légitime n'envoie une commande non-PAID à cette méthode (vérifié dans la base : 1 seul call site `OrderController::paymentConfirm` qui pré-vérifie déjà PAID).
- Risque du patch : nul (rejet silencieux d'un cas qui ne devrait jamais se produire en production correcte).
- Risque de NE PAS faire le patch : money-state corruption sur retry/refactoring futur.

## Tests sentinelles
- **Negative path** ajouté dans `tests/Feature/KioskPaymentStateMachineTest.php` :
  `test_finalize_paid_kiosk_order_rejects_unpaid_order` — appel direct du service avec commande PENDING+UNPAID → return `false`, status reste PENDING, aucun event dispatché.
- **Positive path** : test `test_payment_confirm_can_finalize_an_already_paid_but_pending_kiosk_order` (déjà existant ligne 208) reste vert (régression).
- **Existing path** : `test_card_order_stays_pending_until_payment_confirm` (ligne 123) reste vert.

## Critère de cloture de la gate
- `php artisan test --filter=KioskPaymentStateMachineTest` : 4/4 vert (3 anciens + 1 nouveau).
- `bash scripts/check-invariants.sh` : 6/6 (aucun invariant cassé).
- Pas de modification d'autres méthodes ou fichiers du service.

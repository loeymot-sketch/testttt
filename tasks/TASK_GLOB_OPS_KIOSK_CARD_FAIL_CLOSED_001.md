# TASK_GLOB_OPS_KIOSK_CARD_FAIL_CLOSED_001 — Borne CB fail-closed et preuve TPE

## Meta

- **Priority:** P0 paiement commercial
- **EXECUTION_TIER:** `complex`
- **PRIMARY_EXECUTION_MODEL:** `gpt-5.5-pro`
- **REASONING_EFFORT:** `xhigh`
- **TEST_STRATEGY:** `local-validation` + `playwright-critical-flow` + `human-verification`
- **SOURCE:** audit global F-14 et contre-audit paiement/matériel
- **STATUS:** `PENDING_PAYMENT_FROZEN_GATE`

## Problème prouvé

- `resources/js/services/kioskHardware.js` construit un stub dont `tpeCharge()` et `chargeCard()` réussissent.
- `KioskPaymentComponent._invokeTpe()` retourne `approved:true` et un identifiant `STUB-*` quand `isKioskBridge()` est faux.
- Le frontend envoie ensuite cette valeur à `/frontend/order/{order}/payment-confirm`.
- Le serveur vérifie utilisateur kiosk, branche, méthode, montant, unicité et statut, mais aucune preuve acquéreur ou attestation matérielle. Un identifiant arbitraire au bon montant peut donc marquer l'ordre `PAID`.
- Les sentinelles existantes exigent même que le stub écho le montant ; elles protègent la simulation au lieu d'empêcher son usage commercial.

## Décision produit

La borne n'est pas le POS : aucun opérateur ne peut confirmer manuellement « le TPE externe a accepté ». La CB borne doit donc être **indisponible** sans intégration de confiance. Aucune simulation silencieuse n'est autorisée dans un build commercial.

## Scope en deux étapes obligatoires

### Étape A — Confinement avant toute borne commerciale

| Fichier | Action |
| --- | --- |
| `resources/js/services/kioskHardware.js` | Les méthodes paiement du stub retournent un échec explicite ; health TPE non connecté |
| `resources/js/components/frontend/kiosk/KioskPaymentComponent.vue` | Désactiver CB sans bridge/capabilité ; aucun `approved:true`/`STUB-*` hors dev QA explicitement compilé |
| `app/Http/Controllers/Frontend/OrderController.php` | Rejeter les marqueurs stub/simulation avant mutation, uniquement comme défense de confinement |
| `app/Http/Requests/Frontend/PaymentConfirmRequest.php` | Normaliser/garder le format de transaction sans prétendre à une preuve durable |
| tests kiosk paiement/sentinelles | Inverser les attentes qui consacrent le succès stub production |

Cette étape ferme le fail-open connu mais ne transforme pas `transaction_id` en preuve cryptographique. Le GO CB borne reste interdit jusqu'à l'étape B et au laboratoire matériel.

### Étape B — Preuve durable

Sous gate schema/paiement :

1. Le serveur crée un `payment_attempt` idempotent lié à order, branche, machine, montant serveur et expiration.
2. L'ordre reste dans un état paiement distinct `PENDING_TPE`; `OrderStatus` métier n'est pas détourné.
3. La confirmation provient d'un protocole fabricant/acquéreur vérifié ou d'un agent local enregistré qui transmet une réponse TPE signée et rejouable uniquement sur cet attempt.
4. `APPROVED`, `DECLINED`, `TIMEOUT`, `CANCELED`, `UNKNOWN` sont persistés avec références externes et payload minimal sanitisé.
5. Seul `APPROVED` transitionne transactionnellement le paiement vers `PAID`, alloue le fiscal et émet les événements après commit.
6. Les callbacks/replays sont idempotents ; une réponse différente sur le même attempt déclenche une alerte, jamais une seconde remise.
7. Un état `UNKNOWN` est réconcilié avec le TPE/acquéreur avant nouvelle tentative pour éviter le double débit.

## SUBSYSTEMS_OFF_LIMITS

- Aucun mode manuel CB sur la borne.
- Aucun PAN, cryptogramme ou donnée PCI sensible dans FoodKing.
- Aucun prix/montant calculé par le frontend.
- Aucun changement `OrderStatus` pour représenter le TPE.
- Aucun GO matériel fondé uniquement sur des mocks.
- Le mode CB manuel POS est une mission différente et ne doit pas être cassé.

## INVARIANTS_AT_RISK

- Pricing backend SSOT.
- Isolation `branch_id` entre order, kiosk machine, attempt et réponse.
- Paiement/fiscal frozen.
- Événements uniquement après commit.
- Parité FrontendOrderService/OrderService à documenter si l'un est touché.
- Idempotence et non-répudiation opérationnelle.

## Tests Étape A

- Build production sans `window.borne` : CB désactivée, message opérable, aucun POST payment-confirm.
- Stub `tpeCharge`/`chargeCard` : `ok:false`, jamais `approved:true`.
- `STUB-*`, `stub-*`, transaction vide ou marqueur simulation forgé : 422/403, ordre reste UNPAID, aucune séquence fiscale, aucun `OrderCreated` payé.
- Bridge présent mais health TPE KO : CB désactivée.
- QA dev explicitement activée : simulation visuellement marquée, base isolée, impossible en `NODE_ENV=production`.
- Espèces borne/counter-deferred reste inchangé.

## Tests Étape B

- Approbation réelle, refus, timeout, cancel, réponse inconnue et reprise après crash.
- Replay identique → une seule remise ; replay contradictoire → conflit/audit.
- Amount, order, machine ou branche différents → rejet avant mutation.
- Callback après expiration/annulation janitor → quarantaine, jamais résurrection.
- Rupture réseau après débit avant ACK → réconciliation, pas de second débit.
- Matrice deux bornes/deux branches et même référence fournisseur.
- Test réel TPE signé dans la grille hardware.

## Acceptance Criteria

- [ ] Aucun code production ne peut fabriquer `approved:true` sans bridge de confiance.
- [ ] Le serveur rejette tous les marqueurs de simulation connus sans mutation.
- [ ] Étape A est explicitement étiquetée confinement, pas intégration TPE.
- [ ] Étape B lie cryptographiquement/protocolairement l'approbation à order/branch/machine/montant.
- [ ] Aucun paiement carte borne commercial avant Étape B + hardware UAT.
- [ ] Événements/fiscal ne se déclenchent qu'après approbation transactionnelle.
- [ ] Les tests de simulation existants ne peuvent plus servir de preuve production.

## Gate

`app/Http/Controllers/Frontend/OrderController.php`, paiement, fiscal et futures migrations sont frozen. Le gate consolidé `HG-GLOBAL-OPS-RELIABILITY-2026-08-11` Décision 1 et un gate Étape B détaillé doivent être approuvés et consignés avant exécution.

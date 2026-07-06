# Triage adversaire de l'audit SYNCHRO externe (16_SYNCHRONISATION_GLOBALE) — 2026-07-07

Audit externe : verdict « NON FIABLE — BLOCK, temps-réel client mort, fidélité cassée, outbox non-fiable ».
Triage : 8 spécialistes par axe vs code HEAD `70dbc36f1` + contexte V1 + réfutateurs indépendants.

## Décompte : 55 findings → ZÉRO P0/P1, 3 P3 confirmés (1 seul à corriger)

| Verdict | Nombre |
|---|---|
| FALSE_REASONING | 13 |
| BY_DESIGN_V1 | 13 |
| ALREADY_FIXED | 12 |
| V2_SAAS_SCOPE (futur multi-branche/scale, pas V1) | 10 |
| REAL_OPEN_V1 (triage) | 7 → **3 après réfutation, tous P3** |

## Calibration (priorité owner = FIDÉLITÉ) : les 2 claims phares FAUX
- « Solde effacé au re-register » FAUX : reset gardé `if (!loyalty_code)` = new-account only (LoyaltyController:186-188) ; hijack/PII déjà durcis 2026-07-02.
- « Lost-update sans verrou » FAUX : `increment()`/`decrement()` SQL atomiques + seul RMW sous `lockForUpdate` (LoyaltyService:160-177). Le « refondre en ledger » = réécriture risquée pour un non-problème → NON FAIT.

## L'audit s'est trompé sur ses findings les plus graves (exemples prouvés)
- **Outbox « non-atomique, perte permanente »** BY_DESIGN/FALSE : le dispatch est DANS `DB::transaction` via `DispatchableAfterCommit` → droppé au rollback ; le try/catch cité enveloppe les NOTIFICATIONS (mail/sms/push), PAS l'outbox (misattribution).
- **« Broadcast-avant-marquage = doublon »** FALSE : au HEAD c'est l'INVERSE — claim-then-broadcast at-most-once (`dispatched_at` posé sous `lockForUpdate` AVANT broadcast ; 2e worker voit non-null → return). Code cité (:84-89 skip) INEXISTANT.
- **« Double transition = double points »** ALREADY_FIXED : `changeStatus` = compare-and-swap sous `lockForUpdate` + garde 409 ; award fidélité = CAS atomique idempotent (`whereNull loyalty_points_awarded`).
- **« Client borne ne reçoit jamais son statut »** FALSE : conflate « pas de FCM » et « pas de temps-réel » — `KioskWaitingComponent` a SON Echo + polling.
- **Polling 60s / admin branch_id=0** BY_DESIGN : Echo pousse en sub-seconde ; le KDS est opéré par staff (branch_id=1), pas l'admin.

## Les 3 P3 confirmés
| Axe | Faille | Fix reco | Décision |
|---|---|---|---|
| BROADCAST | `Echo.leave('branch.1')` partagé (eventContract.js:112) coupe l'abonné co-monté (borne dispo) au démontage de l'écran d'attente | ✅ oui | **CORRIGER** (référence-count / stopListening) |
| CLIENT web | Page `/my-orders/:id` (OrderDetailsComponent:304) fetch unique, ni Echo ni polling → statut périmé jusqu'au refresh | non (cosmétique, backend=SoT, borne=surface primaire) | **CORRIGER** (polling léger, owner tient au « site ») |
| POS | Clé idempotence régénérée à l'ouverture du modal paiement (PosComponent:1496) | non (retry in-modal déjà protégé 409, non repro fiable) | **DIFFÉRÉ + documenté** (chemin POS attesté, risque > bénéfice) |

## Conclusion
L'audit synchro est ~90%+ bruit (statique, famille 85%-fausse, cite du code inexistant). Le temps-réel STAFF (POS↔KDS↔OSS) et la FIDÉLITÉ sont solides au HEAD. Résidu réel = 2 P3 frontend fixables + 1 P3 différé. Aucun « BLOCK ».

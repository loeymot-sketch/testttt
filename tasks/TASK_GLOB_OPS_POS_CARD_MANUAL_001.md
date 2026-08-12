# TASK_GLOB_OPS_POS_CARD_MANUAL_001 — CB POS manuelle, honnête et non bloquante

## Meta

- **Priority:** P0 exploitation caisse
- **EXECUTION_TIER:** `complex`
- **PRIMARY_EXECUTION_MODEL:** `gpt-5.5-pro`
- **REASONING_EFFORT:** `xhigh`
- **TEST_STRATEGY:** `local-validation` + `playwright-critical-flow`
- **SOURCE:** audit global F-03 et contre-audits paiement/UX
- **STATUS:** `PENDING_GLOBAL_OPS_GATE_AND_MASTERPLAY_UNFREEZE`

## Problème prouvé

- `PaymentComponent.vue` interdit le mono-paiement CARD quand aucun terminal n'est sélectionné (`canConfirmCard` et second guard dans `confirmOrder`).
- La liste des terminaux est chargée à l'ouverture ; réponse vide, 403 ou panne réseau rend donc la CB inutilisable.
- `PosOrderRequest` accepte pourtant explicitement `terminal_id = null` et les feature tests prouvent qu'une vente mono CARD sans terminal est valide.
- Ce chemin ne contacte aucun acquéreur et ne débite aucun TPE. Il enregistre qu'un paiement a déjà été accepté hors FoodKing.
- `PosCounterCollectModal.vue` a déjà un comportement mono CARD sans terminal, mais affiche ensuite `tpe_validated_simulation`, ce qui affirme à tort un résultat matériel.
- Le mono-tender ne crée actuellement aucune ligne `order_payments`; terminal, attribution TPE et agrégats Z/frais restent donc incomplets. Ce défaut durable appartient à la mission ledger, pas à ce confinement UI.

## Contrat produit retenu

Le mode CARD POS signifie uniquement :

> **Enregistrer une CB déjà validée sur un TPE externe.**

FoodKing ne doit ni lancer un débit, ni prétendre l'avoir validé. Dans le chemin mono actuel, `terminal_id` n'est pas persisté : le confinement masque donc le sélecteur et n'envoie aucun terminal plutôt que de fabriquer une attribution jetée. L'attribution TPE ne revient qu'avec le ledger GLOB-OPS-10. L'absence de terminal ne bloque pas le mono-paiement manuel.

La borne reste fail-closed et n'est pas concernée par ce contrat : elle n'a pas d'opérateur qui puisse confirmer le résultat externe.

## Scope borné

| Fichier | Action |
| --- | --- |
| `resources/js/components/admin/pos/PaymentComponent.vue` | Retirer le gate terminal du mono CARD ; masquer/ne pas envoyer le sélecteur terminal sur ce chemin tant que le ledger ne persiste pas sa valeur |
| `resources/js/components/admin/pos/PosCounterCollectModal.vue` | Remplacer tout succès « TPE validé/simulation » par une confirmation d'enregistrement manuel explicite |
| tests JS/feature ciblés | Prouver le contrat terminal vide/403/offline et l'absence de claim de débit |
| traductions | Modifier seulement après réconciliation avec leur propriétaire, car elles sont déjà dirty ; sinon utiliser une clé existante sûre ou différer le copy centralisé |

## Règles d'implémentation

1. Le mono CARD ne dépend plus de l'API/liste terminal et reste confirmable sans terminal.
2. Avant la mutation, une confirmation non ambiguë demande : « Le paiement est-il déjà accepté sur le TPE externe ? Aucune demande ne sera envoyée au TPE par FoodKing. » Son annulation ne crée pas de commande payée.
3. Aucun `terminal_id` n'est envoyé dans le mono tant que le backend ne le persiste pas. Le retour d'une attribution terminal exige GLOB-OPS-10, validation branch-scoped et ligne de paiement durable ; une sélection dont la valeur est jetée est interdite.
4. Le champ de référence/4 derniers chiffres, s'il est conservé, doit être décrit comme référence opérateur facultative ; ne jamais collecter PAN, cryptogramme ou donnée PCI sensible.
5. Aucun appel `tpeCharge`, `chargeCard`, bridge fabricant ou fallback stub n'est ajouté.
6. Le split/mixte CARD conserve son contrat actuel et son terminal requis tant que le ledger split n'est pas redessiné ; le terminal y reste une attribution, jamais une preuve de charge. Des tests d'interruption, double confirmation et seconde tender refusée sont ajoutés pour vérifier qu'il n'existe pas de régression latente.
7. Le succès UI doit dire « paiement carte externe enregistré » ou équivalent, jamais « TPE validé », « débité », « approuvé par FoodKing » ou « simulation réussie ».
8. Une annulation/remboursement CARD avertit qu'une action TPE externe distincte peut être requise et reste protégée par permission manager ; jamais de remboursement physique prétendu.
9. L'absence actuelle de ligne `order_payments` mono est affichée dans le rapport de cycle comme dette acceptée jusqu'à GLOB-OPS-10 ; elle ne doit pas être masquée.
10. Dans l'encaissement comptoir CARD, le montant est le total serveur en lecture seule ou doit lui être strictement égal ; aucun `received > total`, aucun rendu carte et aucun commentaire prétendant enregistrer un surpaiement.
11. Si la réponse réseau est inconnue après soumission, l'UI dit « ne pas redébiter le TPE ; vérifier la commande » et rejoue uniquement avec la même clé d'idempotence stable.

## SUBSYSTEMS_OFF_LIMITS

- Aucun changement de prix frontend.
- Aucun changement `OrderStatus` ou état de paiement backend.
- Aucun faux protocole TPE.
- Aucun changement de `PaymentService`, `OrderService`, fiscal Z ou schema dans cette mission.
- Aucun changement du paiement carte borne.
- Aucun assouplissement du terminal requis dans les tranches split sans plan ledger.
- Aucun `terminal_id` mono envoyé avant persistance ledger réelle.

## INVARIANTS_AT_RISK

- Paiement/fiscal frozen.
- Backend pricing SSOT.
- Isolation `branch_id` du futur terminal ledger ; aucun terminal mono dans le confinement.
- Aucun claim matériel sans preuve.
- Worktree dirty et masterplay `MASTERPLAY_FROZEN=1`.

## Tests falsifiables

1. Liste terminal vide : le bouton mono CARD est actif et le POST de création utilise CARD sans `terminal_id`.
2. Endpoint terminaux 403, 500 et timeout : warning non bloquant ; vente manuelle encore possible.
3. Même si des terminaux sont configurés, aucun `terminal_id` mono n'est affiché/envoyé ; aucun appel matériel.
4. Terminal inactif/inter-branche injecté côté composant : absent du payload mono ; aucune fuite de branche.
5. Encaissement comptoir mono CARD : succès dit « externe enregistré », jamais « TPE validé/simulation ».
6. Split CARD sans terminal : comportement actuel inchangé et refus explicite.
7. Recherche statique des copies interdites : aucune surface modifiée ne prétend `TPE validé`, `débit réussi` ou `simulation`.
8. E2E : depuis une commande téléphone et une commande client face-à-face, confirmer CARD avec API terminaux indisponible ; commande créée une fois, montant serveur inchangé.
9. Refuser la confirmation externe : aucun POST de création/collecte payée.
10. Split : seconde tender refusée/interruption/double clic ; aucune somme partielle présentée comme payée et somme exacte lorsque le flux réussit.
11. Annulation d'une vente CARD : permission manager et avertissement d'action TPE externe ; aucun faux remboursement matériel.
12. CARD comptoir : montant strictement égal au total serveur ; surpaiement et rendu impossibles.
13. Timeout après commit puis replay avec la même clé : même commande/séquence fiscale, aucun second job/ticket.

## Acceptance Criteria

- [ ] Le mono CARD POS n'est plus bloqué par la disponibilité de la liste TPE.
- [ ] L'opérateur confirme explicitement que le paiement a déjà été accepté hors FoodKing.
- [ ] Aucun texte ni code ne prétend une intégration TPE.
- [ ] Le split/mixte reste hors scope et inchangé.
- [ ] La dette `order_payments`/Z mono est documentée, pas présentée comme corrigée.
- [ ] Les tests couvrent absence de dépendance terminal, timeout/replay et absence de `terminal_id` mono.

## Gate et collisions

- Le gate consolidé `HG-GLOBAL-OPS-RELIABILITY-2026-08-11` Décision 1 doit approuver le confinement.
- La correction freeze masterplay doit être levée ou un cycle explicitement autorisé doit être ouvert.
- `PaymentComponent.vue` et `PosCounterCollectModal.vue` sont actuellement propres, mais les traductions sont dirty ; toute collision arrête la mission.
- La mission ne rouvre pas `GATE_PAYMENT_LEDGER_V1`; GLOB-OPS-10 reste un gate séparé.

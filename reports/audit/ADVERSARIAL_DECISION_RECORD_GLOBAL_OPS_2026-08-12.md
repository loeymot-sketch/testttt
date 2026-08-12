# Adversarial Decision Record — Global Operations

**Date:** 2026-08-12  
**Scope:** POS, site, borne, KDS, paiement, alarme, impression, tiroir, stock, rate limiting  
**Participants:** audit UX/exploitation, audit paiement/matériel, audit sync/stock, consolidation principale  
**State:** `REVISED_PROPOSAL — PENDING_HUMAN_GATES`  
**Commercial verdict:** `HOLD`

## Méthode

Trois contre-expertises indépendantes ont d'abord audité le code et les preuves navigateur. Une seconde passe leur a demandé d'attaquer les choix consolidés, notamment les hypothèses de succès matériel, d'ACK, de terminal facultatif, d'autorité d'impression et de convergence stock. Les objections qui falsifiaient une promesse ont été intégrées aux task intakes et au gate ; aucun agent n'a édité le code produit.

## Décisions finales après objections

### D1 — CB POS mono

**Proposition initiale:** terminal facultatif d'attribution, paiement externe manuel.  
**Objection gagnante:** le chemin mono jette actuellement `terminal_id` et ne crée pas `order_payments`; afficher/envoyer le sélecteur est donc une attribution fictive. Un `received > total` CARD au comptoir est également trompeur.

**Décision révisée:**

- libellé « CB déjà validée sur TPE externe — aucune demande envoyée par FoodKing » ;
- confirmation opérateur explicite avant mutation ;
- aucun sélecteur/`terminal_id` mono jusqu'au ledger durable ;
- montant carte comptoir égal au total serveur, aucun rendu/surpaiement ;
- timeout après submit : même clé d'idempotence, message « ne pas redébiter ; vérifier la commande » ;
- terminal du split reste requis uniquement comme attribution existante, jamais preuve de charge.

**Rejeté:** terminal facultatif dont la valeur est jetée ; faux `tpeCharge`; copy « TPE validé/simulation ».

### D2 — CB borne

**Consensus:** `fail-closed`. Aucun opérateur n'est présent pour attester un débit externe. Le stub `approved:true/STUB-*` doit être impossible dans un build commercial. Une future intégration garde l'ordre en état paiement distinct jusqu'à réponse vérifiée et réconciliable.

**Rejeté:** fallback simulation, transaction ID arbitraire comme preuve, réutilisation du mode manuel POS.

### D3 — Operator Inbox

**Consensus avec garde-fous:** une projection backend unique est la seule façon de supprimer les populations contradictoires. Elle reste une projection, jamais une seconde machine d'état.

**Contrat révisé:**

- deux charges utiles : résumé léger par buckets, puis pages cursorisées ;
- tri urgence/action requise puis ancienneté, sans top-100 implicite ;
- `actions[]` ne contient que des codes typés et une version attendue ;
- chaque mutation repasse par service canonique, branche, rôle, paiement, state machine, CAS et idempotence ;
- cache/curseur indexés par branche, utilisateur/rôle et filtres ;
- vues POS/KDS/bar spécialisées comme filtres de la même projection ;
- état réseau dégradé conserve la dernière vérité et désactive les actions non revalidables.

**Rejeté:** sidebar sur la liste POS existante, URLs d'actions fournies par le frontend, endpoint monolithique sans pagination/freshness.

### D4 — Sonnerie et attention

**Proposition initiale:** ACK permanent de branche jusqu'à acceptation.  
**Objections gagnantes:** un clic peut faire taire toute l'équipe puis être oublié ; cuisine ne doit pas silencer caisse/boissons ; un onglet/leader peut tomber ; vue/livraison n'est pas action humaine.

**Décision révisée:**

`DELIVERED → SEEN → CLAIMED(lease) → RESOLVED`

- scope `branch + attention_kind + station/responsibility` ;
- delivery/seen n'arrêtent jamais durablement l'alarme ;
- claim temporaire, visible, avec actor/device, heartbeat, CAS/fencing et expiry ;
- le claim suspend l'audio agrégé du scope, mais l'alerte visuelle reste partout ;
- disparition du leader/expiry reprend la sonnerie dans un SLO ;
- seule une action métier canonique résout ;
- backlog initial agrégé, jamais N sons superposés ;
- commandes futures silencieuses avant `release_at`.

**Rejeté:** bip one-shot/localStorage, ACK global de branche, ACK permanent au simple rendu/clic.

### D5 — Impression

**Proposition initiale:** `pending → leased → delivered`, agent local unique.  
**Objections gagnantes:** Winspool ne prouve pas le papier ; crash après submit avant ACK est ambigu ; poste unique est un SPOF ; device ne doit pas être identité immuable du job ; dual-consume au cutover doublerait les tickets.

**Décision révisée:**

`PENDING → LEASED → SPOOL_ACCEPTED | FAILED_BEFORE_SPOOL | UNKNOWN_AFTER_SUBMIT | DEAD_LETTER`

- identité logique `{branch, order, order_revision, ticket_type, logical_station, generation}` ;
- snapshot/octets immuables + checksum ; claimant device temporaire ;
- une lease active par imprimante avec fencing, agents principal/standby ;
- écriture complète et fins page/document vérifiées ;
- `UNKNOWN_AFTER_SUBMIT` sans retry automatique ; duplicata humain explicite/audité ;
- retry technique conserve la génération ; reprint humain crée une génération ;
- nouveau consumer activé uniquement après désactivation coordonnée des autorités legacy ; dual-write éventuellement observé, jamais dual-consume ;
- `printed_at` n'est ni claim ni preuve papier sur ACK Winspool.

**Rejeté:** 202 comme succès, exactly-once physique promis, `localStorage` comme autorité, admin `branch_id=0` claimant toutes branches.

### D6 — Tiroir

**Proposition initiale:** `{ok:true}` worker = exécuté, retry manuel.  
**Objections gagnantes:** le worker ne possède aucun capteur d'ouverture ; une réponse perdue après impulsion rend le retry dangereux ; backend no-sale et listener comptoir peuvent encore pulser en parallèle.

**Décision révisée:**

- `FAILED_BEFORE_WRITE` retryable ;
- `UNKNOWN_AFTER_SUBMIT` sans retry/fallback automatique ; l'opérateur observe puis assume une nouvelle action ;
- `COMMAND_ACCEPTED_BY_SPOOLER` n'est jamais « tiroir ouvert » ; état physique `UNKNOWN_PHYSICAL` sans capteur/confirmation ;
- paiement et tiroir restent deux résultats : aucun retry de commande/paiement ;
- cutover inclut contrôleur no-sale et listener comptoir legacy, avec un seul exécuteur local.

**Rejeté:** `null`/202/non-false comme succès, serveur + bridge double pulse, retry transparent après résultat inconnu.

### D7 — Stock

**Proposition initiale:** séparer deux preuves de release.  
**Objection gagnante:** deux tables indépendantes sans intention commune recréeraient un split-brain ; rembourser un aliment déjà préparé ne doit pas automatiquement le remettre en `on_hand`.

**Décision révisée:**

- intention/saga commune atomique, preuves distinctes pour stock physique et disponibilité ;
- réservation atomique à la création, consommation à la production, release avant production ;
- après préparation : waste/override/contre-écriture métier, pas retour automatique d'aliment ;
- projection backend `sellable_quantity` dérivée de ledger `on_hand`, réservations, consommation et overrides ;
- chaque effet possède idempotence, lease/retry/dead-letter ; un sibling ne marque jamais l'autre ;
- le réconciliateur compare mouvements attendus/réels, même lorsque l'ancien `released_qty` dit à tort « complet ».

**Rejeté:** exception avalée, compteur partagé comme preuve, remboursement = restock automatique.

### D8 — 429, KDS et CSP

**Consensus révisé:**

- contrat KDS aligné sur le vrai `WebSocketService` et test utilisant le vrai contrat ;
- une requête en vol par `{branch, feed, cursor}` et leader cross-tab avec expiry ;
- séquence WebSocket monotone, gap → rattrapage REST cursorisé ;
- budgets séparés critique/opérationnel/analytique, backoff+jitter+`Retry-After` ;
- cache et cursors branch-scoped ; onglet caché ralentit analytics, pas alerte/print critique ;
- CSP natif parsé, sanitisé, dédupliqué et placé dans un bucket réellement séparé du business/NAT ; aucune hausse globale du plafond.

## Gates qui restent obligatoires

1. Confinements P0 (borne CB, copy CB POS, faux succès drawer/print, contrat KDS).
2. Ownership ADR et correction freeze masterplay.
3. Branch isolation : utilisateurs/devices/printers simultanés A/B.
4. Schema attention + claim/failover.
5. Schema/payment/fiscal ledger.
6. Print authority/delivery/cutover.
7. Stock reservation/saga/backfill dry-run.
8. Historique/retention avant mutation des 568 lignes ouvertes.
9. E2E réel multi-surface sans injection de store/event.
10. Hardware UAT signé : TPE, tiroir, imprimantes, borne, réseau, panne/restart.

## Conclusion

Le débat n'a pas produit un GO ; il a retiré plusieurs promesses techniquement fausses. Le plan révisé maximise le contrôle en nommant explicitement les états inconnus, en rendant les claims temporaires et branch-scoped, et en réservant les preuves physiques au laboratoire ou à une télémétrie réellement disponible.

**ADVERSARIAL_VERDICT: REVISE_ACCEPTED — READY_FOR_HUMAN_GATE, NOT READY_FOR_PRODUCT_EXECUTION**


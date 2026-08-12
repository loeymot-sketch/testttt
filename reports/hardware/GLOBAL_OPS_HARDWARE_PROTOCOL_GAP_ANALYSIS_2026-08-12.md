# Global Operations — Hardware Protocol Gap Analysis

**Date:** 2026-08-12  
**Reviewed artifacts:** hardware protocols, qualification checklist, acceptance grid  
**Status:** `ADDENDUM REQUIRED — EXISTING GRID UNSIGNED`  
**Verdict:** `NO HARDWARE GO`

## 1. Résumé

La grille existante est utile comme inventaire, mais plusieurs tests supposent une architecture que le produit ne possède pas. En particulier, les tests TPE 1.1–1.6 décrivent un POS qui « lance » et reçoit une approbation TPE. Le code audité possède seulement :

- une déclaration manuelle CB au POS ;
- un stub fail-open dangereux sur la borne ;
- aucun protocole fabricant/acquéreur vérifié ;
- aucune preuve que `terminal_id` mono est persisté ;
- aucune réconciliation TPE intégrée.

Exécuter les tests tels quels et cocher PASS à partir d'une saisie manuelle créerait une fausse preuve d'intégration.

Les tests impression/tiroir emploient également « ticket sorti » ou « tiroir ouvert » alors que le bridge actuel ne connaît que l'enqueue/WritePrinter et ne possède pas de capteur physique. La grille doit conserver la vérification humaine du papier/tiroir et distinguer le résultat logiciel observé.

## 2. Écarts bloquants par domaine

### 2.1 TPE

| Test existant | Hypothèse non satisfaite | Traitement avant exécution |
| --- | --- | --- |
| 1.1 Approve EMV | POS initie la transaction et reçoit APPROVED | Marquer `NOT APPLICABLE — MANUAL EXTERNAL` tant qu'aucun protocole intégré n'est livré ; créer protocole manuel séparé |
| 1.2 NFC | Retour approuvé vers POS <2 s | Même blocage ; mesurer le TPE seul ne prouve pas FoodKing |
| 1.3 Decline | Refus remonté au POS | Impossible dans le mode déclaratif ; FoodKing doit seulement ne pas enregistrer si l'opérateur refuse la confirmation |
| 1.4 Timeout réseau | État TPE connu du POS | Impossible sans attempt/callback ; le mode manuel doit avertir « vérifier avant redébit » |
| 1.5 Cancel client | Annulation propagée au POS | Impossible sans intégration ; panier reste sous contrôle opérateur |
| 1.6 Crash POS | Réconciliation automatique/orpheline | Impossible sans payment attempt durable ; tester seulement l'idempotence de la déclaration après timeout |
| 1.7 Ticket restaurant | Support matériel lié à UI | Distinguer déclaration manuelle, support du TPE externe et éventuelle intégration future |
| 1.8 Z reconciliation | Z caisse contient attribution exhaustive par TPE | Le mono n'écrit pas `order_payments`; rapprochement TPE complet impossible jusqu'au ledger |

### 2.2 Borne CB

La grille ne contient pas de campagne dédiée au fail-open actuellement prouvé. Ajouter avant tout GO :

1. build commercial sans bridge : CB indisponible, aucun POST de confirmation ;
2. marqueur `STUB-*`, transaction vide ou forged : rejet, commande non payée ;
3. bridge health KO : aucun fallback simulation ;
4. timeout/réponse inconnue : aucun second débit automatique ;
5. appareil/branche/montant/order différents : rejet ;
6. callback/replay identique : un seul paiement ; contradictoire : quarantaine ;
7. test réel avec protocole fournisseur seulement après livraison de l'étape durable.

### 2.3 Impression

| Écart | Risque | Ajout obligatoire |
| --- | --- | --- |
| 202 enqueue pris comme succès | ticket perdu marqué imprimé | Capturer job ID et résultat worker ; 202 = queued |
| WritePrinter sans état papier | faux `DELIVERED` | Distinguer `SPOOL_ACCEPTED` de preuve physique ; observation humaine/télémétrie |
| claim sans lease | ticket bloqué après crash | crash après claim avant write puis expiry/reprise |
| crash après papier avant ACK | doublon au retry | état `UNKNOWN_AFTER_SUBMIT`, décision/duplicata humain |
| POS/KDS dual-consume | double ticket | test cutover fail-closed et une seule autorité active |
| `branch_id=0` | mauvaise imprimante/branche | deux branches, admin global, devices branch/station-bound |
| snapshot mutable | ticket rejoué avec contenu différent | checksum/octets immuables et nouvelle génération pour correction |
| test 2.8 50 tickets seulement | quantité sans unicité/routage | vérifier ID/génération/station de chacun et zéro doublon |

### 2.4 Tiroir

Le bridge ne possède pas de capteur d'ouverture. Les preuves doivent distinguer :

- intention créée ;
- write accepté par spooler ;
- observation physique du tiroir ;
- log no-sale/vente ;
- résultat inconnu après submit.

Ajouter :

1. `null`, 202, réponse malformée : jamais « ouvert » ;
2. connexion refusée avant write : retry sûr ;
3. réponse perdue après submit : aucun retry automatique ;
4. backend no-sale + bridge disponible : une seule impulsion ;
5. listener comptoir legacy désactivé au cutover ;
6. paiement cash réussi + tiroir KO : aucune resoumission du paiement ;
7. pins `m=0/m=1`, câble RJ12, verrou physique et modèle exact ;
8. observation vidéo/horodatée comparée au journal.

### 2.5 Réseau, rate limits et multi-device

Les protocoles réseau actuels ne vérifient pas :

- plusieurs onglets partageant le bucket utilisateur ;
- plusieurs appareils derrière le même NAT/IP ;
- tempête CSP anonyme ;
- reconnexion WebSocket 20–50 clients ;
- leader cross-tab/failover ;
- budget réservé aux mutations critiques ;
- deux branches ouvertes par un admin global.

Ajouter une campagne avec compteur serveur/Redis, HAR, correlation IDs, P50/P95, `Retry-After` et preuve qu'accepter/encaisser reste possible pendant le stress analytique.

## 3. Protocoles séparés à adopter

### 3.1 POS CB manuel externe — applicable maintenant après confinement

| ID | Scénario | PASS |
| --- | --- | --- |
| M-CB-01 | Aucun terminal configuré | Confirmation manuelle possible ; texte dit qu'aucune demande n'est envoyée |
| M-CB-02 | Opérateur répond « non » à « déjà accepté ? » | Aucun POST/PAID |
| M-CB-03 | Confirmation « oui » | Une commande CARD, montant serveur exact, aucune charge appelée |
| M-CB-04 | Timeout après commit | Replay même clé retourne même commande, message ne pas redébiter |
| M-CB-05 | Comptoir | Montant carte égal au total, aucun rendu/surpaiement |
| M-CB-06 | Annulation/retour | Permission manager et avertissement remboursement TPE externe |
| M-CB-07 | Rapprochement | Déclaré `LIMITED` tant que ledger mono/TPE absent, jamais PASS exhaustif |

### 3.2 TPE intégré — non applicable tant que non implémenté

Les tests 1.1–1.6 deviennent applicables uniquement lorsqu'existent :

- payment attempt durable ;
- identifiant ordre/branche/device/montant lié au protocole ;
- états APPROVED/DECLINED/TIMEOUT/CANCELED/UNKNOWN ;
- callback/réponse authentifiée ;
- réconciliation après état inconnu ;
- protection replay/double débit ;
- sandbox/certification fournisseur documentée.

### 3.3 Borne CB — fail-closed puis intégrée

Aucun mode manuel n'est autorisé. Le confinement doit être PASS avant activation de la borne ; l'intégration réelle nécessite ensuite les mêmes preuves durables que le TPE intégré.

## 4. Matrice de preuves à archiver

Pour chaque test :

- branche, établissement, poste/station et utilisateur ;
- modèle, firmware, numéro de série, driver, nom imprimante/spooler ;
- version exacte application/bridge/agent et commit ;
- heure NTP et correlation/job/payment attempt ID ;
- HAR/logs serveur/worker sanitisés ;
- photo/vidéo et ticket/reçu avec données de test ;
- état DB avant/après ou export d'audit ;
- verdict binaire, signataire humain et anomalie liée.

Une capture écran de toast, un HTTP 2xx ou un test mocké ne constitue jamais une preuve de papier, d'ouverture ou de débit.

## 5. Conditions de GO matériel corrigées

- [ ] Le mode évalué est nommé correctement : manuel externe ou intégré.
- [ ] Aucun test intégré n'est coché PASS à partir d'une saisie manuelle.
- [ ] CB borne fail-closed sans bridge.
- [ ] TPE intégré réel, si activé, couvre inconnu/replay/crash/réconciliation.
- [ ] Impression différencie spool et observation papier.
- [ ] Tiroir différencie write et ouverture physique.
- [ ] Une seule autorité active par imprimante/tiroir au cutover.
- [ ] Deux branches et admin global ne provoquent aucune fuite matérielle.
- [ ] NAT/reconnect/429 multi-device sont testés.
- [ ] Toutes les cases pertinentes de la grille sont signées, avec `N/A` justifié pour les autres.

## 6. Décision

La grille existante ne doit pas être supprimée ni pré-cochée. Elle doit être complétée par cet addendum et révisée au moment du cycle hardware autorisé. Jusqu'à cette révision et à l'exécution physique signée :

**HARDWARE_PROTOCOL_VERDICT: HOLD — CURRENT PROTOCOL ASSUMES UNIMPLEMENTED TPE INTEGRATION**


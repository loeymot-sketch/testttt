# TASK_GLOB_OPS_DRAWER_ACK_001 — Tiroir : supprimer le faux succès et le double pulse

## Meta

- **Priority:** P0/P1 matériel caisse
- **EXECUTION_TIER:** `complex`
- **PRIMARY_EXECUTION_MODEL:** `gpt-5.5-pro`
- **REASONING_EFFORT:** `xhigh`
- **TEST_STRATEGY:** `local-validation` + `playwright-critical-flow` + `human-verification`
- **SOURCE:** audit global F-04 et contre-audit matériel
- **STATUS:** `PENDING_GLOBAL_OPS_GATE_AND_FILE_RECONCILIATION`

## Problème prouvé

- `kioskHardware.openDrawer()` transforme en succès toute réponse du pont qui n'est pas explicitement `{ok:false}` ; `null` devient donc `{ok:true, via:'caisse-bridge'}`.
- Le pont caisse renvoie HTTP 202 à l'enqueue, pas après l'impulsion physique.
- `PaymentComponent.vue` déclenche l'ouverture cash en fire-and-forget et masque toute erreur.
- Le chemin no-sale dans `PosComponent.vue` peut appeler le backend puis le bridge local, donc produire deux intentions/pulses.
- Les tests actuels mockent l'ouverture ; aucun ne prouve les pins, le câble, le port imprimante ou l'ouverture physique.

## Contrat de confinement

- `requested` signifie seulement qu'une intention a été émise.
- `command_accepted_by_spooler` exige un résultat explicite du worker, mais ne prouve pas que le tiroir est physiquement ouvert.
- `failed_before_write` est réessayable en sécurité ; `unknown_after_submit` est visible mais n'autorise aucun retry/fallback automatique, car l'impulsion a pu avoir lieu.
- Une action opérateur produit au maximum une intention idempotente vers un seul exécuteur local.

Un HTTP 202 d'enqueue n'est jamais affiché comme « tiroir ouvert ».

## Scope étape A — sans schema

| Fichier | Action |
| --- | --- |
| `resources/js/services/kioskHardware.js` | Accepter seulement un résultat explicite et typé ; `null`, malformé, timeout ou 202 enqueue-only → `unknown/failed`, jamais preuve d'ouverture |
| `resources/js/components/admin/pos/PaymentComponent.vue` | Attendre le résultat ; afficher warning persistant et retry manuel sans annuler la vente cash déjà commitée |
| `resources/js/components/admin/pos/PosComponent.vue` | Après réconciliation du dirty diff, désigner un seul chemin pour no-sale et empêcher serveur+local double pulse |
| bridge/helper et tests ciblés | Exposer la différence enqueue/résultat worker ; ne pas modifier les fichiers dirty sans owner |

## Scope étape B — après gate durable

Créer une intention matérielle branch/device/session-scoped et idempotente :

`REQUESTED → LEASED → COMMAND_ACCEPTED_BY_SPOOLER | FAILED_BEFORE_WRITE | UNKNOWN_AFTER_SUBMIT`

L'agent local enregistré soumet une fois, puis ACKe avec job ID, branche, device, session et erreur normalisée. Sans capteur matériel, le statut physique reste `UNKNOWN_PHYSICAL`; l'audit ne déduit jamais « tiroir ouvert » du statut HTTP ou de Winspool.

## Règles d'implémentation

1. Le commit de la vente cash et l'ouverture du tiroir sont deux résultats distincts. Une panne tiroir ne rejoue pas automatiquement la mutation de paiement.
2. Un échec après paiement affiche « paiement enregistré, tiroir non confirmé ». Le retry n'est proposé que pour `FAILED_BEFORE_WRITE`; pour `UNKNOWN_AFTER_SUBMIT`, l'opérateur observe le tiroir puis peut créer une nouvelle action explicitement assumée, jamais un retry transparent. Aucune action ne resoumet la commande.
3. No-sale vérifie permission/session côté backend, mais l'impulsion physique n'est envoyée que par l'exécuteur local retenu. Le scope durable inclut donc `CashDrawerController`/route ou une nouvelle intention serveur ; le contrôleur ne peut plus pulser directement lors du cutover.
4. Aucun fallback simultané serveur + bridge. Un fallback n'est autorisé qu'après échec connu avant exécution, avec même clé idempotente.
5. Tous les résultats matériels sont branch-scoped ; un admin global choisit explicitement branche/device.
6. Le listener comptoir legacy `PrintFiscalReceiptAndOpenDrawerOnCounterPaid` est désactivé ou converti en producteur d'intention au cutover ; dual-execution est interdit.

## SUBSYSTEMS_OFF_LIMITS

- Aucun changement des règles comptables/fiscales du cash.
- Aucun retry de la vente ou du paiement à cause du tiroir.
- Aucun succès physique basé sur `2xx`, `202`, valeur truthy, absence d'exception ou acceptation Winspool.
- Aucun secret statique exposé dans JavaScript comme pseudo-authentification durable.
- Aucun claim de validation matérielle par test simulé.

## Tests falsifiables étape A

1. `printEscPosViaCaisseBridge()` retourne `null` : `openDrawer()` échoue/inconnu, jamais `ok:true`.
2. Réponse `{}`/malformée : warning visible. Connexion refusée avant write : retry sûr. Timeout après soumission possible : `UNKNOWN_AFTER_SUBMIT`, pas de retry automatique.
3. HTTP 202 enqueue-only : l'UI affiche « envoyé/en attente » au mieux, jamais « ouvert ».
4. Résultat worker `{ok:false,error}` : erreur opérable conservée.
5. Résultat worker `{ok:true,jobId}` : au plus `COMMAND_ACCEPTED_BY_SPOOLER`, jamais « ouvert » sans capteur/confirmation.
6. Paiement cash réussi + tiroir KO : une commande/une remise, aucun POST de paiement supplémentaire.
7. No-sale avec backend et bridge disponibles : une seule invocation physique.
8. Deux clics rapides en étape A : single-flight navigateur uniquement, sans prétention de garantie après reload. L'idempotence durable appartient à l'étape B.
9. Réponse perdue après soumission : `UNKNOWN_AFTER_SUBMIT`, aucun fallback/retry automatique.
10. No-sale : backend autorise/audite sans pulse ; seul l'agent local exécute après cutover.
11. Counter cash : le listener legacy ne produit aucun second pulse après activation de l'autorité locale.

## Tests terrain obligatoires

- Pins ESC/POS `m=0` et `m=1` sur le vrai modèle.
- Câble débranché, imprimante éteinte, bridge arrêté, spooler bloqué et redémarrage.
- Vente cash et no-sale sur la caisse réelle.
- Vérification vidéo/horodatée : une action = une ouverture ; réponse worker cohérente.

## Acceptance Criteria

- [ ] `null`, timeout, réponse malformée et 202 ne produisent jamais un faux succès.
- [ ] Une vente cash déjà réussie n'est jamais rejouée par le retry tiroir.
- [ ] Une action no-sale ne peut produire qu'un pulse logique.
- [ ] L'échec reste visible jusqu'à action opérateur.
- [ ] L'ACK worker est libellé « commande acceptée par le spooler », jamais preuve d'ouverture physique.
- [ ] La grille hardware est exécutée et signée avant GO.

## Gate et collisions

- `HG-GLOBAL-OPS-RELIABILITY-2026-08-11` Décision 1 autorise seulement l'étape A.
- L'étape B et le cutover du contrôleur/listener backend requièrent un gate schema/frozen/matériel distinct.
- `PosComponent.vue`, bridges/listeners/renderers sont dirty : réservation et réconciliation obligatoires ; ne jamais écraser le diff existant.
- `HG-HARDWARE-LAB-SIGNOFF` reste obligatoire après code.

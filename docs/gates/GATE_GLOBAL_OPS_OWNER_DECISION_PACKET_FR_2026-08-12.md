# Paquet de décision propriétaire — Fiabilité globale FoodKing

**Statut :** `PARENT D1–D7 APPROUVÉ AVEC CONTRAINTE PROPRIÉTAIRE — Q1–Q29 RESTE GUIDE D'EXÉCUTION`  
**But :** permettre au propriétaire de valider en connaissance de cause toutes les corrections, tests et le chemin vers le déploiement  
**Gate parent :** `HG-GLOBAL-OPS-RELIABILITY-2026-08-11`  
**Verdict actuel :** `HOLD` jusqu'aux corrections, doubles audits et tests matériels signés

**Clarification propriétaire reçue le 2026-08-12 :** CB POS manuelle sur TPE externe non connecté ; FoodKing enregistre CARD pour fiscalité/gestion, imprime et poursuit. Aucune intégration TPE n'est demandée dans le scope courant. Les autres recommandations de l'audit sont validées ; l'exécution technique est confiée à une mission Claude orchestrée en raison du coût.

## Doctrine de décision

Les choix recommandés privilégient dans cet ordre :

1. ne jamais perdre une commande ou enregistrer un faux paiement ;
2. ne jamais mentir sur un effet matériel ;
3. rendre chaque anomalie visible et attribuable ;
4. protéger la branche, le prix serveur, la fiscalité et l'idempotence ;
5. réduire les gestes du caissier sans cacher les états inconnus ;
6. déployer progressivement avec rollback, jamais en big bang.

## Q1 — Autoriser les confinements P0 immédiatement ?

### Choix

- **A — Recommandé :** ouvrir des cycles bornés pour borne CB fail-closed, CB POS honnête, faux succès tiroir/impression, contrat KDS et CSP/429.
- B : continuer seulement l'audit.
- C : ne rien changer.

### Mon choix à votre place

**A.** Les défauts actuels peuvent créer faux paiement, commande ignorée, ticket perdu et faux succès matériel. Les confinements réduisent le risque sans prétendre résoudre les architectures durables.

## Q2 — Que signifie « Carte » sur la caisse tant que le TPE n'est pas intégré ?

### Choix

- **A — Recommandé :** « CB déjà validée sur TPE externe » ; confirmation explicite ; aucune demande envoyée par FoodKing ; aucun terminal mono affiché tant que sa valeur n'est pas persistée.
- B : conserver l'écran TPE/simulation.
- C : bloquer complètement la CB POS.

### Mon choix à votre place

**A.** C'est le fonctionnement réel demandé : le caissier encaisse sur le TPE externe, puis enregistre le résultat. Bloquer détruit la productivité ; simuler crée un mensonge. Le montant carte comptoir doit être exactement le total serveur, sans rendu ni surpaiement.

## Q3 — Faut-il intégrer réellement le TPE plus tard ?

### Choix

- **A — Recommandé :** oui, comme phase séparée avec protocole fabricant/acquéreur, `payment_attempt`, états APPROVED/DECLINED/TIMEOUT/CANCELED/UNKNOWN et réconciliation.
- B : rester définitivement manuel externe.
- C : utiliser le stub actuel comme intégration.

### Mon choix à votre place

**A**, après stabilisation. Le manuel externe débloque l'exploitation rapidement ; l'intégration réelle réduit ensuite les erreurs de rapprochement. Elle ne doit jamais être simulée. Aucun PAN/cryptogramme ne doit entrer dans FoodKing.

## Q4 — Que faire de la CB sur la borne sans intégration réelle ?

### Choix

- **A — Recommandé :** fail-closed : CB désactivée sans bridge/preuve de confiance.
- B : demander au client de confirmer lui-même que le TPE a accepté.
- C : conserver `STUB-*`/simulation.

### Mon choix à votre place

**A.** À la borne, aucun opérateur fiable ne peut attester le paiement. Toute autre option peut produire une commande payée sans argent reçu.

## Q5 — Ouvrir le ledger complet des paiements ?

### Choix

- **A — Recommandé :** chaque mono, split, comptoir, remboursement et contre-écriture produit une ligne immutable, branch-scoped, avec montant serveur, opérateur, terminal réellement persisté et référence externe autorisée.
- B : conserver le pilote restreint et accepter des Z/frais TPE incomplets.
- C : aucun ledger.

### Mon choix à votre place

**A**, sous revue fiscale. Sans ledger, le mono CB n'alimente pas correctement l'attribution TPE/Z/fees. La comptabilité et les recherches d'écart resteront incomplètes.

## Q6 — Autoriser un paiement espèces sans session caisse ouverte ?

### Choix

- **A — Recommandé :** bloquer normalement ; dérogation manager uniquement, auditée, avec tâche de rapprochement obligatoire.
- B : autoriser avec simple warning.
- C : toujours autoriser sans trace.

### Mon choix à votre place

**A.** Une vente cash sans mouvement de caisse rend le Z et le comptage faux. La dérogation manager protège l'exploitation en cas exceptionnel sans rendre l'écart invisible.

## Q7 — Comment annuler une commande déjà payée ?

### Choix

- **A — Recommandé :** jamais simple annulation/UNPAID ; workflow manager de retour/remboursement avec motif, contre-écriture et avertissement TPE externe.
- B : permettre au caissier de remettre la commande impayée.
- C : supprimer la commande.

### Mon choix à votre place

**A.** Une commande payée doit conserver son histoire. FoodKing ne doit jamais prétendre avoir remboursé le TPE externe si l'opérateur n'a pas effectué cette action.

## Q8 — Quelle liste de commandes construire ?

### Choix

- **A — Recommandé :** `Operator Inbox` backend unique, résumé léger + pages cursorisées, sidebar/drawer POS, filtres spécialisés KDS/bar, `actions[]` typées et revalidées.
- B : ajouter une sidebar sur la liste POS actuelle.
- C : garder POS, tracker, dashboard et historique indépendants.

### Mon choix à votre place

**A.** C'est le seul choix qui corrige les 77 commandes web visibles au POS mais 0 dans le tracker. Une nouvelle interface sur une mauvaise population serait seulement plus jolie, pas plus fiable.

## Q9 — Quelles actions afficher dans l'Inbox ?

### Choix

- **A — Recommandé :** actions calculées côté serveur selon statut, paiement, rôle et branche ; chaque clic revalidé avec version/CAS/idempotence.
- B : reconstituer les actions dans Vue.
- C : proposer toutes les actions et laisser l'API refuser.

### Mon choix à votre place

**A.** `PREPARED` ne doit jamais afficher Annuler générique ; PENDING doit permettre Accepter/Rejeter ; une commande payée exige un workflow manager. Cela réduit erreurs et clics inutiles.

## Q10 — Comment gérer la sonnerie et la prise en charge ?

### Choix

- **A — Recommandé :** `DELIVERED → SEEN → CLAIMED(lease) → RESOLVED`, par branche + type + responsabilité/station.
- B : ACK permanent au premier clic.
- C : bip unique actuel.

### Mon choix à votre place

**A.** Le claim temporaire suspend l'audio mais reste visible ; si l'opérateur ou le poste disparaît, la sonnerie reprend. Seule l'action métier réelle résout. Cuisine ne doit pas faire taire caisse ou boissons.

## Q11 — Quelle expérience sonore ?

### Choix

- **A — Recommandé :** première alerte rapide, salve courte toutes les 8–12 s, agrégée par responsabilité, volume testable, badge/titre/notification et bannière si audio bloqué.
- B : son continu jusqu'à acceptation.
- C : un bip par commande.

### Mon choix à votre place

**A.** Un son continu fatigue l'équipe et finit par être coupé. Des salves agrégées restent impossibles à ignorer sans créer 77 sons simultanés au redémarrage.

## Q12 — Quand une commande programmée doit-elle apparaître et sonner ?

### Choix

- **A — Recommandé :** séparer heure demandée, heure promise et `release_at`; accepter « dans N minutes » ou heure exacte ; afficher Upcoming mais ne sonner/libérer qu'à `release_at`.
- B : utiliser uniquement `scheduled_at` partout.
- C : faire sonner dès la création.

### Mon choix à votre place

**A.** Une commande de demain ne doit ni vieillir, ni saturer le KDS, ni sonner aujourd'hui. Le serveur reste la seule source des heures normalisées, y compris minuit/DST.

## Q13 — Comment éliminer les 429 ?

### Choix

- **A — Recommandé :** coordinateur cross-tab, une requête en vol par branche/feed/cursor, WebSocket séquencé + catch-up, backoff/jitter/Retry-After et budgets critique/opérationnel/analytique.
- B : augmenter simplement 120/min.
- C : réduire quelques timers au hasard.

### Mon choix à votre place

**A.** Augmenter la limite masque le défaut et affaiblit les protections. Les commandes/encaissements doivent conserver un budget même si dashboard/CSP sont actifs.

## Q14 — Que faire des rapports CSP qui provoquent une tempête ?

### Choix

- **A — Recommandé :** parser les formats natifs, borner/sanitariser, dédupliquer et utiliser un bucket séparé du business/NAT.
- B : conserver les throttles imbriqués.
- C : désactiver CSP reporting.

### Mon choix à votre place

**A.** Désactiver supprimerait un signal sécurité ; conserver le bucket actuel peut bloquer les clients derrière le même NAT.

## Q15 — Quelle autorité pour l'impression ?

### Choix

- **A — Recommandé :** file serveur `print_jobs`, identité logique branche/ordre/révision/document/station/génération, une lease active par imprimante, agents principal/standby.
- B : navigateur POS autoritaire.
- C : KDS/localStorage autoritaire.

### Mon choix à votre place

**A.** Un navigateur peut être fermé ou suspendu. Le device doit être claimant temporaire, pas propriétaire immuable du ticket.

## Q16 — Que signifie « imprimé » ?

### Choix

- **A — Recommandé :** états `SPOOL_ACCEPTED`, `FAILED_BEFORE_SPOOL`, `UNKNOWN_AFTER_SUBMIT`; ne jamais prétendre le papier sur HTTP 202/Winspool seul.
- B : 202 = imprimé.
- C : absence d'exception = imprimé.

### Mon choix à votre place

**A.** Après spool sans ACK, le ticket peut être sorti ou non : il faut montrer « inconnu » et proposer un duplicata humain audité, jamais réimprimer automatiquement.

## Q17 — Quels documents imprimer automatiquement ?

### Choix

- **A — Recommandé :** ticket production cuisine/boissons automatique après événement métier/release ; copie comptoir configurable ; reçu client à la demande ; reçu borne selon politique explicitement validée.
- B : tout imprimer automatiquement.
- C : rien automatiquement.

### Mon choix à votre place

**A.** La production ne doit pas dépendre d'un clic. Le reçu client systématique gaspille du papier et contredit la configuration actuelle ; il reste accessible en un clic/reprint.

## Q18 — Comment gérer le tiroir ?

### Choix

- **A — Recommandé :** un exécuteur local ; `FAILED_BEFORE_WRITE` retryable ; `UNKNOWN_AFTER_SUBMIT` sans retry automatique ; Winspool ne vaut pas ouverture physique.
- B : backend puis bridge local en fallback immédiat.
- C : tout non-false = succès.

### Mon choix à votre place

**A.** Le fallback automatique après résultat inconnu peut créer deux impulsions. Le paiement réussi ne doit jamais être resoumis à cause du tiroir.

## Q19 — Quelle architecture stock ?

### Choix

- **A — Recommandé :** réservation atomique à la commande, consommation à la production, release avant production, waste/override après préparation ; saga commune avec preuves distinctes stock/disponibilité.
- B : garder `released_qty` partagé avec drapeau erreur.
- C : continuer les exceptions avalées.

### Mon choix à votre place

**A.** La disponibilité ne doit jamais masquer l'échec du stock physique. Un aliment déjà préparé et remboursé ne retourne pas automatiquement en stock consommable.

## Q20 — Que faire des anciennes commandes ouvertes ?

### Choix

- **A — Recommandé :** classer actionnable, programmée, candidate janitor, orpheline historique et payée/fiscalisée ; produire un repair set relu humainement.
- B : annuler automatiquement toutes les anciennes.
- C : les ignorer.

### Mon choix à votre place

**A.** Une suppression/annulation massive peut toucher des commandes payées ou fiscalisées. La santé doit être nettoyée sans réécrire aveuglément l'histoire.

## Q21 — Quelle observabilité et quelles escalades ?

### Choix

- **A — Recommandé :** une seule commande critique suffit pour passer en dégradé ; heartbeat scheduler/worker/janitor, outbox age, leases expirées, print/stock dead letters, 429 et freshness Inbox ; erreur probe = UNKNOWN/RED.
- B : alerte seulement après 10 événements.
- C : logs sans notification humaine.

### Mon choix à votre place

**A.** Un restaurant peut perdre une seule commande importante. Le volume faible ne doit jamais produire un faux vert.

## Q22 — Quels canaux de notification ?

### Choix

- **A — Recommandé :** POS visuel+audio immédiat, notification système/PWA, push manager pour non-résolu, puis SMS/WhatsApp uniquement après choix fournisseur et délai d'escalade.
- B : son POS uniquement.
- C : email uniquement.

### Mon choix à votre place

**A.** Plusieurs couches évitent qu'un onglet muet perde une commande. Le SMS/WhatsApp doit rester une escalade bornée pour éviter coût et bruit.

## Q23 — Comment traiter l'application mobile ?

### Choix

- **A — Recommandé :** la déclarer non qualifiée aujourd'hui ; auditer le vrai dépôt/app, puis brancher tokens, branche, outbox/push/polling et E2E réel.
- B : considérer `/m` comme preuve mobile.
- C : déclarer la mobile couverte par le web.

### Mon choix à votre place

**A.** `/m` est une mini-page stock, pas l'application client. Il faut éviter une promesse commerciale non démontrée.

## Q24 — Que faire de l'ingress Uber en cours ?

### Choix

- **A — Recommandé :** quarantaine/fail-closed tant que prix serveur, dédup branche et réservation stock atomique ne sont pas prouvés.
- B : accepter puis tenter le stock après commit.
- C : laisser l'exception stock être avalée.

### Mon choix à votre place

**A.** Une commande payée et visible cuisine sans réservation stock est plus grave qu'une commande explicitement mise en quarantaine.

## Q25 — Sécuriser les bridges locaux ?

### Choix

- **A — Recommandé :** confinement origin allowlist/taille/type, puis agent pull de jobs signés, token device limité branche/station/printer et anti-replay.
- B : token statique dans JavaScript.
- C : CORS `*` et octets arbitraires.

### Mon choix à votre place

**A.** Un token lisible par la page n'est pas une vraie barrière. L'agent doit tirer uniquement ce que le serveur lui assigne.

## Q26 — Quelle stratégie de test ?

### Choix

- **A — Recommandé :** tests unitaires/feature fail-first, E2E naturel sans injection store/event, chaos, deux branches, multi-postes, puis laboratoire matériel signé.
- B : PHPUnit/Vitest seulement.
- C : Playwright avec événements synthétiques seulement.

### Mon choix à votre place

**A.** Les tests actuels verts ont masqué de faux contrats. Chaque test doit pouvoir échouer sur les fenêtres réelles : crash, ACK perdu, timeout, double clic, branche B, papier vide.

## Q27 — Quelle stratégie de déploiement ?

### Choix

- **A — Recommandé :** canary en laboratoire puis une branche/une caisse/une station, feature flags et rollback ; élargissement progressif après observation.
- B : déployer toutes les branches en même temps.
- C : corriger directement en production sans lab.

### Mon choix à votre place

**A.** Les changements touchent paiement, impression, temps réel et stock. Le canary limite l'impact d'une hypothèse matérielle fausse.

## Q28 — Quels seuils opérationnels viser ?

### Choix recommandé

- nouvelle commande visible : p95 ≤ 3 s via temps réel, fallback ≤ 10 s ;
- première alerte : ≤ 3 s après `release_at` ;
- reprise après leader perdu : ≤ 15 s ;
- zéro 429 au repos sur la matrice multi-onglets ;
- mutation accepter/encaisser p95 ≤ 1,5 s hors paiement externe ;
- job d'impression créé ≤ 2 s après événement autorisé ; spool accepté cible ≤ 5 s ;
- fuite inter-branche : zéro ;
- dead-letter critique : visible/page humain ≤ 60 s ;
- divergence stock non silencieuse : zéro.

### Mon choix à votre place

**Valider ces SLO comme cibles de qualification**, puis les ajuster uniquement avec mesures terrain, pas pour rendre un test plus facile.

## Q29 — Quand autoriser le GO commercial ?

### Choix

- **A — Recommandé :** seulement après D1–D28 exécutées selon scope, double audit PASS, E2E/chaos, repair historique contrôlé et grille matérielle signée.
- B : après tests logiciels verts.
- C : après confinement P0 seulement.

### Mon choix à votre place

**A.** Les confinements réduisent le risque mais ne prouvent ni TPE, ni papier, ni tiroir, ni réseau réel.

## Décision globale que je prendrais à votre place

Je validerais **tous les choix A recommandés**, avec ce séquençage :

1. confinements P0 ;
2. Operator Inbox + contrat actions + polling ;
3. attention/claim/alarme ;
4. ledger paiement, print jobs et stock saga sous gates schema/fiscal ;
5. planning/health/mobile/Uber ;
6. réparation historique revue ;
7. E2E/chaos ;
8. laboratoire matériel ;
9. canary ;
10. déploiement progressif puis surveillance renforcée.

## Formule d'approbation proposée

> En qualité de propriétaire/décideur humain, j'approuve les choix recommandés A des questions Q1 à Q29 du paquet `GATE_GLOBAL_OPS_OWNER_DECISION_PACKET_FR_2026-08-12`. J'approuve donc D1 à D7 Option A du gate parent et j'autorise la levée du correction freeze uniquement pour ouvrir les cycles bornés correspondants. Les migrations, la fiscalité, les paiements intégrés et le matériel restent soumis à leurs gates et validations spécifiques. Cette approbation ne constitue pas encore un GO commercial ; le GO interviendra uniquement après double audit PASS, E2E/chaos et grille matérielle signée.

Après cette réponse humaine, les choix doivent être transcrits dans le gate principal et `GATE_LOG.md` avant tout code produit.

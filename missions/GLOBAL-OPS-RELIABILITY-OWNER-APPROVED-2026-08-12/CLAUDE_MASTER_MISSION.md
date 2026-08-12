# CLAUDE MASTER MISSION — FoodKing Global Operations Reliability

Tu es l'orchestrateur responsable du résultat global FoodKing pour le mandat `GLOBAL-OPS-RELIABILITY-OWNER-APPROVED-2026-08-12`.

## 0. Autorité et rôle

1. Lis intégralement `CLAUDE.md`, `AGENTS.md` et `.cursor/ACTIVE_CYCLE.md` avant toute action.
2. Lis ensuite, dans cet ordre :
   - `docs/gates/GATE_GLOBAL_OPERATIONS_RELIABILITY_2026-08-11.md` ;
   - `reports/audit/AUDIT_GLOBAL_OPERATIONS_CAISSE_KDS_WEB_MOBILE_2026-08-11.md` ;
   - `reports/audit/ADVERSARIAL_DECISION_RECORD_GLOBAL_OPS_2026-08-12.md` ;
   - `reports/qa/GLOBAL_OPS_REQUIREMENTS_TRACEABILITY_2026-08-12.md` ;
   - `reports/planning/PLAN_GLOBAL_OPS_CLAUDE_ORCHESTRATION_2026-08-12.md` ;
   - `docs/architecture/OPERATOR_INBOX_ATTENTION_CONTRACT_PROPOSAL_2026-08-12.md` ;
   - les `tasks/TASK_GLOB_OPS_*.md` seulement au moment du lot concerné.
3. Tu es PLAN + AUDIT + arbitre d'escalade. Tu es comptable du résultat technique mais, conformément au dépôt, tu ne modifies pas directement le code produit : délègue chaque EXECUTE à `codex-extension` et audite ensuite.
4. N'utilise jamais un seul énorme EXECUTE. Crée des cycles atomiques suivant le plan master, avec allowlist stricte, test fail-first et rollback.
5. Ne déclare jamais le GO toi-même. Le GO commercial reste humain après hardware UAT et double PASS.

## 1. Décision humaine propriétaire déjà prise

Le propriétaire approuve D1–D7 Option A avec la contrainte suivante, autoritaire sur toute formulation antérieure :

### CB POS

- Le TPE est externe, physiquement séparé et non connecté à la caisse.
- Le caissier tape/encaisse sur le TPE manuellement.
- FoodKing ne lance, ne valide et ne réconcilie aucune transaction TPE dans le scope courant.
- Dans FoodKing, le caissier confirme seulement que la CB a déjà été acceptée.
- FoodKing enregistre `CARD` pour la fiscalité et la gestion, imprime selon la politique et poursuit le flux normal.
- Le mono CARD ne doit jamais être bloqué faute de terminal configuré.
- Aucun sélecteur ou `terminal_id` mono ne doit être conservé si le backend le jette.
- Si un futur ledger persiste réellement un terminal/label, celui-ci devient optionnel, branch-scoped et auditable ; il ne prouve toujours pas le débit.
- Ne construis aucune intégration TPE fabricant dans les lots actuels. Elle est un projet futur optionnel.

### CB borne

- Différente du POS : aucun humain ne peut confirmer.
- Sans intégration de confiance, elle doit être fail-closed.

### Cash drawer

- Le propriétaire affirme que le tiroir est physiquement connecté mais ne s'ouvre pas.
- Ne suppose pas que le connecteur est Ethernet : beaucoup de tiroirs utilisent un connecteur 6P6C RJ12/RJ11 ressemblant à RJ45, branché au port `DK` de l'imprimante ticket.
- Exige modèle/photo/étiquette/port, nom imprimante/driver et topologie avant conclusion.
- Le bridge/Winspool n'a pas de capteur d'ouverture : ne déclare jamais « ouvert » sur 2xx/202/WritePrinter.

## 2. Objectif final non réductible

Corriger, tester et préparer le déploiement des systèmes :

- POS/caisse ;
- site web ;
- borne ;
- KDS/cuisine ;
- écran client/OSS ;
- véritable application mobile si le repo/app est accessible ;
- paiements/fiscal/caisse ;
- impression/tiroir/bridges ;
- stock/disponibilité ;
- temps réel, rate limits, santé et notifications ;
- commandes historiques et intégrations externes.

La mission n'est terminée que lorsque les 18 exigences de la matrice sont `PROVEN`, pas simplement parce que les tests unitaires passent.

## 3. Budget et discipline de contexte

Le propriétaire demande maximum de raisonnement mais interdit le gaspillage de contexte :

1. Les artefacts disque sont la mémoire ; ne recopie pas l'audit complet dans chaque prompt.
2. Pour chaque sous-cycle, injecte uniquement : invariant global court, finding concerné, fichiers allowlistés, tests et gate.
3. Utilise `missions/<TASK_ID>/plan_excerpt.md` et `execute_brief.md`, pas un prompt master répété.
4. Un lot = un objectif falsifiable et un groupe cohérent de fichiers.
5. Après chaque lot, écris un rapport court avec diff, tests, risques résiduels et preuve ; réutilise ce rapport au lot suivant.
6. Ne relance pas des suites massives si un test ciblé prouve d'abord un échec local ; élargis seulement après vert ciblé.
7. La rigueur ne doit jamais être réduite pour économiser : supprime la duplication, pas les tests ou le raisonnement.

## 4. Démarrage obligatoire

Exécute en lecture seule :

```bash
npm run verify:boucle
bash scripts/agent-activity-log.sh tail 50
git status --short
```

Puis :

1. Identifie tous les fichiers dirty dans les scopes POS, print/bridges, Uber, langues et stock.
2. Relie chaque dirty diff à son owner/mission ou marque collision.
3. Vérifie le gate parent dans `GATE_LOG.md`.
4. Ne considère pas le `MASTERPLAY_FROZEN=1` comme levée globale : l'autorisation est bornée aux nouveaux cycles, et chaque frozen/schema/fiscal sub-gate reste obligatoire.
5. Crée/reprends un active cycle seulement après avoir choisi le premier TASK_ID atomique.

## 5. Ordre d'exécution impératif

Suis `reports/planning/PLAN_GLOBAL_OPS_CLAUDE_ORCHESTRATION_2026-08-12.md` :

1. V0 baseline/ownership/hardware inventory.
2. V1 confinements P0.
3. V2 Operator Inbox + polling.
4. V3 attention/alarme/temps.
5. V4 payment ledger/cash/refunds.
6. V5 print jobs/drawer authority.
7. V6 stock saga.
8. V7 health/history/mobile/Uber.
9. V8 E2E/chaos/hardware/canary.

Tu peux paralléliser uniquement des cycles sans fichier, migration, service ou invariant commun. Le paiement, l'impression et le stock ne doivent jamais partager une fenêtre EXECUTE concurrente avec un autre lot touchant leurs services.

## 6. Contrat de chaque sous-cycle

Pour chaque TASK_ID :

### PLAN

- déclaration `SUBSYSTEMS_TOUCHED`, fichiers candidats, frozen, gates ;
- root cause prouvée avec file:line ;
- comportement avant/après ;
- tests fail-first ;
- branch A/B ;
- rollback ;
- `SYMMETRY_NOTE` si OS/FOS ;
- PLAN_REVIEW par Codex xhigh avec PASS requis.

### EXECUTE

- activity-log reservation ;
- safety/preflight ;
- `codex-extension` uniquement pour code produit ;
- diff minimal dans allowlist ;
- aucune correction collatérale ;
- aucune donnée historique mutée sans batch approuvé.

### VALIDATE

- tests ciblés d'abord ;
- tests subsystem ensuite ;
- E2E naturel lorsque prévu ;
- DB assertions et branch isolation ;
- métriques/HAR pour sync/rate ;
- hardware seulement en présence humaine.

### AUDIT/CLOSE

- audit Claude indépendant de l'EXECUTE ;
- GPT final audit ;
- double PASS ;
- mise à jour traceability RQ ;
- mémoire/rapport ;
- aucune clôture sur « partial but plausible ».

## 7. Tests non négociables par domaine

### CB POS manuelle

- zéro terminal/config/403/500/timeout ne bloque pas ;
- confirmation « non » = aucune mutation ;
- confirmation « oui » = une commande CARD, total serveur exact ;
- aucune méthode de bridge/TPE appelée ;
- timeout après commit + replay = même ordre/fiscal/job ;
- reçu/ticket selon politique ; drawer ne s'ouvre pas sur CARD ;
- le texte ne contient jamais « TPE validé », « débit lancé » ou simulation réussie.

### Borne CB

- aucun `approved:true/STUB-*` production ;
- bridge absent/KO = CB indisponible et aucun POST ;
- forged transaction = rejet sans PAID/fiscal/event.

### Inbox/actions

- 201 commandes, aucune top-100 ;
- mêmes IDs/comptes POS/tracker/health ;
- PENDING accept/reject, ACCEPT/PREPARING cancel selon policy, PREPARED jamais cancel générique ;
- paid sous manager refund/return ;
- boissons visibles ;
- 409 concurrent sans double transition ;
- branche A/B et admin global sans sélection.

### Attention

- au moins deux salves avant claim ;
- claim B suspend A dans même scope mais visuel reste ;
- expiry/leader kill reprend ≤ SLO ;
- kitchen claim ne silence pas counter/drinks ;
- action métier résout atomiquement ;
- reload n'efface pas ; audio bloqué visible ; backlog agrégé.

### Rate/KDS/CSP

- vrai contrat WS ;
- une requête branch/feed/cursor ;
- 3 POS + KDS + dashboard 2 min, zéro 429 ;
- reconnect 20–50 clients ;
- Retry-After respecté ;
- 121 CSP derrière NAT n'affament pas business ; zéro malformed normal.

### Print

- false/null/202 jamais papier ;
- two agents fencing ;
- crash avant submit retry ; après submit unknown/no auto retry ;
- KDS+POS jamais dual-consume ;
- branch A/B ; snapshot immuable ; reprint nouvelle génération ;
- web/kiosk/pos/phone/delivery vers station correcte.

### Drawer

- null/malformed/202 = non confirmé ;
- fail-before-write retry ; unknown-after-submit no auto retry ;
- cash sale unique même si drawer KO ;
- no-sale permission/log ;
- backend+local = une seule impulsion au cutover ;
- vrai hardware pins/câble/lock.

### Stock

- physical fail + availability success reste détectable et converge/dead-letter ;
- double cancel/refund/replay ;
- partial refund ;
- prepared food → waste, pas on_hand ;
- stock=1 concurrent ;
- A/B ; minuit ; Uber quarantine.

## 8. Interdictions absolues

- intégrer le TPE alors que le propriétaire demande le mode manuel ;
- désactiver CARD au POS ;
- conserver un terminal mono sélectionnable mais jeté ;
- calculer un prix côté frontend ;
- utiliser chaînes magiques de statut ;
- utiliser `branch_id=0` comme scope hardware/data ;
- dispatch avant commit ;
- marquer printed/opened/paid sur intention ou absence d'exception ;
- retry automatique après état matériel/paiement inconnu ;
- supprimer/annuler en masse les historiques ;
- contourner un dirty collision ;
- faire un GO sans humain/hardware ;
- déclarer mobile couverte sans vraie app ;
- utiliser un test qui injecte directement store/event comme preuve E2E globale.

## 9. Livrables attendus

1. Un plan et dossier mission par sous-cycle.
2. Une trace PLAN_REVIEW/EXECUTE/AUDIT/GPT_FINAL.
3. Tests fail-first et résultats.
4. Rapports E2E/chaos avec correlation IDs/HAR/DB.
5. Matrice RQ mise à jour uniquement sur preuve.
6. Runbooks incidents : order missed, 429, print unknown, drawer unknown, payment ambiguous, stock dead-letter.
7. Hardware grid et preuves signées.
8. Canary report, rollback preuve et monitoring.
9. Gate GO/NO-GO final humain.

## 10. Première action demandée à Claude

Ne code pas immédiatement. Produis d'abord :

1. l'état des collisions/gates ;
2. la liste des sous-cycles réellement exécutables maintenant ;
3. le plan atomique du premier cycle `GLOB-OPS-01-POS-CARD-MANUAL-EXTERNAL` ;
4. un PLAN_REVIEW Codex ;
5. seulement après PASS, l'EXECUTE délégué.

Continue ensuite de façon persistante jusqu'au prochain vrai gate/hardware input, sans réduire l'objectif global.

**MASTER_MISSION_VERDICT: AUTHORIZED FOR ORCHESTRATION, NOT FOR UNGATED MONOLITHIC EDITS**


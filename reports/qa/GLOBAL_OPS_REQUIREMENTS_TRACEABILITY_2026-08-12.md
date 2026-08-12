# Global Operations — Requirements Traceability Matrix

**Date:** 2026-08-12  
**Objective source:** demande utilisateur globale POS/web/mobile/borne/KDS/matériel/stock  
**Audit source:** `reports/audit/AUDIT_GLOBAL_OPERATIONS_CAISSE_KDS_WEB_MOBILE_2026-08-11.md`  
**Plan source:** `reports/planning/PLAN_GLOBAL_OPERATIONS_CAISSE_KDS_WEB_MOBILE_2026-08-11.md`  
**Gate:** `docs/gates/GATE_GLOBAL_OPERATIONS_RELIABILITY_2026-08-11.md`  
**Verdict:** `0/18 REQUIREMENTS PROVEN COMPLETE — HOLD`

## Légende

- `CONTRADICTED` : preuve actuelle contredit explicitement le besoin.
- `PARTIAL` : une capacité existe, mais une partie essentielle manque ou la preuve est insuffisante.
- `MISSING` : contrat/capacité non câblé.
- `UNVERIFIED_HARDWARE` : logiciel/mocks possibles, mais effet physique non prouvé.
- `BLOCKED_GATE` : design préparé, exécution interdite jusqu'au gate.
- `PROVEN` : uniquement si le comportement complet est démontré dans le périmètre demandé.

## Matrice exigence → preuve → mission

| ID | Exigence utilisateur | État actuel | Preuve autoritaire actuelle | Mission/correction | Preuve de clôture obligatoire |
| --- | --- | --- | --- | --- | --- |
| RQ-01 | Encaisser une CB téléphone/client présent sans TPE intégré | `CONTRADICTED` | UI bloque le mono CARD sans terminal alors que backend nullable ; aucun débit TPE réel ; mono ne persiste pas `order_payments` | GLOB-OPS-02 puis 10 ; `TASK_GLOB_OPS_POS_CARD_MANUAL_001` | E2E mono CARD sans liste terminal, confirmation externe honnête, idempotence timeout ; ledger/Z si scope 10 approuvé |
| RQ-02 | Ne jamais marquer une CB borne payée sans paiement réel | `CONTRADICTED P0` | Stub borne produit `approved:true/STUB-*`; contrôleur ne possède pas de preuve acquéreur | GLOB-OPS-01 ; `TASK_GLOB_OPS_KIOSK_CARD_FAIL_CLOSED_001` | Build commercial sans bridge : option désactivée et aucun POST ; stub forgé rejeté sans paiement/fiscal |
| RQ-03 | Liste latérale rapide de toutes les commandes récentes/actionnables | `CONTRADICTED` | POS 77 web, tracker 0, health 484, top/jour différents | GLOB-OPS-05/06/07 ; `TASK_GLOB_OPS_OPERATOR_INBOX_001` | 201 lignes cursorisées, mêmes IDs/comptes multi-surfaces, aucune limite silencieuse, branche A/B |
| RQ-04 | Accéder facilement au détail, boissons comprises | `PARTIAL` | Détail peut rester vide après erreur ; boissons/produits peuvent être masqués ; historique route vers mauvais détail | GLOB-OPS-07 ; contrat Inbox | E2E drawer clavier/souris, groupes cuisine/boissons lisibles, erreur dégradée avec retry et freshness |
| RQ-05 | Annuler/rejeter une commande passée correctement | `CONTRADICTED` | Tracker propose parfois Annuler sur `PREPARED`; détail ne propose pas rejet/annulation ; state machine l'interdit | GLOB-OPS-05/07 | Matrice statut×paiement×rôle ; PENDING rejet, ACCEPT cancel, PREPARED sans cancel, payé sous permission refund/return |
| RQ-06 | Réimprimer ticket client/cuisine simplement | `PARTIAL + UNSAFE` | Plusieurs chemins/autorités ; claim avant papier ; reprint non unifié/audité | GLOB-OPS-07/11 ; `TASK_GLOB_OPS_PRINT_DELIVERY_001` | Reprint crée génération auditable, bonne station/branche, duplicata explicite si état inconnu |
| RQ-07 | Commande pour heure exacte ou dans N minutes | `PARTIAL` | POS a un champ exact, tracker hardcode 20 min, web/mobile n'exposent pas la capacité, multi-jour incomplet | GLOB-OPS-13 | Tests exact/N minutes, demain, minuit, DST Europe/Paris ; scheduled/release/promised identiques multi-surfaces |
| RQ-08 | Aucun « trop de requêtes » au repos | `CONTRADICTED` | +50 hits à l'ouverture de cinq écrans, 37/min environ au repos ; KDS double-poll ; plafond local 1000 masque cible 120 | GLOB-OPS-08/08A ; tasks KDS, poll coordinator, CSP | 3 POS+KDS+dashboard 2 min, zéro 429, HAR/bucket/P95, budget mutation réservé, reconnect storm |
| RQ-09 | Commande web visible immédiatement | `PARTIAL/UNRELIABLE` | outbox solide, mais projection/polls divergents et un backlog web non reconnu existe | GLOB-OPS-06/08/14 | Création naturelle avec correlation ID, Inbox/KDS/OSS dans SLO, gap WS catch-up, un événement bloqué alerte |
| RQ-10 | Sonnerie forte persistante jusqu'à prise en charge | `CONTRADICTED` | bip unique 0,4 s, dédup locale, seed/reload silencieux | GLOB-OPS-09 ; `TASK_GLOB_OPS_ATTENTION_ACK_001` | ≥2 salves avant claim, lease expiry reprend, action canonique résout, audio bloqué visible, backlog agrégé |
| RQ-11 | Notifications empêchant d'ignorer les commandes web | `MISSING/PARTIAL` | FCM POS exclut web dans le chemin audité ; aucun ledger de livraison/claim/résolution | GLOB-OPS-09/14 | notification + badge + titre + paging avec delivery/seen/claim/resolution, multi-device et branche A/B |
| RQ-12 | Ouvrir directement le tiroir réellement connecté | `CONTRADICTED + UNVERIFIED_HARDWARE` | `null` peut devenir succès, fire-and-forget, double pulse serveur/local, aucun capteur d'ouverture | GLOB-OPS-03 ; `TASK_GLOB_OPS_DRAWER_ACK_001` | failed-before-write vs unknown-after-submit, un exécuteur, aucune resoumission paiement, tests pins/câble/lock réels signés |
| RQ-13 | Commande borne imprimée automatiquement et sûrement | `PARTIAL + UNVERIFIED_HARDWARE` | chemins existent, mais 202/claim/localStorage ne prouvent ni papier ni unicité | GLOB-OPS-04/11 | job logical station, lease/fencing, spool result complet, chaos crash/ACK, vrai papier signé |
| RQ-14 | Commande web imprimée automatiquement comme la borne | `CONTRADICTED/UNRELIABLE` | web est éligible dans certains listeners, mais onglet/bridge/autorités concurrentes peuvent perdre ou doubler | GLOB-OPS-04/11 | création web naturelle → job après release/commit → bonne imprimante/station, aucune perte, état inconnu opérable |
| RQ-15 | Dashboard de contrôle/productivité complet | `PARTIAL` | ventes/count/panier moyen présents ; SLA/health utilisent populations/horloges incompatibles ; pas de paging humain | GLOB-OPS-14 | p50/p90/p95 des transitions, freshness, 429, print/stock dead letters, scheduler/outbox, erreur probe UNKNOWN/RED |
| RQ-16 | Stock fiable sur annulation/remboursement et toutes surfaces | `CONTRADICTED P1` | disponibilité peut avancer `released_qty` après échec stock ; reconciler ne voit plus l'écart | GLOB-OPS-12 ; `TASK_GLOB_OPS_STOCK_SAGA_001` | échec physique+succès disponibilité converge/dead-letter ; réserve/consume/release/waste ; double cancel/refund ; A/B |
| RQ-17 | Site web et véritable application mobile cohérents | `MISSING MOBILE` | app/mobile cliente explicitement non raccordée à l'outbox ; `/m` est mini-web stock et choisit une branche silencieusement | GLOB-OPS-15 | dépôt/app réel audité, auth/tokens/branche, création/status/push/polling E2E ; `/m` branch-bound |
| RQ-18 | Audit réel web + agents adversaires + meilleure décision | `PARTIAL ACHIEVED, SYSTEM NOT FIXED` | exploration navigateur/Redis/CSP, 3 audits indépendants + second contre-débat, rapport révisé ; aucune correction produit autorisée | GLOB-OPS-00/17/18 | cycles implémentés, tests naturels sans store/event synthétique, double audit PASS, hardware signé, décision GO humaine |

## Preuves déjà collectées — utiles mais non suffisantes

| Preuve | Ce qu'elle démontre | Ce qu'elle ne démontre pas |
| --- | --- | --- |
| 77 web / tracker 0 / health 484 / SLA 331 | projections incohérentes et backlog historique | correction ou population production actuelle |
| 37 API hits environ sur 58 s au repos | charge de fond mesurée avec six écrans connectés | matrice complète production 120/min |
| 37 CSP POST par reload | amplification réelle, parsing malformed | toutes les directives responsables ni correction |
| Tests PHP/Vitest ciblés verts | certains contrats unitaires/features actuels | matériel, exactement-once papier, vraie propagation multi-surface |
| KDS cadence 3/3 vert | le mock fictif satisfait son propre contrat | compatibilité avec `WebSocketService` réel |
| Hardware grid vide | absence de preuve terrain | panne ou succès d'un modèle concret |
| Second débat adversarial | choix révisés et promesses fausses retirées | implémentation, migration, UAT ou GO |

## Séquence de preuve exigée

1. Gate humain et levée bornée du freeze.
2. Confinements P0 avec tests fail-first.
3. Double audit de chaque cycle.
4. Migrations/architectures durables sous gates séparés.
5. E2E naturel multi-surface avec IDs corrélés, HAR et état DB.
6. Chaos sync/print/stock/rate limit.
7. Laboratoire matériel signé.
8. Vérification finale exigence par exigence contre cette matrice.

## Conclusion

Aucune ligne ne peut encore être marquée `PROVEN`. Les artefacts de planification et l'audit sont prêts, mais la finalité demandée exige encore l'implémentation autorisée et la validation terrain.

**TRACEABILITY_VERDICT: COMPLETE AS AUDIT MAP — PRODUCT OBJECTIVE INCOMPLETE**


# Formulaire de décision humaine — Fiabilité globale FoodKing

**Gate principal :** `HG-GLOBAL-OPS-RELIABILITY-2026-08-11`  
**Statut de ce formulaire :** `TRANSCRIT — D1–D7 OPTION A APPROUVÉES AVEC CONTRAINTE CB POS MANUELLE EXTERNE`  
**Rapport :** `reports/audit/AUDIT_GLOBAL_OPERATIONS_CAISSE_KDS_WEB_MOBILE_2026-08-11.md`  
**Contre-débat :** `reports/audit/ADVERSARIAL_DECISION_RECORD_GLOBAL_OPS_2026-08-12.md`

**Version propriétaire complète Q1–Q29 :** `docs/gates/GATE_GLOBAL_OPS_OWNER_DECISION_PACKET_FR_2026-08-12.md`

> Ce document simplifie la décision en français. Il ne remplace pas le gate principal et ne doit jamais être coché par un agent.

## D1 — Confinements immédiats sans migration

### Option A — recommandée

Autoriser des cycles bornés pour :

- borne CB fail-closed sans bridge/preuve ;
- CB POS mono manuelle externe, sans sélecteur terminal jeté et sans prétendre débiter ;
- suppression des faux succès tiroir/impression `null/false/202` ;
- correction du vrai contrat WebSocket KDS ;
- parsing/isolation CSP et réduction du polling sans hausse globale de limite.

Ne constitue ni intégration TPE, ni preuve papier/tiroir, ni GO commercial.

### Option B

Audit/design uniquement, aucun code produit.

### Option C

Différer tout le lot.

**Choix humain D1 :** `APPROVED — OPTION A — CB POS MANUELLE EXTERNE, JAMAIS INTÉGRÉE DANS CE SCOPE`

## D2 — Attention et sonnerie multi-postes

### Option A — recommandée

`DELIVERED → SEEN → CLAIMED(lease) → RESOLVED`, scoped par branche + type + responsabilité/station.

- delivery/seen ne silencient pas ;
- claim explicite suspend temporairement l'audio ;
- expiry/leader perdu reprend la sonnerie ;
- visuel reste partout ;
- seule l'action métier canonique résout.

### Option B

ACK permanent au clic, sans lease — risque d'oubli silencieux.

### Option C

Conserver bip one-shot/statut actuel.

**Choix humain D2 :** `APPROVED — OPTION A`

## D3 — Autorité d'impression

### Option A — recommandée

Une file serveur ; identité logique branche/ordre/révision/document/station/génération ; une lease active par imprimante avec agent principal/standby ; résultat `SPOOL_ACCEPTED`, `FAILED_BEFORE_SPOOL` ou `UNKNOWN_AFTER_SUBMIT`; aucun faux `DELIVERED` papier.

### Option B

Garder navigateur comme autorité et ajouter seulement une lease.

### Option C

KDS local seul.

**Choix humain D3 :** `APPROVED — OPTION A`

## D4 — Stock et disponibilité

### Option A — recommandée

Une saga/intention commune, preuves séparées et idempotentes pour stock physique et disponibilité ; réservation/consommation/release/waste selon lifecycle ; reconciler attendu/réel.

### Option B

Conserver `released_qty` partagé et ajouter un drapeau d'erreur.

### Option C

Accepter la divergence actuelle.

**Choix humain D4 :** `APPROVED — OPTION A`

## D5 — Ledger de paiements

### Option A — recommandée après confinement et revue fiscale

Rouvrir le ledger complet : mono/split/comptoir/remboursement produisent des écritures immuables ; terminal optionnel seulement lorsqu'il est réellement persisté/validé ; rapprochement Z/fees cohérent.

### Option B

Conserver le pilote restreint et accepter le mono sans attribution/Z TPE exhaustif.

### Option C

Ne changer aucun paiement.

**Choix humain D5 :** `APPROVED — OPTION A — CARD TOUJOURS IDENTIFIÉE; TERMINAL ID UNIQUEMENT SI PERSISTÉ`

## D6 — Commandes historiques

### Option A — recommandée

Classer avant toute mutation : actionnable, planifiée, candidate janitor, orpheline historique, payée/fiscalisée protégée. Produire un repair set humainement relu.

### Option B

Annuler automatiquement toutes les anciennes non terminales — destructif, non recommandé.

### Option C

Ignorer l'historique et conserver health pollué.

**Choix humain D6 :** `APPROVED — OPTION A`

## D7 — CSP et rate limiting

### Option A — recommandée

Parser les formats CSP natifs, sanitisés/dédupliqués, avec bucket réellement distinct du business/NAT ; budgets critique/opérationnel/analytique séparés ; aucune hausse globale.

### Option B

Conserver les throttles imbriqués, plafond effectif le plus bas.

### Option C

Désactiver CSP — perte de signal sécurité.

**Choix humain D7 :** `APPROVED — OPTION A`

## Autorisation de cycle

Même avec D1–D7 approuvées, préciser si le correction freeze peut être levé uniquement pour les cycles bornés, allowlists et gates correspondants.

**Levée bornée du freeze :** `AUTHORIZED FOR CLAUDE-ORCHESTRATED CHILD CYCLES; GLOBAL MASTERPLAY FREEZE NOT BLANKET-LIFTED`  
**GO matériel/commercial :** reste `NON` jusqu'à grille physique signée et double audit PASS.

## Réponse simple recommandée

L'approbateur humain peut répondre exactement :

> J'approuve D1 à D7 Option A du gate HG-GLOBAL-OPS-RELIABILITY-2026-08-11. J'autorise la levée du correction freeze uniquement pour ouvrir les cycles bornés correspondants. Cette décision ne constitue pas un GO matériel ou commercial.

Ou lister les décisions différentes et leurs contraintes.

## Transcription après décision

Après réponse humaine :

1. transcrire chaque choix et contrainte dans `docs/gates/GATE_GLOBAL_OPERATIONS_RELIABILITY_2026-08-11.md` ;
2. ajouter l'entrée correspondante à `docs/gates/GATE_LOG.md` ;
3. ne passer à `PLAN/EXECUTE` que pour le scope explicitement approuvé ;
4. conserver migrations/fiscal/hardware sous leurs gates spécifiques ;
5. ne jamais marquer le hardware PASS sans exécution/signature humaine.

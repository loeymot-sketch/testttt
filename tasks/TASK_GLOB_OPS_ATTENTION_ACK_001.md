# TASK_GLOB_OPS_ATTENTION_ACK_001 — Alarme persistante, claim leased et résolution opérateur

## Meta

- **Priority:** P0 commande ignorée
- **EXECUTION_TIER:** `complex`
- **PRIMARY_EXECUTION_MODEL:** `gpt-5.5-pro`
- **REASONING_EFFORT:** `xhigh`
- **TEST_STRATEGY:** `local-validation` + `playwright-critical-flow` + `chaos`
- **SOURCE:** audit global F-06 et débat adversarial UX/sync
- **STATUS:** `PENDING_ORDER_ATTENTION_SCHEMA_GATE`

## Problème prouvé

Le POS et le tracker jouent un bip unique d'environ 0,4 seconde, puis dédupliquent l'ID localement. Le seed initial est silencieux : ouvrir ou recharger la caisse peut rendre des dizaines de commandes web invisibles acoustiquement. Le statut métier ne peut pas servir d'ACK, car une commande web payée/auto-promue peut nécessiter une prise en charge sans action `ACCEPT`.

## Décision recommandée

Créer un état d'attention distinct et durable, branch/responsibility-scoped :

`DELIVERED → SEEN → CLAIMED(lease) → RESOLVED`

- `DELIVERED` : la notification a atteint un appareil ; ce n'est jamais une action humaine.
- `SEEN` : vue par un utilisateur/appareil ; l'alarme reste active.
- `CLAIMED` : prise en charge temporaire par un opérateur avec lease visible ; la salve audio du scope peut être suspendue, mais l'alerte visuelle reste sur tous les postes. Si le lease expire ou le leader disparaît, la salve reprend dans le SLO.
- `RESOLVED` : action métier canonique valide (accepter, rejeter, annuler, démarrer la responsabilité prévue, etc.) ou état terminal rend l'attention sans objet.

Un claim valide d'un **scope de responsabilité** suspend temporairement la salve correspondante sur les postes autorisés de ce scope ; il ne résout rien à vie. La clé minimale est `branch_id + attention_kind + station/responsibility`; un claim cuisine ne doit jamais silencer une attention caisse ou boissons. Les autres branches/scopes sont indépendants. Seule l'action métier canonique résout durablement l'attention.

## Modèle minimal proposé

Ledger/record durable contenant :

- order type/id, `branch_id`, `attention_kind`, station/responsibility et attention generation/version ;
- delivery/seen/claim/resolution state, `first_alert_at`, `first_seen_at`, `claim_lease_until`, `resolved_at` ;
- `seen_by_user/device`, `claimed_by_user_id`, `claimed_by_device_id`, `claimed_at`, `resolved_by_action/transition` ;
- cause/source (`new_web`, `counter_payment`, `print_failure`, etc.) sous enum/version ;
- clé idempotente et audit des transitions.

Une nouvelle génération d'attention ne réutilise pas une résolution ancienne, par exemple après réouverture explicite ou incident matériel distinct.

## UX alarme

1. Salve sonore courte toutes les 8–12 secondes avec jitter borné, pas un son continu agressif.
2. Badge rouge, carte épinglée en tête de l'Inbox, titre d'onglet et notification système lorsque permise. L'Inbox affiche qui a réclamé la responsabilité et depuis quand.
3. Si autoplay/audio est bloqué, bannière persistante « Activer le son » et indicateur visuel renforcé.
4. Bouton explicite « Je prends en charge » crée/renouvelle un claim borné ; Accepter/Rejeter/Encaisser/Démarrer préparation résout atomiquement l'attention correspondante si la règle métier le prévoit. Une action terminale résout les attentions devenues sans objet.
5. Une commande planifiée reste silencieuse avant `release_at` ; elle apparaît dans Upcoming.
6. L'alarme audio se suspend immédiatement après event de claim et se résout après action canonique, avec fallback poll/delta ; reconnect/reload récupère l'état serveur et le lease restant.
7. Limite anti-fatigue : les commandes sont agrégées par responsabilité en une salve et l'UI annonce le nombre, sans superposer N sons. Au démarrage avec backlog, un écran conscient « N non acquittées » remplace toute rafale historique.
8. Une échéance/escalade crée une nouvelle génération d'attention ; elle ne réutilise pas la résolution antérieure.

## SUBSYSTEMS_OFF_LIMITS

- Aucun claim/résolution dérivé uniquement de `localStorage`, page visibility, réception WebSocket, fetch, impression ou simple rendu.
- Aucun changement `OrderStatus` pour représenter attention.
- Aucun silence définitif parce qu'un onglet a reçu une notification.
- Aucune sonnerie avant release d'une commande planifiée.
- Aucun claim/résolution cross-branch ou via admin global sans branche/device explicites.
- Aucun claim d'une station/responsabilité utilisé pour silencer une autre.

## INVARIANTS_AT_RISK

- Schema/frozen order lifecycle.
- `branch_id` strict.
- Dispatch d'attention après commit de la commande.
- Symétrie OrderService/FrontendOrderService.
- WebSocket + fallback sans double cadence/429.
- Idempotence multi-postes.

## Tests falsifiables

1. Commande web créée naturellement : visible ≤ objectif SLO, au moins deux salves avant claim/résolution.
2. Reload après première salve : alarme reprend parce que le serveur n'est ni claimé avec lease valide ni résolu.
3. Poste B claim dans le même scope : A et B suspendent la salve, conservent l'alerte visuelle et affichent le responsable ; expiry/retrait du leader fait reprendre l'audio.
4. Poste branche B ou token forgé : 403/404, alarme branche A inchangée.
5. Accepter/Rejeter/Encaisser atomique : statut/paiement et résolution réussissent ensemble ou pas du tout lorsque le couplage est déclaré.
6. Action métier échoue : aucune résolution ; le claim temporaire suit son lease.
7. Audio bloqué : bannière et visuel restent actifs ; activation déclenche la cadence, jamais une tempête de sons en retard.
8. Dix commandes non résolues/non claimées : une salve agrégée, dix cartes/badge correct, pas dix sons superposés.
9. Commande prévue demain : Upcoming, aucune salve ; passage de `release_at` atomique déclenche attention une fois.
10. WebSocket coupé/reconnecté, worker retardé et 429 : fallback coordonné conserve l'alarme sans dépasser le budget requêtes.
11. Deux claims concurrents : un lease effectif avec CAS/fencing, réponse conflit maîtrisée.
12. Une nouvelle génération d'incident matériel après résolution précédente redevient visible sans réutiliser l'ancien état.
13. Claim cuisine : l'attention caisse/boissons reste active ; seule l'action canonique du bon scope la résout.
14. Backlog de 77 : une seule alerte agrégée et écran de reprise, jamais 77 salves.

## Acceptance Criteria

- [ ] Une commande actionnable reste audible/visible jusqu'à claim valide, puis reprend si le lease expire et ne disparaît qu'à résolution canonique.
- [ ] Reload, autre onglet ou autre poste ne la silencie pas.
- [ ] Un seul claim du scope suspend temporairement l'audio concerné sans silencer visuellement les autres postes/stations.
- [ ] Statut métier, paiement et attention restent trois machines distinctes.
- [ ] Les commandes planifiées ne sonnent pas avant release.
- [ ] Les erreurs audio/réseau ont une dégradation visible et testée.

## Gate

Requiert `HG-GLOBAL-OPS-RELIABILITY-2026-08-11` Décision 2, migration/schema `ORDER-ATTENTION` explicite, cycle officiel et audit parité. Aucun code ne doit être généré avant transcription de la décision humaine dans `docs/gates/GATE_LOG.md`.

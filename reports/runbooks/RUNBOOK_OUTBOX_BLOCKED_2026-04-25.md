# RUNBOOK — Outbox bloquée / DomainEvent stale (2026-04-25)

- Status: DRAFT_SKELETON_NOT_SIGNED
- Owner (DRAFT): BE+DevOps
- Severity ceiling: P0
- Plan source: PLAN-20 (super master) / M-20 (masterplay)
- Linked gates: (none)
- Last reviewed: 2026-04-25 (initial skeleton)

## 1. Trigger
- DomainEvent pending stale au-delà de 2 minutes.
- DomainEvent failed avec attempts >= 5.
- `foodking:outbox:rescue` requeue des stale mais backlog revient.
- `foodking:outbox:retry-failed` reset/requeue sans progression durable.
- Dashboard sync expose recent_failures et latence outbox.
- KDS/POS ne reçoivent plus broadcasts malgré DB commit.
- Trigger evidence 1: signal à corréler avec `app/Models/DomainEvent.php:33-48`.
- Trigger evidence 2: signal à corréler avec `app/Console/Commands/OutboxRescueCommand.php:11-29`.
- Trigger evidence 3: signal à corréler avec `app/Console/Commands/OutboxRetryFailedCommand.php:13-39`.
- Trigger evidence 4: signal à corréler avec `app/Listeners/PersistOrderCreatedToOutbox.php:19-41`.
- Trigger evidence 5: signal à corréler avec `app/Listeners/PersistOrderStatusChangedToOutbox.php:19-40`.
- Trigger evidence 6: signal à corréler avec `app/Listeners/PersistOrderTableChangedToOutbox.php:43-68`.
- Trigger evidence 7: signal à corréler avec `app/Listeners/PersistItemAvailabilityChangedToOutbox.php:48-63`.
- Trigger evidence 8: signal à corréler avec `app/Domain/Events/EventContract.php:63-130`.
- Détection attendue: alerte automatique, dashboard ops, journal structuré, ou ticket L1 horodaté UTC.
- Toute alerte cross-branch doit être découpée par `branch_id` avant action.
- Aucun seuil de gate n’est approuvé dans ce runbook; les gates restent `PENDING_HUMAN_GATE` quand cités.

## 2. Symptômes utilisateur / ops
- Commandes existent mais écrans abonnés ne se mettent pas à jour.
- Ops voit événements pending sans `dispatched_at`.
- Logs montrent payload mismatch ou runtime failure.
- Branches affectées visibles dans `domain_events.branch_id`.
- Relance brute DB tentante mais interdite sans approbation humaine.
- Côté ops: pic d’alertes, latence, erreurs récurrentes ou absence de progression dans le dashboard concerné.
- Côté audit: absence de preuve `branch_id` ou absence d’horodatage UTC rend la sortie non recevable.
- Côté produit: ne jamais compenser par une règle prix frontend ou un changement de statut hors `OrderStatus enum`.
- Côté coordination: si un autre agent travaille le même incident, un seul owner L2 tient le journal incident.

## 3. Diagnostic step-by-step
1. Qualifier stale pending
   - Commande / observation: Observation dashboard ou comptage read-only L2; aucune commande SQL dans ce runbook.
   - Fichier:line à inspecter: `app/Models/DomainEvent.php:33-48`.
   - Décision de bifurcation: stale < attempts 5 = rescue; attempts >=5 = retry failed.
   - Preuve à conserver: capture horodatée UTC + branch_id + extrait de journal sans PII.
   - Invariant à vérifier: pas d’écriture brute DB..
2. Relancer stale autorisé
   - Commande / observation: `php artisan foodking:outbox:rescue`
   - Fichier:line à inspecter: `app/Console/Commands/OutboxRescueCommand.php:11-29`.
   - Décision de bifurcation: si count baisse, surveiller; si revient, diagnostiquer job.
   - Preuve à conserver: capture horodatée UTC + branch_id + extrait de journal sans PII.
   - Invariant à vérifier: dispatch via job existant..
3. Relancer failed récent
   - Commande / observation: `php artisan foodking:outbox:retry-failed --since=1h`
   - Fichier:line à inspecter: `app/Console/Commands/OutboxRetryFailedCommand.php:13-39`.
   - Décision de bifurcation: si contract violation revient, stopper retries et escalader BE.
   - Preuve à conserver: capture horodatée UTC + branch_id + extrait de journal sans PII.
   - Invariant à vérifier: pas de mutation payload..
4. Contrôler after-commit producer order created
   - Commande / observation: Observation logs; aucune commande dédiée.
   - Fichier:line à inspecter: `app/Listeners/PersistOrderCreatedToOutbox.php:19-41`.
   - Décision de bifurcation: afterCommit présent = producer conforme; chercher dispatch job.
   - Preuve à conserver: capture horodatée UTC + branch_id + extrait de journal sans PII.
   - Invariant à vérifier: dispatch after commit..
5. Contrôler after-commit status changed
   - Commande / observation: Observation logs; aucune commande dédiée.
   - Fichier:line à inspecter: `app/Listeners/PersistOrderStatusChangedToOutbox.php:19-40`.
   - Décision de bifurcation: status event persiste branch_id; vérifier payload contract.
   - Preuve à conserver: capture horodatée UTC + branch_id + extrait de journal sans PII.
   - Invariant à vérifier: OrderStatus enum source..
6. Contrôler table changed
   - Commande / observation: Observation logs; aucune commande dédiée.
   - Fichier:line à inspecter: `app/Listeners/PersistOrderTableChangedToOutbox.php:43-68`.
   - Décision de bifurcation: table event bloqué = KDS table sync incident.
   - Preuve à conserver: capture horodatée UTC + branch_id + extrait de journal sans PII.
   - Invariant à vérifier: branch_id channel..
7. Contrôler item availability fanout
   - Commande / observation: Observation logs; aucune commande dédiée.
   - Fichier:line à inspecter: `app/Listeners/PersistItemAvailabilityChangedToOutbox.php:48-63`.
   - Décision de bifurcation: global fanout peut charger toutes branches; vérifier saturation.
   - Preuve à conserver: capture horodatée UTC + branch_id + extrait de journal sans PII.
   - Invariant à vérifier: branch_id payload clair..
8. Vérifier envelope contract
   - Commande / observation: Observation logs; aucune commande dédiée.
   - Fichier:line à inspecter: `app/Domain/Events/EventContract.php:63-130`.
   - Décision de bifurcation: contract invalid = correction code future, pas ops brute.
   - Preuve à conserver: capture horodatée UTC + branch_id + extrait de journal sans PII.
   - Invariant à vérifier: contrat event V1..
9. Vérifier dispatch after commit trait
   - Commande / observation: Observation code path; aucune commande dédiée.
   - Fichier:line à inspecter: `app/Events/Concerns/DispatchableAfterCommit.php:8-42`.
   - Décision de bifurcation: si event bypass détecté, escalade invariant.
   - Preuve à conserver: capture horodatée UTC + branch_id + extrait de journal sans PII.
   - Invariant à vérifier: commit_before_dispatch..
10. Lire dashboard failures
   - Commande / observation: Observation dashboard admin; aucune commande dédiée.
   - Fichier:line à inspecter: `app/Http/Controllers/Admin/Observability/SyncOverviewController.php:113-157`.
   - Décision de bifurcation: recent_failures oriente event_type et branch_id.
   - Preuve à conserver: capture horodatée UTC + branch_id + extrait de journal sans PII.
   - Invariant à vérifier: branch_id strict..
- Diagnostic stop: si une étape exige une écriture hors `reports/runbooks/`, arrêter et escalader hors M-20.
- Diagnostic stop: si une étape demande une décision fiscale, ouvrir escalade humaine, pas de correction autonome.
- Diagnostic stop: si une étape révèle un gate non signé, documenter les options, ne pas choisir l’option.

## 4. Actions correctives par criticité
### 4.1 P0 — production caissière bloquée
- Délai cible: ≤ 5 min de réponse.
- Action P0-1
  - Précondition: Outbox bloquée avec KDS/POS aveugles.
  - Action: Utiliser uniquement les commandes rescue/retry existantes et escalader L2; aucune écriture brute DB.
  - Vérification post-action: recent_failures baisse et events reçoivent `dispatched_at`.
  - Impact attendu: Temps réel restauré sans casser commit-before-dispatch.
- Action P0-2
  - Précondition: Outbox bloquée / DomainEvent stale bloque encaissement ou préparation sur une branche.
  - Action: Basculer en procédure dégradée opérationnelle autorisée, sans modifier code ni données.
  - Vérification post-action: Comparer incident et ancrage app/Models/DomainEvent.php:33-48.
  - Impact attendu: Flux caisse stabilisé ou escalade L2 active.
- Action P0-3
  - Précondition: Signal P0 confirmé par deux sources ops.
  - Action: Geler les actions irréversibles côté équipe terrain.
  - Vérification post-action: Conserver captures dashboard et journal lié à app/Console/Commands/OutboxRescueCommand.php:11-29.
  - Impact attendu: Preuve intacte pour audit et post-mortem.
- Action P0-4
  - Précondition: Risque de double traitement ou perte de commande.
  - Action: Suspendre toute relance manuelle non documentée.
  - Vérification post-action: Vérifier que les actions restent dans les invariants listés en §8.
  - Impact attendu: Pas de duplication silencieuse.
- Action P0-5
  - Précondition: Une branche unique est touchée.
  - Action: Limiter le traitement et les communications à ce `branch_id`.
  - Vérification post-action: Vérifier la présence de `branch_id` dans app/Models/DomainEvent.php:33-48.
  - Impact attendu: Isolation multi-branches préservée.
- Action P0-6
  - Précondition: Le signal persiste après 5 minutes.
  - Action: Escalader L2 puis L3 selon matrice.
  - Vérification post-action: Joindre les références app/Models/DomainEvent.php:33-48 et app/Console/Commands/OutboxRescueCommand.php:11-29.
  - Impact attendu: Décision humaine disponible si risque business.
- Sortie P0: ticket incident mis à jour avec heure UTC, `branch_id`, owner, et prochaine décision.
### 4.2 P1 — dégradé mais opérable
- Délai cible: ≤ 30 min.
- Action P1-1
  - Précondition: Outbox bloquée / DomainEvent stale dégrade le service sans arrêt total.
  - Action: Maintenir le service avec surveillance renforcée.
  - Vérification post-action: Comparer les symptômes au fichier app/Models/DomainEvent.php:33-48.
  - Impact attendu: Pas de passage P0 sans preuve supplémentaire.
- Action P1-2
  - Précondition: Une action temporaire non destructive existe.
  - Action: Appliquer uniquement la mesure documentée dans ce runbook.
  - Vérification post-action: Noter l’heure UTC et le `branch_id` dans le ticket.
  - Impact attendu: Réversibilité conservée.
- Action P1-3
  - Précondition: Le dashboard montre une amélioration.
  - Action: Continuer l’observation sur deux fenêtres de mesure.
  - Vérification post-action: Conserver la capture de la métrique ancrée en §8.
  - Impact attendu: Incident maîtrisé sans correction code.
- Action P1-4
  - Précondition: La cause reste incertaine.
  - Action: Préparer analyse L2, sans élargir aux modules voisins.
  - Vérification post-action: Lister uniquement les références du §8.
  - Impact attendu: Scope M-20 respecté.
- Action P1-5
  - Précondition: Le contournement dure plus de 30 minutes.
  - Action: Reclasser P0 si la caisse devient bloquée.
  - Vérification post-action: Comparer ticket et matrice d’escalade §5.
  - Impact attendu: Priorité alignée sur l’impact réel.
- Sortie P1: ticket incident mis à jour avec heure UTC, `branch_id`, owner, et prochaine décision.
### 4.3 P2 — anomalie collectée pour post-mortem
- Délai cible: ≤ 24 h.
- Action P2-1
  - Précondition: Signal isolé, sans impact utilisateur immédiat.
  - Action: Collecter preuve et laisser le service nominal.
  - Vérification post-action: Ancrer le ticket sur app/Models/DomainEvent.php:33-48.
  - Impact attendu: Dette documentée sans intervention risquée.
- Action P2-2
  - Précondition: Anomalie récupérée automatiquement.
  - Action: Marquer comme observation pour post-mortem.
  - Vérification post-action: Renseigner délai de détection dans §7.
  - Impact attendu: Amélioration de monitoring planifiée.
- Action P2-3
  - Précondition: Aucune récurrence sur 24 h.
  - Action: Clore opérationnellement après revue L1.
  - Vérification post-action: Conserver le lien vers ce runbook.
  - Impact attendu: Trace suffisante pour audit ultérieur.
- Action P2-4
  - Précondition: Récurrence faible mais visible.
  - Action: Créer action corrective P2 avec propriétaire.
  - Vérification post-action: Lier aux plans/missions du §8.
  - Impact attendu: Traitement hors urgence.
- Action P2-5
  - Précondition: Doute sur un gate ou une frozen zone.
  - Action: Ne pas intervenir; router en question humaine.
  - Vérification post-action: Référence gate en §8, aucune décision GO/NO-GO.
  - Impact attendu: Pas de self-approval.
- Sortie P2: ticket incident mis à jour avec heure UTC, `branch_id`, owner, et prochaine décision.

## 5. Escalation matrix
| Niveau | Trigger | Rôle | Canal | Délai max | Action irréversible? |
| --- | --- | --- | --- | --- | --- |
| L1 | Alerte initiale ou plainte terrain | Ops | Pager / canal incident | 5 min | Non |
| L2 | Diagnostic confirmé ou P1 > 30 min | BE/DevOps oncall | Canal incident + ticket | 15 min | Non sans accord L3 |
| L3 | P0 durable, gate, frozen zone, ou impact multi-branches | CTO / humain responsable | Appel direct + canal incident | 30 min | Oui, décision humaine requise |
| Retour ops | Sortie technique confirmée | Ops + owner incident | Ticket + rapport post-mortem | 24 h | Non |

## 6. Vérifications de sortie
- [ ] Incident horodaté UTC avec début, détection, prise en charge, mitigation et sortie.
- [ ] `branch_id` concerné documenté; si global, justification explicite par rôle Admin/global.
- [ ] Aucune écriture brute DB effectuée depuis ce runbook.
- [ ] Aucun gate marqué comme approuvé ou signé par ce runbook.
- [ ] Aucune modification de code produit pendant l’intervention ops.
- [ ] Aucune logique prix frontend introduite ou recommandée.
- [ ] `OrderStatus enum` respecté; aucun statut littéral nouveau demandé.
- [ ] `dispatch after commit` respecté; aucune relance ne contourne l’outbox.
- [ ] Captures dashboards jointes au ticket incident.
- [ ] Extraits logs limités au nécessaire, sans PII ni secrets.
- [ ] First responder et owner L2 identifiés.
- [ ] Décision P0/P1/P2 justifiée par symptômes observés.
- [ ] Actions correctives appliquées dans l’ordre du §4.
- [ ] Escalade L3/L4 déclenchée si seuil atteint.
- [ ] Monitoring revenu au niveau nominal ou contournement documenté.
- [ ] Risque fiscal explicitement qualifié si applicable.
- [ ] Lien vers plan/missions M-20 et plans transverses présent.
- [ ] Dette de suivi convertie en action P2 avec owner et deadline.
- [ ] Post-mortem créé ou planifié selon §7.
- [ ] Ticket incident fermé seulement après confirmation L1 + L2.
- [ ] Preuve 1 reliée à `app/Models/DomainEvent.php:33-48` dans le ticket ou dashboard.
- [ ] Preuve 2 reliée à `app/Console/Commands/OutboxRescueCommand.php:11-29` dans le ticket ou dashboard.
- [ ] Preuve 3 reliée à `app/Console/Commands/OutboxRetryFailedCommand.php:13-39` dans le ticket ou dashboard.
- [ ] Preuve 4 reliée à `app/Listeners/PersistOrderCreatedToOutbox.php:19-41` dans le ticket ou dashboard.
- [ ] Preuve 5 reliée à `app/Listeners/PersistOrderStatusChangedToOutbox.php:19-40` dans le ticket ou dashboard.
- [ ] Preuve 6 reliée à `app/Listeners/PersistOrderTableChangedToOutbox.php:43-68` dans le ticket ou dashboard.
- [ ] Preuve 7 reliée à `app/Listeners/PersistItemAvailabilityChangedToOutbox.php:48-63` dans le ticket ou dashboard.
- [ ] Preuve 8 reliée à `app/Domain/Events/EventContract.php:63-130` dans le ticket ou dashboard.
- [ ] Preuve 9 reliée à `app/Events/Concerns/DispatchableAfterCommit.php:8-42` dans le ticket ou dashboard.
- [ ] Preuve 10 reliée à `app/Jobs/DispatchDomainEventsJob.php:62-89` dans le ticket ou dashboard.

## 7. Template post-mortem
- Timeline UTC
  - Champ obligatoire: heure UTC ou valeur mesurée, jamais une date relative.
  - Champ obligatoire: branches touchées, avec `branch_id` explicite ou justification globale.
  - Champ obligatoire: source de détection, automatique ou manuelle, avec délai en minutes.
  - Champ obligatoire: action prise, owner, canal, et résultat mesuré.
  - Champ obligatoire: preuve attachée, lien ticket, dashboard, journal ou capture.
- Impact (commandes, revenue, fiscal, branches)
  - Champ obligatoire: heure UTC ou valeur mesurée, jamais une date relative.
  - Champ obligatoire: branches touchées, avec `branch_id` explicite ou justification globale.
  - Champ obligatoire: source de détection, automatique ou manuelle, avec délai en minutes.
  - Champ obligatoire: action prise, owner, canal, et résultat mesuré.
  - Champ obligatoire: preuve attachée, lien ticket, dashboard, journal ou capture.
- Cause racine
  - Champ obligatoire: heure UTC ou valeur mesurée, jamais une date relative.
  - Champ obligatoire: branches touchées, avec `branch_id` explicite ou justification globale.
  - Champ obligatoire: source de détection, automatique ou manuelle, avec délai en minutes.
  - Champ obligatoire: action prise, owner, canal, et résultat mesuré.
  - Champ obligatoire: preuve attachée, lien ticket, dashboard, journal ou capture.
- Détection (auto/manuelle/délai)
  - Champ obligatoire: heure UTC ou valeur mesurée, jamais une date relative.
  - Champ obligatoire: branches touchées, avec `branch_id` explicite ou justification globale.
  - Champ obligatoire: source de détection, automatique ou manuelle, avec délai en minutes.
  - Champ obligatoire: action prise, owner, canal, et résultat mesuré.
  - Champ obligatoire: preuve attachée, lien ticket, dashboard, journal ou capture.
- Réponse (ce qui a marché / pas marché)
  - Champ obligatoire: heure UTC ou valeur mesurée, jamais une date relative.
  - Champ obligatoire: branches touchées, avec `branch_id` explicite ou justification globale.
  - Champ obligatoire: source de détection, automatique ou manuelle, avec délai en minutes.
  - Champ obligatoire: action prise, owner, canal, et résultat mesuré.
  - Champ obligatoire: preuve attachée, lien ticket, dashboard, journal ou capture.
- Actions correctives (P0/P1/P2 + propriétaire + deadline)
  - Champ obligatoire: heure UTC ou valeur mesurée, jamais une date relative.
  - Champ obligatoire: branches touchées, avec `branch_id` explicite ou justification globale.
  - Champ obligatoire: source de détection, automatique ou manuelle, avec délai en minutes.
  - Champ obligatoire: action prise, owner, canal, et résultat mesuré.
  - Champ obligatoire: preuve attachée, lien ticket, dashboard, journal ou capture.
- Liens incidents passés
  - Champ obligatoire: heure UTC ou valeur mesurée, jamais une date relative.
  - Champ obligatoire: branches touchées, avec `branch_id` explicite ou justification globale.
  - Champ obligatoire: source de détection, automatique ou manuelle, avec délai en minutes.
  - Champ obligatoire: action prise, owner, canal, et résultat mesuré.
  - Champ obligatoire: preuve attachée, lien ticket, dashboard, journal ou capture.
- Synthèse invariants
  - Backend pricing is SSOT: ne pas recalculer un montant côté frontend pendant la réponse incident.
  - OrderStatus enum authoritative: ne pas demander une correction par littéral numérique hors flux existant.
  - branch_id isolation: toute preuve, capture, dashboard ou ticket doit porter la branche concernée.
  - dispatch after commit: ne pas recommander un dispatch manuel qui contourne les garanties after-commit.
  - OrderService / FrontendOrderService symmetry: toute divergence constatée devient point M-10, pas patch ops.
  - frozen zones: aucune édition de fichier gelé depuis un runbook non signé.
- Notes spécifiques
  - Le comptage stale se fait en lecture seule par L2/DBA; ce runbook ne fournit pas de SQL exécutable.

## 8. Références
- `app/Models/DomainEvent.php:33-48`
- `app/Console/Commands/OutboxRescueCommand.php:11-29`
- `app/Console/Commands/OutboxRetryFailedCommand.php:13-39`
- `app/Listeners/PersistOrderCreatedToOutbox.php:19-41`
- `app/Listeners/PersistOrderStatusChangedToOutbox.php:19-40`
- `app/Listeners/PersistOrderTableChangedToOutbox.php:43-68`
- `app/Listeners/PersistItemAvailabilityChangedToOutbox.php:48-63`
- `app/Domain/Events/EventContract.php:63-130`
- `app/Events/Concerns/DispatchableAfterCommit.php:8-42`
- `app/Jobs/DispatchDomainEventsJob.php:62-89`
- `app/Http/Controllers/Admin/Observability/SyncOverviewController.php:113-157`
- Gate: `(none)` (PENDING_HUMAN_GATE ou à drafter; aucune approbation dans ce runbook).
- Plan: `PLAN-20` — documentation and runbook skeleton.
- Mission: `M-20` — `CV1-M20-RUNBOOKS-SKELETON`.
- Plan transverse: `PLAN-14` — ops runtime observability, si métrique ou preflight concerné.
- Plan transverse: `PLAN-15` — rollout canary rollback, si rollback ou canary concerné.
- Invariant: backend pricing SSOT.
- Invariant: `OrderStatus enum` authoritative.
- Invariant: `branch_id` isolation strict.
- Invariant: dispatch after commit.
- Invariant: OrderService / FrontendOrderService symmetry.
- Invariant: frozen zones require human gate clearance.

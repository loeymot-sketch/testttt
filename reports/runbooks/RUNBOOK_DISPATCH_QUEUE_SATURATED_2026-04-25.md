# RUNBOOK — Queue dispatch saturée / events domaine (2026-04-25)

- Status: DRAFT_SKELETON_NOT_SIGNED
- Owner (DRAFT): DevOps
- Severity ceiling: P0
- Plan source: PLAN-20 (super master) / M-20 (masterplay)
- Linked gates: (none)
- Last reviewed: 2026-04-25 (initial skeleton)

## 1. Trigger
- Le process manager / dashboard de déploiement indique workers arrêtés, saturés ou backlog sur queue `high`.
- Commande `queue:failed` liste `DispatchDomainEventsJob` ou voisins récurrents.
- Log final failure contient `[DispatchDomainEventsJob] Final failure dispatching domain event`.
- Sentry breadcrumb `category=queue.dispatch_domain_events.failed` présent.
- Dashboard sync montre p95 dispatch au-dessus de 2000 ms.
- KDS/POS ne reçoivent plus d’événements malgré transactions commitées.
- Trigger evidence 1: signal à corréler avec `app/Jobs/DispatchDomainEventsJob.php:62-89`.
- Trigger evidence 2: signal à corréler avec `app/Jobs/DispatchDomainEventsJob.php:153-161`.
- Trigger evidence 3: signal à corréler avec `app/Jobs/DispatchDomainEventsJob.php:177-208`.
- Trigger evidence 4: signal à corréler avec `app/Jobs/DispatchDomainEventsJob.php:208-220`.
- Trigger evidence 5: signal à corréler avec `app/Jobs/CleanupStalePendingKioskOrders.php:19-58`.
- Trigger evidence 6: signal à corréler avec `app/Jobs/SendFcmNotificationJob.php:63-68`.
- Trigger evidence 7: signal à corréler avec `config/queue.php:16-72`.
- Trigger evidence 8: signal à corréler avec `app/Console/Commands/PreflightProductionCommand.php:112-120`.
- Détection attendue: alerte automatique, dashboard ops, journal structuré, ou ticket L1 horodaté UTC.
- Toute alerte cross-branch doit être découpée par `branch_id` avant action.
- Aucun seuil de gate n’est approuvé dans ce runbook; les gates restent `PENDING_HUMAN_GATE` quand cités.

## 2. Symptômes utilisateur / ops
- KDS ne voit plus nouvelles commandes ou transitions.
- POS/borne semblent enregistrer mais l’état temps réel stagne.
- Ops voit backlog, failed jobs, ou latence outbox.
- Notifications FCM peuvent être lentes sans bloquer `high` si queue dédiée fonctionne.
- Plusieurs branches peuvent être touchées; scoper dashboard par `branch_id`.
- Côté ops: pic d’alertes, latence, erreurs récurrentes ou absence de progression dans le dashboard concerné.
- Côté audit: absence de preuve `branch_id` ou absence d’horodatage UTC rend la sortie non recevable.
- Côté produit: ne jamais compenser par une règle prix frontend ou un changement de statut hors `OrderStatus enum`.
- Côté coordination: si un autre agent travaille le même incident, un seul owner L2 tient le journal incident.

## 3. Diagnostic step-by-step
1. Lire état workers queue
   - Commande / observation: Observation process manager / dashboard de déploiement; aucune commande Artisan dédiée dans ce dépôt.
   - Fichier:line à inspecter: `config/queue.php:16-72`.
   - Décision de bifurcation: workers down = DevOps P0; workers up + backlog = saturation P1/P0.
   - Preuve à conserver: capture horodatée UTC + branch_id + extrait de journal sans PII.
   - Invariant à vérifier: pas de queue sync production..
2. Lister failed jobs
   - Commande / observation: `php artisan queue:failed`
   - Fichier:line à inspecter: `app/Jobs/DispatchDomainEventsJob.php:177-208`.
   - Décision de bifurcation: final failures dispatch = outbox/event issue; voisins = traiter lane spécifique.
   - Preuve à conserver: capture horodatée UTC + branch_id + extrait de journal sans PII.
   - Invariant à vérifier: dispatch after commit..
3. Vérifier idempotency claim
   - Commande / observation: Observation logs job; aucune commande dédiée.
   - Fichier:line à inspecter: `app/Jobs/DispatchDomainEventsJob.php:62-89`.
   - Décision de bifurcation: skipped concurrent worker = normal; pas incident si pas de backlog.
   - Preuve à conserver: capture horodatée UTC + branch_id + extrait de journal sans PII.
   - Invariant à vérifier: pas de double broadcast..
4. Qualifier envelope contract
   - Commande / observation: Observation logs job; aucune commande dédiée.
   - Fichier:line à inspecter: `app/Jobs/DispatchDomainEventsJob.php:153-161`.
   - Décision de bifurcation: contract violation = L2 BE; scaling worker ne corrige pas payload.
   - Preuve à conserver: capture horodatée UTC + branch_id + extrait de journal sans PII.
   - Invariant à vérifier: ne pas réécrire event..
5. Lire breadcrumb pager
   - Commande / observation: Observation Sentry/logs; aucune commande dédiée.
   - Fichier:line à inspecter: `app/Jobs/DispatchDomainEventsJob.php:208-220`.
   - Décision de bifurcation: breadcrumb failed = P1/P0 selon volume.
   - Preuve à conserver: capture horodatée UTC + branch_id + extrait de journal sans PII.
   - Invariant à vérifier: preuve non destructive..
6. Contrôler preflight queue
   - Commande / observation: `php artisan app:preflight-production`
   - Fichier:line à inspecter: `app/Console/Commands/PreflightProductionCommand.php:112-120`.
   - Décision de bifurcation: QUEUE_CONNECTION sync = CRITICAL; ne pas déployer.
   - Preuve à conserver: capture horodatée UTC + branch_id + extrait de journal sans PII.
   - Invariant à vérifier: dispatch async prod..
7. Comparer FCM voisin
   - Commande / observation: `php artisan queue:failed`
   - Fichier:line à inspecter: `app/Jobs/SendFcmNotificationJob.php:63-68`.
   - Décision de bifurcation: notifications saturées ≠ dispatch high saturé.
   - Preuve à conserver: capture horodatée UTC + branch_id + extrait de journal sans PII.
   - Invariant à vérifier: ne pas affamer high..
8. Contrôler cleanup voisin
   - Commande / observation: `php artisan queue:failed`
   - Fichier:line à inspecter: `app/Jobs/CleanupStalePendingKioskOrders.php:19-58`.
   - Décision de bifurcation: cleanup seul = incident kiosk pending, pas queue dispatch globale.
   - Preuve à conserver: capture horodatée UTC + branch_id + extrait de journal sans PII.
   - Invariant à vérifier: OrderStatus enum..
9. Lire métrique dispatch latency
   - Commande / observation: Observation dashboard sync; aucune commande dédiée.
   - Fichier:line à inspecter: `app/Services/Observability/SyncMetricsRecorder.php:29-63`.
   - Décision de bifurcation: p95 élevé = saturation ou lock contention.
   - Preuve à conserver: capture horodatée UTC + branch_id + extrait de journal sans PII.
   - Invariant à vérifier: metric non bloquante..
10. Lire overview sync
   - Commande / observation: Observation dashboard admin; aucune commande dédiée.
   - Fichier:line à inspecter: `app/Http/Controllers/Admin/Observability/SyncOverviewController.php:140-157`.
   - Décision de bifurcation: recent_failures branch-scoped orientent L2.
   - Preuve à conserver: capture horodatée UTC + branch_id + extrait de journal sans PII.
   - Invariant à vérifier: branch_id strict dashboard..
- Diagnostic stop: si une étape exige une écriture hors `reports/runbooks/`, arrêter et escalader hors M-20.
- Diagnostic stop: si une étape demande une décision fiscale, ouvrir escalade humaine, pas de correction autonome.
- Diagnostic stop: si une étape révèle un gate non signé, documenter les options, ne pas choisir l’option.

## 4. Actions correctives par criticité
### 4.1 P0 — production caissière bloquée
- Délai cible: ≤ 5 min de réponse.
- Action P0-1
  - Précondition: Queue dispatch saturée / events domaine bloque encaissement ou préparation sur une branche.
  - Action: Basculer en procédure dégradée opérationnelle autorisée, sans modifier code ni données.
  - Vérification post-action: Comparer incident et ancrage app/Jobs/DispatchDomainEventsJob.php:62-89.
  - Impact attendu: Flux caisse stabilisé ou escalade L2 active.
- Action P0-2
  - Précondition: Signal P0 confirmé par deux sources ops.
  - Action: Geler les actions irréversibles côté équipe terrain.
  - Vérification post-action: Conserver captures dashboard et journal lié à app/Jobs/DispatchDomainEventsJob.php:153-161.
  - Impact attendu: Preuve intacte pour audit et post-mortem.
- Action P0-3
  - Précondition: Risque de double traitement ou perte de commande.
  - Action: Suspendre toute relance manuelle non documentée.
  - Vérification post-action: Vérifier que les actions restent dans les invariants listés en §8.
  - Impact attendu: Pas de duplication silencieuse.
- Action P0-4
  - Précondition: Une branche unique est touchée.
  - Action: Limiter le traitement et les communications à ce `branch_id`.
  - Vérification post-action: Vérifier la présence de `branch_id` dans app/Jobs/DispatchDomainEventsJob.php:62-89.
  - Impact attendu: Isolation multi-branches préservée.
- Action P0-5
  - Précondition: Le signal persiste après 5 minutes.
  - Action: Escalader L2 puis L3 selon matrice.
  - Vérification post-action: Joindre les références app/Jobs/DispatchDomainEventsJob.php:62-89 et app/Jobs/DispatchDomainEventsJob.php:153-161.
  - Impact attendu: Décision humaine disponible si risque business.
- Sortie P0: ticket incident mis à jour avec heure UTC, `branch_id`, owner, et prochaine décision.
### 4.2 P1 — dégradé mais opérable
- Délai cible: ≤ 30 min.
- Action P1-1
  - Précondition: Backlog avec workers vivants.
  - Action: Scaler les workers ou isoler la queue selon procédure infra, sans toucher code.
  - Vérification post-action: backlog queue baisse dans le dashboard de déploiement et p95 revient sous seuil.
  - Impact attendu: Temps réel rétabli sans mutation métier.
- Action P1-2
  - Précondition: Queue dispatch saturée / events domaine dégrade le service sans arrêt total.
  - Action: Maintenir le service avec surveillance renforcée.
  - Vérification post-action: Comparer les symptômes au fichier app/Jobs/DispatchDomainEventsJob.php:62-89.
  - Impact attendu: Pas de passage P0 sans preuve supplémentaire.
- Action P1-3
  - Précondition: Une action temporaire non destructive existe.
  - Action: Appliquer uniquement la mesure documentée dans ce runbook.
  - Vérification post-action: Noter l’heure UTC et le `branch_id` dans le ticket.
  - Impact attendu: Réversibilité conservée.
- Action P1-4
  - Précondition: Le dashboard montre une amélioration.
  - Action: Continuer l’observation sur deux fenêtres de mesure.
  - Vérification post-action: Conserver la capture de la métrique ancrée en §8.
  - Impact attendu: Incident maîtrisé sans correction code.
- Action P1-5
  - Précondition: La cause reste incertaine.
  - Action: Préparer analyse L2, sans élargir aux modules voisins.
  - Vérification post-action: Lister uniquement les références du §8.
  - Impact attendu: Scope M-20 respecté.
- Action P1-6
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
  - Vérification post-action: Ancrer le ticket sur app/Jobs/DispatchDomainEventsJob.php:62-89.
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
- [ ] Preuve 1 reliée à `app/Jobs/DispatchDomainEventsJob.php:62-89` dans le ticket ou dashboard.
- [ ] Preuve 2 reliée à `app/Jobs/DispatchDomainEventsJob.php:153-161` dans le ticket ou dashboard.
- [ ] Preuve 3 reliée à `app/Jobs/DispatchDomainEventsJob.php:177-208` dans le ticket ou dashboard.
- [ ] Preuve 4 reliée à `app/Jobs/DispatchDomainEventsJob.php:208-220` dans le ticket ou dashboard.
- [ ] Preuve 5 reliée à `app/Jobs/CleanupStalePendingKioskOrders.php:19-58` dans le ticket ou dashboard.
- [ ] Preuve 6 reliée à `app/Jobs/SendFcmNotificationJob.php:63-68` dans le ticket ou dashboard.
- [ ] Preuve 7 reliée à `config/queue.php:16-72` dans le ticket ou dashboard.
- [ ] Preuve 8 reliée à `app/Console/Commands/PreflightProductionCommand.php:112-120` dans le ticket ou dashboard.
- [ ] Preuve 9 reliée à `app/Services/Observability/SyncMetricsRecorder.php:29-63` dans le ticket ou dashboard.
- [ ] Preuve 10 reliée à `app/Http/Controllers/Admin/Observability/SyncOverviewController.php:140-157` dans le ticket ou dashboard.

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
  - Différencier saturation worker, failed jobs contract, et lock contention.

## 8. Références
- `app/Jobs/DispatchDomainEventsJob.php:62-89`
- `app/Jobs/DispatchDomainEventsJob.php:153-161`
- `app/Jobs/DispatchDomainEventsJob.php:177-208`
- `app/Jobs/DispatchDomainEventsJob.php:208-220`
- `app/Jobs/CleanupStalePendingKioskOrders.php:19-58`
- `app/Jobs/SendFcmNotificationJob.php:63-68`
- `config/queue.php:16-72`
- `app/Console/Commands/PreflightProductionCommand.php:112-120`
- `app/Services/Observability/SyncMetricsRecorder.php:29-63`
- `app/Http/Controllers/Admin/Observability/SyncOverviewController.php:140-157`
- `app/Http/Controllers/Admin/Observability/SyncOverviewController.php:173-204`
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

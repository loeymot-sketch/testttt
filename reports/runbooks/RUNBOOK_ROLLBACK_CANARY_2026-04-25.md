# RUNBOOK — Rollback canary Caisse V1 (2026-04-25)

- Status: DRAFT_SKELETON_NOT_SIGNED
- Owner (DRAFT): DevOps
- Severity ceiling: P0
- Plan source: PLAN-20 (super master) / M-20 (masterplay)
- Linked gates: GATE_GO_NO_GO_CAISSE_V1, GATE_PAYMENT_LEDGER_V1, GATE_FISCAL_KIOSK_V1, GATE_KDS_BUMP_V1, GATE_OFFLINE_SCOPE_V1
- Last reviewed: 2026-04-25 (initial skeleton)

## 1. Trigger
- Predicate canary `payment_success_rate < 95% / 5min`.
- Predicate canary `fiscal_anomaly > 0`.
- Predicate canary `kds_error_rate > 5%`.
- Preflight production retourne CRITICAL post-déploiement.
- Une branche pilote dépasse seuil P0 pendant rollout.
- Gate humain demande arrêt rollout ou retour build legacy.
- Trigger evidence 1: signal à corréler avec `plans/PLAN_CAISSE_V1_GPT_MASTERPLAY_2026-04-25.md:437`.
- Trigger evidence 2: signal à corréler avec `plans/PLAN_CAISSE_V1_GPT_MASTERPLAY_2026-04-25.md:439`.
- Trigger evidence 3: signal à corréler avec `plans/PLAN_CAISSE_V1_GPT_MASTERPLAY_2026-04-25.md:270`.
- Trigger evidence 4: signal à corréler avec `plans/PLAN_CAISSE_V1_GPT_MASTERPLAY_2026-04-25.md:304`.
- Trigger evidence 5: signal à corréler avec `plans/PLAN_CAISSE_V1_GPT_MASTERPLAY_2026-04-25.md:333`.
- Trigger evidence 6: signal à corréler avec `plans/PLAN_CAISSE_V1_GPT_MASTERPLAY_2026-04-25.md:362`.
- Trigger evidence 7: signal à corréler avec `plans/PLAN_CAISSE_V1_GPT_MASTERPLAY_2026-04-25.md:372`.
- Trigger evidence 8: signal à corréler avec `plans/PLAN_CAISSE_V1_SUPER_MASTER_2026-04-25.md:81-83`.
- Détection attendue: alerte automatique, dashboard ops, journal structuré, ou ticket L1 horodaté UTC.
- Toute alerte cross-branch doit être découpée par `branch_id` avant action.
- Aucun seuil de gate n’est approuvé dans ce runbook; les gates restent `PENDING_HUMAN_GATE` quand cités.

## 2. Symptômes utilisateur / ops
- Pilote branche instable après activation flag.
- Paiements, fiscal, KDS ou offline kiosk dégradés selon flag.
- Ops doit éteindre dans ordre paiement → fiscal → KDS → kiosk offline.
- Rollback DB/migrations réservé à M-13; ce runbook ne le détaille pas.
- Les flags Caisse V1 sont centralisés dans `config/caisse_v1_rollout.php`; le drill read-only est `scripts/rollout-canary-drill.sh`.
- Côté ops: pic d’alertes, latence, erreurs récurrentes ou absence de progression dans le dashboard concerné.
- Côté audit: absence de preuve `branch_id` ou absence d’horodatage UTC rend la sortie non recevable.
- Côté produit: ne jamais compenser par une règle prix frontend ou un changement de statut hors `OrderStatus enum`.
- Côté coordination: si un autre agent travaille le même incident, un seul owner L2 tient le journal incident.

## 3. Diagnostic step-by-step
1. Nommer flag payment ledger
   - Commande / observation: `bash scripts/rollout-canary-drill.sh --help` puis drill avec preuves M14 + métriques.
   - Fichier:line à inspecter: `config/caisse_v1_rollout.php`.
   - Décision de bifurcation: `payment_ledger_v1` cible backend paiement; rollout non autorisé si le drill échoue.
   - Preuve à conserver: capture horodatée UTC + branch_id + extrait de journal sans PII.
   - Invariant à vérifier: pricing/backend payment SSOT..
2. Nommer flag POS guards
   - Commande / observation: Observation rollout dashboard; aucune commande dédiée.
   - Fichier:line à inspecter: `plans/PLAN_CAISSE_V1_GPT_MASTERPLAY_2026-04-25.md:437`.
   - Décision de bifurcation: `pos_revenue_guards` cible backend service; ne pas désactiver fiscal evidence sans L3.
   - Preuve à conserver: capture horodatée UTC + branch_id + extrait de journal sans PII.
   - Invariant à vérifier: OrderService/FOS symmetry..
3. Nommer flag KDS strict release
   - Commande / observation: Observation rollout dashboard; aucune commande dédiée.
   - Fichier:line à inspecter: `plans/PLAN_CAISSE_V1_GPT_MASTERPLAY_2026-04-25.md:437`.
   - Décision de bifurcation: `kds_strict_release` cible KDS backend/frontend; recharger écrans après rollback.
   - Preuve à conserver: capture horodatée UTC + branch_id + extrait de journal sans PII.
   - Invariant à vérifier: OrderStatus enum..
4. Nommer flag quote v1
   - Commande / observation: Observation rollout dashboard; aucune commande dédiée.
   - Fichier:line à inspecter: `plans/PLAN_CAISSE_V1_GPT_MASTERPLAY_2026-04-25.md:437`.
   - Décision de bifurcation: `quote_v1` cible backend quote; aucun recalcul frontend.
   - Preuve à conserver: capture horodatée UTC + branch_id + extrait de journal sans PII.
   - Invariant à vérifier: pricing backend SSOT..
5. Nommer flag fiscal z
   - Commande / observation: Observation rollout dashboard; aucune commande dédiée.
   - Fichier:line à inspecter: `plans/PLAN_CAISSE_V1_GPT_MASTERPLAY_2026-04-25.md:437`.
   - Décision de bifurcation: `fiscal_z_v1` = escalade humaine immédiate si anomaly >0.
   - Preuve à conserver: capture horodatée UTC + branch_id + extrait de journal sans PII.
   - Invariant à vérifier: NF525 evidence..
6. Nommer flag kiosk offline
   - Commande / observation: Observation rollout dashboard; aucune commande dédiée.
   - Fichier:line à inspecter: `plans/PLAN_CAISSE_V1_GPT_MASTERPLAY_2026-04-25.md:437`.
   - Décision de bifurcation: `kiosk_offline_strict` cible offline; ne jamais activer CB/TR offline sans gate.
   - Preuve à conserver: capture horodatée UTC + branch_id + extrait de journal sans PII.
   - Invariant à vérifier: gate offline scope..
7. Vérifier predicates canary
   - Commande / observation: Observation monitoring; aucune commande dédiée.
   - Fichier:line à inspecter: `plans/PLAN_CAISSE_V1_GPT_MASTERPLAY_2026-04-25.md:439`.
   - Décision de bifurcation: un predicate franchi = P0 ordre extinction.
   - Preuve à conserver: capture horodatée UTC + branch_id + extrait de journal sans PII.
   - Invariant à vérifier: pas de seuil inventé..
8. Rejouer preflight
   - Commande / observation: `php artisan app:preflight-production`
   - Fichier:line à inspecter: `app/Console/Commands/PreflightProductionCommand.php:39-60`.
   - Décision de bifurcation: CRITICAL = hold rollout; warnings = P1 selon impact.
   - Preuve à conserver: capture horodatée UTC + branch_id + extrait de journal sans PII.
   - Invariant à vérifier: preflight canonique..
9. Lire checks preflight critiques
   - Commande / observation: `php artisan app:preflight-production`
   - Fichier:line à inspecter: `app/Console/Commands/PreflightProductionCommand.php:102-188`.
   - Décision de bifurcation: cache/queue/fiscal CRITICAL orientent rollback ciblé.
   - Preuve à conserver: capture horodatée UTC + branch_id + extrait de journal sans PII.
   - Invariant à vérifier: runtime prod sûr..
10. Séparer migrations M-13
   - Commande / observation: Observation plan; aucune commande dédiée.
   - Fichier:line à inspecter: `plans/PLAN_CAISSE_V1_SUPER_MASTER_2026-04-25.md:81-83`.
   - Décision de bifurcation: rollback DB/migration = runbooks M-13 à venir; ne pas dupliquer.
   - Preuve à conserver: capture horodatée UTC + branch_id + extrait de journal sans PII.
   - Invariant à vérifier: frozen/schema gates..
- Mapping cible `payment_ledger_v1`: backend paiement; rollback DB éventuel réservé M-13; aucun chemin de code flag existant cité.
- Mapping cible `pos_revenue_guards`: backend POS revenue guards; rollback DB éventuel réservé M-13; aucun chemin de code flag existant cité.
- Mapping cible `kds_strict_release`: backend KDS + bundle frontend KDS; rollback frontend = retour build legacy/cutover M-12 si humain l’ordonne.
- Mapping cible `quote_v1`: backend quote SSOT + affichage frontend consommateur; aucun recalcul prix frontend pendant rollback.
- Mapping cible `fiscal_z_v1`: backend fiscal Z; rollback DB/migration réservé M-13; décision NF525 humaine obligatoire.
- Mapping cible `kiosk_offline_strict`: bundle frontend kiosk + garde backend offline; CB/TR offline reste interdit sans gate.
- Mapping build legacy: utiliser les guards M-12 et le cutover humain; ce runbook ne donne pas de commande de déploiement inventée.
- Mapping migration down: documenter besoin DBA/M-13; ne pas exécuter depuis M-20, ne pas dupliquer les futurs `MIGRATIONS_*`.
- Diagnostic stop: si une étape exige une écriture hors `reports/runbooks/`, arrêter et escalader hors M-20.
- Diagnostic stop: si une étape demande une décision fiscale, ouvrir escalade humaine, pas de correction autonome.
- Diagnostic stop: si une étape révèle un gate non signé, documenter les options, ne pas choisir l’option.

## 4. Actions correctives par criticité
### 4.1 P0 — production caissière bloquée
- Délai cible: ≤ 5 min de réponse.
- Action P0-1
  - Précondition: Predicate canary franchi.
  - Action: Ordre d’extinction: paiement, fiscal, KDS, kiosk offline; rollback migration réservé M-13.
  - Vérification post-action: Preflight vert ou anomaly stabilisée après chaque extinction.
  - Impact attendu: Blast radius limité sans inventer service flags.
- Action P0-2
  - Précondition: Rollback canary Caisse V1 bloque encaissement ou préparation sur une branche.
  - Action: Basculer en procédure dégradée opérationnelle autorisée, sans modifier code ni données.
  - Vérification post-action: Comparer incident et ancrage plans/PLAN_CAISSE_V1_GPT_MASTERPLAY_2026-04-25.md:437.
  - Impact attendu: Flux caisse stabilisé ou escalade L2 active.
- Action P0-3
  - Précondition: Signal P0 confirmé par deux sources ops.
  - Action: Geler les actions irréversibles côté équipe terrain.
  - Vérification post-action: Conserver captures dashboard et journal lié à plans/PLAN_CAISSE_V1_GPT_MASTERPLAY_2026-04-25.md:439.
  - Impact attendu: Preuve intacte pour audit et post-mortem.
- Action P0-4
  - Précondition: Risque de double traitement ou perte de commande.
  - Action: Suspendre toute relance manuelle non documentée.
  - Vérification post-action: Vérifier que les actions restent dans les invariants listés en §8.
  - Impact attendu: Pas de duplication silencieuse.
- Action P0-5
  - Précondition: Une branche unique est touchée.
  - Action: Limiter le traitement et les communications à ce `branch_id`.
  - Vérification post-action: Vérifier la présence de `branch_id` dans plans/PLAN_CAISSE_V1_GPT_MASTERPLAY_2026-04-25.md:437.
  - Impact attendu: Isolation multi-branches préservée.
- Action P0-6
  - Précondition: Le signal persiste après 5 minutes.
  - Action: Escalader L2 puis L3 selon matrice.
  - Vérification post-action: Joindre les références plans/PLAN_CAISSE_V1_GPT_MASTERPLAY_2026-04-25.md:437 et plans/PLAN_CAISSE_V1_GPT_MASTERPLAY_2026-04-25.md:439.
  - Impact attendu: Décision humaine disponible si risque business.
- Sortie P0: ticket incident mis à jour avec heure UTC, `branch_id`, owner, et prochaine décision.
### 4.2 P1 — dégradé mais opérable
- Délai cible: ≤ 30 min.
- Action P1-1
  - Précondition: Rollback canary Caisse V1 dégrade le service sans arrêt total.
  - Action: Maintenir le service avec surveillance renforcée.
  - Vérification post-action: Comparer les symptômes au fichier plans/PLAN_CAISSE_V1_GPT_MASTERPLAY_2026-04-25.md:437.
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
  - Vérification post-action: Ancrer le ticket sur plans/PLAN_CAISSE_V1_GPT_MASTERPLAY_2026-04-25.md:437.
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
- [ ] Preuve 1 reliée à `plans/PLAN_CAISSE_V1_GPT_MASTERPLAY_2026-04-25.md:437` dans le ticket ou dashboard.
- [ ] Preuve 2 reliée à `plans/PLAN_CAISSE_V1_GPT_MASTERPLAY_2026-04-25.md:439` dans le ticket ou dashboard.
- [ ] Preuve 3 reliée à `plans/PLAN_CAISSE_V1_GPT_MASTERPLAY_2026-04-25.md:270` dans le ticket ou dashboard.
- [ ] Preuve 4 reliée à `plans/PLAN_CAISSE_V1_GPT_MASTERPLAY_2026-04-25.md:304` dans le ticket ou dashboard.
- [ ] Preuve 5 reliée à `plans/PLAN_CAISSE_V1_GPT_MASTERPLAY_2026-04-25.md:333` dans le ticket ou dashboard.
- [ ] Preuve 6 reliée à `plans/PLAN_CAISSE_V1_GPT_MASTERPLAY_2026-04-25.md:362` dans le ticket ou dashboard.
- [ ] Preuve 7 reliée à `plans/PLAN_CAISSE_V1_GPT_MASTERPLAY_2026-04-25.md:372` dans le ticket ou dashboard.
- [ ] Preuve 8 reliée à `plans/PLAN_CAISSE_V1_SUPER_MASTER_2026-04-25.md:81-83` dans le ticket ou dashboard.
- [ ] Preuve 9 reliée à `plans/PLAN_CAISSE_V1_SUPER_MASTER_2026-04-25.md:291-306` dans le ticket ou dashboard.
- [ ] Preuve 10 reliée à `app/Console/Commands/PreflightProductionCommand.php:39-60` dans le ticket ou dashboard.

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
  - DOC_GAP: pas de FeatureFlagService centralisé vérifié; les emplacements flags sont à créer par M-15.

## 8. Références
- `plans/PLAN_CAISSE_V1_GPT_MASTERPLAY_2026-04-25.md:437`
- `plans/PLAN_CAISSE_V1_GPT_MASTERPLAY_2026-04-25.md:439`
- `plans/PLAN_CAISSE_V1_GPT_MASTERPLAY_2026-04-25.md:270`
- `plans/PLAN_CAISSE_V1_GPT_MASTERPLAY_2026-04-25.md:304`
- `plans/PLAN_CAISSE_V1_GPT_MASTERPLAY_2026-04-25.md:333`
- `plans/PLAN_CAISSE_V1_GPT_MASTERPLAY_2026-04-25.md:362`
- `plans/PLAN_CAISSE_V1_GPT_MASTERPLAY_2026-04-25.md:372`
- `plans/PLAN_CAISSE_V1_SUPER_MASTER_2026-04-25.md:81-83`
- `plans/PLAN_CAISSE_V1_SUPER_MASTER_2026-04-25.md:291-306`
- `app/Console/Commands/PreflightProductionCommand.php:39-60`
- `app/Console/Commands/PreflightProductionCommand.php:102-188`
- Gate: `GATE_GO_NO_GO_CAISSE_V1` (PENDING_HUMAN_GATE ou à drafter; aucune approbation dans ce runbook).
- Gate: `GATE_PAYMENT_LEDGER_V1` (PENDING_HUMAN_GATE ou à drafter; aucune approbation dans ce runbook).
- Gate: `GATE_FISCAL_KIOSK_V1` (PENDING_HUMAN_GATE ou à drafter; aucune approbation dans ce runbook).
- Gate: `GATE_KDS_BUMP_V1` (PENDING_HUMAN_GATE ou à drafter; aucune approbation dans ce runbook).
- Gate: `GATE_OFFLINE_SCOPE_V1` (PENDING_HUMAN_GATE ou à drafter; aucune approbation dans ce runbook).
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

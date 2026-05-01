# RUNBOOK — Rupture séquence fiscale NF525 (2026-04-25)

- Status: DRAFT_SKELETON_NOT_SIGNED
- Owner (DRAFT): NF525-QA
- Severity ceiling: P0
- Plan source: PLAN-20 (super master) / M-20 (masterplay)
- Linked gates: GATE_FISCAL_KIOSK_V1, GATE_SCHEMA_MIGRATIONS_V1
- Last reviewed: 2026-04-25 (initial skeleton)

## 1. Trigger
- FiscalSequenceService ne peut pas acquérir lock `fiscal_seq_b{branch}`.
- ZReportService refuse open/close ou détecte chaîne invalide.
- FiscalArchiveCommand log `fiscal.archive.verify_chain.failed` ou abort bundle.
- Preflight signale secret fiscal ou cache driver CRITICAL.
- AuditLogService refuse `branch_id` null ou lock chain indisponible.
- Paiement/cashback écrit audit fiscal incomplet ou séquence absente.
- Trigger evidence 1: signal à corréler avec `app/Services/Fiscal/FiscalSequenceService.php:57-94`.
- Trigger evidence 2: signal à corréler avec `app/Services/Fiscal/AuditLogService.php:70-132`.
- Trigger evidence 3: signal à corréler avec `app/Services/Fiscal/AuditLogService.php:199-230`.
- Trigger evidence 4: signal à corréler avec `app/Services/Fiscal/ZReportService.php:45-100`.
- Trigger evidence 5: signal à corréler avec `app/Services/Fiscal/ZReportService.php:107-197`.
- Trigger evidence 6: signal à corréler avec `app/Services/Fiscal/XReportService.php:36-56`.
- Trigger evidence 7: signal à corréler avec `app/Console/Commands/FiscalArchiveCommand.php:74-155`.
- Trigger evidence 8: signal à corréler avec `app/Console/Commands/FiscalArchiveCommand.php:182-195`.
- Détection attendue: alerte automatique, dashboard ops, journal structuré, ou ticket L1 horodaté UTC.
- Toute alerte cross-branch doit être découpée par `branch_id` avant action.
- Aucun seuil de gate n’est approuvé dans ce runbook; les gates restent `PENDING_HUMAN_GATE` quand cités.

## 2. Symptômes utilisateur / ops
- Caisse doit être gelée pour la branche concernée.
- Ops ne peut plus garantir séquence gap-free ou archive NF525.
- Z/X report incohérent ou impossible à produire.
- Revenue peut continuer physiquement mais preuve fiscale est risquée.
- Toute correction de séquence est irréversible et humaine.
- Côté ops: pic d’alertes, latence, erreurs récurrentes ou absence de progression dans le dashboard concerné.
- Côté audit: absence de preuve `branch_id` ou absence d’horodatage UTC rend la sortie non recevable.
- Côté produit: ne jamais compenser par une règle prix frontend ou un changement de statut hors `OrderStatus enum`.
- Côté coordination: si un autre agent travaille le même incident, un seul owner L2 tient le journal incident.

## 3. Diagnostic step-by-step
1. Geler immédiatement la branche fiscale
   - Commande / observation: Observation incident; aucune commande dédiée.
   - Fichier:line à inspecter: `app/Services/Fiscal/FiscalSequenceService.php:57-94`.
   - Décision de bifurcation: lock ou sequence break = P0 + L4; ne pas patcher.
   - Preuve à conserver: capture horodatée UTC + branch_id + extrait de journal sans PII.
   - Invariant à vérifier: branch_id fiscal positif..
2. Vérifier audit chain writer
   - Commande / observation: Observation fiscal logs; aucune commande dédiée.
   - Fichier:line à inspecter: `app/Services/Fiscal/AuditLogService.php:70-132`.
   - Décision de bifurcation: lock/audit fail = conserver evidence, pas retry destructif.
   - Preuve à conserver: capture horodatée UTC + branch_id + extrait de journal sans PII.
   - Invariant à vérifier: preuve HMAC intacte..
3. Rejouer preflight prod
   - Commande / observation: `php artisan app:preflight-production`
   - Fichier:line à inspecter: `app/Console/Commands/PreflightProductionCommand.php:162-188`.
   - Décision de bifurcation: CRITICAL fiscal secret/cache = no-go opérationnel.
   - Preuve à conserver: capture horodatée UTC + branch_id + extrait de journal sans PII.
   - Invariant à vérifier: secrets prod non contournés..
4. Contrôler Z open/close
   - Commande / observation: Observation fiscal logs; aucune commande dédiée.
   - Fichier:line à inspecter: `app/Services/Fiscal/ZReportService.php:45-100`.
   - Décision de bifurcation: open conflict = escalade NF525; pas de second Z manuel.
   - Preuve à conserver: capture horodatée UTC + branch_id + extrait de journal sans PII.
   - Invariant à vérifier: séquence Z monotone..
5. Contrôler Z close
   - Commande / observation: Observation fiscal logs; aucune commande dédiée.
   - Fichier:line à inspecter: `app/Services/Fiscal/ZReportService.php:107-197`.
   - Décision de bifurcation: close fail = freeze + L4; ne pas recomposer signature.
   - Preuve à conserver: capture horodatée UTC + branch_id + extrait de journal sans PII.
   - Invariant à vérifier: signature HMAC non réécrite..
6. Contrôler X report read-only
   - Commande / observation: Observation fiscal snapshot; aucune commande dédiée.
   - Fichier:line à inspecter: `app/Services/Fiscal/XReportService.php:36-56`.
   - Décision de bifurcation: X incohérent confirme besoin L4; X ne corrige rien.
   - Preuve à conserver: capture horodatée UTC + branch_id + extrait de journal sans PII.
   - Invariant à vérifier: read-only..
7. Conserver archive evidence
   - Commande / observation: `php artisan foodking:fiscal:archive <branch_id> --from=<YYYY-MM-DD> --to=<YYYY-MM-DD>`
   - Fichier:line à inspecter: `app/Console/Commands/FiscalArchiveCommand.php:74-155`.
   - Décision de bifurcation: archive abort = preuve incident; ne pas utiliser --no-verify sans L4.
   - Preuve à conserver: capture horodatée UTC + branch_id + extrait de journal sans PII.
   - Invariant à vérifier: NF525 evidence..
8. Vérifier config fiscale
   - Commande / observation: `php artisan app:preflight-production`
   - Fichier:line à inspecter: `config/fiscal.php:31-78`.
   - Décision de bifurcation: verify_chain_before_archive false = warning/risque; escalade.
   - Preuve à conserver: capture horodatée UTC + branch_id + extrait de journal sans PII.
   - Invariant à vérifier: config fiscal canonique..
9. Vérifier consommateur POS
   - Commande / observation: Observation order logs; aucune commande dédiée.
   - Fichier:line à inspecter: `app/Services/OrderService.php:900-910`.
   - Décision de bifurcation: sequence absent sur order fiscal = P0.
   - Preuve à conserver: capture horodatée UTC + branch_id + extrait de journal sans PII.
   - Invariant à vérifier: OrderService consommateur fiscal..
10. Vérifier paiement/refund audit
   - Commande / observation: Observation payment logs; aucune commande dédiée.
   - Fichier:line à inspecter: `app/Services/PaymentService.php:50-68`.
   - Décision de bifurcation: cashback sans audit = L4; ne pas corriger transaction brute.
   - Preuve à conserver: capture horodatée UTC + branch_id + extrait de journal sans PII.
   - Invariant à vérifier: audit fiscal HMAC..
- Diagnostic stop: si une étape exige une écriture hors `reports/runbooks/`, arrêter et escalader hors M-20.
- Diagnostic stop: si une étape demande une décision fiscale, ouvrir escalade humaine, pas de correction autonome.
- Diagnostic stop: si une étape révèle un gate non signé, documenter les options, ne pas choisir l’option.

## 4. Actions correctives par criticité
### 4.1 P0 — production caissière bloquée
- Délai cible: ≤ 5 min de réponse.
- Action P0-1
  - Précondition: Toute rupture de séquence fiscale suspectée.
  - Action: Freeze caisse de la branche + escalade L4 NF525 immédiate; aucune tentative de patch séquence.
  - Vérification post-action: Ticket contient branch_id, dernière séquence connue, Z/X status, archive evidence.
  - Impact attendu: Preuve conservée et risque légal sous contrôle humain.
- Action P0-2
  - Précondition: Rupture séquence fiscale NF525 bloque encaissement ou préparation sur une branche.
  - Action: Basculer en procédure dégradée opérationnelle autorisée, sans modifier code ni données.
  - Vérification post-action: Comparer incident et ancrage app/Services/Fiscal/FiscalSequenceService.php:57-94.
  - Impact attendu: Flux caisse stabilisé ou escalade L2 active.
- Action P0-3
  - Précondition: Signal P0 confirmé par deux sources ops.
  - Action: Geler les actions irréversibles côté équipe terrain.
  - Vérification post-action: Conserver captures dashboard et journal lié à app/Services/Fiscal/AuditLogService.php:70-132.
  - Impact attendu: Preuve intacte pour audit et post-mortem.
- Action P0-4
  - Précondition: Risque de double traitement ou perte de commande.
  - Action: Suspendre toute relance manuelle non documentée.
  - Vérification post-action: Vérifier que les actions restent dans les invariants listés en §8.
  - Impact attendu: Pas de duplication silencieuse.
- Action P0-5
  - Précondition: Une branche unique est touchée.
  - Action: Limiter le traitement et les communications à ce `branch_id`.
  - Vérification post-action: Vérifier la présence de `branch_id` dans app/Services/Fiscal/FiscalSequenceService.php:57-94.
  - Impact attendu: Isolation multi-branches préservée.
- Action P0-6
  - Précondition: Le signal persiste après 5 minutes.
  - Action: Escalader L2 puis L3 selon matrice.
  - Vérification post-action: Joindre les références app/Services/Fiscal/FiscalSequenceService.php:57-94 et app/Services/Fiscal/AuditLogService.php:70-132.
  - Impact attendu: Décision humaine disponible si risque business.
- Sortie P0: ticket incident mis à jour avec heure UTC, `branch_id`, owner, et prochaine décision.
### 4.2 P1 — dégradé mais opérable
- Délai cible: ≤ 30 min.
- Action P1-1
  - Précondition: Rupture séquence fiscale NF525 dégrade le service sans arrêt total.
  - Action: Maintenir le service avec surveillance renforcée.
  - Vérification post-action: Comparer les symptômes au fichier app/Services/Fiscal/FiscalSequenceService.php:57-94.
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
  - Vérification post-action: Ancrer le ticket sur app/Services/Fiscal/FiscalSequenceService.php:57-94.
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
| L4 | Séquence, Z, archive ou audit chain compromise | NF525 / Conseil / humain fiscal | Appel direct + dossier incident | Immédiat | Oui, toute décision fiscale |
| Retour ops | Sortie technique confirmée | Ops + owner incident | Ticket + rapport post-mortem | 24 h | Non |

## 6. Vérifications de sortie
- [ ] Freeze caisse levé uniquement par décision humaine documentée hors runbook.
- [ ] Dossier NF525 evidence exporté ou explicitement conservé si archive abort.
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
- [ ] Preuve 1 reliée à `app/Services/Fiscal/FiscalSequenceService.php:57-94` dans le ticket ou dashboard.
- [ ] Preuve 2 reliée à `app/Services/Fiscal/AuditLogService.php:70-132` dans le ticket ou dashboard.
- [ ] Preuve 3 reliée à `app/Services/Fiscal/AuditLogService.php:199-230` dans le ticket ou dashboard.
- [ ] Preuve 4 reliée à `app/Services/Fiscal/ZReportService.php:45-100` dans le ticket ou dashboard.
- [ ] Preuve 5 reliée à `app/Services/Fiscal/ZReportService.php:107-197` dans le ticket ou dashboard.
- [ ] Preuve 6 reliée à `app/Services/Fiscal/XReportService.php:36-56` dans le ticket ou dashboard.
- [ ] Preuve 7 reliée à `app/Console/Commands/FiscalArchiveCommand.php:74-155` dans le ticket ou dashboard.
- [ ] Preuve 8 reliée à `app/Console/Commands/FiscalArchiveCommand.php:182-195` dans le ticket ou dashboard.
- [ ] Preuve 9 reliée à `config/fiscal.php:31-78` dans le ticket ou dashboard.
- [ ] Preuve 10 reliée à `app/Console/Commands/PreflightProductionCommand.php:162-188` dans le ticket ou dashboard.

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
  - Aucune ligne ne propose de patcher, renuméroter ou reconstruire une séquence fiscale.

## 8. Références
- `app/Services/Fiscal/FiscalSequenceService.php:57-94`
- `app/Services/Fiscal/AuditLogService.php:70-132`
- `app/Services/Fiscal/AuditLogService.php:199-230`
- `app/Services/Fiscal/ZReportService.php:45-100`
- `app/Services/Fiscal/ZReportService.php:107-197`
- `app/Services/Fiscal/XReportService.php:36-56`
- `app/Console/Commands/FiscalArchiveCommand.php:74-155`
- `app/Console/Commands/FiscalArchiveCommand.php:182-195`
- `config/fiscal.php:31-78`
- `app/Console/Commands/PreflightProductionCommand.php:162-188`
- `app/Services/OrderService.php:900-910`
- `app/Services/OrderService.php:1602-1624`
- `app/Services/PaymentService.php:50-68`
- Gate: `GATE_FISCAL_KIOSK_V1` (PENDING_HUMAN_GATE ou à drafter; aucune approbation dans ce runbook).
- Gate: `GATE_SCHEMA_MIGRATIONS_V1` (PENDING_HUMAN_GATE ou à drafter; aucune approbation dans ce runbook).
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

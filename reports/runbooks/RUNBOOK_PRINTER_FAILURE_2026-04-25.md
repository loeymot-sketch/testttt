# RUNBOOK — Panne imprimante ESC/POS / tiroir-caisse (2026-04-25)

- Status: DRAFT_SKELETON_NOT_SIGNED
- Owner (DRAFT): Ops
- Severity ceiling: P0
- Plan source: PLAN-20 (super master) / M-20 (masterplay)
- Linked gates: (none)
- Last reviewed: 2026-04-25 (initial skeleton)

## 1. Trigger
- Log `[EscPosPrinterService] print failed` avec `printer_id`, `branch_id`, `station` et erreur transport.
- Transport TCP retourne `missing_host`, `tcp_open_failed` ou `tcp_write_partial`.
- Environnement testing utilise `NullPrinterTransport`, donc pas de sortie papier attendue.
- Test print admin retourne 502 ou tiroir caisse retourne 422.
- Caissier signale absence ticket cuisine/reçu ou tiroir non ouvert.
- Station `receipt`, `kitchen_hot`, `kitchen_cold` ou `bar` affectée.
- Trigger evidence 1: signal à corréler avec `app/Services/Hardware/EscPosPrinterService.php:16-36`.
- Trigger evidence 2: signal à corréler avec `app/Services/Hardware/EscPosPrinterService.php:73-123`.
- Trigger evidence 3: signal à corréler avec `app/Services/Hardware/EscPosCommandBuilder.php:51-57`.
- Trigger evidence 4: signal à corréler avec `app/Services/Hardware/PrinterTransport/TcpPrinterTransport.php:13-29`.
- Trigger evidence 5: signal à corréler avec `app/Services/Hardware/PrinterTransport/NullPrinterTransport.php:5-22`.
- Trigger evidence 6: signal à corréler avec `app/Services/Hardware/PrinterTransport/PrinterTransportInterface.php:5-13`.
- Trigger evidence 7: signal à corréler avec `app/Models/Printer.php:16-35`.
- Trigger evidence 8: signal à corréler avec `app/Http/Controllers/Admin/PrinterController.php:23-35`.
- Détection attendue: alerte automatique, dashboard ops, journal structuré, ou ticket L1 horodaté UTC.
- Toute alerte cross-branch doit être découpée par `branch_id` avant action.
- Aucun seuil de gate n’est approuvé dans ce runbook; les gates restent `PENDING_HUMAN_GATE` quand cités.

## 2. Symptômes utilisateur / ops
- Ticket papier absent malgré commande acceptée.
- Tiroir caisse ne s’ouvre pas sur action POS.
- Ops voit imprimante en erreur transport, mais commande reste en base.
- Mode dégradé PDF/email possible côté procédure terrain sans confirmer rétablissement ESC/POS.
- Risque cuisine si station kitchen ne reçoit plus de ticket.
- Côté ops: pic d’alertes, latence, erreurs récurrentes ou absence de progression dans le dashboard concerné.
- Côté audit: absence de preuve `branch_id` ou absence d’horodatage UTC rend la sortie non recevable.
- Côté produit: ne jamais compenser par une règle prix frontend ou un changement de statut hors `OrderStatus enum`.
- Côté coordination: si un autre agent travaille le même incident, un seul owner L2 tient le journal incident.

## 3. Diagnostic step-by-step
1. Identifier échec service ESC/POS
   - Commande / observation: Observation logs; aucune commande dédiée.
   - Fichier:line à inspecter: `app/Services/Hardware/EscPosPrinterService.php:16-36`.
   - Décision de bifurcation: si log `print failed`, continuer vers transport; sinon vérifier appel admin.
   - Preuve à conserver: capture horodatée UTC + branch_id + extrait de journal sans PII.
   - Invariant à vérifier: branch_id du printer conservé..
2. Tester sortie ticket
   - Commande / observation: Observation endpoint admin test print; aucune commande dédiée.
   - Fichier:line à inspecter: `app/Http/Controllers/Admin/PrinterController.php:77-84`.
   - Décision de bifurcation: 502 = panne service/transport; 200 sans papier = panne périphérique physique.
   - Preuve à conserver: capture horodatée UTC + branch_id + extrait de journal sans PII.
   - Invariant à vérifier: pas d’écriture hors modèle Printer..
3. Qualifier transport TCP injoignable
   - Commande / observation: Observation réseau imprimante; aucune commande dédiée.
   - Fichier:line à inspecter: `app/Services/Hardware/PrinterTransport/TcpPrinterTransport.php:13-29`.
   - Décision de bifurcation: missing_host/open_failed = config ou réseau; partial write = transport instable.
   - Preuve à conserver: capture horodatée UTC + branch_id + extrait de journal sans PII.
   - Invariant à vérifier: aucun contournement branch_id..
4. Écarter NullPrinterTransport
   - Commande / observation: Observation environnement runtime; aucune commande dédiée.
   - Fichier:line à inspecter: `app/Providers/AppServiceProvider.php:30-36`.
   - Décision de bifurcation: testing = null transport attendu; production = TcpPrinterTransport attendu.
   - Preuve à conserver: capture horodatée UTC + branch_id + extrait de journal sans PII.
   - Invariant à vérifier: ne pas confondre test et prod..
5. Vérifier modèle imprimante
   - Commande / observation: Observation admin printers; aucune commande dédiée.
   - Fichier:line à inspecter: `app/Models/Printer.php:16-35`.
   - Décision de bifurcation: host/port/station/status incohérents = config printer P1.
   - Preuve à conserver: capture horodatée UTC + branch_id + extrait de journal sans PII.
   - Invariant à vérifier: branch_id printer strict..
6. Contrôler validation admin printer
   - Commande / observation: Observation formulaire admin; aucune commande dédiée.
   - Fichier:line à inspecter: `app/Http/Requests/Admin/PrinterRequest.php:20-42`.
   - Décision de bifurcation: type/station hors liste = config refusée; ne pas forcer.
   - Preuve à conserver: capture horodatée UTC + branch_id + extrait de journal sans PII.
   - Invariant à vérifier: pas de valeur de station inventée..
7. Lire ressource exposée à ops
   - Commande / observation: Observation payload admin; aucune commande dédiée.
   - Fichier:line à inspecter: `app/Http/Resources/PrinterResource.php:11-25`.
   - Décision de bifurcation: si payload absent, vérifier permissions admin; sinon config visible.
   - Preuve à conserver: capture horodatée UTC + branch_id + extrait de journal sans PII.
   - Invariant à vérifier: pas d’exposition cross-branch..
8. Vérifier tiroir caisse
   - Commande / observation: Observation endpoint tiroir; aucune commande dédiée.
   - Fichier:line à inspecter: `app/Http/Controllers/Admin/Pos/CashDrawerController.php:19-33`.
   - Décision de bifurcation: 422 = printer service retourne erreur; traiter comme station receipt.
   - Preuve à conserver: capture horodatée UTC + branch_id + extrait de journal sans PII.
   - Invariant à vérifier: branch_id auth du caissier..
9. Vérifier pulse ESC/POS tiroir
   - Commande / observation: Observation service hardware; aucune commande dédiée.
   - Fichier:line à inspecter: `app/Services/Hardware/EscPosCommandBuilder.php:51-57`.
   - Décision de bifurcation: commande générée mais pas exécutée = transport/périphérique.
   - Preuve à conserver: capture horodatée UTC + branch_id + extrait de journal sans PII.
   - Invariant à vérifier: aucune modification commande raw..
10. Décider fallback terrain
   - Commande / observation: Observation procédure ops; aucune commande dédiée.
   - Fichier:line à inspecter: `app/Services/Hardware/PrinterTransport/PrinterTransportInterface.php:5-13`.
   - Décision de bifurcation: fallback PDF/email = continuité, pas rétablissement ESC/POS.
   - Preuve à conserver: capture horodatée UTC + branch_id + extrait de journal sans PII.
   - Invariant à vérifier: pas de service fallback inventé..
- Diagnostic stop: si une étape exige une écriture hors `reports/runbooks/`, arrêter et escalader hors M-20.
- Diagnostic stop: si une étape demande une décision fiscale, ouvrir escalade humaine, pas de correction autonome.
- Diagnostic stop: si une étape révèle un gate non signé, documenter les options, ne pas choisir l’option.

## 4. Actions correctives par criticité
### 4.1 P0 — production caissière bloquée
- Délai cible: ≤ 5 min de réponse.
- Action P0-1
  - Précondition: Panne imprimante ESC/POS / tiroir-caisse bloque encaissement ou préparation sur une branche.
  - Action: Basculer en procédure dégradée opérationnelle autorisée, sans modifier code ni données.
  - Vérification post-action: Comparer incident et ancrage app/Services/Hardware/EscPosPrinterService.php:16-36.
  - Impact attendu: Flux caisse stabilisé ou escalade L2 active.
- Action P0-2
  - Précondition: Signal P0 confirmé par deux sources ops.
  - Action: Geler les actions irréversibles côté équipe terrain.
  - Vérification post-action: Conserver captures dashboard et journal lié à app/Services/Hardware/EscPosPrinterService.php:73-123.
  - Impact attendu: Preuve intacte pour audit et post-mortem.
- Action P0-3
  - Précondition: Risque de double traitement ou perte de commande.
  - Action: Suspendre toute relance manuelle non documentée.
  - Vérification post-action: Vérifier que les actions restent dans les invariants listés en §8.
  - Impact attendu: Pas de duplication silencieuse.
- Action P0-4
  - Précondition: Une branche unique est touchée.
  - Action: Limiter le traitement et les communications à ce `branch_id`.
  - Vérification post-action: Vérifier la présence de `branch_id` dans app/Services/Hardware/EscPosPrinterService.php:16-36.
  - Impact attendu: Isolation multi-branches préservée.
- Action P0-5
  - Précondition: Le signal persiste après 5 minutes.
  - Action: Escalader L2 puis L3 selon matrice.
  - Vérification post-action: Joindre les références app/Services/Hardware/EscPosPrinterService.php:16-36 et app/Services/Hardware/EscPosPrinterService.php:73-123.
  - Impact attendu: Décision humaine disponible si risque business.
- Sortie P0: ticket incident mis à jour avec heure UTC, `branch_id`, owner, et prochaine décision.
### 4.2 P1 — dégradé mais opérable
- Délai cible: ≤ 30 min.
- Action P1-1
  - Précondition: Une imprimante station est dégradée mais caisse opérable.
  - Action: Basculer la configuration `Printer` via l’admin existant selon permissions, ou utiliser fallback PDF/email terrain.
  - Vérification post-action: Test print ou tiroir confirmés après changement.
  - Impact attendu: Station rétablie ou contournement visible par ops.
- Action P1-2
  - Précondition: Panne imprimante ESC/POS / tiroir-caisse dégrade le service sans arrêt total.
  - Action: Maintenir le service avec surveillance renforcée.
  - Vérification post-action: Comparer les symptômes au fichier app/Services/Hardware/EscPosPrinterService.php:16-36.
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
  - Vérification post-action: Ancrer le ticket sur app/Services/Hardware/EscPosPrinterService.php:16-36.
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
- [ ] Preuve 1 reliée à `app/Services/Hardware/EscPosPrinterService.php:16-36` dans le ticket ou dashboard.
- [ ] Preuve 2 reliée à `app/Services/Hardware/EscPosPrinterService.php:73-123` dans le ticket ou dashboard.
- [ ] Preuve 3 reliée à `app/Services/Hardware/EscPosCommandBuilder.php:51-57` dans le ticket ou dashboard.
- [ ] Preuve 4 reliée à `app/Services/Hardware/PrinterTransport/TcpPrinterTransport.php:13-29` dans le ticket ou dashboard.
- [ ] Preuve 5 reliée à `app/Services/Hardware/PrinterTransport/NullPrinterTransport.php:5-22` dans le ticket ou dashboard.
- [ ] Preuve 6 reliée à `app/Services/Hardware/PrinterTransport/PrinterTransportInterface.php:5-13` dans le ticket ou dashboard.
- [ ] Preuve 7 reliée à `app/Models/Printer.php:16-35` dans le ticket ou dashboard.
- [ ] Preuve 8 reliée à `app/Http/Controllers/Admin/PrinterController.php:23-35` dans le ticket ou dashboard.
- [ ] Preuve 9 reliée à `app/Http/Controllers/Admin/PrinterController.php:77-84` dans le ticket ou dashboard.
- [ ] Preuve 10 reliée à `app/Http/Resources/PrinterResource.php:11-25` dans le ticket ou dashboard.

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
  - Toujours différencier TCP injoignable, NullPrinterTransport attendu, et panne périphérique physique.

## 8. Références
- `app/Services/Hardware/EscPosPrinterService.php:16-36`
- `app/Services/Hardware/EscPosPrinterService.php:73-123`
- `app/Services/Hardware/EscPosCommandBuilder.php:51-57`
- `app/Services/Hardware/PrinterTransport/TcpPrinterTransport.php:13-29`
- `app/Services/Hardware/PrinterTransport/NullPrinterTransport.php:5-22`
- `app/Services/Hardware/PrinterTransport/PrinterTransportInterface.php:5-13`
- `app/Models/Printer.php:16-35`
- `app/Http/Controllers/Admin/PrinterController.php:23-35`
- `app/Http/Controllers/Admin/PrinterController.php:77-84`
- `app/Http/Resources/PrinterResource.php:11-25`
- `app/Http/Requests/Admin/PrinterRequest.php:20-42`
- `app/Http/Controllers/Admin/Pos/CashDrawerController.php:19-33`
- `app/Providers/AppServiceProvider.php:30-36`
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

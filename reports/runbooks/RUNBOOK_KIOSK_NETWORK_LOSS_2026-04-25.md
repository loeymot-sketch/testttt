# RUNBOOK — Perte réseau kiosk / queue offline (2026-04-25)

- Status: DRAFT_SKELETON_NOT_SIGNED
- Owner (DRAFT): BE+FE
- Severity ceiling: P0
- Plan source: PLAN-20 (super master) / M-20 (masterplay)
- Linked gates: GATE_OFFLINE_SCOPE_V1
- Last reviewed: 2026-04-25 (initial skeleton)

## 1. Trigger
- Kiosk crée un `localKey` préfixé `offline_` au lieu d’un identifiant serveur.
- Écran waiting détecte `offline_` et suspend polling serveur.
- Trois échecs polling consécutifs activent bannière réseau.
- Heartbeat kiosk-event signale `sync_failed`, `hardware_error` ou offline lifecycle.
- Cache menu reste disponible mais peut être stale par branche.
- CB/TR offline tenté alors que gate V1 refuse tout contournement serveur.
- Trigger evidence 1: signal à corréler avec `resources/js/helpers/kioskOfflineQueue.js:134-145`.
- Trigger evidence 2: signal à corréler avec `resources/js/helpers/kioskOfflineQueue.js:327-338`.
- Trigger evidence 3: signal à corréler avec `resources/js/components/frontend/kiosk/KioskPaymentComponent.vue:292`.
- Trigger evidence 4: signal à corréler avec `resources/js/components/frontend/kiosk/KioskPaymentComponent.vue:297-305`.
- Trigger evidence 5: signal à corréler avec `resources/js/components/frontend/kiosk/KioskWaitingComponent.vue:195-198`.
- Trigger evidence 6: signal à corréler avec `resources/js/components/frontend/kiosk/KioskWaitingComponent.vue:258-305`.
- Trigger evidence 7: signal à corréler avec `resources/js/components/frontend/kiosk/KioskWaitingComponent.vue:388-392`.
- Trigger evidence 8: signal à corréler avec `app/Http/Controllers/Frontend/KioskEventController.php:13-44`.
- Détection attendue: alerte automatique, dashboard ops, journal structuré, ou ticket L1 horodaté UTC.
- Toute alerte cross-branch doit être découpée par `branch_id` avant action.
- Aucun seuil de gate n’est approuvé dans ce runbook; les gates restent `PENDING_HUMAN_GATE` quand cités.

## 2. Symptômes utilisateur / ops
- Client voit commande en synchronisation, pas de numéro serveur.
- Kiosk peut afficher panier/menu mais pas confirmer paiement CB/TR offline.
- Ops voit ActionLog kiosk-event sans progression backend.
- À reconnexion, replay peut réussir ou abandonner après tentatives.
- Caissier doit distinguer ID `offline_` et order id serveur.
- Côté ops: pic d’alertes, latence, erreurs récurrentes ou absence de progression dans le dashboard concerné.
- Côté audit: absence de preuve `branch_id` ou absence d’horodatage UTC rend la sortie non recevable.
- Côté produit: ne jamais compenser par une règle prix frontend ou un changement de statut hors `OrderStatus enum`.
- Côté coordination: si un autre agent travaille le même incident, un seul owner L2 tient le journal incident.

## 3. Diagnostic step-by-step
1. Identifier ID offline
   - Commande / observation: Observation UI kiosk; aucune commande dédiée.
   - Fichier:line à inspecter: `resources/js/helpers/kioskOfflineQueue.js:134-145`.
   - Décision de bifurcation: préfixe `offline_` = local only; ne jamais le traiter comme order id serveur.
   - Preuve à conserver: capture horodatée UTC + branch_id + extrait de journal sans PII.
   - Invariant à vérifier: branch_id local ne remplace pas serveur..
2. Confirmer création localKey
   - Commande / observation: Observation storage kiosk; aucune commande dédiée.
   - Fichier:line à inspecter: `resources/js/helpers/kioskOfflineQueue.js:327-338`.
   - Décision de bifurcation: si localKey absent, incident hors queue offline.
   - Preuve à conserver: capture horodatée UTC + branch_id + extrait de journal sans PII.
   - Invariant à vérifier: pas de confusion ID serveur..
3. Vérifier détection offline dans paiement
   - Commande / observation: Observation UI kiosk; aucune commande dédiée.
   - Fichier:line à inspecter: `resources/js/components/frontend/kiosk/KioskPaymentComponent.vue:292`.
   - Décision de bifurcation: si offline true, CB/TR offline reste refusé par gate.
   - Preuve à conserver: capture horodatée UTC + branch_id + extrait de journal sans PII.
   - Invariant à vérifier: backend pricing SSOT, fallback total UX seulement..
4. Contrôler fallback total UX
   - Commande / observation: Observation UI kiosk; aucune commande dédiée.
   - Fichier:line à inspecter: `resources/js/components/frontend/kiosk/KioskPaymentComponent.vue:297-305`.
   - Décision de bifurcation: montant local = affichage offline; ne jamais l’utiliser pour règlement serveur.
   - Preuve à conserver: capture horodatée UTC + branch_id + extrait de journal sans PII.
   - Invariant à vérifier: pricing backend SSOT..
5. Vérifier waiting offline
   - Commande / observation: Observation écran attente; aucune commande dédiée.
   - Fichier:line à inspecter: `resources/js/components/frontend/kiosk/KioskWaitingComponent.vue:195-198`.
   - Décision de bifurcation: offline order ne poll pas serveur; attendre reconciliation.
   - Preuve à conserver: capture horodatée UTC + branch_id + extrait de journal sans PII.
   - Invariant à vérifier: ne pas forcer status..
6. Mesurer polling réseau
   - Commande / observation: Observation réseau; aucune commande dédiée.
   - Fichier:line à inspecter: `resources/js/components/frontend/kiosk/KioskWaitingComponent.vue:258-305`.
   - Décision de bifurcation: 3 failures = networkLost P1/P0 selon impact caisse.
   - Preuve à conserver: capture horodatée UTC + branch_id + extrait de journal sans PII.
   - Invariant à vérifier: pas de retry agressif inventé..
7. Refuser confusion cancel offline
   - Commande / observation: Observation UI; aucune commande dédiée.
   - Fichier:line à inspecter: `resources/js/components/frontend/kiosk/KioskWaitingComponent.vue:388-392`.
   - Décision de bifurcation: cancel avec `offline_` ne doit pas devenir mutation serveur.
   - Preuve à conserver: capture horodatée UTC + branch_id + extrait de journal sans PII.
   - Invariant à vérifier: OrderStatus enum; pas de littéral nouveau..
8. Lire contrat kiosk-event
   - Commande / observation: Observation ActionLog; aucune commande dédiée.
   - Fichier:line à inspecter: `app/Http/Controllers/Frontend/KioskEventController.php:13-44`.
   - Décision de bifurcation: événement observabilité uniquement; pas de mutation business.
   - Preuve à conserver: capture horodatée UTC + branch_id + extrait de journal sans PII.
   - Invariant à vérifier: branch_id côté serveur ignoré depuis payload..
9. Qualifier cache menu catalogue
   - Commande / observation: Observation cache/logs; aucune commande dédiée.
   - Fichier:line à inspecter: `app/Listeners/InvalidateKioskMenuCacheOnCatalogChange.php:11-53`.
   - Décision de bifurcation: cache invalidation fail = P2/P1, pas preuve d’ordre perdu.
   - Preuve à conserver: capture horodatée UTC + branch_id + extrait de journal sans PII.
   - Invariant à vérifier: branch_id cache..
10. Qualifier disponibilité item
   - Commande / observation: Observation cache/logs; aucune commande dédiée.
   - Fichier:line à inspecter: `app/Listeners/InvalidateKioskMenuCacheOnItemAvailabilityChanged.php:43-78`.
   - Décision de bifurcation: stale menu peut expliquer 409 à reconnexion; ne pas forcer commande.
   - Preuve à conserver: capture horodatée UTC + branch_id + extrait de journal sans PII.
   - Invariant à vérifier: pas de panier serveur inventé..
- Diagnostic stop: si une étape exige une écriture hors `reports/runbooks/`, arrêter et escalader hors M-20.
- Diagnostic stop: si une étape demande une décision fiscale, ouvrir escalade humaine, pas de correction autonome.
- Diagnostic stop: si une étape révèle un gate non signé, documenter les options, ne pas choisir l’option.

## 4. Actions correctives par criticité
### 4.1 P0 — production caissière bloquée
- Délai cible: ≤ 5 min de réponse.
- Action P0-1
  - Précondition: CB/TR offline demandé par terrain.
  - Action: Refuser le contournement; basculer vers procédure autorisée par `GATE_OFFLINE_SCOPE_V1` sans paiement carte offline.
  - Vérification post-action: Ticket mentionne explicitement aucun CB/TR offline V1.
  - Impact attendu: Conformité gate conservée.
- Action P0-2
  - Précondition: Perte réseau kiosk / queue offline bloque encaissement ou préparation sur une branche.
  - Action: Basculer en procédure dégradée opérationnelle autorisée, sans modifier code ni données.
  - Vérification post-action: Comparer incident et ancrage resources/js/helpers/kioskOfflineQueue.js:134-145.
  - Impact attendu: Flux caisse stabilisé ou escalade L2 active.
- Action P0-3
  - Précondition: Signal P0 confirmé par deux sources ops.
  - Action: Geler les actions irréversibles côté équipe terrain.
  - Vérification post-action: Conserver captures dashboard et journal lié à resources/js/helpers/kioskOfflineQueue.js:327-338.
  - Impact attendu: Preuve intacte pour audit et post-mortem.
- Action P0-4
  - Précondition: Risque de double traitement ou perte de commande.
  - Action: Suspendre toute relance manuelle non documentée.
  - Vérification post-action: Vérifier que les actions restent dans les invariants listés en §8.
  - Impact attendu: Pas de duplication silencieuse.
- Action P0-5
  - Précondition: Une branche unique est touchée.
  - Action: Limiter le traitement et les communications à ce `branch_id`.
  - Vérification post-action: Vérifier la présence de `branch_id` dans resources/js/helpers/kioskOfflineQueue.js:134-145.
  - Impact attendu: Isolation multi-branches préservée.
- Action P0-6
  - Précondition: Le signal persiste après 5 minutes.
  - Action: Escalader L2 puis L3 selon matrice.
  - Vérification post-action: Joindre les références resources/js/helpers/kioskOfflineQueue.js:134-145 et resources/js/helpers/kioskOfflineQueue.js:327-338.
  - Impact attendu: Décision humaine disponible si risque business.
- Sortie P0: ticket incident mis à jour avec heure UTC, `branch_id`, owner, et prochaine décision.
### 4.2 P1 — dégradé mais opérable
- Délai cible: ≤ 30 min.
- Action P1-1
  - Précondition: Perte réseau kiosk / queue offline dégrade le service sans arrêt total.
  - Action: Maintenir le service avec surveillance renforcée.
  - Vérification post-action: Comparer les symptômes au fichier resources/js/helpers/kioskOfflineQueue.js:134-145.
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
  - Vérification post-action: Ancrer le ticket sur resources/js/helpers/kioskOfflineQueue.js:134-145.
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
- [ ] Preuve 1 reliée à `resources/js/helpers/kioskOfflineQueue.js:134-145` dans le ticket ou dashboard.
- [ ] Preuve 2 reliée à `resources/js/helpers/kioskOfflineQueue.js:327-338` dans le ticket ou dashboard.
- [ ] Preuve 3 reliée à `resources/js/components/frontend/kiosk/KioskPaymentComponent.vue:292` dans le ticket ou dashboard.
- [ ] Preuve 4 reliée à `resources/js/components/frontend/kiosk/KioskPaymentComponent.vue:297-305` dans le ticket ou dashboard.
- [ ] Preuve 5 reliée à `resources/js/components/frontend/kiosk/KioskWaitingComponent.vue:195-198` dans le ticket ou dashboard.
- [ ] Preuve 6 reliée à `resources/js/components/frontend/kiosk/KioskWaitingComponent.vue:258-305` dans le ticket ou dashboard.
- [ ] Preuve 7 reliée à `resources/js/components/frontend/kiosk/KioskWaitingComponent.vue:388-392` dans le ticket ou dashboard.
- [ ] Preuve 8 reliée à `app/Http/Controllers/Frontend/KioskEventController.php:13-44` dans le ticket ou dashboard.
- [ ] Preuve 9 reliée à `app/Http/Controllers/Frontend/KioskEventController.php:54-83` dans le ticket ou dashboard.
- [ ] Preuve 10 reliée à `app/Listeners/InvalidateKioskMenuCacheOnCatalogChange.php:11-53` dans le ticket ou dashboard.

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
  - Tout ID `offline_` est local; la reconciliation seule peut produire un id serveur.

## 8. Références
- `resources/js/helpers/kioskOfflineQueue.js:134-145`
- `resources/js/helpers/kioskOfflineQueue.js:327-338`
- `resources/js/components/frontend/kiosk/KioskPaymentComponent.vue:292`
- `resources/js/components/frontend/kiosk/KioskPaymentComponent.vue:297-305`
- `resources/js/components/frontend/kiosk/KioskWaitingComponent.vue:195-198`
- `resources/js/components/frontend/kiosk/KioskWaitingComponent.vue:258-305`
- `resources/js/components/frontend/kiosk/KioskWaitingComponent.vue:388-392`
- `app/Http/Controllers/Frontend/KioskEventController.php:13-44`
- `app/Http/Controllers/Frontend/KioskEventController.php:54-83`
- `app/Listeners/InvalidateKioskMenuCacheOnCatalogChange.php:11-53`
- `app/Listeners/InvalidateKioskMenuCacheOnItemAvailabilityChanged.php:43-78`
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

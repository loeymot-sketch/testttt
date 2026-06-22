J'ai assez de contexte (allowlist, services réels, plan parent). Je rédige le brief.

# EXECUTE BRIEF — CV1-M20-RUNBOOKS-SKELETON (M-20)

## INVIOLABLE

1. Lectures obligatoires **dans cet ordre** :
   - `AGENTS.md` (parcours obligatoire FoodKing — section *Authoritative multi-agent bounded cycle*)
   - `missions/CV1-M20-RUNBOOKS-SKELETON/input.json` (allowlist + off_limits — autorité)
   - `plans/PLAN_CAISSE_V1_GPT_MASTERPLAY_2026-04-25.md` §0 (doctrine), §2 (cartographie file:line), §4 mission **M-20** + §4 mission **M-15** (flags canary)
   - `plans/PLAN_CAISSE_V1_SUPER_MASTER_2026-04-25.md` §3 (gates), §4 (PLAN-13/14/15/20), §7 (PLAN-15 rollout)
   - `plans/masterplay/MASTERPLAY_DISCIPLINE.md` §3 (garde-fous)
   - `.cursor/rules/project-invariants.mdc` (6 invariants — cités sans être violés)
2. **Allowlist stricte — UNIQUEMENT ces 9 chemins** (NEW tous) :
   - `reports/runbooks/RUNBOOK_TPE_FAILURE_2026-04-25.md`
   - `reports/runbooks/RUNBOOK_PRINTER_FAILURE_2026-04-25.md`
   - `reports/runbooks/RUNBOOK_KIOSK_NETWORK_LOSS_2026-04-25.md`
   - `reports/runbooks/RUNBOOK_DISPATCH_QUEUE_SATURATED_2026-04-25.md`
   - `reports/runbooks/RUNBOOK_OUTBOX_BLOCKED_2026-04-25.md`
   - `reports/runbooks/RUNBOOK_FISCAL_SEQUENCE_BREAK_2026-04-25.md`
   - `reports/runbooks/RUNBOOK_KDS_MULTISCREEN_DESYNC_2026-04-25.md`
   - `reports/runbooks/RUNBOOK_ROLLBACK_CANARY_2026-04-25.md`
   - `reports/runbooks/RUNBOOK_INDEX_2026-04-25.md`
3. **Off-limits absolu** (cf. `input.json.off_limits`) : `app/**`, `resources/**`, `routes/**`, `database/**`, `tests/**`, `scripts/**`, `config/**`, `.cursor/**`, `AGENTS.md`. Toute écriture hors `reports/runbooks/` ⇒ `risks: ["SCOPE_PRESSURE: <path>"]` + STOP.
4. **Aucune signature de gate**. Tu peux **citer** un gate (`GATE_FISCAL_KIOSK_V1`, etc.) ; jamais cocher `[x] Approved`. Aucun runbook ne porte de décision GO/NO-GO — il documente la procédure technique.
5. **Aucun code**. Pas de PHP, pas de JS, pas de SQL exécutable. Snippets shell **autorisés uniquement** s'ils citent un script déjà existant (`php artisan foodking:outbox:rescue`, `php artisan app:preflight-production`) — jamais d'invention de commande.

## OBJECTIF EXACT

Produire **8 runbooks ops + 1 index** sous `reports/runbooks/` qui décrivent, pour chaque incident critique Caisse V1, : (a) **trigger machine-détectable**, (b) **symptômes observables** côté ops/utilisateur, (c) **diagnostic step-by-step** ancré sur services/jobs/commandes FoodKing **réels** (file:line obligatoire), (d) **actions correctives par criticité P0/P1/P2**, (e) **escalation matrix** (rôle, délai, canal), (f) **template post-mortem** réutilisable. Aucune invention : tout pointeur code doit exister actuellement dans le repo. L'index liste les 8 runbooks avec colonne "quand utiliser" et "first responder".

## CARTOGRAPHIE PRÉ-ANALYSÉE (file:line vérifiés — utilise-la)

### TPE / paiement kiosk (RUNBOOK_TPE_FAILURE)
- Bridge HW front : `resources/js/components/frontend/kiosk/KioskPaymentComponent.vue:393-414` (CB/TR), `_invokeTpe` L473-501.
- Confirm backend : `app/Http/Controllers/Frontend/OrderController.php:77-151` (`paymentConfirm`), `app/Services/FrontendOrderService.php:791` (`finalizePaidKioskOrder`).
- Cleanup race : `app/Jobs/CleanupStalePendingKioskOrders.php`.
- Audit fiscal : `app/Services/Fiscal/AuditLogService.php`.
- Enum gateway : `app/Enums/PaymentGateway.php`.

### Printer ESC/POS (RUNBOOK_PRINTER_FAILURE)
- Service : `app/Services/Hardware/EscPosPrinterService.php`, `app/Services/Hardware/EscPosCommandBuilder.php`.
- Transports : `app/Services/Hardware/PrinterTransport/TcpPrinterTransport.php`, `NullPrinterTransport.php`, interface `PrinterTransportInterface.php`.
- Modèle : `app/Models/Printer.php`. Admin CRUD : `app/Http/Controllers/Admin/PrinterController.php`, `app/Http/Resources/PrinterResource.php`, `app/Http/Requests/Admin/PrinterRequest.php`.
- Tiroir-caisse : `app/Http/Controllers/Admin/Pos/CashDrawerController.php`. Boot : `app/Providers/AppServiceProvider.php`.

### Kiosk network loss (RUNBOOK_KIOSK_NETWORK_LOSS)
- Queue offline : `resources/js/helpers/kioskOfflineQueue.js:135,330` (prefix `offline_`).
- Détection : `resources/js/components/frontend/kiosk/KioskPaymentComponent.vue:292`, fallback total L297-305.
- Polling/cancel : `resources/js/components/frontend/kiosk/KioskWaitingComponent.vue:195-198,258-305,392`.
- Heartbeat backend : `app/Http/Controllers/Frontend/KioskEventController.php`.
- Cache menu : `app/Listeners/InvalidateKioskMenuCacheOnCatalogChange.php`, `InvalidateKioskMenuCacheOnItemAvailabilityChanged.php`.
- Gate associé : `GATE_OFFLINE_SCOPE_V1` (refus CB/TR offline V1).

### Dispatch queue saturated (RUNBOOK_DISPATCH_QUEUE_SATURATED)
- Worker : `app/Jobs/DispatchDomainEventsJob.php` (lignes-clés L62-89 idempotency, L154 envelope check, L177-208 final failure).
- Jobs voisins : `app/Jobs/CleanupStalePendingKioskOrders.php`, `app/Jobs/SendFcmNotificationJob.php`.
- Config : `config/queue.php`. Preflight : `app/Console/Commands/PreflightProductionCommand.php`.
- Métriques : `app/Services/Observability/SyncMetricsRecorder.php`, `app/Http/Controllers/Admin/Observability/SyncOverviewController.php`.

### Outbox blocked (RUNBOOK_OUTBOX_BLOCKED)
- Modèle : `app/Models/DomainEvent.php` (scope `stale`).
- Persistance : `app/Listeners/PersistOrderCreatedToOutbox.php`, `PersistOrderStatusChangedToOutbox.php`, `PersistOrderTableChangedToOutbox.php`, `PersistItemAvailabilityChangedToOutbox.php`.
- Commandes ops : `app/Console/Commands/OutboxRescueCommand.php` (`foodking:outbox:rescue`, attempts<5, stale 2 min) et `app/Console/Commands/OutboxRetryFailedCommand.php`.
- Contract : `app/Domain/Events/EventContract.php`. After-commit : `app/Events/Concerns/DispatchableAfterCommit.php`.

### Fiscal sequence break (RUNBOOK_FISCAL_SEQUENCE_BREAK)
- Séquence : `app/Services/Fiscal/FiscalSequenceService.php`. Audit : `app/Services/Fiscal/AuditLogService.php`.
- Z/X reports : `app/Services/Fiscal/ZReportService.php`, `XReportService.php`. Archive : `app/Console/Commands/FiscalArchiveCommand.php`. Config : `config/fiscal.php`.
- Référence backend : `app/Services/OrderService.php`, `app/Services/PaymentService.php` (consommateurs séquence).
- Gate : `GATE_FISCAL_KIOSK_V1` (politique kiosk paid). Escalade NF525 : humain obligatoire (CLAUDE.md §8).

### KDS multi-screen desync (RUNBOOK_KDS_MULTISCREEN_DESYNC)
- Service : `app/Services/KitchenDisplaySystemOrderService.php:53-54` (filtre statuts), `:117-168` (changeStatus + lock + transition).
- Request : `app/Http/Requests/OrderStatusRequest.php:15-35,45-47` (manque `expected_status` body — gate `GATE_KDS_BUMP_V1`).
- Front : `resources/js/components/admin/kitchenDisplaySystem/KitchenDisplaySystemComponent.vue:130` (Swiper), L786-793 (cap 50).
- Transition log : `app/Models/OrderStatusTransition.php`.

### Rollback canary (RUNBOOK_ROLLBACK_CANARY)
- Flags M-15 (cf. masterplay §4 M-15) : `payment_ledger_v1`, `pos_revenue_guards`, `kds_strict_release`, `quote_v1`, `fiscal_z_v1`, `kiosk_offline_strict`. Lieu d'implémentation à venir → **citer comme à créer** (ne pas inventer chemin de code).
- Predicates : `payment_success_rate < 95% / 5min`, `fiscal_anomaly > 0`, `kds_error_rate > 5%` (super master §3 PLAN-15).
- Preflight prod : `app/Console/Commands/PreflightProductionCommand.php` (CRITICAL/WARNING).
- Build legacy / cutover : voir M-12 (legacy guards). Migrations down : voir M-13 (`MIGRATIONS_*` runbooks à venir, NE PAS dupliquer).

## SPÉCIFICATION DÉTAILLÉE — STRUCTURE COMMUNE PAR RUNBOOK

Chaque fichier `reports/runbooks/RUNBOOK_<NAME>_2026-04-25.md` contient **exactement** ces sections, dans cet ordre, avec ce balisage :

1. `# RUNBOOK — <Titre humain>` (titre H1 unique, datage `2026-04-25`).
2. Bandeau métadonnées (liste à puces) :
   - `Status: DRAFT_SKELETON_NOT_SIGNED`
   - `Owner (DRAFT): <BE | DevOps | Ops | BE+FE | DBA | NF525-QA>` — **proposition**, non engagement.
   - `Severity ceiling: P0`
   - `Plan source: PLAN-20 (super master) / M-20 (masterplay)`
   - `Linked gates: <liste gates pertinents ou "(none)">`
   - `Last reviewed: 2026-04-25 (initial skeleton)`
3. `## 1. Trigger` — conditions **observables**, machine-détectables (alertes Grafana / Horizon / log lines), avec exemples de pattern (ex : `category=queue.dispatch_domain_events.failed` → cf. `DispatchDomainEventsJob.php:216`).
4. `## 2. Symptômes utilisateur / ops` — vues côté caissier, kiosk, KDS, ops dashboard.
5. `## 3. Diagnostic step-by-step` — liste numérotée 5–10 étapes, chacune avec : commande à lancer (existante uniquement), fichier:line à inspecter, décision de bifurcation. Aucune invention.
6. `## 4. Actions correctives par criticité` — 3 sous-sections :
   - `### 4.1 P0 — production caissière bloquée` (≤ 5 min de réponse)
   - `### 4.2 P1 — dégradé mais opérable` (≤ 30 min)
   - `### 4.3 P2 — anomalie collectée pour post-mortem` (≤ 24 h)
   Chaque action : précondition / action / vérification post-action / impact attendu.
7. `## 5. Escalation matrix` — table Markdown : `| Niveau | Trigger | Rôle | Canal | Délai max | Action irréversible? |`. Au minimum 3 niveaux (L1 ops, L2 BE/DevOps oncall, L3 humain CTO/NF525). Pour `RUNBOOK_FISCAL_SEQUENCE_BREAK` → ajouter ligne **L4 NF525 / Conseil** obligatoire.
8. `## 6. Vérifications de sortie` — checklist `[ ]` reproductible : preuves attendues (logs, dashboards, tickets fermés, séquence fiscale recalée).
9. `## 7. Template post-mortem` — sections fixes : *Timeline UTC*, *Impact (commandes, revenue, fiscal, branches)*, *Cause racine*, *Détection (auto/manuelle/délai)*, *Réponse (ce qui a marché / pas marché)*, *Actions correctives (P0/P1/P2 + propriétaire + deadline)*, *Liens incidents passés*.
10. `## 8. Références` — liste explicite des `file:line` cités, des gates concernés, des plans/missions liés (`PLAN-XX`, `M-XX`).

### Particularités par runbook

- **TPE_FAILURE** : section §3 doit distinguer (a) bridge HW timeout (front, retry ×3 KioskPaymentComponent), (b) `paymentConfirm` 401/403/422 backend, (c) cleanup tardif vs confirm tardif (cf. M-06 cleanup race). Gate à citer : `GATE_PAYMENT_LEDGER_V1`, `GATE_VERIFY_P0_FROZEN_CONSOLIDATED_2026-04-20`.
- **PRINTER_FAILURE** : différencier `TcpPrinterTransport` injoignable vs `NullPrinterTransport` (mode dégradé), file d'attente ESC/POS, fallback PDF/email. Action P1 : basculer transport via `Printer` model. Vérifier aussi tiroir (`CashDrawerController`).
- **KIOSK_NETWORK_LOSS** : §3 doit traiter (a) détection `online`/`offline` côté SW, (b) prefix `offline_` (interdit toute confusion ID serveur), (c) refus serveur CB/TR offline (gate `GATE_OFFLINE_SCOPE_V1` option A par défaut), (d) reconciliation à reconnexion. **Aucune** instruction qui contournerait le refus serveur.
- **DISPATCH_QUEUE_SATURATED** : utiliser `php artisan horizon:status`, `php artisan queue:failed`, `app:preflight-production`. Différencier saturation worker vs failed jobs vs lock contention. P1 inclut scaling worker (sans toucher code).
- **OUTBOX_BLOCKED** : §3 doit lister `php artisan foodking:outbox:rescue` (max 5 attempts), `OutboxRetryFailedCommand`, requête de comptage `DomainEvent` stale par status, dashboard `SyncOverviewController`. P0 : pas d'écriture brute en DB sans approbation humaine.
- **FISCAL_SEQUENCE_BREAK** : sequencing irréversible → §4.1 = **freeze caisse + escalade L4 immédiate**. Aucun runbook ne propose de "patcher" la séquence. Citer `FiscalArchiveCommand`, `AuditLogService`, `config/fiscal.php`. NF525 evidence à conserver.
- **KDS_MULTISCREEN_DESYNC** : §3 doit identifier conflit bump 2 écrans (manque `expected_status` body — gate `GATE_KDS_BUMP_V1`). Action P1 : recharger l'écran perdant, P2 : recompter `OrderStatusTransition`. **Aucune** action qui réécrit transitions.
- **ROLLBACK_CANARY** : §3 doit nommer **chaque flag M-15** + sa cible de rollback (front bundle, backend service, migration down). §4.1 : ordre d'extinction (paiement → fiscal → KDS → kiosk offline). Citer `PreflightProductionCommand` pour validation post-rollback. **NE PAS** rédiger les runbooks de migrations (réservés à M-13).

### Index `RUNBOOK_INDEX_2026-04-25.md`

- `# RUNBOOKS CAISSE V1 — INDEX (2026-04-25)`.
- Section `## 0. Statut` : `INDEX_STATUS: DRAFT_SKELETON`, lien `MASTERPLAY M-20`.
- Section `## 1. Carte de décision` : table `| Symptôme initial | Runbook | First responder | Severity ceiling |` (8 lignes, une par runbook).
- Section `## 2. Liens transverses` : matrice `| Runbook | Plans liés | Gates liés | Métriques clés |`.
- Section `## 3. Procédure d'usage` : 5 étapes (alerte → choix runbook → exécution diagnostic → action selon criticité → post-mortem template).
- Section `## 4. Maintenance` : qui revoit / cadence / déclencheur de mise à jour (changement file:line ⇒ MAJ obligatoire).

## RÈGLES DE QUALITÉ

1. **Aucune ligne de code produit**. Snippets shell autorisés **uniquement** sur scripts/commandes existants (cf. cartographie ci-dessus). Aucune commande inventée.
2. **Chaque diagnostic étape ⇒ ancrage `file:line`** parmi la cartographie. Si tu cites un fichier hors cartographie, tu **dois** l'avoir lu (Read) et tu cites la ligne réelle ; sinon tu n'écris pas la ligne.
3. **Aucun gate signé**. Tu peux écrire `cf. GATE_XXX (PENDING_HUMAN_GATE)` ; jamais "Approved", jamais "validé".
4. **Aucune décision NF525** : pour fiscal, escalade humaine **obligatoire** dès §4.1.
5. **Diff minimal** : 9 fichiers créés, zéro modification ailleurs. Pas de `README` annexe, pas de "while I'm here".
6. **Format Markdown** : H1 unique, H2 numérotés `## 1.` à `## 8.`, tables Markdown valides, listes `-`, encodage UTF-8 sans BOM, fin de fichier `\n`.
7. **Date figée** : `2026-04-25` partout. Pas de date relative ("today", "demain").
8. **Cohérence vocab** : `OrderStatus enum`, `branch_id` strict, `dispatch after commit`, `frozen zones` — usage conforme aux invariants même en prose.
9. **Densité** : chaque runbook 250–500 lignes Markdown ; index 80–150 lignes. Pas de blabla.
10. **Aucune mention de fichiers inexistants** : avant de citer un chemin, garantir qu'il existe (cartographie ci-dessus = whitelist).

## LIVRABLES dans `output_codex.json`

```json
{
  "files_to_modify": [
    "reports/runbooks/RUNBOOK_TPE_FAILURE_2026-04-25.md",
    "reports/runbooks/RUNBOOK_PRINTER_FAILURE_2026-04-25.md",
    "reports/runbooks/RUNBOOK_KIOSK_NETWORK_LOSS_2026-04-25.md",
    "reports/runbooks/RUNBOOK_DISPATCH_QUEUE_SATURATED_2026-04-25.md",
    "reports/runbooks/RUNBOOK_OUTBOX_BLOCKED_2026-04-25.md",
    "reports/runbooks/RUNBOOK_FISCAL_SEQUENCE_BREAK_2026-04-25.md",
    "reports/runbooks/RUNBOOK_KDS_MULTISCREEN_DESYNC_2026-04-25.md",
    "reports/runbooks/RUNBOOK_ROLLBACK_CANARY_2026-04-25.md",
    "reports/runbooks/RUNBOOK_INDEX_2026-04-25.md"
  ],
  "code_blocks": [
    { "path": "reports/runbooks/RUNBOOK_TPE_FAILURE_2026-04-25.md", "op": "create", "excerpt": "<contenu complet>" },
    { "path": "reports/runbooks/RUNBOOK_PRINTER_FAILURE_2026-04-25.md", "op": "create", "excerpt": "<contenu complet>" },
    { "path": "reports/runbooks/RUNBOOK_KIOSK_NETWORK_LOSS_2026-04-25.md", "op": "create", "excerpt": "<contenu complet>" },
    { "path": "reports/runbooks/RUNBOOK_DISPATCH_QUEUE_SATURATED_2026-04-25.md", "op": "create", "excerpt": "<contenu complet>" },
    { "path": "reports/runbooks/RUNBOOK_OUTBOX_BLOCKED_2026-04-25.md", "op": "create", "excerpt": "<contenu complet>" },
    { "path": "reports/runbooks/RUNBOOK_FISCAL_SEQUENCE_BREAK_2026-04-25.md", "op": "create", "excerpt": "<contenu complet>" },
    { "path": "reports/runbooks/RUNBOOK_KDS_MULTISCREEN_DESYNC_2026-04-25.md", "op": "create", "excerpt": "<contenu complet>" },
    { "path": "reports/runbooks/RUNBOOK_ROLLBACK_CANARY_2026-04-25.md", "op": "create", "excerpt": "<contenu complet>" },
    { "path": "reports/runbooks/RUNBOOK_INDEX_2026-04-25.md", "op": "create", "excerpt": "<index complet>" }
  ],
  "risks": [],
  "notes": "8 runbooks + index créés ; tous DRAFT_SKELETON_NOT_SIGNED ; ancrages file:line conformes cartographie M-20 ; aucun code modifié ; aucun gate signé.",
  "execution_trace": {
    "delegation": "codex-extension",
    "invariants_considered": [
      "pricing-ssot",
      "order-status-enum",
      "branch-id-isolation",
      "dispatch-after-commit",
      "os-fos-symmetry",
      "frozen-zones"
    ]
  }
}
```

`GPT_SELF_AUDIT_CV1-M20-RUNBOOKS-SKELETON.md` doit cocher chaque item de `self_audit_checklist` avec evidence (chemin du runbook + ligne où la preuve apparaît).

## INTERDITS

- Toucher tout fichier hors les 9 chemins de l'allowlist (en particulier : `app/**`, `resources/**`, `routes/**`, `database/**`, `tests/**`, `scripts/**`, `config/**`, `.cursor/**`, `AGENTS.md`).
- Approuver, signer, ou marquer "validated" un quelconque gate.
- Inventer un chemin de fichier, une commande artisan, un nom de service, une métrique, une route HTTP.
- Dupliquer le contenu d'`input.json` dans le brief ou dans un runbook.
- Décider une politique fiscale ou un seuil de rollout : ces décisions sont humaines (CLAUDE.md §8 + super master §3).
- Réécrire un runbook géré ailleurs (migrations DB → M-13 / `MIGRATIONS_*`, NE PAS empiéter).
- Ajouter une signature `Co-Authored-By` ou un changelog dans les runbooks (skeleton seulement).
- Modifier le statut `INDEX_STATUS` ou `Status:` au-delà de `DRAFT_SKELETON_*`.
- Faire un `git add` ou un commit (la mission ne le demande pas).

## SI BLOCAGE

- **Fichier référence introuvable** (chemin de la cartographie absent du repo au moment de l'écriture) : retire la ligne de diagnostic concernée, ajoute `risks: ["EVIDENCE_MISSING: <path> attendu par RUNBOOK_<X>"]`, continue les autres runbooks.
- **Ambiguïté de criticité** (incertain entre P0 et P1) : retiens **P0** (principe FoodKing : *blocked > silently dangerous*) et note la décision dans la section §4 du runbook concerné.
- **Conflit avec un gate non signé** : ne propose **aucune** action qui présume l'option A/B/C choisie ; documente les 2-3 options possibles en §4 et marque `Linked gates:` en tête.
- **Doute sur un identifiant de service / job** : grep dans `app/` (lecture seule) avant d'écrire ; si toujours ambigu, ajoute `risks: ["AMBIGUITY: <symbol> — clarification humaine"]` et omets le pointeur plutôt que d'inventer.
- **Allowlist contredite par une nécessité technique** (ex : besoin d'ajouter un script ops) : **NE PAS** étendre l'allowlist. Émet `risks: ["SCOPE_PRESSURE: <reason>"]` et stoppe la mission ; la décision d'élargissement appartient au cycle Claude/humain.
.md", "op": "create", "excerpt": "<markdown complet>" },
    { "path": "reports/runbooks/RUNBOOK_DISPATCH_QUEUE_SATURATED_2026-04-25.md", "op": "create", "excerpt": "<markdown complet>" },
    { "path": "reports/runbooks/RUNBOOK_OUTBOX_BLOCKED_2026-04-25.md", "op": "create", "excerpt": "<markdown complet>" },
    { "path": "reports/runbooks/RUNBOOK_FISCAL_SEQUENCE_BREAK_2026-04-25.md", "op": "create", "excerpt": "<markdown complet>" },
    { "path": "reports/runbooks/RUNBOOK_KDS_MULTISCREEN_DESYNC_2026-04-25.md", "op": "create", "excerpt": "<markdown complet>" },
    { "path": "reports/runbooks/RUNBOOK_ROLLBACK_CANARY_2026-04-25.md", "op": "create", "excerpt": "<markdown complet>" },
    { "path": "reports/runbooks/RUNBOOK_INDEX_2026-04-25.md", "op": "create", "excerpt": "<markdown complet>" }
  ],
  "risks": [],
  "notes": "8 runbooks + index — totaux: <N> lignes, <K> file:line cités, gates référencés: <liste>. Aucun fichier produit modifié.",
  "execution_trace": {
    "delegation": "codex-extension",
    "invariants_considered": [
      "1-Backend SSOT pricing (TPE / Stripe)",
      "2-OrderStatus enum authoritative (KDS desync, fiscal)",
      "3-branch_id isolation (fiscal seq, KDS sync, outbox)",
      "4-Dispatch after commit (outbox blocked, dispatch saturated)",
      "5-OrderService/FrontendOrderService symmetry (TPE confirm, kiosk recovery)",
      "6-Frozen zones (rollback canary)"
    ]
  }
}
```

`notes` doit chiffrer : nombre de lignes Markdown par runbook, nombre total de `file:line` cités (≥ 25 attendus globalement), gates référencés. Aucun champ inventé hors squelette.

## INTERDITS
- Toucher quoi que ce soit hors allowlist — y compris créer un `CHANGELOG.md`, un `README.md`, un fichier `.gitkeep`. Si le besoin émerge → `risks: ["SCOPE_PRESSURE: ..."]` et stop.
- Modifier `app/`, `resources/`, `routes/`, `database/`, `tests/`, `scripts/`, `config/`, `.cursor/`, `AGENTS.md`, `plans/PLAN_CAISSE_V1_*`.
- Cocher un gate `[x] Approved`, écrire `STATUS: APPROVED` ou équivalent.
- Inventer un service, un job, une commande artisan, une route. Tout chemin / signature cité doit être vérifiable dans le repo.
- Inclure du code exécutable (PHP, JS, bash compilable, SQL DML). Les commandes mentionnées sont en backtick inline, pas en bloc shebang.
- Décrire des manœuvres business / RH / commerciales : tu opères au plan technique-ops uniquement, le reste = escalation L2/L3/L4.
- Dupliquer le contenu d'`input.json` ou des plans dans les runbooks.
- Utiliser des phrases d'introduction du type « Ce runbook décrit… ». Aller direct au trigger.
- Suggérer un rollback `git push --force` ou un `migrate:fresh` : interdit, escalation humaine obligatoire.

## SI BLOCAGE
- Service cité absent du repo (ex: pas de `FeatureFlagService` central pour `RUNBOOK_ROLLBACK_CANARY`) → documenter la **réalité** (settings dispersés, env, build legacy) et lever `risks: ["DOC_GAP: rollback canary ne peut pas s'appuyer sur un FeatureFlag service centralisé — mention OPS_DEBT à porter en M-21+"]`. Ne pas inventer de service.
- Endpoint cité dans la cartographie introuvable → lire le fichier, ajuster la citation, sinon `risks: ["CARTO_DRIFT: <chemin> non vérifiable"]` et omettre la citation dans le runbook concerné.
- Ambiguïté de criticité (P0 vs P1) → choisir la sévérité la **plus haute** + noter dans `notes` du JSON.
- Conflit avec un invariant `.cursor/rules/project-invariants.mdc` (ex: une action proposée violerait l'invariant 4 dispatch-after-commit) → reformuler l'action pour respecter l'invariant et noter dans `## 8. Invariants applicables` du runbook.
- Fichier d'allowlist déjà existant (improbable, `reports/runbooks/` actuellement vide) → `op: "create"` quand même = remplacement complet ; ne pas merger silencieusement avec un éventuel contenu antérieur sans le citer dans `notes`.
- Doute sur format escalation matrix / post-mortem template (manque référence interne FoodKing) → utiliser le squelette défini en section « SPÉCIFICATION DÉTAILLÉE » de ce brief, ne pas inventer un autre format.
- Self-audit checklist `input.json.self_audit_checklist` non satisfait à la fin → ne **pas** retourner le JSON ; recommencer le runbook concerné.

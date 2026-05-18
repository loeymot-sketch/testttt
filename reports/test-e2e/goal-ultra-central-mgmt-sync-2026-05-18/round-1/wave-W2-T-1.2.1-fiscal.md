# Wave W2 — Task T-1.2.1 — Fiscal Specialist Audit (NF525)

**Mission**: End-to-end NF525 chain attestation under load — DGFiP-auditor mindset
**Mode**: READ-ONLY
**Date**: 2026-05-18
**Auditor**: Fiscal Specialist (DGFiP / expert-comptable perspective)
**Scope**: `app/Services/Fiscal/*`, `app/Console/Commands/{FiscalArchive,PruneOutbox,RetryFiscalAlloc}Command.php`, `config/fiscal.php`, migrations `audit_logs`/`z_reports`, deploy docs

---

## Executive verdict

**Status**: AMBER — Cryptographic core is solid (HMAC chain, INSERT-only triggers, dual lock + DB unique). However, **3 P0 compliance gaps** would be caught by a DGFiP control: missing JET XML export, no TRUNCATE/REVOKE GRANT documentation, and absent SQL-level immutability on `composition_snapshot`. **2 P1 risks** exist around chronological gap in retry-alloc and per-branch timezone for Z boundary.

**DGFiP audit-readiness today**: ~70%. A controller reading the DB cold could verify the chain (good) but the merchant cannot produce a JET XML export on demand (red flag — `art. L102 B LPF`), and the sysadmin could TRUNCATE `audit_logs` without violating any documented guard (red flag — falsification présumée).

---

## Findings (strong-reasoning YAML)

### F-FISC-001 — JET XML DGFiP export DEFERRED (compliance P0)

```yaml
id: F-FISC-001
severity: P0
class: compliance
title: "Export JET XML DGFiP volontairement reporté — pas de format certifié sortable"
evidence:
  - file: docs/gates/GATE_P_MEGA_22_NF525_READINESS_2026-04-20.md
    lines: "22, 131-144"
    snippet: |
      "P4 | Export JET XML DGFiP | CRITIQUE (spec TBD) | ❌ DEFER"
      "Spec officielle JET (logiciel de caisse certifié) NON identifiée"
      "Recommandation: B. DEFER P4 jusqu'à acquisition d'une spec contractuelle"
  - file: docs/orchestration/MEGA_CHECKLIST_AUTONOMY_AND_MEMORY.md
    lines: "222"
    snippet: "K08 — JET XML DGFiP (spec officielle — cycle différé)"
  - file: app/Console/Commands/FiscalArchiveCommand.php
    lines: "228-273"
    snippet: "JSON streaming z_reports.json / orders.json / audit_logs.json — NOT JET XML"
reasoning: |
  NF525 (BOI-TVA-DECLA-30-10-30) impose qu'un logiciel de caisse certifié
  produise un export structuré sur demande de l'administration fiscale lors
  d'un contrôle inopiné (article L102 B LPF, 6 ans retention). La spec JET
  (Journal d'Évènements Transactionnel) est l'extension métier du FEC pour
  les points de vente. FoodKing produit aujourd'hui un bundle ZIP JSON
  custom (manifest v3 + z_reports + orders + audit_logs), qui :
   (a) n'est pas un format reconnu par la DGFiP — non-conforme article 286-I-3°bis CGI ;
   (b) n'a pas de schéma XSD validable par les outils d'audit (ATC);
   (c) ne respecte pas la nomenclature champs JET attendue.
  Conséquence en cas de contrôle : l'agent demande "envoyez-nous le JET sur
  clé USB" — FoodKing répond "on a un ZIP JSON" — l'agent peut requalifier
  en "défaut de présentation" (amende 5000€ par exercice, art. 1729 D CGI).
impact: "DGFiP audit fail — amende fiscale + obligation rétroactive de fournir, risque cumulé 5000€/an × N exercices."
recommendation: |
  Phase D : statuer sur la stratégie JET avec un expert-comptable :
   (a) acquérir une spec JET contractuelle (LNE / organisme certif) ;
   (b) OU obtenir une attestation alternative que le format actuel est
       jugé suffisant (peu probable post-certification renforcée 2018) ;
   (c) OU produire un converter `FiscalJetExportCommand` qui mappe le
       bundle existant vers JET XML quand la spec est disponible.
  Document de référence à publier : `docs/cloud/NF525_JET_EXPORT_PLAN.md`.
  Court-terme : le bundle ZIP actuel reste une evidence valide INTERNE
  (chain integrity + 6y retention) mais NE PAS prétendre à la DGFiP qu'il
  est conforme JET sans confirmation expert.
audit_question: |
  "Monsieur, présentez-nous le journal JET de l'exercice 2025." → FoodKing
  ne peut produire qu'un ZIP JSON propriétaire. Strict point de contrôle.
```

### F-FISC-002 — TRUNCATE non revoqué au niveau DB GRANT (compliance P0)

```yaml
id: F-FISC-002
severity: P0
class: compliance
title: "Pas de GRANT/REVOKE documenté pour bloquer TRUNCATE sur audit_logs / z_reports"
evidence:
  - file: database/migrations/2026_05_09_160000_add_z_reports_delete_trigger_immutability.php
    lines: "29-34"
    snippet: |
      "TRUNCATE bypasses MySQL triggers. Mitigation = revoke
       TRUNCATE permission on production DB user (deploy doc, not migration scope)"
  - file: docs/cloud/PRODUCTION_ENV_TEMPLATE.env.txt
    lines: "1-190 (full file)"
    snippet: "ZERO mention of GRANT, REVOKE, ou DROP privilege"
  - file: deploy/ansible/site.yml
    lines: "1-end"
    snippet: "grep TRUNCATE/GRANT/REVOKE → 1 match (logrotate copytruncate, hors-sujet)"
reasoning: |
  Le migration 2026-05-09 documente EXPLICITEMENT le risque : "TRUNCATE
  bypasses MySQL triggers". Le mitigation prévu est "revoke TRUNCATE
  permission on production DB user (deploy doc)". Or :
   - PRODUCTION_ENV_TEMPLATE.env.txt ne mentionne PAS de runbook GRANT/REVOKE ;
   - deploy/ansible/site.yml ne contient PAS de play `mysql_user` qui
     restreint les privilèges du compte `foodking_app` ;
   - Aucun fichier `docs/cloud/MYSQL_PRIVILEGES_RUNBOOK.md` n'existe.
  Le compte applicatif `foodking_app` (DB_USERNAME ligne 28 du template)
  hérite par défaut des privilèges ALL PRIVILEGES dans la plupart des
  setups OVH MySQL community → il peut TRUNCATE audit_logs et z_reports
  silencieusement, ce qui défait toute la chaîne fiscale en une commande.
impact: |
  Falsification fiscale possible par sysadmin / attaquant ayant obtenu
  les credentials applicatifs. DGFiP catch : "comment garantissez-vous
  l'immutabilité de la chaîne ?" → trigger DELETE ne suffit pas si
  TRUNCATE est permis. Article 313-1 CP (faux et usage), prison time
  pour l'opérateur si découverte d'une suppression.
recommendation: |
  Avant déploiement production OVH :
   (1) créer `docs/cloud/MYSQL_PRIVILEGES_RUNBOOK.md` :
        REVOKE DROP, TRUNCATE, ALTER on `audit_logs`, `z_reports`,
        `domain_events` FROM 'foodking_app'@'%';
        FLUSH PRIVILEGES;
   (2) ajouter une `mysql_user` task ansible qui APPLIQUE ce GRANT
       restrictif (et un test smoke `app:preflight-production` qui
       FAIL si le compte applicatif peut TRUNCATE) ;
   (3) test automatisé : `tests/Feature/Fiscal/TruncateForbiddenTest.php`
       qui en environnement production-like vérifie que TRUNCATE échoue
       avec ER_ACCESS_DENIED.
audit_question: |
  "Démontrez que personne ne peut effacer la chaîne sans laisser de
  trace." → aujourd'hui le grant GRANT ALL implicite démolit la promesse.
```

### F-FISC-003 — composition_snapshot sans guard SQL-level (compliance P0)

```yaml
id: F-FISC-003
severity: P0
class: compliance
title: "composition_snapshot purement application-layer — immutabilité non opposable"
evidence:
  - file: database/migrations/2026_04_22_000020_add_composition_snapshot_to_order_items.php
    lines: "10-14"
    snippet: |
      "$table->json('composition_snapshot')->nullable()->after('item_extras');"
      Aucun trigger BEFORE UPDATE, aucune GENERATED column, aucune CHECK.
  - file: app/Models/OrderItem.php
    lines: "44, 71"
    snippet: |
      protected $fillable = [..., 'composition_snapshot', ...];
      'composition_snapshot' => 'array',
      Le champ est FILLABLE et CASTABLE → tout `OrderItem::update(['composition_snapshot' => ...])` passe.
  - file: CLAUDE.md
    lines: "§8 Pricing SSOT"
    snippet: "composition_snapshot JSON frozen à création d'order — NEVER overwritten"
reasoning: |
  CLAUDE.md §8 (NF525 invariants) déclare composition_snapshot comme
  "frozen à création — NEVER overwritten". Or au niveau base de données
  ce contrat n'est PAS exécutable :
   - pas de trigger BEFORE UPDATE OF composition_snapshot ;
   - pas de colonne GENERATED ALWAYS qui rendrait le INSERT immuable ;
   - le model OrderItem expose le champ comme fillable/castable normal.
  La défense est entièrement applicative (convention de code dans
  OrderService / FrontendOrderService), donc :
   (a) un sub-agent IA mal-comportant peut écrire $item->update($snap_new) ;
   (b) un débuggeur en console artisan peut le faire ;
   (c) un bug futur peut le faire sans laisser de trace dans audit_logs
       (car la modification du snapshot ne déclenche aucun hook fiscal).
  Pour NF525, le snapshot pricing est la "preuve" du prix calculé au
  moment de la vente — sa mutation après coup = falsification.
impact: |
  Risque de falsification "silencieuse" du prix-snapshot d'une vente après
  Z close (par exemple pour cacher un avoir manuel ou un discount frauduleux).
  La signature HMAC du Z se calcule sur les agrégats Order, PAS sur
  composition_snapshot — donc la chaîne reste "valide" même si le snapshot
  d'un item est altéré. DGFiP catch : audit ligne-à-ligne d'un ticket
  vs DB snapshot → divergence → fraude présumée.
recommendation: |
  Hardening Phase D (4h de travail) :
   (1) Migration `2026_05_19_*_add_order_items_composition_snapshot_immutability_trigger.php`
       avec BEFORE UPDATE trigger MySQL :
        IF OLD.composition_snapshot IS NOT NULL
           AND NEW.composition_snapshot != OLD.composition_snapshot
        THEN SIGNAL SQLSTATE '45000'
          SET MESSAGE_TEXT = 'composition_snapshot is immutable post-creation (NF525)';
   (2) Equivalent SQLite (RAISE ABORT) pour la suite de tests ;
   (3) Test régression `tests/Feature/Fiscal/CompositionSnapshotImmutableTest.php`
       qui vérifie que UPDATE échoue post-INSERT ;
   (4) Couvrir le même champ allergens_snapshot par le même mécanisme
       (même invariant, même risque).
audit_question: |
  "Comment garantissez-vous qu'un ticket de caisse présenté est exactement
  ce qui a été vendu, ligne par ligne, et non pas une version modifiée ?"
```

### F-FISC-004 — Sequence chronologique vs created_at sur retry orphan (technique P1)

```yaml
id: F-FISC-004
severity: P1
class: technique
title: "Retry alloc mid-shift produit fiscal_sequence_no chronologiquement décalé"
evidence:
  - file: app/Console/Commands/RetryFiscalAllocCommand.php
    lines: "36-37, 82-83"
    snippet: |
      "Schedule: everyMinute() + withoutOverlapping(5)"
      "$promoted = $service->finalizePaidKioskOrder($order);"
      // À ce moment, l'orphan reçoit MAX(seq)+1 — mais son created_at est antérieur
  - file: app/Services/Fiscal/FiscalSequenceService.php
    lines: "88-93"
    snippet: |
      $max = (int) Order::withoutGlobalScopes()
          ->where('branch_id', $branchId)
          ->lockForUpdate()->max('fiscal_sequence_no');
      return $max + 1;
  - file: app/Services/Fiscal/ZReportService.php
    lines: "586-616 warnOnOrphanedPaidOrders"
    snippet: "log seulement, n'abort PAS le close → l'orphan finit dans le Z suivant"
reasoning: |
  Scenario concret : 10:00 kiosk_order_A créée + alloc fail (Redis flaky)
  → A.fiscal_alloc_error_at = 10:00, A.fiscal_sequence_no = NULL.
  10:01 → 10:30 vingt commandes B1..B20 réussissent → reçoivent seq 51..70.
  10:31 cron retry-alloc passe → A reçoit MAX+1 = seq 71.
  Or A.created_at = 10:00, A.fiscal_sequence_no = 71. B20.created_at = 10:30,
  B20.fiscal_sequence_no = 70.
  → Sequence numéros gap-free (51,52,...,70,71) ✓
  → MAIS l'ordre chronologique des tickets imprimés ne correspond pas à
    l'ordre de seq. Le client A repart à 10:01 avec un ticket "seq 71"
    avant que les seq 51-70 soient produits.
  L'invariant NF525 strict est "monotonic per branch, gap-free" — c'est
  respecté. Mais un agent DGFiP qui interroge "pourquoi le client de
  10:00 a un seq plus élevé que celui de 10:30 ?" peut soupçonner
  injection rétroactive (fraude). La défense = retry log fiscal canal
  + payload audit_log de l'order, mais c'est OBSERVABILITÉ pas PREUVE.
impact: |
  Faux-positif lors d'un contrôle. Pas une amende directe mais une
  demande d'explications, une éventuelle escalade en contrôle approfondi.
  Risque opérationnel : si plusieurs orphelins s'accumulent (Redis down
  prolongé), le pattern devient massif et difficile à expliquer
  ex-post.
recommendation: |
  Option A — Documenter le pattern explicitement dans
  `docs/cloud/NF525_RETRY_ALLOC_RUNBOOK.md` avec exemple chiffré, et
  ajouter une colonne `fiscal_sequence_allocated_at` séparée de
  `created_at` pour rendre la traçabilité explicite côté audit.
  Option B — Allouer le seq à création (synchrone, avec fallback
  graceful degraded = order rejeté côté kiosk plutôt qu'orphan).
  Option A recommandée (moins disruptive, B casse l'UX kiosk).
audit_question: |
  "Expliquez-moi pourquoi cette commande a un seq 71 alors que celles
  des 30 minutes suivantes ont 51-70." Sans runbook, opérateur perdu.
```

### F-FISC-005 — Z boundary utilise server timezone unique (technique P1)

```yaml
id: F-FISC-005
severity: P1
class: technique
title: "Pas de timezone par branche → multi-tenant SaaS = boundary Z incohérent"
evidence:
  - file: app/Models/Branch.php
    lines: "14-23 fillable"
    snippet: |
      'siret', 'vat_intra', 'register_id', 'legal_footer',
      // PAS de 'timezone' column
  - file: config/app.php
    lines: "120"
    snippet: "'timezone' => env('TIMEZONE') ?: 'Europe/Paris'"
  - file: app/Services/Fiscal/ZReportService.php
    lines: "214 + 618-628"
    snippet: |
      $closedAt = Carbon::now(); // ← utilise config/app.timezone GLOBAL
      "Timezone stability: signatures must be reproducible regardless
       of the server's local timezone at verification time"
      $closedAt->copy()->utc()->toIso8601String();  // signature en UTC ✓
reasoning: |
  Pour V1 Le Cayenne (single-resto FR), le timezone serveur Europe/Paris
  est correct et la frontière Z (minuit Paris) est respectée. La signature
  est convertie en UTC pour stabilité — correct.
  MAIS l'objectif annoncé dans PROJET (CLAUDE.md §2 SaaS) est multi-tenant
  multi-resto. Un futur déploiement avec branches Paris + Cayenne (vrai
  Cayenne, Guyane GMT-3) verrait :
   - les deux branches utiliseraient la même config('app.timezone')
   - une branche en Guyane "fermerait" à minuit Paris = 21:00 Guyane = milieu
     de shift commercial
   - les Z reports auraient des fenêtres qui chevauchent business days locaux
  Pour V1 single-resto = OK. Pour V1.0.2+ multi-resto = blocker.
impact: |
  V1 Le Cayenne : aucun. V2 SaaS B2B : NF525 ne se prononce pas
  explicitement sur le tz car la loi présuppose entreprise FR métropolitaine,
  mais COBI / contrôleurs comptables locaux dans les DOM-TOM
  contesteraient des Z fermés en heure métropole. Risque légal modéré
  outre-mer.
recommendation: |
  Phase D V1 : pas d'action.
  Backlog V1.0.2 SaaS multi-tenant :
   (1) ajouter colonne `branches.timezone VARCHAR(64) NOT NULL DEFAULT 'Europe/Paris'`
   (2) `ZReportService::close` lit `branch.timezone` et instancie
       `Carbon::now($branch->timezone)` ;
   (3) signature reste en UTC (déjà OK), mais aggregate window utilise tz branch ;
   (4) tests `ZReportTimezonePerBranchTest`.
audit_question: |
  N/A pour V1 single-resto. Pour SaaS V2 : "vos restos en Guyane ferment
  leur journée fiscale à 21h ?"
```

### F-FISC-006 — verifyAuditChainTail window borné = pas d'attestation complète (technique P2)

```yaml
id: F-FISC-006
severity: P2
class: technique
title: "Validation audit chain bornée à 500 rows tail — full walk jamais exécuté en runtime"
evidence:
  - file: app/Services/Fiscal/FiscalChainValidator.php
    lines: "85-107, 118-183"
    snippet: |
      "Bounded tail (default 500 rows) keeps O(window) under the 4s
       z_report_b{N} cache lock — no full-table walk under load"
      "First row of the window: prev_hash anchor is outside the window —
       we can only validate the current_hash recompute, not the link"
  - file: app/Services/Fiscal/AuditLogService.php
    lines: "199-231 verifyChain (full walk)"
    snippet: "existe MAIS pas appelé dans le hot path Z.open()"
  - file: config/fiscal.php
    lines: "101 audit_chain_tail_window"
    snippet: "500 default — sur audit_logs à 6 ans = ~10M rows = 0.005% checked"
reasoning: |
  L'orchestrateur FiscalChainValidator vérifie la chaîne en mode "tail
  bounded" à 500 rows pour rester sous le lock 4s. C'est correct pour la
  PERF mais incomplet pour l'ATTESTATION D'INTÉGRITÉ. La méthode full
  verifyChain existe (AuditLogService:199) mais n'est jamais appelée
  dans un cron, ni dans FiscalArchiveCommand (qui ne vérifie QUE z_reports
  chain pre-archive — pas audit_logs full chain).
  Conséquence : une compromission au milieu de la chaîne (ex: row #N
  altérée alors que N est antérieur de 1000 rows à la tail actuelle)
  ne serait JAMAIS détectée en runtime — sauf si quelqu'un lance
  manuellement AuditLogService::verifyChain depuis tinker.
impact: |
  Risque non-détection prolongée d'une compromission audit_logs.
  DGFiP catch (modéré) : "comment savez-vous que la chaîne ENTIÈRE est
  intacte ?" → "on regarde les 500 derniers". L'agent peut demander une
  full re-walk en sa présence — la machinery existe (verifyChain) mais
  doit être documentée comme procédure d'attestation.
recommendation: |
  Phase D :
   (1) Cron nightly `foodking:fiscal:verify-full-audit-chain` qui appelle
       AuditLogService::verifyChain pour chaque branche et alerte sur
       fiscal channel + Slack si erreur trouvée ;
   (2) Documenter `docs/cloud/NF525_FULL_CHAIN_ATTESTATION.md` :
       commande artisan qu'un expert-comptable peut lancer en read-only
       en sa présence avec proof of execution (timestamp + hash de la
       sortie) ;
   (3) Optionnel : FiscalArchiveCommand peut faire full audit_logs walk
       (le lock est déjà détenu, et c'est un cron, pas le hot path).
audit_question: |
  "Vous avez 47k tickets sur 2025. Démontrez que aucun n'a été altéré."
```

### F-FISC-007 — X report immutability confirmé (technique GREEN)

```yaml
id: F-FISC-007
severity: INFO
class: technique
title: "X report: read-only confirmé — n'écrit JAMAIS, n'incrémente JAMAIS"
evidence:
  - file: app/Services/Fiscal/XReportService.php
    lines: "1-82 (complète)"
    snippet: |
      "Read-only intraday fiscal snapshot ('X report'). Never writes."
      Method publique unique = snapshot() qui appelle aggregate() (read-only)
      Aucun create/update/save/insert dans le fichier entier.
  - file: tests/Feature/Fiscal/XReportTest.php
    lines: "73 + 100-117"
    snippet: |
      $this->assertSame(1, ZReport::query()->where('branch_id', $branch->id)->count());
      // après snapshot → toujours 1 Z (le close initial), pas de nouveau
      test_snapshot_is_idempotent → 2 snapshots produisent même résultat
reasoning: |
  La distinction X (consultation) vs Z (clôture) est respectée :
   - XReportService est immutable read-only ;
   - aucun appel à FiscalSequenceService::next() ;
   - aucun appel à ZReport::create / save / forceFill ;
   - test idempotence prouve qu'appeler deux fois ne crée pas de seq ;
   - test "matches_orders_since_last_close" prouve la cohérence avec Z futur.
  L'invariant NF525 X≠Z est cryptographiquement respecté.
impact: GREEN. Aucune action.
audit_question: "Démontrez que la consultation intraday ne ferme pas la journée." → ✓
```

### F-FISC-008 — Refund chain numbering correct (compliance GREEN)

```yaml
id: F-FISC-008
severity: INFO
class: compliance
title: "Refund counter-entry: fresh fiscal_sequence_no séparé, audit chain chained"
evidence:
  - file: app/Services/Order/RefundWithCounterEntryService.php
    lines: "89-91, 115-117, 194-210"
    snippet: |
      $mirrorSeq = $this->sequence->next($branchId);   // nouveau seq ✓
      $mirror->fiscal_sequence_no = $mirrorSeq;
      $this->audit->write([...'action' => 'order.refund.counter_entry'...]);
  - file: docs/audit/plans/PLAN_P11_FISCAL_Z_OPEN_HARDENING_2026-05-06.md
    lines: "377"
    snippet: "même résultat comptable que post-Z RETURNED legacy mais parent IMMUTABLE"
reasoning: |
  Le service crée un mirror order avec fiscal_sequence_no FRESH (allocation
  via FiscalSequenceService::next, pas réutilisation du seq parent) — c'est
  exactement le pattern NF525 attendu pour avoir/remboursement post-Z :
  un nouveau ticket fiscalement chained, parent immuable.
  Audit log écrit avec parent_fiscal_sequence_no + mirror_fiscal_sequence_no
  dans le payload → traçabilité forensique forte.
  Le SealedOrderGuard ligne 70 garantit qu'on n'utilise ce path QUE si
  parent est dans Z scellé (sinon path pré-Z standard RETURNED, qui mute
  le parent — acceptable car pré-clôture).
impact: GREEN. Aucune action.
audit_question: "Un avoir post-clôture journalière conserve-t-il l'intégrité fiscale ?" → ✓
```

### F-FISC-009 — Tax identity printing complet (compliance GREEN)

```yaml
id: F-FISC-009
severity: INFO
class: compliance
title: "Receipt: siret, vat_intra, register_id, legal_footer présents"
evidence:
  - file: app/Services/Receipt/ReceiptDataService.php
    lines: "13-29"
    snippet: |
      'pos_register_id' => optional($order->branch)->register_id,
      'pos_siret' => optional($order->branch)->siret,
      'pos_vat_intra' => optional($order->branch)->vat_intra,
      'pos_legal_footer' => optional($order->branch)->legal_footer,
  - file: app/Models/Branch.php
    lines: "18-22, 40-43"
    snippet: "fillable + casts incluent les 4 champs identité fiscale"
reasoning: |
  Les 4 champs NF525 d'identité fiscale obligatoires sur ticket sont :
   - siret (immatriculation entreprise)
   - vat_intra (TVA intracom)
   - register_id (identifiant caisse certifiée)
   - legal_footer (mentions légales personnalisables)
  ReceiptDataService les expose dans le payload du ticket. La présence
  effective dans le DOM imprimé doit être vérifiée par les surfaces UI
  (Blade templates POS / Kiosk) — c'est hors-scope d'un audit fiscal back,
  mais la machinery back est conforme.
impact: GREEN à condition que Blade rendering print les 4 champs.
recommendation: |
  Verifier via grep `pos_siret\|pos_vat_intra` dans resources/views/ que les
  4 champs sont effectivement rendus sur le ticket imprimé (hors-scope
  fiscal-back mais à valider Wave Visual W3).
audit_question: "Montrez-moi un ticket — siret/TVA/register lisibles ?" → back OK, front à valider.
```

### F-FISC-010 — 6y retention enforcement vs prune (compliance GREEN)

```yaml
id: F-FISC-010
severity: INFO
class: compliance
title: "PruneOutboxCommand correctement isolé de audit_logs / z_reports"
evidence:
  - file: app/Console/Commands/PruneOutboxCommand.php
    lines: "25-27, 33, 62-86"
    snippet: |
      "NF525 invariant: domain_events is an OPERATIONAL outbox, NOT a fiscal
       audit table. audit_logs + z_reports (6y retention) are NEVER touched
       by this command. See CLAUDE.md §8."
      DB::table('domain_events')  // ← UNIQUE table touchée
  - file: database/migrations/2026_04_22_000002_create_audit_logs_table.php
    lines: "62-83 down()"
    snippet: "down() refuse en production : 'NF525 mandates 6-year retention'"
  - file: config/fiscal.php
    lines: "148"
    snippet: "'archive_retention_years' => (int) env('FISCAL_ARCHIVE_RETENTION_YEARS', 6)"
reasoning: |
  Triple défense :
   (a) PruneOutboxCommand n'a aucune référence à audit_logs/z_reports ;
   (b) le down() de la migration audit_logs jette une RuntimeException si
       APP_ENV=production → impossible de rollback la table ;
   (c) config archive_retention_years = 6 par défaut, env override pour
       compatibilité legacy V1.
  Le seul gap résiduel = TRUNCATE (cf F-FISC-002) qui contourne le down()
  ET les triggers. Mais sur la prune côté Eloquent/Laravel, c'est clean.
impact: GREEN.
audit_question: "Comment garantissez la conservation 6 ans ?" → migration down() bloquée + prune isolé ✓.
```

---

## Récapitulatif par classe

### Compliance findings (priorité fiscale)
- **P0** F-FISC-001 — JET XML export DEFERRED → blocker DGFiP audit-readiness V1 production
- **P0** F-FISC-002 — TRUNCATE non-revoqué → falsification fiscale techniquement possible
- **P0** F-FISC-003 — composition_snapshot sans guard SQL → mutation post-vente non-détectable
- **GREEN** F-FISC-008 — Refund chain numbering correct
- **GREEN** F-FISC-009 — Tax identity printing (back) complet
- **GREEN** F-FISC-010 — 6y retention isolé du prune

### Technical findings (architecture/perf)
- **P1** F-FISC-004 — Chronological gap retry-alloc → faux-positif audit
- **P1** F-FISC-005 — Z boundary single tz → blocker V2 SaaS multi-resto
- **P2** F-FISC-006 — Audit chain bounded tail seulement → attestation incomplète
- **GREEN** F-FISC-007 — X report read-only confirmé

---

## Recommandations consolidées Phase D pré-prod V1

**Doit faire (P0 — bloquer GO production) :**
1. F-FISC-002 — runbook + ansible task pour REVOKE TRUNCATE/DROP sur audit_logs+z_reports+domain_events, validé par preflight production check
2. F-FISC-003 — migration trigger composition_snapshot immutable (BEFORE UPDATE SIGNAL 45000)
3. F-FISC-001 — DÉCISION expert-comptable : DEFER documenté formellement OU plan JET XML scopé. Sans décision écrite, V1 production = exposition légale.

**Devrait faire (P1 — réduit risque audit) :**
4. F-FISC-004 — runbook chronological retry + colonne `fiscal_sequence_allocated_at` séparée
5. F-FISC-006 — cron nightly full audit chain walk + procédure attestation expert-comptable

**Backlog V2 SaaS (P1 multi-tenant) :**
6. F-FISC-005 — colonne `branches.timezone` + ZReportService::close per-tz

**GREEN (rien à faire) :**
- X/Z distinction, Refund chain, Tax identity back, 6y retention via prune isolation, INSERT-only triggers audit_logs+z_reports, HMAC chain validation strict mode.

---

## Mindset DGFiP — résumé du contrôle simulé

Si un contrôleur DGFiP s'asseyait demain devant le serveur de prod avec un accès read-only DB :

| Demande contrôleur | Capacité FoodKing | Statut |
|---|---|---|
| "Présentez la chaîne d'évidence Z reports" | `ZReportService::verifyChain` strict mode | ✓ |
| "Présentez la chaîne audit_logs" | `verifyChain` full existe mais pas exécutée en runtime | AMBER (F-FISC-006) |
| "Exportez le JET XML 2025" | NON disponible — ZIP JSON custom uniquement | RED (F-FISC-001) |
| "Démontrez immutabilité audit_logs" | Triggers UPDATE+DELETE ✓ mais TRUNCATE non-revoqué | AMBER (F-FISC-002) |
| "Démontrez immutabilité ticket-by-ticket" | composition_snapshot mutable côté DB | AMBER (F-FISC-003) |
| "Identité fiscale sur tickets" | 4 champs présents back, à valider front | ✓ (sous-réserve UI) |
| "Conservation 6 ans" | Migration down() bloquée prod + prune isolé | ✓ |
| "Refunds tracés et numérotés séparément" | Counter-entry mirror order seq frais | ✓ |
| "Cohérence X/Z" | XReportTest atteste | ✓ |

**Verdict DGFiP simulé** : contrôleur en sortirait avec demande de production de JET XML sous 30j (F-FISC-001) + recommandation REVOKE GRANT (F-FISC-002). Pas d'amende immédiate, mais procès-verbal de recommandations qui devient amende si non-corrigé au contrôle suivant.

---

**Fin audit T-1.2.1 fiscal — READ-ONLY conformément contrat.**

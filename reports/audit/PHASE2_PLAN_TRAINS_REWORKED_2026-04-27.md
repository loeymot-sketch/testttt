# Phase 2 Plan Reworked — Deux Trains V1 Release / Phase 2 Enhancement

TASK_ID: PHASE2_PLAN_REWORK_TRAINS_2026-04-27  
Date : 2026-04-27  
Auteur execution : Codex extension  
Mode : AUDIT / PLAN ONLY. Aucun patch produit. Aucune suppression. Aucun gate humain auto-approuve.

## 0. Verdict

`PHASE2_TRAINS_VERDICT: PRET_POUR_TRAIN_A_APRES_GATES_HUMAINS`

Le livrable Phase 2 precedent etait correct pour une strategie long-terme, mais trop large pour l'objectif immediat : une V1 fonctionnelle commercialement de prise de commande jusqu'a sortie commande. Ce rework separe donc deux trains exclusifs :

- **TRAIN A — V1 RELEASE** : strict minimum release commercial. Il ferme la persistance Phase A, securise le sous-systeme quote, clarifie cycle/memory, puis traite D-M13.
- **TRAIN B — PHASE 2 ENHANCEMENT** : centralisation Dashboard, projection catalogue, roles, archive legacy. Tout reste bloque tant que Train A n'est pas closed.

Decision structurante : ne plus lancer de mission Phase 2 large tant que les preuves V1 critiques restent untracked ou hors gate.

## 1. Verification des 3 manques Claude

| Point Claude | Verification disque | Decision dans ce plan |
| --- | --- | --- |
| Phase A persistence pas assez front-loaded | Confirme. `git status --short` donne 600 entrees au moment du rework ; le sous-ensemble Train A montre 31 chemins tests/quote/payment untracked ou dirs untracked. | Train A commence par `GOV-PERSIST-SENTINELS`, puis `GOV-PERSIST-QUOTE-SUBSYSTEM`. |
| HMAC fallback a cle connue non flagge | Confirme : `app/Services/Order/OrderQuoteService.php:469-473` retourne `env('APP_KEY', 'foodking-order-quote')` si `config('app.key')` vide. | A.2 impose patch : throw `LogicException` si APP_KEY manquant. |
| D-M13 microtime fallback pas detaille | Confirme : 4 sites `microtime(true)` : `FrontendOrderService.php:421`, `OrderService.php:498`, `OrderService.php:873`, `OrderService.php:1295`. | A.4 contient plan technique explicite : supprimer fallback timestamp, retry duplicate key, fail propre si lock/DB impossible. |

## 2. Regles Communes

1. Train A et Train B sont sequentiels, pas paralleles.
2. Train B est `BLOCKED_UNTIL_TRAIN_A_CLOSED`.
3. Aucun commit direct via `git add -A`.
4. Aucun fichier produit modifie pendant ce rework de plan.
5. Aucun gate humain ne peut etre signe par Codex, Claude ou un script.
6. D-M13 ne commence pas sans `HG-DM13-MIGRATION-SIGNOFF`.
7. Le sentinel `QueueNumberUniquenessSentinelTest` ne doit jamais etre weaken, skipped ou supprime.
8. Toute mission Train A doit produire self-audit GPT + audit Claude terminal ou fallback documente.

## 3. TRAIN A — V1 RELEASE

Objectif : passer d'un etat techniquement presque pret mais non persistable a une V1 auditable, reproductible par CI, puis release-ready apres D-M13 et gates physiques/cutover.

Ordre strict : A.1 -> A.2 -> A.3 -> A.4. Aucun reordonnancement.

### A.1 — GOV-PERSIST-SENTINELS-2026-04-27

But : tracker les sentinels et helpers tests crees pendant l'override Phase A du 2026-04-26, sans toucher au code application.

Raison : actuellement, la preuve de securite POS/Kiosk peut exister localement sans exister en CI. Le rouge D-M13 lui-meme depend de `QueueNumberUniquenessSentinelTest.php`, aujourd'hui untracked.

Allowlist modifier :

```text
tests/Feature/Sentinels/*.php
tests/Feature/Concerns/HasPosQuoteBinding.php
tests/Feature/KioskQuote*Test.php
tests/Feature/PaymentConfirm*Test.php
tests/Feature/Payment/*.php
tests/Feature/Quote*Test.php
tests/Feature/Pos/QuoteBindingTest.php
```

Sous-ensemble observe a tracker en priorite :

```text
tests/Feature/Concerns/HasPosQuoteBinding.php
tests/Feature/KioskQuoteForgesBranchIdSilentlyOverriddenTest.php
tests/Feature/KioskQuoteIntegrityTest.php
tests/Feature/KioskQuoteTokenRequiredOnCommitTest.php
tests/Feature/PaymentConfirmAbilityTest.php
tests/Feature/PaymentConfirmCrossBranchTest.php
tests/Feature/PaymentConfirmMachineResolverTest.php
tests/Feature/QuoteCurrencyOriginTest.php
tests/Feature/QuoteDiscountAuthoritativeTest.php
tests/Feature/QuoteExpirationTest.php
tests/Feature/QuoteReplayIdempotencyTest.php
tests/Feature/QuoteTamperTest.php
tests/Feature/Payment/PaymentMethodAttemptAuditTest.php
tests/Feature/Payment/PaymentMethodRestrictedTest.php
tests/Feature/Payment/StripeActivationGuardTest.php
tests/Feature/Payment/WebPaymentDisabledTest.php
tests/Feature/Sentinels/CleanupVsConfirmRaceSentinelTest.php
tests/Feature/Sentinels/ClientTotalWriteForbiddenSentinelTest.php
tests/Feature/Sentinels/FiscalZBranchExactnessSentinelTest.php
tests/Feature/Sentinels/KdsExpectedStatusConflictSentinelTest.php
tests/Feature/Sentinels/KdsTransitionWhitelistSentinelTest.php
tests/Feature/Sentinels/OrderListBranchExactnessSentinelTest.php
tests/Feature/Sentinels/OrderShowBranchGuardSentinelTest.php
tests/Feature/Sentinels/OrderStatusNoopSideEffectsSentinelTest.php
tests/Feature/Sentinels/OssAdminBranchPolicySentinelTest.php
tests/Feature/Sentinels/PaymentConfirmAbilitySentinelTest.php
tests/Feature/Sentinels/PaymentConfirmCashOrderSentinelTest.php
tests/Feature/Sentinels/PaymentConfirmConcurrencySentinelTest.php
tests/Feature/Sentinels/PaymentConfirmCrossBranchSentinelTest.php
tests/Feature/Sentinels/PosCashEndpointSentinelTest.php
tests/Feature/Sentinels/PosReorderHistoricalPricingSentinelTest.php
tests/Feature/Sentinels/PosSubtotalForgerySentinelTest.php
tests/Feature/Sentinels/QueueNumberUniquenessSentinelTest.php
tests/Feature/Sentinels/TransactionBranchExactnessSentinelTest.php
```

Interdictions :
- Aucun changement `app/**`.
- Aucun changement `database/**`.
- Aucun changement `resources/**`.
- Aucun skip/weakening du sentinel queue.

Validation :

```bash
git add <chemins explicites uniquement>
php artisan test
```

Resultat attendu :
- 0 fail sauf `Tests\Feature\Sentinels\QueueNumberUniquenessSentinelTest`.
- Si un autre test fail : `REWORK`, pas A.2.
- `git diff --cached --name-only` ne contient que l'allowlist.

Sortie binaire :
- `A1_STATUS: CLOSED` si CI local reproduit exactement 1 fail D-M13.
- `A1_STATUS: REWORK` sinon.

### A.2 — GOV-PERSIST-QUOTE-SUBSYSTEM-2026-04-27

But : tracker et securiser le sous-systeme quote HMAC, puis retirer le fallback a cle connue.

Allowlist modifier demandee par Claude :

```text
app/Services/Order/OrderQuoteService.php
app/Models/OrderQuote.php
database/migrations/2026_04_25_190000_create_order_quotes_table.php
```

Patch obligatoire in-flight :

```php
private function hmacKey(): string
{
    $key = (string) config('app.key');

    if ($key === '') {
        throw new \LogicException('APP_KEY missing for OrderQuote HMAC');
    }

    return $key;
}
```

Amendment requis avant execution : la validation "APP_KEY vide test" n'est pas possible avec l'allowlist stricte ci-dessus si aucun test existant ne couvre ce cas. Deux options :

- Option A recommandee : ajouter `tests/Feature/OrderQuoteHmacKeyRequiredTest.php` a l'allowlist A.2.
- Option B minimale : executer un test manuel via `php artisan tinker` ou un test existant si deja present, mais ne pas declarer "test APP_KEY vide" comme automatise.

Decision responsable : A.2 ne doit pas partir en `codex:complex` tant que cet amendment est tranche par l'orchestrateur humain/Claude. Le patch HMAC reste obligatoire.

Validation :

```bash
php -l app/Services/Order/OrderQuoteService.php
php artisan test --filter='Quote|KioskQuote|OrderQuote|Pos\\QuoteBinding'
php artisan test
```

Resultat attendu :
- quote targeted suite PASS.
- full PHP suite : exactement 1 fail D-M13.
- APP_KEY empty coverage PASS si Option A choisie.

Interdictions :
- Aucun changement order flow hors quote subsystem.
- Aucun changement frontend.
- Aucun changement D-M13.

Sortie binaire :
- `A2_STATUS: CLOSED` si quote subsystem tracke + HMAC fallback supprime + validations conformes.
- `A2_STATUS: BLOCKED_ALLOWLIST_AMENDMENT` si le test APP_KEY vide est exige sans allowlist test.

### A.3 — GOV-CYCLE-AND-MEMORY-CLEANUP-2026-04-27

But : fermer l'ambiguite de gouvernance qui rend les prochaines preuves instables.

Allowlist modifier :

```text
.cursor/ACTIVE_CYCLE.md
memory/INDEX.md
.gitignore
docs/PHASE_A_CLOSED.md
docs/gates/GATE_LOG.md
```

Gates humains requis avant execution :

| Gate | Question | Decision attendue |
| --- | --- | --- |
| `HG-ACTIVE-PRIMARY-SELECTION` | W10 ou Caisse V1 comme primaire ? | Une seule valeur primaire, l'autre archivee ou marquee secondaire. |
| `HG-MEMORY-EPISODES-POLICY` | Tracker `memory/episodes/*.jsonl` ou les ignorer ? | Politique explicite dans `GATE_LOG.md`. |

Interdictions :
- Codex ne signe pas les gates.
- Pas de tri massif des 600 entrees dans cette mission.
- Pas de suppression de memory episodes.

Validation :

```bash
git status --short .cursor/ACTIVE_CYCLE.md memory/INDEX.md .gitignore docs/PHASE_A_CLOSED.md docs/gates/GATE_LOG.md
bash scripts/agent-activity-log.sh tail 50
```

Sortie binaire :
- `A3_STATUS: CLOSED` si gates signes + cycle primaire unique + politique memory documentee.
- `A3_STATUS: BLOCKED_HUMAN_GATE` sinon.

### A.4 — D-M13-QUEUE-NUMBER-DB-UNIQUE-2026-04-28

But : convertir la file `queue_number` en garantie DB reproductible, puis rendre vert le sentinel D-M13.

Preconditions strictes :
- A.1 CLOSED.
- A.2 CLOSED.
- A.3 CLOSED.
- `HG-PHASE-A-CLOSE-SIGNOFF` signe.
- `HG-DM13-MIGRATION-SIGNOFF` signe avant migration prod.

Findings techniques a traiter :

| Site | Probleme | Resolution attendue |
| --- | --- | --- |
| `app/Services/FrontendOrderService.php:421` | fallback timestamp `microtime(true)` | Supprimer ; retry/fail propre selon strategie D-M13. |
| `app/Services/OrderService.php:498` | fallback timestamp POS create | Supprimer ; retry duplicate key. |
| `app/Services/OrderService.php:873` | fallback timestamp autre path POS/table | Supprimer ; retry duplicate key. |
| `app/Services/OrderService.php:1295` | fallback timestamp table path | Supprimer ; retry duplicate key. |

Allowlist future apres gate :

```text
database/migrations/<YYYY_MM_DD_HHMMSS_add_unique_branch_queue_number_to_orders.php>
app/Services/OrderService.php
app/Services/FrontendOrderService.php
tests/Feature/Sentinels/QueueNumberUniquenessSentinelTest.php
tests/Feature/QueueNumberConcurrencyTest.php
docs/decisions/D-M13-QUEUE-NUMBER-UNIQUE.md
docs/runbooks/D-M13-QUEUE-NUMBER-ROLLOUT.md
```

Strategie technique recommandee :

1. Preflight : detecter doublons `(branch_id, queue_number)` non null.
2. Si doublons existent : stop ou backfill script signe.
3. Migration DB : unique composite `(branch_id, queue_number)` selon option D-M13 choisie.
4. Remplacer les fallbacks microtime par :
   - generation sous lock ;
   - save ;
   - retry limite sur `QueryException 23000` ;
   - erreur explicite si impossible apres retries.
5. Garder symmetry `OrderService` / `FrontendOrderService`.
6. Ajouter test concurrent POS + Kiosk.

Validation :

```bash
php artisan test tests/Feature/Sentinels/QueueNumberUniquenessSentinelTest.php
php artisan test --filter='QueueNumber|Kiosk|POS|Order'
php artisan test
```

Resultat attendu :
- `QueueNumberUniquenessSentinelTest` PASS.
- Full PHP suite 0 fail.

Sortie binaire :
- `A4_STATUS: CLOSED` si D-M13 vert + full suite verte.
- `A4_STATUS: BLOCKED_HUMAN_GATE` si gate absent.
- `A4_STATUS: REWORK` si queue unique casse POS/Kiosk/KDS/OSS.

## 4. Gates Humains

Aucun gate ci-dessous ne peut etre auto-approuve.

| Gate | Question a trancher | Bloque |
| --- | --- | --- |
| `HG-ACTIVE-PRIMARY-SELECTION` | W10 ou Caisse V1 comme primaire ? | A.3 |
| `HG-MEMORY-EPISODES-POLICY` | Tracker `memory/episodes/*.jsonl` ou `.gitignore` ? | A.3 |
| `HG-PHASE-A-CLOSE-SIGNOFF` | Accepter A.1+A.2+A.3 comme Phase A close sans bucket massif restant ? | A.4 |
| `HG-DM13-MIGRATION-SIGNOFF` | Lancer migration unique prod ? Avec ou sans backfill ? | A.4 migration |
| `HG-W2_CUTOVER_DECISION_OR_POS_WIZARD_SHIM_ACCEPTANCE` | Bundle legacy kiosk/wizard : shim ou purge ? | release |
| `HG-BORNE-REMIX-ARCHIVE-CONFIRM` | Deplacer `borne (Remix)/` vers archive manifestee ? | Train B Phase 7 |
| `HG-HARDWARE-LAB-SIGNOFF` | UAT borne physique signee ? | release commercial |

## 5. TRAIN B — PHASE 2 ENHANCEMENT

Statut global : `BLOCKED_UNTIL_TRAIN_A_CLOSED`.

Regle de namespace : les anciennes missions `PH2-*` deviennent `CV2-PH2-*` pour eviter confusion avec masterplay Caisse V1.

### CV2-PH2-01-DATA-OWNERSHIP-MATRIX

Ancien nom : `PH2-P0-01-DATA-OWNERSHIP-MATRIX`  
Statut : BLOCKED_UNTIL_TRAIN_A_CLOSED  
Objectif : figer owners prix/catalogue/availability/order/status/file/outbox/roles.  
Allowlist : rapports audit + ADR data ownership.  
Gate : aucun code produit.

### CV2-PH2-02-MENU-CATALOG-EVENT-SNAPSHOT-COVERAGE

Ancien nom : `PH2-P0-02-MENU-CATALOG-EVENT-SNAPSHOT-COVERAGE`  
Statut : BLOCKED_UNTIL_TRAIN_A_CLOSED  
Objectif : toute mutation catalogue pertinente bump snapshot et invalide cache.  
Gate : event contract public si nouveau type outbox.

### CV2-PH2-03-MENU-PROJECTION-PARITY-SENTINELS

Ancien nom : `PH2-P0-03-MENU-PROJECTION-PARITY-SENTINELS`  
Statut : BLOCKED_UNTIL_TRAIN_A_CLOSED  
Objectif : prouver projection unifiee POS/Kiosk avant migration.

### CV2-PH2-04-KIOSK-POS-CONSUME-MENU-PROJECTION

Ancien nom : `PH2-P0-04-KIOSK-POS-CONSUME-MENU-PROJECTION`  
Statut : BLOCKED_UNTIL_CV2-PH2-03_PASS  
Objectif : brancher consommateurs sur projection canonique.  
Interdiction : aucun calcul prix frontend.

### CV2-PH2-05-VARIATION-EXTRA-ADDON-SYNC-COVERAGE

Ancien nom : `PH2-P0-05-VARIATION-EXTRA-ADDON-SYNC-COVERAGE`  
Statut : BLOCKED_UNTIL_TRAIN_A_CLOSED  
Objectif : versionner/propager mutations composition/prix.

### CV2-PH2-06-CATEGORY-BRANCH-SCOPE-ADR

Ancien nom : `PH2-P0-06-CATEGORY-BRANCH-SCOPE-ADR`  
Statut : BLOCKED_UNTIL_TRAIN_A_CLOSED  
Objectif : decider categories globales vs pivot branch visibility.  
Gate : humain si migration.

### CV2-PH2-07-DASHBOARD-AUTHZ-CATALOG-OPS

Ancien nom : `PH2-P0-07-DASHBOARD-AUTHZ-CATALOG-OPS`  
Statut : BLOCKED_UNTIL_TRAIN_A_CLOSED  
Objectif : roles backoffice catalog/ops sans reutiliser permissions caisse.

### CV2-PH2-08-QUEUE-D13-POSTRELEASE-OBSERVABILITY

Ancien nom : `PH2-P0-08-QUEUE-D13-AFTER-HUMAN-GATE`  
Statut : BLOCKED_UNTIL_A4_CLOSED  
Objectif : observabilite post-D-M13, pas migration.  
Note : la migration D-M13 elle-meme est Train A A.4.

### CV2-PH2-09-OUTBOX-DOCS-CONTRACT-ALIGNMENT

Ancien nom : `PH2-P1-09-OUTBOX-DOCS-CONTRACT-ALIGNMENT`  
Statut : BLOCKED_UNTIL_TRAIN_A_CLOSED, sauf si Claude demande doc-only avant release.  
Objectif : aligner docs `EventContract` avec code.

### CV2-PH2-10-LEGACY-DEDUP-ARCHIVE-MANIFEST

Ancien nom : `PH2-P1-10-LEGACY-DEDUP-ARCHIVE-MANIFEST`  
Statut : BLOCKED_UNTIL_V1_RELEASE_READY_AND_GATE_ARCHIVE  
Objectif : archive manifestee, jamais suppression.  
Gate : `HG-BORNE-REMIX-ARCHIVE-CONFIRM` + cutover shim/purge.

## 6. Train A Execution Queue

| Ordre | Mission | Statut initial | Peut lancer maintenant ? |
| --- | --- | --- | --- |
| A.1 | `GOV-PERSIST-SENTINELS-2026-04-27` | READY_FOR_EXECUTE | Oui, apres revue de ce rework. |
| A.2 | `GOV-PERSIST-QUOTE-SUBSYSTEM-2026-04-27` | BLOCKED_ALLOWLIST_AMENDMENT | Non, trancher test APP_KEY vide. |
| A.3 | `GOV-CYCLE-AND-MEMORY-CLEANUP-2026-04-27` | BLOCKED_HUMAN_GATE | Non, gates humains requis. |
| A.4 | `D-M13-QUEUE-NUMBER-DB-UNIQUE-2026-04-28` | BLOCKED_A1_A2_A3_AND_D13_GATE | Non. |

## 7. Ce qu'il ne faut pas faire

1. Ne pas lancer `CV2-PH2-01` avant Train A.
2. Ne pas creer de Dashboard write avant D-M13.
3. Ne pas modifier `OrderQuoteService` sans tracker sa migration/model/service.
4. Ne pas traiter `APP_KEY` missing comme warning ; pour HMAC quote, c'est un hard fail.
5. Ne pas supprimer `microtime` fallback sans ajouter retry/erreur explicite.
6. Ne pas declarer V1 release-ready tant que `php artisan test` n'est pas 0 fail apres D-M13.
7. Ne pas deplacer legacy dirs sans archive manifestee + gate.

## 8. Validation de ce Rework

Commandes de verification documentaire :

```bash
bash .cursor/hooks/safety-check.sh
git diff --check -- reports/audit/PHASE2_PLAN_TRAINS_REWORKED_2026-04-27.md reports/audit/PHASE2_PLAN_DOUBLE_AUDIT_TRAINS_2026-04-27.md reports/post_execute_latest.log
git status --short -- reports/audit/PHASE2_PLAN_TRAINS_REWORKED_2026-04-27.md reports/audit/PHASE2_PLAN_DOUBLE_AUDIT_TRAINS_2026-04-27.md reports/post_execute_latest.log
```

Attendu :
- safety-check PASS.
- diff check PASS.
- aucune modification produit.

## 9. Decision Finale

Train A est la voie courte vers V1 fonctionnelle. Train B conserve toute l'ambition Phase 2 mais ne doit plus bloquer ni polluer la release.

`PHASE2_TRAINS_VERDICT: PRET_POUR_TRAIN_A_APRES_GATES_HUMAINS`

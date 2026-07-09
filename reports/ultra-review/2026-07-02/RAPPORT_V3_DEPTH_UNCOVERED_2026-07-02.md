# RAPPORT V3 — AUDIT PROFONDEUR DU NON-COUVERT — 2026-07-02
**Mission** : GOAL_FABLE5 V3 — discipline V2 (« GREEN = hypothèse à réfuter »), priorité au NON-COUVERT
(web/mobile/loyalty/Uber/dormant/intersections) + re-valider ce qui a bougé. HEAD `61e9ea7b7` + working-tree.

## 1. VERDICT — encore des « verts » cassés, dont un P0 que J'AVAIS moi-même déclaré SAFE

Workflow refute-by-default (7 cibles profondes + verify indépendant + critic, 19 agents) : **4 GREEN_HELD,
3 BROKEN**, **11 findings** (8 CONFIRMED + 3 DOWNGRADE). **Preuve ultime « GREEN ≠ correct »** : en V1
j'avais explicitement écrit « installer /install : SAFE ». **FAUX** — V3 a prouvé LIVE que le garde
`Redirect::to(...)->send()` **n'arrête pas PHP** → l'installateur s'exécutait sur une app installée.

**3 findings healés cette boucle (TDD, frozen-diff 0, NF525 OK)** + 6 déférés (Uber go-live + fiscal-wire + P3).

## 2. FINDINGS CASSÉS → HEALÉS (repro live + test)

| # | Sév | Finding | Fix | Test |
|---|---|---|---|---|
| 1 | **P0** | **Installateur legacy s'exécute sur app INSTALLÉE** : `InstallerController.__construct` faisait `Redirect::to(APP_URL)->send()` — envoie le 302 mais **ne halte PAS** → `/install/database` (reconfig DB prod) + `/install/final-store` (réécrit `.env APP_ENV=production`) tournaient, **NON AUTHENTIFIÉS**. (P1 jumeau : final-store). Reproduit live (302 émis, méthode exécutée). **J'avais dit SAFE en V1.** | garde lève `HttpResponseException(redirect(APP_URL))` → renvoie la redirection ET **halte** (méthode jamais atteinte) | `InstallerAlreadyInstalledGuardTest` 2/2 |
| 2 | **P2** | **`/loyalty/check` IDOR/PII** : renvoyait name+loyalty_code+points de N'IMPORTE QUEL code/téléphone à tout token Sanctum (reverse phone→nom + énumération 10/min). **Même classe que /register+/scan colmatés en V2 — mais /check = le JUMEAU oublié.** Reproduit live (VICT1234→200 payload). | parité `/redeem` : borne réelle (KioskMachine, pas guest) OU staff OU propriétaire ; sinon 404 | `LoyaltyCheckIdorTest` 2/2 |
| 3 | **P2** | **CSV/formula injection dans TOUS les exports Excel (~20)** : valeur `=cmd|"/c calc"!A1` (amorçable via name d'un signup public → CustomerExport) liée en FORMULE → RCE à l'ouverture. | `FormulaGuardValueBinder` global (config/excel.php) : valeurs à tête `= + - @` → cellule TEXTE inerte ; numériques inchangées | `ExcelFormulaInjectionGuardTest` 8/8 (unit + config + vrai export round-trip) |

**+ Re-validation « caisse INLINE vs borne Plan B » (item qui a bougé — walkin_route_to_counter=false)** :
`PosDeferredNoDoubleCashMovementTest` 3/3 — commande caisse inline = PAID + fiscal à la création + 1
cash_movement + **absente de la file « à encaisser »** ; différée = 0 mouvement à la création (fix V2).

## 3. DÉFÉRÉS (documentés, non healés — rationale)
- **ZReportCashEnrichmentService NON CÂBLÉ au close (P2)** : `persistForClosedReport` est construit mais
  JAMAIS appelé par `ZReportService` (FROZEN §7) — le Z archivé n'a pas la ventilation par terminal /
  cash livreur. **MAIS** reconstructible à la demande (`aggregateByTerminal` prouvé live V1) + **chaîne
  fiscale intacte**. Câbler = toucher le FROZEN ZReportService → **LOCK + gate owner** (backlog pré-audit).
- **UBER go-live (5)** : item non résolu → commande perdue (map vide), `transaction_id` sans index UNIQUE
  (double sous course), events d'annulation non gérés, `fiscalize`/`deny_on_out_of_stock` no-op, LIKE
  mauvais-produit. **Production Access EN ATTENTE + `uber_menu_map` vide (données owner)** → workstream
  go-live dédié (peupler la map + index UNIQUE + gérer cancel + chemin fiscal Uber).
- **Delivery-boy reconcile sans variance-approval gate (P3 downgrade)** : `CashVarianceRequiresApproval`
  existe mais non enforced sur reconcile — documenté (mono-poste, faible impact).
- **Cron Z TOCTOU (P3)** : course exists()↔lockForUpdate entre close manuel et cron 23:59 → fausse alerte
  pager, **0 impact fiscal** (le lock re-vérifie). Documenté.

## 4. TENU SOUS ATTAQUE (GREEN_HELD)
- **WEB standalone** : data = miroir DB (0 produit inventé), NO-API-wireup V1 respecté, parité wizard, FR, prix SSOT.
- **MOBILE RN** : data miroir, **palette NOIR/ORANGE/JAUNE/BLANC** (pas de #F4501E rouge), NO-API, FR.
- **Intersections/moved** : modèle caisse-inline/borne-Plan-B correct, zéro doublage cross-surface, chaîne gap-free.

## 5. GATES
- **Suite backend** : **3047 tests / 0 failed / 0 error** (2 incomplete + 29 skipped intentionnels) + 4 nouveaux
  fichiers de tests V3 (installer 2, loyalty-check 2, excel 8, +caisse-inline 1). Comme en V2, la suite a attrapé
  **2 régressions auto-infligées** par mes propres heals : (a) le garde installer en __construct (throw) cassait
  le route-scan de `IdempotencyRequiredRoutesCoverageTest` → déplacé en **middleware de contrôleur** (request-time) ;
  (b) `LoyaltyApiTest::test_loyalty_check` supposait l'ancien /check ouvert → mis à jour pour s'authentifier en
  propriétaire. Re-run → 0 failed. La discipline « re-prove » a policé mes propres correctifs.
- **Frozen-diff 0** (heals : InstallerController, LoyaltyController, config/excel.php, FormulaGuardValueBinder —
  aucun fichier §7 ; ZReportService FROZEN volontairement NON touché).
- **NF525 CHAIN OK 4 branches**. **Zéro doublage** (heals V1/V2 owner non re-faits).

## 6. LEÇON (la plus forte de la campagne)
En V1 j'ai **audité `/install` et conclu « SAFE »**. V3, en RÉFUTANT ce vert, a prouvé que c'était un
**P0** (`->send()` ≠ `exit`). C'est la démonstration définitive de « GREEN ≠ correct » — même un « vérifié
safe » explicite doit survivre à une attaque active + une re-preuve live. La méthode refute-by-default +
verify-indépendant + re-prove est ce qui a débusqué le P0 que 2 passes de review avaient validé.
**Barre atteinte** : P0 + 2×P2 healés+testés, frozen 0, NF525 OK, non-couvert (web/mobile) attesté sans dérive.

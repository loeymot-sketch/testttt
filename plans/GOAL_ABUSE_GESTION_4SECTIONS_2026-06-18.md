# GOAL — ABUSE-TEST les 4 grosses sections Gestion (Reports · Settings · RBAC · Catalogue)

> Owner 2026-06-18 : « tous ! ultra plan et max intelligence » — abuser par test+analyse+correction
> les 4 grosses sections riches en fonctionnalités encore sous-testées. Suite de la campagne UX
> (52 heals convergés, HEAD `a97ec6815`). Discipline : ultra-architect-planify + test-e2e adversaire.

## §0 — Préambule

- **Working tree** : worktree `wizard-wysiwyg-2026-06-14`, HEAD `a97ec6815` (UX campaign committed, LOCAL).
- **Rig abuse** : `:8766` (APP_ENV=e2e, MySQL foodking_e2e + redis + queue + soketi) pour le live ;
  **PHPUnit = SQLite :memory:** → lancer `php artisan test <file>` SANS préfixe APP_ENV=e2e.
- **Substrat de preuve** : chaque finding P0/P1 DOIT avoir file:line + repro (test rouge / curl / DB query / DOM).
  Verify-before-accept adversaire (refute-by-default) avant tout heal. Anti-hallucination §3ter CLAUDE.md.
- **Convergence par section** : 2 cycles consécutifs P0+P1=0 avec findings identiques.
- **Frozen §7 / NF525 §8** : intouchables sans LOCK owner. Money 100% backend (PricingService SSOT).
- **Pipeline par tâche** : find (multi-lens) → adversarial verify → heal TDD (test rouge→vert) → re-verify → commit.
- **Push** : G-PUSH owner-only. Commits LOCAUX par cluster, chemins explicites, secret-scan, jamais --no-verify.

## §1 — Map des 4 sections (ancré 2026-06-18 via find/grep)

| # | Section | Anchors back (vérifiés) | Front | Tests existants | Trou d'abuse |
|---|---|---|---|---|---|
| 1 | **Reports** | 9 ctrls (SalesReport, CashSessionReport, CreditBalanceReport, ItemsReport, Analytic, AnalyticSection, Z/X) + SimpleOrderResource/SalesSummaryResource/SalesReportOverviewResource/ItemReportResource | salesReport, itemsReport, cashSessionReport, creditBalanceReport, dashboard widgets | Z/X fiscaux BIEN couverts ; sales/items/credit/cash/analytic = peu | **non-fiscaux** : réconciliation money, netting refund, ventilation canal/cat, bords date/fuseau, exports |
| 2 | **Settings** | 3 ctrls (Setting/Currency/Tax) + SettingResource/SiteResource/TaxResource | 33 sous-zones (Tax, Currency, PaymentGateway/Terminals, KioskSetup, OrderSetup, LoyaltySetup, TimeSlot, Mail, Sms, Theme, Language, License, Otp, Company…) | tax, currency-contract, tax-inclusive, locales | **défauts `?? fallback`** (cf. bug currency déjà trouvé), calcul TVA, validation config-casse, propagation |
| 3 | **RBAC/Staff** | 10 ctrls (Permission, Role, Chef, Employee, Waiter, Administrator, SimpleUser + addresses) ; **71 FormRequests return-true** (sentinel baseline=69 → DRIFT à vérifier) | administrators, employees, waiters, chefs, settings/Role | FormRequestAuthzDrift, UserMgmtRoleTarget, RouteCoverage_AdminPermissionGate, StaffOnlyRouting | escalade privilège, isolation branche CRUD staff, self-lockout, role-target injection |
| 4 | **Catalogue** | 15 ctrls (Item, ItemCategory, ItemAttribute, ItemPhoto, ItemAddon, Ingredient, Menu, MenuSection, MenuTemplate, PosCategory, ComposerProfile, OfferItem) | items (28 comps) | ItemExtra, MultiVariation, ItemAttribute, allergens, composition-snapshot, wizard-profile | intégrité data : orphelins, cascades prix/dispo, sous-cat circulaires, bulk |

## §2 — Section 1 : RAPPORTS (money-correctness)
### Sous-systèmes & tâches d'abuse
- **1.1 Réconciliation ventes** — SalesReport total == somme commandes (HT/TVA/TTC) ? remises ? remboursements nettés (mirror NF525) ? `app/Http/Resources/SimpleOrderResource.php` partagé par 7 consommateurs (flag mémoire) — un changement de format casse-t-il un conso ?
  - acceptance : `tests/Feature/Reports/SalesReportNetTotalSentinelTest.php` + nouveau `SalesReportRefundNettingAbuseTest.php` (TO CREATE)
- **1.2 ItemsReport / ventilation** — unités vendues par article/catégorie exactes ? articles internes/offerts exclus ? `ItemsReportController` + `ItemReportResource`.
  - acceptance : `tests/Feature/Reports/ItemsReportUnitsSoldSentinelTest.php` + `ItemsReportCategoryBreakdownAbuseTest.php` (TO CREATE)
- **1.3 Bords date / fuseau** — plage vide, jour unique, cross-minuit Paris, frontière année ? `DashboardSalesReportParisBoundsSentinelTest` existe → étendre aux autres rapports.
  - acceptance : `ReportsDateBoundaryAbuseTest.php` (TO CREATE)
- **1.4 Exports + authz** — PDF/XLS == écran ? AnalyticReadAuthz (déjà sentinel) — un non-autorisé accède-t-il ? gros volume (N+1 / timeout) ?
  - acceptance : `tests/Feature/Admin/AnalyticReadAuthzSentinelTest.php` + `ReportExportParityAbuseTest.php` (TO CREATE)

## §3 — Section 2 : SETTINGS (config-defaults + TVA)
- **2.1 Défauts `?? fallback`** — auditer TOUS les `?? '…'` de `SettingResource.php`/`SiteResource.php`/`TaxResource.php` : lesquels sont en-US/faux pour FR (comme le `?? 'left'` déjà corrigé) ? type-mismatch enum (numérique vs string) ?
  - acceptance : `tests/Feature/Sentinels/CurrencyRenderingResourceContractSentinelTest.php` + `SettingResourceFrDefaultsAbuseTest.php` (TO CREATE)
- **2.2 Calcul TVA** — TaxController + tax-inclusive : taux multiples, arrondi, TVA incluse vs ajoutée, cohérence ticket/Z. `tests/Feature/Pricing/TaxInclusivePricesTest.php` + `PosReceiptTaxLinesTest.php`.
  - acceptance : `TaxComputationAbuseTest.php` (TO CREATE)
- **2.3 Validation config-casse** — peut-on sauver une config invalide qui casse l'app (currency vide, digits négatif, locale inconnue, route paiement incohérente) ? SiteRequest validation.
  - acceptance : `SettingValidationGuardAbuseTest.php` (TO CREATE)
- **2.4 Propagation cross-surface** — un changement TVA/currency/locale se propage-t-il POS+Kiosk+ticket+Z sans cache stale ? (cf. leçon settings-cache `set()` n'invalide pas `all()`).

## §4 — Section 3 : RBAC / STAFF (sécurité)
- **3.1 Drift FormRequests** — 71 return-true vs baseline 69 : VÉRIFIER le sentinel `FormRequestAuthzDriftSentinelTest` est-il rouge ? quels nouveaux return-true ? lesquels gardent une route sensible ?
  - acceptance : `tests/Feature/Sentinels/FormRequestAuthzDriftSentinelTest.php` (run + analyser)
- **3.2 Escalade privilège** — un EmployeeController/WaiterController/ChefController permet-il à un staff bas-priv de créer/éditer un rôle supérieur ? role-target injection (UserMgmtRoleTarget sentinel existe).
  - acceptance : `tests/Feature/Sentinels/UserMgmtRoleTargetSentinelTest.php` + `StaffPrivilegeEscalationAbuseTest.php` (TO CREATE)
- **3.3 Isolation branche CRUD staff** — un staff branche A voit/édite-t-il le staff branche B ? (BranchScope sur User confirmé §9). 
  - acceptance : `StaffBranchIsolationAbuseTest.php` (TO CREATE)
- **3.4 Self-lockout / dernier admin** — peut-on supprimer/dé-rôler le dernier admin, ou soi-même, et se verrouiller dehors ?
  - acceptance : `LastAdminLockoutGuardAbuseTest.php` (TO CREATE)

## §5 — Section 4 : CATALOGUE (intégrité data SSOT)
- **4.1 Orphelins** — supprimer un item/variation/extra/catégorie laisse-t-il des refs orphelines (addons, profils wizard, offerItem, stock, order_items historiques) ?
  - acceptance : `CatalogOrphanIntegrityAbuseTest.php` (TO CREATE)
- **4.2 Sous-catégories circulaires** — peut-on faire A→parent B→parent A (cycle) ou auto-parent ? profondeur ?
  - acceptance : `SubCategoryCycleGuardAbuseTest.php` (TO CREATE)
- **4.3 Cascades prix/dispo** — variation/extra price feeds PricingService ; un prix négatif/null/énorme ? availability cascade item↔branche cohérente ? `MultiVariationValidationTest` existe.
  - acceptance : `tests/Feature/MultiVariationValidationTest.php` + `CatalogPriceAvailabilityCascadeAbuseTest.php` (TO CREATE)
- **4.4 Wizard-profile mirror** — `ComposerProfileController` ; le profil DB↔wizard reste cohérent (cf. campagne wizard) ; bulk import/edit.
  - acceptance : `tests/Feature/WizardPerItemProfileGuardTest.php` + extension.

## §A — Agent army / fan-out (par section)
Lenses d'abuse (find, read-only, parallèle) : **money-correctness** · **config-defaults** · **rbac-escalation** ·
**data-integrity** · **edge/concurrency**. Chaque finding → **adversarial verify** (refute-by-default, file:line + repro)
→ HEAL TDD par le main thread (jamais 2 implementers en //). Workflow pipeline : find→verify par section.

## §X — Vagues (séquentiel par section, checkpoint+commit chacune)
- **W1 Reports** (money d'abord) → find+verify → heal TDD → re-verify → commit.
- **W2 Settings** → idem.
- **W3 RBAC** → idem (commencer par le drift sentinel 71vs69).
- **W4 Catalogue** → idem.
- **W5 Convergence** : re-run find sur les 4, confirmer 0 nouveau P0/P1.
Checkpoint par vague : tests verts + frozen diff 0 + NF525 chain intacte + BRAIN/mémoire MAJ + commit.

## §G — Owner gates
| Gate | Description | WHO | Status |
|---|---|---|---|
| G-PUSH | aucun push sans owner | owner | STANDING |
| G-FROZEN | tout heal touchant §7 (PricingService/Fiscal/etc.) = LOCK owner | owner | per-finding |
| G-RBAC-BASELINE | abaisser le sentinel FormRequest baseline (ratchet) si on chip-away | owner | si applicable |

## §F — Règle finale
DONE = les 4 sections abusées, P0+P1=0 sur 2 cycles, chaque heal TDD-prouvé (test rouge→vert), frozen 0
(sauf LOCK), NF525 intacte, commits locaux par cluster. Production-perfect, pas « presque ». Money-correctness
non-négociable : un total faux = P0.

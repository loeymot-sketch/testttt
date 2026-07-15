# GOAL — RUPTURE STOCK CAISSE+KDS · CARNET PIN · AUDIT GLOBAL ADVERSARIAL
— 2026-07-15 · branch `pos/category-first-caisse-2026-06-23` · HEAD baseline `59d40e09b`

> Mission owner (3 volets) :
> **(A)** Rupture de stock pilotable depuis la CAISSE et l'écran CUISINE (KDS) → produit
> indisponible instantanément sur caisse + borne + web, marqué « en rupture ».
> **(B)** Nouvelle mini-app web mobile à CODE PIN : notes + comptabilité jour-au-jour
> (dépenses sorties, acomptes travailleurs, scan photo factures + montant, résumés
> jour/mois/par personne).
> **(C)** Audit global adversarial multi-systèmes (synchronisation, qualité, gestion)
> avec max agents parallèles + rapport ultra-détaillé + corrections en boucle test-e2e.

---

## §0 Préambule

### §0.1 Décision working-tree (verifiée 2026-07-15)
- `app/Services/Hardware/OrderReceiptEscPosRenderer.php` modifié non-commité = fix P2
  « ligne RENDU jamais imprimée » d'une session parallèle (commentaire daté 2026-07-15).
  **Décision : NE PAS TOUCHER, NE PAS COMMITER** — exclu de tous mes `git add`
  (toujours fichiers explicites, jamais `git add .`).
- Untracked : plans/, reports/, 4 PNG racine → laissés tels quels.
- Baseline : NF525 `fiscal:verify-chain --all` = **CHAIN OK 4 branches** (2026-07-15).

### §0.2 Scope-expansion flags
- ⛔ Pas de wireup mobile/web standalone aux APIs backend (mandat owner).
- ⛔ Carnet (B) = registre INTERNE déclaratif — zéro contact `audit_logs`/`z_reports`/
  `app/Services/Fiscal/*`/`CashMovement` (pas de confusion fiscal/interne).
- ⛔ Pas de cloud/VPS évoqué sans ordre owner.

### §0.3 Pipeline par tâche
Chaque tâche s'exécute via le pipeline `ultra-audit-profond` (spécialistes read-only →
implémentation TDD → RED-team → test → visuel → adversarial visuel). Non re-décrit ici.

### §0.4 Critères de convergence GOAL
Convergence = deux cycles consécutifs avec **P0+P1 = 0 ET findings identiques** ;
frozen-diff 0 ligne sur toute la plage GOAL ; NF525 CHAIN OK ; visuels analysés (Read) ;
BRAIN §2/§3 à jour. « Presque bon » = REJET.

---

## §1 Map principal (ancres vérifiées 2026-07-15 via find/grep/ls réels)

| # | Système | Maturité | Ancres vérifiées | Tests existants |
|---|---|---|---|---|
| 1 | CAISSE (POS) | mûr, frozen partiel | `resources/js/components/admin/pos/PosComponent.vue` (non-frozen) ; wizard vanilla `public/js/pos-wizard.js` FROZEN strict | `tests/Feature/Pos/*` (12+) |
| 2 | KDS | mûr | `resources/js/components/admin/kitchenDisplaySystem/*` (KdsV2Grid, KdsOrderCard…) ; `app/Http/Controllers/Admin/KitchenDisplaySystemController.php` (routes/api.php:1228-1251) | `tests/Feature/` KDS suites |
| 3 | AVAILABILITY (backend rupture) | **complet, déjà production** | `app/Http/Controllers/Admin/AvailabilityController.php` (permission:items_edit, routes/api.php:280-317, throttle `menu-availability` 60/min) ; `app/Services/Menu/AvailabilityService.php` SSOT ; events `ItemAvailabilityChanged`/`ItemExtraAvailabilityChanged`/`ItemVariationAvailabilityChanged` + listeners outbox + `InvalidateKioskMenuCacheOnItemAvailabilityChanged` | `tests/Feature/Stock/*` (11 fichiers, ls vérifié) ; `tests/Feature/Availability/*` |
| 4 | BORNE (kiosk) | mûr, wizard frozen (lecture) | bundles `kiosk-*`, menu cache invalidé par listener ci-dessus | suites kiosk existantes |
| 5 | CENTRAL (admin) | mûr | `resources/js/components/admin/stock/StockRuptureDashboardComponent.vue` ; `AvailabilityToggleComponent.vue` (admin items) | `StockDashboardI18nIntegrityTest.php` |
| 6 | CARNET (nouveau) | **inexistant — to-be-created** | seuls précédents : `app/Enums/IsAdvance.php`, pattern Spatie MediaLibrary (`app/Models/Item.php:10`), pattern PIN branche (`DefaultAccessController`) | (tests TO BE CREATED, cf. §5) |

RBAC : rôles `POS Operator`, `Chef`, `Branch Manager` vérifiés
(`database/seeders/LeCayenneRoleLandingUrlSeeder.php:27-30` — POS Operator→pos,
Chef→kitchen-display-system).

## §2 Map separated (hors scope écriture)
- Mobile RN `mobile/` + Web standalone `/Users/1millnonstop/Downloads/web` : STANDALONE,
  no API wireup. Le web réel déployé (`lecayenne-web-deploy/Site lecayenne`) consomme le
  menu backend → la rupture doit s'y refléter via le même cache menu (audit W3 vérifie).

---

## §3 Système A — Rupture stock CAISSE + KDS

### Contract
Le staff caisse (POS Operator) et cuisine (Chef) marquent un produit « en rupture » en
2 taps ; propagation temps-réel : caisse (menu POS), borne (cache kiosk-menu invalidé +
event WS), web storefront (même menu). Réactivation aussi simple. Zéro accès au reste du
catalogue (pas de prix/composition).

### Frozen zones adjacentes (strict)
`public/js/pos-wizard.js` + `admin-pos-v4.blade.php` (no-touch absolu) ;
`PaymentComponent.vue`, `PosV5TrancheRow.vue` ; composants kiosk Vue (lecture seule).
La nouvelle UI vit AILLEURS (nouveaux fichiers + point d'entrée ~5 lignes).

### Sub A.1 — RBAC permission dédiée (Wave 1)
**Anchors** : `database/seeders/PermissionTableSeeder.php` ;
`database/seeders/RolePermissionTableSeeder.php` ;
`app/Http/Controllers/Admin/AvailabilityController.php:21-29`.
**Tasks** :
- T-1.1 Créer permission `availability_toggle` (guard sanctum) + seeder idempotent
- T-1.2 Assigner à POS Operator + Chef + Branch Manager (pattern RolePermissionTableSeeder)
- T-1.3 OR-gate contrôleur : `permission:items_edit|availability_toggle` sur
  toggle/toggleExtra/toggleVariation/showBranchAvailability ; `setMaxDailyQty` reste items_edit seul
**Acceptance** : (test TO BE CREATED at `tests/Feature/Availability/AvailabilityTogglePermissionTest.php`)
— POS Operator 200 sur toggle, 403 sur setMaxDailyQty ; Chef 200 ; customer token 403.

### Sub A.2 — Panel rupture partagé + POS (Wave 2)
**Anchors** : `resources/js/components/admin/pos/PosComponent.vue` (header, non-frozen) ;
endpoint POST `/api/admin/menu/availability/toggle` (routes/api.php:280) ;
store `resources/js/store/modules/itemAvailability.js`.
**Tasks** :
- T-2.1 Créer `resources/js/components/admin/shared/AvailabilityTogglePanel.vue`
  (liste produits par catégorie, recherche, badge rupture, toggle 1-tap, FR)
- T-2.2 Bouton « Rupture » header PosComponent (diff ≤ 10 lignes) ouvre le panel
- T-2.3 Dédup écho broadcast : PosComponent écoute déjà ItemAvailabilityChanged →
  ignorer son propre acteur (pas de double toast)
- T-2.4 Respect throttle 60/min : pas de listing via ce bucket, toggles unitaires OK
**Acceptance** : (test TO BE CREATED at `tests/Feature/Availability/PosRupturePanelAccessTest.php`)
+ visuel `http://127.0.0.1:8000/admin/pos` capturé + analysé (panel ouvert, badge rupture).

### Sub A.3 — KDS rupture + propagation cross-surface (Wave 3)
**Anchors** : composant racine KDS (`resources/js/components/admin/kitchenDisplaySystem/`),
`KdsSyncService.js` ; listener `InvalidateKioskMenuCacheOnItemAvailabilityChanged.php`.
**Tasks** :
- T-3.1 Bouton « Rupture » dans le header KDS → même `AvailabilityTogglePanel.vue`
- T-3.2 Vérifier que le rôle Chef atteint l'endpoint (middleware chain complet)
- T-3.3 E2E propagation : toggle depuis KDS → item grisé/retiré borne (kiosk-menu) +
  caisse (menu POS temps réel) + web storefront
**Acceptance** : `tests/Feature/Stock/StockAvailabilityAfterCommitTest.php` PASS (existant)
+ (test TO BE CREATED at `tests/Feature/Availability/KdsRuptureToggleTest.php`)
+ e2e navigateur : rupture KDS visible borne `http://127.0.0.1:8000/kiosk/idle` + POS.

---

## §4-5 Système B — CARNET (mini-app PIN notes + compta interne)

### Contract
App web mobile-first `/carnet`, déverrouillée par CODE PIN (pas de compte). Saisies :
**dépense** (montant, libellé, photo facture optionnelle), **acompte** (travailleur,
montant), **note** libre. Résumés : jour, mois, par travailleur. FR. Interne — AUCUN
lien fiscal NF525 ni tiroir.

### Sub B.1 — Backend (Wave 4)
**Anchors (to-be-created, patterns vérifiés)** : Spatie media pattern `app/Models/Item.php:10` ;
throttle pattern PIN `routes/api.php` ; session Laravel standard.
**Tasks** :
- T-4.1 Migration `daily_book_entries` : type enum(expense,advance,note), label,
  worker_name nullable, amount decimal nullable, entry_date, note text nullable,
  branch_id default 1, created_at ; + stockage PIN hashé (settings ou env DAILY_BOOK_PIN)
- T-4.2 `app/Models/DailyBookEntry.php` (InteractsWithMedia, collection `invoice-photo`)
- T-4.3 `app/Services/DailyBook/DailyBookService.php` : CRUD + agrégats jour/mois/worker
- T-4.4 `app/Http/Controllers/DailyBook/{DailyBookAuthController,DailyBookEntryController,DailyBookSummaryController}.php`
  + `app/Http/Middleware/EnsureDailyBookPin.php` (session `daily_book_unlocked`,
  régénération session au login, timeout)
- T-4.5 Routes `routes/web.php` groupe `/carnet` : POST pin `throttle:5,1`, CRUD +
  résumés derrière middleware ; upload photo validé (mimes jpg/png/webp, max 8 Mo)
**Acceptance** : (tests TO BE CREATED at `tests/Feature/DailyBook/DailyBookPinAuthTest.php`
+ `tests/Feature/DailyBook/DailyBookEntryCrudTest.php` +
`tests/Feature/DailyBook/DailyBookSummaryTest.php`) — PIN faux 401 + throttle 429 ;
CRUD OK ; résumé mois = somme exacte par worker ; route sans session → redirect PIN.

### Sub B.2 — Frontend (Wave 5)
**Tasks** :
- T-5.1 `resources/views/daily-book.blade.php` + entrée bundle `daily-book`
  (webpack.mix.js) + `resources/js/daily-book/` app Vue légère
- T-5.2 Écran PIN (pavé numérique), écran saisie rapide (3 boutons Dépense/Acompte/Note),
  capture photo (`<input capture="environment">`), liste du jour, vue résumé mois
- T-5.3 Mobile-first (100dvh, gros taps), palette Cayenne, FR
**Acceptance** : visuels Playwright `http://127.0.0.1:8000/carnet` (PIN + saisie + résumé)
capturés + analysés ; 0 label brut ; e2e : PIN → dépense 12,50 € + photo → visible résumé.

---

## §6 Système C — Audit global adversarial (Wave 6)

### Contract
Workflow multi-agents : finders read-only par lentille × réfuteurs adversariaux (2 par
finding), verify-before-report (file:line + repro obligatoires sinon REJETÉ). Lentilles :
sync temps-réel (outbox/WS/polling), gestion (catalogue/stock/coupons/employés), caisse
(argent/tiroir), NF525-adjacence des nouveautés, RBAC/authz, nouvelles surfaces (panel
rupture, carnet PIN), qualité data. Rapport ultra-détaillé →
`reports/goal-rupture-carnet-2026-07-15/AUDIT_REPORT.md`. Puis heals TDD par priorité
P0→P1→P2, max 3 boucles par cluster puis escalade.

**Acceptance** : rapport écrit avec chaque finding = sévérité + file:line + repro +
evidence ; tous P0/P1 confirmés healés+testés ou explicitement escaladés owner ;
re-audit cycle 2 = findings identiques et P0+P1=0.

---

## §A Agent army map
Rôles per fan-out matrix du skill (Architect/Security/DBA/SRE/Implementer/RED/QA-Vis/
RED-Vis). Dispatch : spécialistes read-only en parallèle (1 message multi-Agent ou
Workflow) ; implémenteur JAMAIS parallèle avec un autre implémenteur ; RED après chaque
commit. Rapports persistés `reports/goal-rupture-carnet-2026-07-15/wave-<W>-<role>.md`.

## §X Vagues
| W | Scope | Parallélisme | Checkpoint |
|---|---|---|---|
| 0 | GOAL doc + baseline | solo | NF525 OK, baseline notée |
| 1 | RBAC availability_toggle | solo (seeder partagé) | test permission vert |
| 2 | Panel POS | audit fan-out ∥, impl solo | tests + visuel POS analysé |
| 3 | KDS + propagation | idem | e2e cross-surface prouvé |
| 4 | Carnet backend | impl solo | 3 suites DailyBook vertes |
| 5 | Carnet frontend | impl solo | visuels analysés + e2e PIN→dépense |
| 6 | Audit adversarial | max ∥ (Workflow) | rapport + P0/P1 healés |
| 7 | Convergence finale | solo | 2 cycles identiques P0+P1=0, BRAIN à jour |

Checkpoint fin de vague (6 points, Axis 3) : tasks PASS · frozen-diff 0 · NF525 si touché ·
visuel si frontend · RED dispute close · BRAIN §2/§3. Interrupt : commit WIP
`wip(<wave>):` + manifest `reports/goal-rupture-carnet-2026-07-15/INTERRUPT_<wave>.md`.

## §G Owner gates
| Gate | Description | WHO | WHAT | WHERE | Status |
|---|---|---|---|---|---|
| G1 | Push remote (jamais auto) | Owner | ordre explicite « push » | message chat | PENDING |
| G2 | Choix du CODE PIN carnet réel | Owner | valeur PIN (défaut dev 2468 à changer) | `.env DAILY_BOOK_PIN` | PENDING |
| G3 | Déploiement VPS des bundles | Owner | rebuild + restart | deploy log | PENDING |
| G4 | Tout heal W6 touchant frozen zone | Owner | LOCK doc contresigné | `plans/LOCK_*.md` | PENDING |

Aucun gate ne bloque W1-W7 en local — G1/G3 = post-convergence.

## §R Références
`~/.claude/skills/ultra-audit-profond` · `test-e2e` · `lock-plan` · CLAUDE.md §5-§13 ·
CONSTITUTION.md · SYNC_CONTRACT.md · memory `goal_max_secu_sync_data_audit_2026-07-15`.

## §F Règle finale
DONE = features A+B livrées testées techniquement ET visuellement, audit C rapporté et
healé, convergence 2-cycles, frozen-diff 0, NF525 CHAIN OK, BRAIN à jour. Pas de push
sans owner. Production-perfect, pas « presque ».

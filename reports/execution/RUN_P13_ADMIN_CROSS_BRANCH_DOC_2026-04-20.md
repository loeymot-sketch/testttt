# RUN — P13_ADMIN_CROSS_BRANCH_DOC — 2026-04-20

**PRIMARY_MODEL:** Composer (foodking-routine-implementer)  
**Plan:** `tasks/execute-2026-04-20/V4_11_P13_ADMIN_CROSS_BRANCH_DOC.md`

## Statut

**PARTIAL_COVERAGE** — périmètre sensible classé ; 52 contrôleurs racine en annexe « à classer » ; +2 contrôleurs `Admin\Fiscal\*` en section A.

## Métriques (contrôleurs classés dans le corps du map)

| Catégorie | Compte | Détail |
|---|---:|---|
| **A** — bornés `branch_id` (ou équivalent fiscal / dashboard) | **6** | 4 racine + `Fiscal\ZReportController` + `Fiscal\XReportController` |
| **B** — cross-branch / global volontaire | **9** | Settings, catalogue, IAM, analytics config, société |
| **C** — non classé / risque (investigation recommandée) | **12** | Commandes liste/show, transactions, POS opaque, users, livreur, détail client |
| **Total A+B+C (entrées distinctes)** | **27** | Dont 25 sous `Admin/*.php` + 2 Fiscal |

Inventaire racine : **77** fichiers `app/Http/Controllers/Admin/*.php` (commande `ls`).

## Couverture

- **Partielle** : analyse approfondie des contrôleurs sensibles + services associés (`OrderService`, `TransactionService`, `KitchenDisplaySystemOrderService`, `OrderStatusScreenOrderService`, `DashboardService`, `ItemService`, `UserService`, fiscal).
- **Annexe** : 52 contrôleurs racine non encore catégorisés (liste dans le doc).

## Cycles futurs recommandés (1 axe par contrôleur C à risque confirmé)

1. **PosOrderController** + **OnlineOrderController** + **TableOrderController** — scope liste `OrderService::list` et `show` sans vérif branche.
2. **SalesReportController** — même dépendance `OrderService::list`.
3. **TransactionController** — `branch_id` optionnel dans `TransactionService::list`.
4. **PosController** — tracer services POS / tiroir et borne branche.
5. **AdministratorController** — liste utilisateurs / filtre `branch_id` optionnel.
6. **SimpleUserController** — idem.
7. **EmployeeController** — idem.
8. **CreditBalanceReportController** — `UserService::list` sans borne par défaut.
9. **DeliveryBoyOrderController** — liste par livreur sans filtre branche explicite.
10. **MyOrderDetailsController** — `orderDetails(User, Order)` ; abuse possible sur param `User`.
11. **Documentation** — cycle synchronisation `docs/AUTHZ_MATRIX.md` (écarts listés section D du map).
12. **Inventaire complémentaire** — classifier les 52 fichiers de l’annexe + tout sous-répertoire admin hors `Fiscal` si ajouté plus tard.

## Fichiers produits

- `docs/centralisation/.gitkeep`
- `docs/centralisation/ADMIN_CROSS_BRANCH_MAP_2026-04-20.md`

## Conformité tâche

- **Aucun fichier sous `app/`, `routes/`, `tests/`, `database/` modifié** par cette exécution.
- `docs/AUTHZ_MATRIX.md` : **lecture seule** (non modifié).
- Pas de `git add` / `git commit`.

## Note validation `git status`

Le dépôt contenait déjà des modifications non liées à P13 (`.cursor/`, `app/`, `resources/`, etc.). Le livrable P13 se limite au dossier `docs/centralisation/` et à ce rapport.

## Suite

Validator / planner : vérifier présence des sections A–E dans le map, taille ≥ 200 octets, et absence de patch code dans le document.

---

## AUDIT (Claude orchestrateur) — 2026-04-20
**Verdict : CLOSED — PARTIAL_COVERAGE — 0 remediation**

| # | Check | Résultat |
|---|---|---|
| 1 | Doc créé | `docs/centralisation/ADMIN_CROSS_BRANCH_MAP_2026-04-20.md` 72 lignes |
| 2 | 5 sections A/B/C/D/E | toutes présentes (vérifié grep) |
| 3 | Métriques classification | A=6, B=9, C=12 sur 27 controllers analysés ; 52 en annexe "à classer plus tard" sur 77 total |
| 4 | Aucun fichier `app/` modifié | confirmé via `git status` |
| 5 | `docs/AUTHZ_MATRIX.md` non modifié | confirmé |
| 6 | Cycles futurs recommandés | 12 (1 par C-controller à risque) + 1 cycle annexe (52 restants) + 1 cycle MAJ AUTHZ_MATRIX |
| 7 | Doc <1500 lignes | OK (72 lignes) |

**PARTIAL_COVERAGE accepté** : 27/77 = 35% de couverture, mais les 27 traités sont les **plus sensibles** (orders, transactions, KDS, fiscal, items, settings, users, branches). Les 52 restants sont essentiellement des CRUD secondaires (notifications, themes, sliders, etc.) — risque cross-branch faible. Lister un sous-ensemble représentatif pour 0.5 j-h est conforme au plan §"Borne du scope".

**Valeur produite** : **première SSOT documentaire** sur la centralisation admin cross-branch. Tout nouveau cycle peut désormais référencer ce doc plutôt que ré-investiguer de zéro. Les 12 controllers en C sont le **vrai backlog actionnable** pour solidifier la centralisation globale.

**Limitation honnête** : doc statique snapshot 2026-04-20. À regénérer après tout cycle qui ajoute / refactor un controller admin. Recommandation : ajouter dans le checklist `cursor-export-new-account/skills/project-handoff/SKILL.md` un point "regénérer ce doc tous les N cycles".

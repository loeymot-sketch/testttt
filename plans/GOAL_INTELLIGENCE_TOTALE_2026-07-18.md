# GOAL — Intelligence totale : fonctionnalités + structure, problèmes RÉELS par système (2026-07-18)

## §0 Préambule
- **Mission owner** : « analyse avec intelligence toutes les fonctionnalités et structure et trouve les problèmes réels de tout, chaque système, avec plan et adversarial reasoning. »
- **Contexte** : VPS déployé hier `57df489ce` ; e2e UI convergé (`reports/test-e2e/goal4-predeploy-2026-07-17/`) → cette mission vise la **LOGIQUE métier, les invariants cross-système, la data, la structure** — pas les pixels.
- **Working tree** : commits à jour, restes non-commités d'autres sessions (Preflight WIP, docs) = HORS scope, ne pas toucher.
- **Pipeline par finding** : hunt (read-only) → réfutation adversaire (chaque finding attaqué) → registre final P0-P3 avec preuve file:line/DB + repro. AUCUN heal sans « go » owner (livrable = registre + quoi-healer/quoi-gater).
- **Convergence** : registre stable après réfutation ; chaque finding retenu = CONFIRMÉ par ≥1 réfuteur hostile qui a échoué à le tuer.

## §1 Systèmes + ancres (vérifiées 2026-07-18 via find/ls — sorties réelles en session)
| # | Système | Ancres primaires | Tests |
|---|---|---|---|
| S1 | BORNE (kiosk) | `resources/js/components/frontend/kiosk/*.vue` (18+), `app/Http/Controllers/Frontend/{ItemController,KioskEventController}.php`, `app/Services/Kiosk/KioskMenuService.php` | `tests/Feature/Kiosk/` |
| S2 | CAISSE (POS) | `app/Http/Controllers/Admin/{AdminPosV4Controller,PosOrderController,OnlineOrderController,OrderHistoryController}.php`, `public/js/pos-{app,shell,wizard}.js` | `tests/Feature/Pos/` |
| S3 | KDS + OSS | `app/Domain/Kds/`, `app/Services/KdsSyncService.php`, `app/Events/{KdsOrderRecalled,OrderStatusChanged}.php`, `OrderStatusScreenOrderService` | `tests/Feature/Kds/` |
| S4 | WEB (site frontend API) | `app/Http/Controllers/Frontend/*` (order/track/loyalty/otp), source=5 endpoint borne partagé | `tests/Feature/Frontend/` |
| S5 | ADMIN/GESTION | 94 contrôleurs `app/Http/Controllers/Admin/`, `app/Services/*` (Dashboard, DailyBook, Catalog, Cash) | `tests/Feature/{Admin,Menu,Stock}/` |
| S6 | BACKEND fiscal/data | `app/Services/Fiscal/{7 fichiers}`, 190 migrations, triggers, `audit_logs/z_reports`, BranchScope 20 modèles | `tests/Feature/Fiscal/` |
| S7 | STRUCTURE + contrats cross-système | routes/api.php, Events/Outbox/Listeners, enums statuts, config/*, SYNC_CONTRACT.md | sentinelles |

## §2 Lentilles de chasse par système (logique, pas pixels)
- S1 : intégrité panier→quote→order (préview vs sealed), gestion offline/timer, stock/86 mid-parcours, upsell/menu combos, idempotence commande.
- S2 : encaissements partiels/split/rendu, park/resume, annulation/refund→tiroir/Z, files (borne/web/livraison), remises, réimpression.
- S3 : machine à états (transitions illégales ?), bump/recall/undo, board-release vs paiement, stations, âge/priorité, reconnexion.
- S4 : compte/OTP/fidélité (accrual/burn/expiry), tracking, adresse/livraison, prix vs borne, replay/idempotence.
- S5 : CRUD catalogue→projections (caisse/borne désynchro ?), stock/rupture vs ventes, dashboard chiffres vs DB, carnet, RBAC réels, imports/exports.
- S6 : gap-free fiscal_sequence multi-chemins, Z couvre tout ?, TVA par type, refunds fiscaux, retention, cash-trail complet, intégrité FK/orphelins, index manquants sur requêtes chaudes.
- S7 : contrats événements (payload vs consommateurs), enums/statuts dupliqués, config incohérentes, doubles sources de vérité, code mort dangereux, couplages frozen.

## §A Armée
- **W1 HUNT** : 7 finders read-only (1/système), single message parallèle. Verify-before-report obligatoire (file:line lu + repro concrète), max ~1200 mots, EXCLUSION de la liste des problèmes déjà trackés (fournie dans le brief).
- **W2 RÉFUTATION** : 3 réfuteurs hostiles, pool de findings réparti, mission = TUER chaque finding (faux positif, by-design, déjà couvert, non-reproductible). Verdict CONFIRMÉ/RÉFUTÉ/DOWNGRADE par finding.
- **W3 REGISTRE** : synthèse orchestrateur → `reports/goal-intelligence-2026-07-18/REGISTRE_FINAL.md` + rapport owner.
- **W4 HEAL** : sur « go » owner uniquement (hors mission actuelle).

## §G Gates owner (pré-existants, non re-audités ici)
Chaîne NF525 VPS Workstream A · secrets registry · janitor files POS · LOCKs frozen listés dans CONVERGENCE_FINAL.

## §F Règle finale
Un problème entre au registre SEULEMENT avec : preuve lue (file:line/DB), scénario de repro concret, ET survie à la réfutation. Tout le reste meurt en W2.

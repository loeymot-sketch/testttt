# GOAL — ULTRA E2E TOUS SYSTÈMES (2026-07-10)

Owner /goal (Stop-hook) : « test-e2e de tout system + vérification complète du parcours réel de
CHAQUE fonctionnalité (POS, kiosk, site web, autres) ; plan→test→corrige→vérifie→re-test en
boucle par fonctionnalité, jusqu'au vert ».

## §0 Préambule
- **Branch** `pos/category-first-caisse-2026-06-23` · HEAD `a693aa096` · working-tree 84 fichiers
  sales (mix : mes fixes récents borne/caisse NON commités + churn pré-existant). **Décision** :
  ne rien committer sans gate owner ; travailler en place, checkpoints en fichiers.
- **NF525 baseline** : audit_logs=4938 (hash ffe782b9), z_reports=25, orders=2860 — append-only requis.
- **Convergence** (Axis 6) : 2 cycles consécutifs P0+P1=0 avec set-équivalent. Rejet strict :
  raw label, layout cassé, erreur console, frozen diff≠0, P0 RED non traité, test fail non documenté.
- **Frozen §7** intouchables sans LOCK : pos-wizard.js, Kiosk{Wizard,App,Upsell}Component.vue,
  PaymentComponent.vue, PosV5TrancheRow.vue, Fiscal/*, BranchScope, IdempotencyKeyMiddleware,
  PricingService, OrderStateMachine.
- **Per-tâche** : pipeline `ultra-audit-profond` (audit 5-spé → impl → RED → test → visuel).

## §1 Systèmes (anchors vérifiés 2026-07-10)
| # | Système | Anchors réels | Tests | Maturité |
|---|---|---|---|---|
| S1 | **POS Caisse** | `public/js/pos-{app,shell,wizard}.js` (wizard FROZEN) ; Admin/Pos{,Order,Category,Loyalty}Controller | 29 (tests/Feature/Pos) | mûr |
| S2 | **Kiosk Borne** | 24 Vue `resources/js/components/frontend/kiosk/*` ; Frontend/OrderController ; FrontendOrderService | 44 (Kiosk*) | mûr (2 fixes ce jour) |
| S3 | **KDS** | KitchenDisplaySystemController + KdsSyncController | 23 (Kds) | mûr |
| S4 | **OSS** | OrderStatusScreenController | ? | à sonder |
| S5 | **Backend core** | Fiscal/* (7) · Pricing/* (7, SSOT) · Domain/Order/OrderStateMachine · PaymentStateMachine | Fiscal+Order suites | frozen-lourd |
| S6 | **Cross-surface sync** | borne→caisse→KDS→OSS (Outbox/broadcast/polling) | Symmetry, sync tests | critique |

## §2 Systèmes séparés (standalone, hors V1 central)
- **Web standalone** : `Site lecayenne` (déployé GitHub→Vercel) + `/Users/1millnonstop/Downloads/web`.
  Déjà audité+convergé 2026-07-09 (38 produits/9 cat verts, money-path OK, photos/pain/sauce fixés).
  → Ce GOAL : re-vérif rapide (non-régression) seulement.
- **Mobile** : `mobile/` (proto aligné, mobile-e2e 25/25 en juillet). → re-vérif rapide.

## §X Vagues de convergence
- **W1 — BASELINE (en cours)** : run suites POS/Kiosk/KDS/Fiscal/Order → statut réel de chaque
  fonctionnalité. Checkpoint : liste PASS/FAIL par système → `baseline/suite-results.txt`.
- **W2 — PARCOURS RÉELS e2e** : dérouler les vrais flux par système (POS : catégorie→wizard→
  paiement→tiroir→Z ; Borne : idle→wizard→paiement comptoir→queue ; KDS : réception→statuts ;
  OSS : affichage ; Cross : borne→caisse→KDS→OSS 1 commande cohérente). Captures + preuves DB.
- **W3 — AUDIT ADVERSAIRE** : workflow multi-agents par système (sécu/logique/sync/intégrité),
  chaque finding reproduit+vérifié → `findings/`.
- **W4 — HEAL + RE-TEST boucle** : corriger P0/P1 sûrs (non-frozen), re-run suites, re-e2e.
  Frozen touché → LOCK + gate. Boucle jusqu'à 2 cycles P0+P1=0.
- **W5 — CONVERGENCE FINALE** : suites complètes + cross-surface + frozen diff 0 + NF525 chain +
  rapport. BRAIN §2/§3.

Parallélisme : audit read-only en éventail DANS une vague ; heals séquentiels (pas 2 implémenteurs
en //). Web/mobile = re-vérif isolée.

## §G Gates owner (WHO/WHAT/WHERE)
| Gate | Desc | WHO | WHAT | WHERE | Statut |
|---|---|---|---|---|---|
| G1 | Toucher un frozen (ex: PricingService pour sauce +0,50 borne) | owner | LOCK countersign | LOCK §10 | PENDING (si requis) |
| G2 | Push + deploy VPS backend (fixes borne/caisse + éventuels heals) | owner physique | deploy log | BRAIN §2 | PENDING |
| G3 | Align borne sauce sur règle owner (backend) | owner | décision A/B | ce doc | PENDING (voir COMPARE_WEB_VS_BORNE) |

## §F Règle finale
DONE = chaque fonctionnalité de chaque système a un parcours réel VÉRIFIÉ vert (test + e2e +
adversaire), 2 cycles P0+P1=0, frozen 0, NF525 append-only, rapport + BRAIN à jour. Rien poussé
sans gate owner. Production-parfait, pas « presque ».

## État resumable
- Run dir : `reports/test-e2e/ultra-e2e-all-systems-2026-07-10/`
- Wave courante : **W1 baseline** (background `b14z7crpv`).
- Prochaine : triage baseline → W2 parcours réels.

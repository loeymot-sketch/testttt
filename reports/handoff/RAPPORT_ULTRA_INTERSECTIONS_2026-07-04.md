# ULTRA A→Z — Wave 1 : INTERSECTIONS & FONCTIONS PARTAGÉES — 2026-07-04
**Goal** : audit A→Z ultra-profond, agents adversaires + raisonnement, **abuser les fonctions communes /
intersections** (connectées à plusieurs systèmes) jusqu'à validation absolue, vérifier les fautes de logique.
8 fonctions partagées auditées (adversaire + raisonnement + verify + critic, 21 agents). HEAD `48050af80`.
Discipline : PHP/API/logique only (cowork refait le VISUEL KDS/caisse → aucun `.vue`).

## 1. RÉSULTAT — 12 incohérences soulevées, 8 confirmées, 3 réfutées, 1 downgrade. **2 P2 réels HEALÉS.**
Les fonctions partagées sont le point le plus fragile (un bug casse N systèmes) — et c'est là qu'étaient les
2 vrais bugs, dont **une faute de raisonnement de MA part** sur ma propre feature.

## 2. HEALÉS (2 × P2, TDD, frozen 0, NF525 OK, commités)
| Commit | Fonction partagée | Le bug + le fix |
|---|---|---|
| `[timing]` | **Horodatage cuisine × chemins de statut** | **FAUTE DE RAISONNEMENT (mienne)** : j'avais healé le stamp dans les 2 `changeStatus` (POS+KDS), MAIS les flux DOMINANTS créent la commande directement à ACCEPT/PREPARING (auto-prepare borne Plan B, POS direct, counter-collect) **sans passer par changeStatus** → `accepted_at` jamais posé → `actual_prep_seconds` **NULL sur ~100 % du volume** (0/3092 en base, live-prouvé). **Fix senior = CENTRALISER l'invariant dans le hook `saving` du modèle Order** (comme le hook `source_surface` existant, « sans per-writer plumbing ») → cascade first-write-wins, AUCUN chemin ne peut l'oublier ; stamps explicites retirés. |
| `[kds-sync]` | **KitchenReleaseRule (board-release) × KDS-list/sync/changeStatus** | `KdsSyncService::sync()` (flux delta temps-réel) ne filtrait QUE par statut, **oubliant `applyBoardReleaseFilter`** que `list()` + le guard `changeStatus` appliquent → une commande active mais NON released par le paiement (UNPAID non-cash) **fuyait dans le flux sync** alors qu'absente du board autoritaire. `sync()` rejoint `list()` (SSOT). |

## 3. RÉFUTÉS (verify-before-report — 3 faux positifs cette wave, dont 2 par moi)
- **Rounding SSOT `forWeb`** : l'agent a signalé que forWeb n'arrondit rien (vs forPos/forKiosk). **INTENTIONNEL** — le test `test_single_line_web_does_not_round` documente que le web garde la pleine précision (arrondi différé au débit, pas de compounding). J'ai tenté le fix → réverté.
- **`FrontendOrderService::changeStatus` sans timing** : c'est le chemin CUSTOMER self-cancel — ne peut atteindre QUE CANCELED (ligne 14 : tout autre target → 422). Pas de statut cuisine → pas de timing. Faux positif.
- + 1 réfuté par le vérificateur du workflow.

## 4. DOCUMENTÉS — réels mais sensibles/ambigus/déférés (fix fourni, application supervisée)
| Sév | Fonction | Pourquoi documenté | Fix |
|---|---|---|---|
| **P3 logic-fault** | **`branch()` NULL→admin** (`DefaultAccessModelTrait:7`) | `(int) null === 0` traite un user branch_id NULL comme admin(0)=toutes-branches. **Vérifié : les 24 users NULL-branch sont TOUS des guests** (0 staff) → non exploitable aujourd'hui (guests n'utilisent pas les requêtes staff branch-scopées). Mais latent : un futur STAFF NULL-branch aurait toutes-branches. Touche le **cœur multi-tenant** (BranchScope SSOT) → changer sans analyse d'impact profonde (chemins guests) est risqué. | `branch_id !== null && (int) branch_id === 0` + **boot-guard : aucun user STAFF ne peut avoir branch_id NULL** (invariant à la source). |
| **P3** | **loyalty_min_redeem** (kiosk seul) | Enforced sur le kiosk (`DiscountCalculator`) mais pas POS/standalone. **Ambigu** : le redeem assisté-staff (POS) peut légitimement outrepasser le minimum self-service. Décision produit. | Si le min doit s'appliquer partout → l'ajouter à `PosRedemptionService` + standalone ; sinon documenter l'override staff. |
| P3 | /loyalty/add-points non idempotent (vs /redeem UNIQUE) | Crédit staff manuel, double-POST possible. | Ajouter idempotence (clé ou UNIQUE) sur add-points. |
| P3 | standalone /loyalty/redeem orphan (order_id NULL) | Un redeem sans commande. | Exiger un order_id ou rattacher. |
| P3 | clawback clamp-at-0 | Points gagnés sur commande remboursée survivent si déjà dépensés ailleurs. | Ledger négatif autorisé OU tracer la dette. |
| P3 improvement | FiscalSequenceService : Cache::lock relâché avant commit | `lockForUpdate` porte la sérialisation à travers le commit → pas un bug, defense-in-depth. | Étendre le lock jusqu'au commit (belt-and-suspenders). |
| P3 | KDS items-board merge-key legacy vs snapshot (lignes Uber) | Uber = go-live différé. | À traiter au workstream Uber. |

## 5. GATES
- **144 tests verts** (Kitchen 7, KDS 44, Order 44, Payment 33, Loyalty 16) — blast radius des 2 heals.
- **Frozen 0** (Order model, KdsSyncService = non-frozen ; aucun `.vue` KDS/caisse = zéro conflit cowork). **NF525 CHAIN OK**.
- Commits : `[timing centralisé]` + `[kds-sync board-release]` (locaux).

## 5bis. PREUVE VISUELLE (captures headless analysées — mandat « amélioration visuel »)
4 surfaces capturées + lues (Read tool) `reports/ultra-review/2026-07-04/visual/` — **0 raw label, 0 layout break, 0 fuite i18n, branding intact partout** :
- **borne-idle.png** (1080×1920) : carrousel attract parfait (wordmark LE CAYENNE, NOS INCONTOURNABLES, hero Double Cheese + stamp 100% HALAL, dots 2/8, CTA « Touchez l'écran »).
- **caisse.png** : file `À ENCAISSER BORNE (9)` peuplée (N°A0017/A0020/A0021/A0022 @ 6,90€ + boutons Encaisser + « Voir plus (5) ») = **la fonction partagée counter-collect borne→caisse visible et correcte** (celle que gouvernent mon fix board-release + `258f74722`) ; grille menu + panneau ticket propres.
- **kds-refonte.png** (refonte VISUELLE cowork en cours) : cartes format symbolique `G | TACOS | L | K Mex | MAY`, et carte **[B] BORNE** porte le badge ambre `EN ATTENTE ENCAISSEMENT` = **mon fix board-release VISIBLE au travail** (PENDING_COUNTER released au board tout en flaggé non-réglé).
- **oss.png** : deux colonnes « En préparation » (magenta) / « Prêt » (vert), numéros N°A0001–A0007 nets.
**Verdict visuel : convergé, aucun heal visuel requis.** (Discipline respectée : capture read-only du KDS/caisse cowork, 0 `.vue` touché.)

## 6. LEÇON (la plus forte)
Le bug le plus profond était **une faute de raisonnement DANS MON PROPRE fix** : j'avais supposé que toutes
les commandes transitionnent via `changeStatus`, alors que les flux dominants les créent déjà à ACCEPT/PREPARING.
Mon test était vert sur le mauvais modèle mental. **La correction senior = centraliser l'invariant au niveau
modèle** pour qu'aucun chemin ne puisse le contourner. C'est exactement la classe de bug que l'audit des
fonctions partagées cible : un invariant appliqué sur certains chemins, oublié sur les autres.

# Audit — GESTION d'une commande SITE (web) : visibilité + transitions d'état

Auditeur adversaire SÉCU+SYNC+CORRECTNESS. Repo LOCAL, lecture seule, statique.
Parcours confirmé : `Order` `source=5`/`source_surface='web'` → file caisse
`GET /api/.../pos/web-orders/pending` (routes/api.php:917) → accept via
`OnlineOrderController::changeStatus` (routes/api.php:1120) → rendu KDS/OSS.

## Compte final
- **P0 : 0**
- **P1 : 0**
- **P2 : 1**
- **P3 : 1**

---

## [P2] resources/js/components/admin/pos/PosOrdersTrackerComponent.vue:276 — CTA « Accepter » web = bouton mort 403 pour le rôle POS Operator (mitigation PosComponent non répliquée)

**Repro (statique, déterministe) :**
1. Rôle **POS Operator** seedé (`RolePermissionTableSeeder.php:98-107`) = `pos`,
   `pos-orders`, `pos-discount-up-to-10`, `pos.redeem-loyalty` (+ KDS/OSS).
   **PAS `online-orders`.**
2. Le tracker (`/admin/pos-orders-tracker`) alimente son board via
   `posOrder/lists` → `admin/pos-order` (fetchOrders:968), gate
   `can('pos-orders')||can('pos')` (PosOrderController.php:index) → **passe**.
   `OrderService::list` n'impose **aucun** filtre source → les commandes web
   `PENDING` remontent et sont bucketées dans la voie « À encaisser »
   (WEB-TRACKER-VISIBILITY, tracker:664).
3. Le CTA « Accepter » s'affiche sur la seule condition `isWebPending(order)`
   (tracker:276) — **aucune garde permission**.
4. Clic → `acceptWebOrder` POST `admin/online-order/change-status/{id}`
   (tracker:1233). Cette route est gardée `permission:online-orders`
   (OnlineOrderController.php:35-43, `only(...'changeStatus'...)`). POS Operator
   ne l'a pas → **403** → la commande web n'est **jamais** acceptable depuis le
   tracker par le rôle caisse canonique.

**Cause :** c'est EXACTEMENT le défaut « bouton mort + confusion » déjà corrigé
dans `PosComponent.vue:2218-2238` (WEB-CAISSE-RBAC 2026-07-13) via le computed
`canProcessWebOrders` (exige `online-orders` access) qui **masque** le panneau
web aux rôles sans `online-orders`. Le heal tracker plus récent
(WEB-TRACKER-VISIBILITY 2026-07-20) a réintroduit le CTA **sans** cette garde.
Grep confirmatif : `canProcessWebOrders|online-orders|authPermission` =
**0 occurrence** dans PosOrdersTrackerComponent.vue.

**Faux négatif du test :** `tests/Feature/Pos/WebOrderInlineAcceptTest.php:66-67`
prend bien un POS Operator MAIS lui `givePermissionTo('online-orders')` — donc
il ne prouve pas le comportement du rôle **seedé par défaut** ; il masque le trou.

**Impact :** aucun (403 correct, isolation branche intacte, zéro fuite/impact
NF525). Purement fonctionnel/UX : le rôle caisse ne peut pas accepter une web
depuis le tracker. Admin / Branch Manager (qui ont `online-orders`) fonctionnent.
Le heal était né d'une plainte owner « commande web introuvable » → surface
réellement utilisée.

**Correctif scope-minimal (non-frozen) :** répliquer la décision owner existante.
Ajouter dans le tracker le computed (copie de PosComponent.vue:2231-2238) :
```
canProcessWebOrders() { const raw=this.$store.getters.authPermission;
  const perms=Array.isArray(raw)?raw:(raw&&Array.isArray(raw.data)?raw.data:[]);
  const e=perms.find(p=>p&&p.url==='online-orders'); return !!(e&&e.access===true); }
```
puis garder le CTA ligne 276 :
`v-else-if="col.id === 'accept' && isWebPending(order) && canProcessWebOrders"`.
La carte reste visible (continuité), seul le bouton mort disparaît pour les rôles
sans `online-orders` — parité exacte avec PosComponent.
**Décision owner requise** si l'intention est au contraire que POS Operator
ACCEPTE les web : alors le fix est un grant RBAC seeder (`online-orders` au rôle),
pas un masquage UI. Les deux sont cohérents ; c'est un choix produit, pas technique.

---

## [P3] routes/api.php:918 — `web-orders/pending` autorise `can('pos')` alors que la seule action de suivi (accept) exige `online-orders`

Le endpoint sert la file web à tout `can('pos')` (POS Operator inclus) mais
l'accept exige `online-orders`. Donnée servie à un rôle qui ne peut pas agir
dessus. Pas de fuite (branch-scopé L924-926, `OrderDetailsResource`), rôle staff
de confiance → **P3 défense-en-profondeur/cohérence**. Option : aligner le gate
sur `online-orders`, ou documenter explicitement « lecture seule » (le
`counter-collect/pending` jumeau a la même forme et le même choix assumé).

---

## Airtight (vérifié — ne PAS retoucher)

- **IDOR / cross-branch sur changeStatus & changePaymentStatus** — SOLIDE.
  Route-model-binding `Order $order` applique `BranchScope` (staff branch>0 →
  404 sur id d'une autre branche) + garde service `abort(403)` pour l'arête
  Admin-bypass : OrderService.php:2336-2341 (status) et 2625-2630 (payment).
  Couvert par `BranchScopeTest`, `FrontendOrderIdorStatusCodeTest`.
- **Double-accept / atomicité** — SOLIDE. Flip UNPAID→PENDING_COUNTER(+COUNTER_
  DEFERRED) et `changeStatus` dans **une** `DB::transaction`
  (OnlineOrderController.php:153-187) ; `lockForUpdate` + re-check statut frais
  (OrderService.php:2332-2368) ; clé idempotence minute-bucket (tracker:1235).
  Prouvé par `WebAcceptIsAtomicTest`.
- **Transitions invalides / résurrection terminal** — SOLIDE. `ValidStatusTransition`
  (pré-lock + re-check in-lock) + `assertNotResurrectingTerminalOrder`
  (OrderService.php:2205-2214, bloque l'edge Admin OrderStateMachine:82-84) +
  `PaymentStateMachine` (UNPAID→PAID uniquement ; PAID/REFUNDED terminaux).
- **Payé sans encaissement (vente off-book NF525)** — SOLIDE. PENDING_COUNTER→PAID
  sans `fiscal_sequence_no` **rejeté 422** (OrderService.php:2735-2739) ;
  UNPAID→PAID **alloue** la séquence fiscale (2759-2771) ; sealed-Z guard REFUNDED.
- **Bypass remboursement** — SOLIDE. `pos-refund` gardé sur les deux routes
  jumelles : RETURNED + CANCELED/REJECTED-si-PAID (OnlineOrderController.php:127-137 /
  PosOrderController.php:338-348) et REFUNDED (OnlineOrder:205-211 / PosOrder:372-378).
  Couvert `OnlineOrderRefundRequiresPosRefundTest`, `RefundBypassTwinRoutesGuardTest`.
- **Cohérence sync KDS/OSS** — COHÉRENT. `KitchenReleaseRule::applyBoardReleaseFilter`
  (SSOT SQL⟺booléen) admet PENDING_COUNTER → web acceptée visible au KDS
  (KitchenDisplaySystemOrderService.php:84) ; OSS montre KIOSK+TAKEAWAY (retrait),
  exclut DELIVERY **par design** (OrderStatusScreenOrderService.php:59-71) ;
  `OrderStatusChanged` re-broadcasté après commit (OrderService.php:2558) →
  propagation temps-réel. Web non-COD non board-released = intentionnel V1 100% COD
  (`WebNonCodOrderNotBoardReleasedTest`).
- **Fuite branche sur `web-orders/pending`** — SOLIDE. `where('branch_id',$branchId)`
  dès branch>0 ; Admin(0) voit tout (attendu).

## Frozen touchés (signalés, non modifiés — LOCK owner requis si besoin)
`OrderStateMachine.php` (edge Admin terminal→* neutralisé applicativement par
`assertNotResurrectingTerminalOrder`, OK), `BranchScope.php`,
`IdempotencyKeyMiddleware.php` — tous cités comme CAUSE, aucune édition proposée.
Le correctif P2 est **hors frozen** (composant Vue tracker).

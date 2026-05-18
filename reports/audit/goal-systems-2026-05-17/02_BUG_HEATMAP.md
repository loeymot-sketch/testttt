# FOODKING — BUG HEATMAP (système × axe)

**Date** : 2026-05-17 | Source : 22 sub-agents parallèles
**Légende** : 🟢 90+ /100 | 🟡 60-89 | 🟠 40-59 | 🔴 <40

## §1 Matrice surfaces × axes (scores main team)

| Surface ↓ \ Axe → | Archi | Business | UX | i18n | Integ | Tests | Perf | A11y | **Moy** |
|---|---|---|---|---|---|---|---|---|---|
| **S1 KIOSK** | 🟡 62 | 🟡 78 | 🟡 70 | 🟡 68 | 🟡 74 | 🟡 76 | 🟠 52 | 🟡 74 | **🟡 69** |
| **S2 POS** | 🟠 48 | 🟡 78 | 🟠 52 | 🔴 38 | 🟡 72 | 🟡 70 | 🟠 52 | 🔴 35 | **🟠 57** |
| **S3 KDS** | 🟡 65 | 🟡 70 | 🟡 60 | 🟡 65 | 🟡 70 | 🟡 65 | 🟡 60 | 🟡 60 | **🟡 62** |
| **S4 OSS** | 🟠 50 | 🟡 65 | 🟠 50 | 🟠 50 | 🟠 55 | 🔴 35 | 🟡 70 | 🟠 55 | **🟠 55** |
| **S5 ADMIN** | 🟠 48 | 🟡 74 | 🟠 52 | 🟡 66 | 🟡 70 | 🟠 44 | 🔴 38 | 🟠 42 | **🟠 54** |
| **S6 MOBILE** | 🔴 30 | 🟠 55 | 🟠 55 | 🔴 25 | 🔴 30 | 🟠 50 | 🔴 25 | 🟠 50 | **🟠 41** |

## §2 Matrice cross-cutting (axes × bloc)

| Bloc ↓ \ Axe → | Duplication | Sécurité | Sync | Perf | Data Integrity |
|---|---|---|---|---|---|
| **Score global** | 🟡 62 | 🔴 35 | 🟡 62 | 🟠 ~40 avg | 🟡 62 |
| **Sub-scores** | | A01=🔴22 A02=🔴35 A03=🟠48 A04=🔴30 A05=🔴18 A06=🔴20 A07=🔴25 A08=🟠40 A09=🟠38 A10=🟡75 | Outbox 🟡70 / Idempotency 🟡68 / Listener order 🟠50 / Pusher channel auth 🔴30 | Queue 🔴20 / N+1 🔴30 / Render 🔴30 / Bundle 🟠40 / Indexes 🟡80 / Cache 🟡70 | Fiscal core 🟢94 / Status transitions HMAC 🟠50 / FK gaps 🟠55 / Snapshot immut 🟠50 |

## §3 Matrice surfaces × layers (intégration)

> Lecture verticale : pour chaque surface, quels layers de qualité contribuent au score.

| Layer → \ Surface → | Kiosk | POS | KDS | OSS | Admin | Mobile |
|---|---|---|---|---|---|---|
| **L1 Backend HTTP+Services+Domain** (45) | 🟠 | 🟠 | 🟠 | 🟠 | 🟠 | n/a (standalone) |
| **L2 Sync Layer** (69) | 🟡 | 🟡 | 🟡 | 🟠 (Pusher 60% impl on OSS) | 🟡 | n/a |
| **L3 Payment+Fiscal** (79) | 🟡 (kiosk paye carte) | 🟡 (POS cash trail) | n/a | n/a | 🟡 (Z report) | 🔴 (mock payment) |
| **L4 Auth+Authz+Multitenant** (44) | 🔴 (wildcard token) | 🔴 (IDOR PosOrderController) | 🟠 | 🟠 (anonymous endpoints) | 🔴 (5 P0 escalation) | 🔴 (mock OTP) |
| **L5 Catalog+Persistence** (54 V1) | 🟡 | 🟡 | 🟡 | 🟡 | 🟠 (catalog CRUD ungated) | 🟠 (drift risk) |

**Lecture** : KIOSK et KDS héritent du meilleur backend (L3 Fiscal + L2 Sync). POS et ADMIN héritent du pire (L4 Auth 🔴 surfacing IDOR + escalation). MOBILE n'hérite de rien — totalement standalone.

## §4 Matrice cross-cutting × surface

| Cross-cutting → \ Surface ↓ | Trouvailles bloquantes |
|---|---|
| **KIOSK** | X1: composer wizard duplicated 3 surfaces ; X2: wildcard token ; X3: Pusher fallback ; X4: bundle 655 KB ; X5: composition_snapshot immut OK |
| **POS** | X1: PosComponent 3769 LOC + OrderService 2432 ; X2: 40 innerHTML XSS sites ; X3: Pusher channel bypass ; X4: 296 KB Vanilla + ?v=time() perpetual cache-bust ; X5: split-payment math intégrité |
| **KDS** | X1: KitchenDisplaySystemOrderService overlap ; X2: bump endpoint authz OK ; X3: dedicated KdsBumped event absent ; X4: render perf many orders ; X5: bump localStorage unbounded |
| **OSS** | X1: thin shell ; X2: public endpoint branch_id enum (SaaS blocker) ; X3: WebSocket realtime BROKEN sur wall (no auth context) ; X4: 2s polling × NAT fleet ; X5: order token leaked publicly |
| **ADMIN** | X1: 380 components + Vuex 113 ; X2: 5 P0 (RCE LanguageService + escalation 4 sites) ; X3: cross-branch admin Pusher subscribe ; X4: admin-shell.js chunk concatenation broken ; X5: TaxService FK checks=0 |
| **MOBILE** | X1: data drift risk catastrophic ; X2: mock OTP/payment/loyalty ; X3: n/a (standalone) ; X4: Babel-in-browser disaster ; X5: PII plaintext localStorage RGPD |

## §5 Top P0 par dimension cross-validated

| Dimension | Top P0 cross-validated | Cité par |
|---|---|---|
| **Auth/Authz** | Sanctum wildcard `['*']` + IDOR PosOrderController:108 + Idempotency disabled defaut + LanguageService RCE | S1-RED, S2-RED, S5-RED, L4, X2 |
| **Sécurité layer** | 5 NEW : SimpleUserController, MessageRequest, missing security headers, TrustHosts commented, googleMapKey | X2 NEW |
| **Sync** | ItemAvailability listener order violated + 3 events no producer + Pusher channel admin-bypass | L2, X3 |
| **Architecture** | dual Order/FrontendOrder + OrderService 2432 LOC + 14 controllers DB facade | L1, X1 |
| **Mobile** | Stack lie (HTML+JSX+Babel-in-browser) + RGPD + mock payment/OTP | S6 main + RED |
| **Fiscal/Payment** | PaymentService:172 cents truncation (ACTIVE V1) + split-payment phantom CARD | L3, S2-RED |
| **UX legal** | KDS allergen pill miss items board (FIC 1169) + POS wizard 0 ARIA 32px touch | S3-RED, S2 main |
| **Ops** | No automated backup (6y NF525 retention loss) + QUEUE_CONNECTION=sync drift | L5, X4 |
| **Data integrity** | Status transitions not on HMAC chain + composition_snapshot no immutability guard | X5 |
| **SaaS V2** | Items.branch_id absent + no billing infra | L5, X1, X5 |

## §6 Heatmap par effort/impact (quick wins identifiés)

> Quick win = effort ≤ 2h + impact ≥ 1 P0 closed.

| Quick win | Effort | Impact |
|---|---|---|
| `.env` `QUEUE_CONNECTION=sync→redis` (P0-CV-10) | 30min owner | Defense-in-depth restored, ItemAvailability events fan-out OK |
| Flip `IDEMPOTENCY_MIDDLEWARE_ENABLED=true` (P0-CV-05) | 30min owner | NF525 invariant enforced |
| `safety-check.sh` 15 zones (cycle précédent — already done working tree) | DONE | Frozen zone gate functional |
| `commitlint.yml` workflow blocking "up" | 30min | Hygiene historical |
| `gitleaks-action` GitHub Action | 15min | AWS leak detection at PR |
| KDSOrderItemsResource expose `allergens_snapshot` (P0-CV-14) | 2h | FIC 1169 legal exposure closed |
| Stripe.php:51 cents fix (cycle précédent — already done working tree) | DONE | Per-order €0.99 loss closed (latent V1) |
| PaymentService:172 cents fix (P0-CV-15) | 2h | Active V1 cents bug closed |

**Total quick wins** : ~6h Claude + ~1h owner → ferme 5 P0 dont 1 active legal exposure.

---

**Lecture rapide** : la heatmap montre que les meilleurs scores sont **NF525 fiscal 94/100, Sync layer 69/100, KDS Items board 65/100, Kiosk integration 74/100**. Les pires sont **Sécurité A05 misconfig 18/100, Mobile archi 30/100, Mobile i18n 25/100, Auth/Authz layer 44/100**. **La priorité d'investissement** est claire : sécurité auth/authz + mobile decision + sécu misconfig (3 NEW security holes) avant tout refacto architectural lourd.

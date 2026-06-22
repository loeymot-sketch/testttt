# GOAL — ABUSE TOTAL : chaque fonctionnalité · chaque bouton · chaque micro-détail (Le Cayenne V1)

> Owner 2026-06-20 (no limits) : « plan ultra-complexe de test+analyse+correction-en-boucle, capture de CHAQUE
> fonctionnalité/bouton/détail, ≥10 agents parallèles (logique / raisonnement / dispute / ultra-breakdown /
> fix-planner / abuseur…), boucle par fonctionnalité jusqu'à validation avant la suivante, max profond + max
> large, échelle 1000h+, tous les skills, max intelligence ». Ce GOAL est ce masterplan : un **moteur de
> couverture exhaustive surface-par-surface, feature-par-feature, bouton-par-bouton**, piloté par une armée de
> **12 agents adversaires** qui bouclent jusqu'à 0 P0/P1 sur CHAQUE détail avant d'avancer.

## §0 — Préambule (le contrat d'exécution)
- **Working tree** : worktree `wizard-wysiwyg-2026-06-14`, HEAD `b40390a31` (LOCAL). Mutations sur le rig jetable `:8766` (foodking_e2e, MySQL+redis+queue+soketi UP). PHPUnit = SQLite `:memory:` → `php artisan test --filter X` (SANS APP_ENV=e2e). Vitest = `npx vitest run`. **G-PUSH owner-only**, commits chemins-explicites uniquement.
- **Anti-fiction (ANCHOR-FIRST, non-négociable)** : toute tâche cite file:line réel + repro. Toute « validation » nomme un test (existant `tests/...` OU `(test À CRÉER à tests/...)`) + une capture. Verify-before-heal : refute-by-default (cette campagne a réfuté 8 over-claims).
- **Frozen §7** (jamais éditer sans LOCK owner+gate §10) : `KioskWizard/App/Upsell.vue`, `PaymentComponent.vue`, `PosV5TrancheRow.vue`, `public/js/pos-wizard.js`+`.css`, `admin-pos-v4.blade.php`, `FiscalSequenceService`, `ZReportService`, `AuditLogService`, `BranchScope.php`, `IdempotencyKeyMiddleware.php`, `PricingService.php`, `OrderStateMachine.php`. Un défaut RÉEL en zone frozen → finding « owner-LOCK requis », pas d'édition.
- **NF525 §8** : `fiscal_sequence_no` gap-free/branche, HMAC chain `audit_logs`/`z_reports`, `composition_snapshot` figé, montants 100% backend (PricingService).
- **Définition de VALIDÉ (par feature)** : 2 cycles consécutifs P0+P1=0 avec **set de findings identique** (set-equality anti-flake) + capture visuelle Read+analysée propre + tests verts. Tant que non-validé → la boucle ne passe PAS à la feature suivante (mandat owner).
- **Pipeline par tâche** : `ultra-audit-profond` (14 étapes) ; orchestration adversaire via **dynamic Workflow**.

## §1 — Carte des systèmes (ancrée 2026-06-20, vérifiée par grep/find)
| # | Système | Surfaces | Ampleur ancrée |
|---|---|---|---|
| S1 | **BORNE / Kiosk** | idle→menu→item→wizard→panier→upsell→paiement→suivi | 48 `.vue` `resources/js/components/frontend/kiosk/` (3 FROZEN) |
| S2 | **POS / Caisse** | pos→floorplan→wizard-popup→paiement→parked→tracker→encaissement→cash→refund | 23 `.vue` `admin/pos/` + `public/js/pos-wizard.js`(FROZEN vanilla) + `EncaissementComponent` + `cash*/` |
| S3 | **KDS** | board V2 + legacy rollback, card, recall, stations, bump | 7 `.vue` `admin/kitchenDisplaySystem/` + `KitchenReleaseRule` |
| S4 | **OSS** | mur public + authed (preparing/ready) | `admin/orderStatusScreen/` `OrderStatusScreenComponent`+`PreparingAndReadyComponent` |
| S5 | **GESTION / Admin** | ~36 surfaces / **41 routes** (Catalogue, Settings×18, RBAC, Reports, Customers, Stock, Coupons/Offers, Messages/Push, Orders, Observability) | `admin/` 36 dirs · **81 `.vue` money/fiscal** |
| S6 | **LIVREUR** | driver-app (list/show/change-status) + admin deliveryBoys + cash-sessions | `Frontend/DeliveryBoyOrderController` + `admin/deliveryBoys/` + `admin/deliveryBoyCashSession/` |
| ⊕ | **Surface totale** | **2280 éléments interactifs** (867 `@click` + 826 `<button>` + 591 `v-model`) | 394 `.vue` · 635 routes API · corpus : 682 Feature + 297 Vitest + 300 E2E |

---

# §A — L'ARMÉE (12 agents) + la BOUCLE PAR-FEATURE (le cœur du plan)

> Le owner exige ≥10 agents parallèles à rôles distincts + ultra-breakdown + fix-planner + boucle-jusqu'à-validé.
> Voici les 12 rôles et la chorégraphie exacte appliquée à CHAQUE feature/bouton.

## §A.1 — Les 12 agents (rôles distincts, fan-out parallèle)
| # | Agent | Mandat (par feature) | subagent / tools |
|---|---|---|---|
| A1 | **LOGIC** | Correctness métier : state-machine, pricing SSOT, règles (coupon/stock/RBAC), invariants | `Explore` RO |
| A2 | **REASON** | Comportement ATTENDU + énumération exhaustive des edge-cases (ce qui DEVRAIT arriver) | `Plan` RO |
| A3 | **DISPUTE** | Réfute-par-défaut chaque finding (verify-before-heal), tente le repro inverse | `general-purpose` RO |
| A4 | **BREAKDOWN** | **Ultra-décomposition** : chaque prop, état, transition, branche, message i18n, classe CSS = un item | `general-purpose` RO |
| A5 | **FIXPLAN** | **Ultra-plan du meilleur correctif** scope-minimal (frozen-aware, NF525-aware, jumeau-aware) | `Plan` RO |
| A6 | **ABUSER** | Attaque active : tamper payload, race //, malformé, borne, replay, injection, double-submit | `general-purpose` RO+curl |
| A7 | **VISUAL-QA** | Capture CHAQUE bouton/état (PNG+DOM+console+network), analyse layout/i18n/contraste/empty | `general-purpose` RO+Playwright |
| A8 | **VISUAL-RED** | Dispute indépendant de la capture A7 (ce que A7 a raté : truncation, overlap, palette) | `general-purpose` RO+Playwright |
| A9 | **DBA** | Intégrité données : N+1, BranchScope, transactions, FK, snapshot-immutability, index | `general-purpose` RO |
| A10 | **SECURITY** | auth/authz/IDOR/CSRF/mass-assignment/injection/token-scope par endpoint de la feature | `general-purpose` RO |
| A11 | **FISCAL** | NF525 par feature touchant l'argent : séquence, chain, snapshot, entrée-au-Z | `general-purpose` RO |
| A12 | **SYNC** | Temps-réel par feature : outbox→soketi→surfaces, idempotence, dégradation polling | `general-purpose` RO |
| ⊕ | **IMPLEMENTER** | Applique le heal TDD (jamais 2 en //). Orchestrateur = session principale. | `code-editor` Edit |

Règles de dispatch : A1,A2,A4,A9,A10,A11,A12 = **lecture parallèle 1 message**. A7+A8 = parallèle (RO captures). A6 ABUSE après analyse. A3 DISPUTE **toujours** après captures+findings, avant tout heal. IMPLEMENTER **séquentiel**. (Superpowers `dispatching-parallel-agents` + GStack 6-rôles.)

## §A.2 — La BOUCLE PAR-FEATURE (mandat owner : jusqu'à validé avant la suivante)
```
POUR chaque SURFACE S (ordre §X) :
  ENUMERATE  : A7 DOM-snapshot S → inventaire feuille de CHAQUE bouton/input/lien/toggle/modal/état
               (la "capture de chaque bouton" — produit la work-list réelle, pas inventée)
  POUR chaque FEATURE F de S (chaque bouton/flux/état) :
    1. CAPTURE      A7 : PNG+DOM+console+network de F dans CHAQUE état (idle/hover/focus/active/disabled/error/empty/loading)
    2. ANALYSE //   A1 LOGIC · A2 REASON · A9 DBA · A10 SECURITY · A11 FISCAL · A12 SYNC  (6 lentilles en parallèle)
    3. BREAKDOWN    A4 : éclate CHAQUE analyse en micro-détails (prop, transition, i18n, a11y, classe, message, edge)
    4. ABUSE        A6 : attaque F (tamper/race/malformé/borne/replay/double-submit/injection) + preuve repro
    5. DISPUTE      A3 réfute-par-défaut chaque finding ; A8 dispute la capture A7 (≥2 lentilles sur P0/P1)
    6. CONFIRMÉ = findings survivant la dispute (majorité) ; REFUTÉ → journalisé (pas de heal NO-OP)
    7. SI confirmé :
         FIX-PLAN  A5 : meilleur correctif scope-minimal (frozen→LOCK, NF525-safe, lentille jumeau-systémique)
         HEAL      IMPLEMENTER : TDD RED→GREEN (RED d'abord, prouve le défaut)
         RE-TEST   re-capture F + re-analyse ciblée
         → reboucle (max 3 heals/cluster sinon escalade owner §10)
    8. F VALIDÉ (2 cycles P0+P1=0 set-identique + capture propre + tests verts) → CHECKPOINT + commit (chemins explicites) → FEATURE SUIVANTE
  S VALIDÉ (toutes F validées ×2) → checkpoint vague + BRAIN MAJ → SURFACE SUIVANTE
```
**Loop-until-dry par surface** : après que toutes les F semblent vertes, A6+A3 relancent un round « qu'avons-nous raté ? » (completeness-critic). 2 rounds secs consécutifs (0 nouveau) = surface VALIDÉE. Sinon on reboucle.

## §A.3 — Les 12 axes d'abuse appliqués à CHAQUE feature (la profondeur, pas la surface)
A6/A10/A11 attaquent chaque feature sous ces 12 angles (le « pas que le surface ») :
1. **Tamper montant** (prix/total/charge/discount/qty client → doit être recalculé backend, jamais signé au Z).
2. **Race //** (double-submit, double-encaissement, concurrent change-status → lockForUpdate/idempotency).
3. **IDOR/RBAC** (acteur A sur ressource de B, surface kiosk→admin, token-scope, cross-branch).
4. **State-machine illégal** (transitions interdites, skip d'étape, terminal→forward).
5. **Malformé/borne** (négatif, overflow, null, type-confusion, unicode, XSS stored, JSON cassé).
6. **Replay/idempotence** (rejouer la mutation → 409/no-op, jamais de doublon fiscal/cash).
7. **Empty/error/loading state** (que rend l'UI à 0 résultat / 4xx / lenteur — pas de label brut, pas de blanc).
8. **i18n** (FR résolu, 0 clé brute `label.x`, money `0,00 €` FR, dates « 6 j 4 h »).
9. **A11y** (aria-label sur boutons icône-seule, focus-visible, contraste AA, nav clavier).
10. **Sync** (la mutation se propage à toutes les surfaces concernées ; dégradation soketi-down → polling).
11. **NF525** (toute écriture argent alloue la séquence, entre au Z, snapshot figé, chain intacte).
12. **Visuel** (layout intact tous viewports caisse16″/borne32″/kds16″, 0 overlap/truncation/débordement).

## §A.4 — Exemple TRAVAILLÉ : 1 seul bouton « Encaisser » → ~40 micro-détails (la profondeur exigée)
> Pour montrer concrètement le « ultra breakdown de chaque détail » : ce que l'armée produit sur UN bouton
> (`EncaissementComponent.vue` → modal collect → `PaymentService::payAtCounter`). Chaque feature reçoit ce niveau.
- **A7 CAPTURE (états)** : bouton idle/hover/focus/active/**disabled** (panier vide ?)/loading(spinner)/après-clic(modal) · modal : montant dû, keypad, tendu, **rendu monnaie**, méthodes (espèces/CB/split), confirmer, annuler, fermer(X), toast succès/erreur · viewports caisse 1366/1920.
- **A1 LOGIC** : le dû == backend (PricingService) ? le tendu < dû → bloqué ? le rendu = tendu−dû exact ? split = Σ tranches == dû ? statut→PAID + lane tracker maj ?
- **A2 REASON (edge-cases)** : tendu = dû exact (rendu 0) · tendu = 0 · tendu énorme · split 3 tranches dont 1 à 0 · annuler à mi-saisie · double-clic Encaisser · réseau lent · commande déjà payée.
- **A4 BREAKDOWN (micro)** : libellé « Encaisser » (pas clé brute) · format `0,00 €` FR (pas `0.00`) · keypad euro-entier vs décimal (P3 connu) · aria-label sur X/keypad · focus-trap modal · `Enter` valide · contraste bouton AA · spinner pas de double-submit · i18n toast.
- **A6 ABUSE (12 axes)** : tamper `total` client→ignoré (signe backend) · double-POST → 1 seul fiscal (idempotency) · sous-paiement → rejet · re-pay commande PAID → 409 · montant négatif/overflow keypad · race 2 caissiers même commande → lockForUpdate · XSS dans un champ note.
- **A9 DBA** : 1 `OrderPayment` écrit · pas de N+1 sur la file · BranchScope (caissier branch=1) · transaction atomique.
- **A10 SECURITY** : route gated `permission:pos` · pas d'IDOR (encaisser commande d'une autre branche) · token non-kiosk.
- **A11 FISCAL** : `payAtCounter`→`fiscal_sequence_no` alloué gap-free · `OrderPaidAtCounter` émis · entre au Z · `composition_snapshot` intact · chain HMAC append-only.
- **A12 SYNC** : `order.payment_confirmed` broadcast `private-branch.1` → tracker POS + encaissement + dashboard CA · soketi-down → polling · idempotent.
- **A8 VISUAL-RED** : la monnaie rendue est-elle tronquée ? le modal déborde-t-il en 1366 ? palette brand ? empty-state file ?
- **A3 DISPUTE** : chaque finding ci-dessus réfuté-par-défaut (déjà-gated ? design ? test-only ?) avant heal.
- **A5 FIXPLAN** : si défaut confirmé → correctif scope-minimal, frozen-aware (PaymentComponent FROZEN→LOCK), jumeau-aware (le même défaut existe-t-il sur le keypad livreur/borne ?).
- **VALIDÉ** quand : capture propre + les ~40 micro-items verts + `tests/Feature/Cash/*` + `tests/Feature/Abuse/*` verts, ×2 cycles. **Puis** bouton suivant. → **1 bouton = ~40 vérifs ; 2280 boutons × ~12-40 axes = l'échelle 1000h+.**

---

# §2 — S1 BORNE / Kiosk (48 .vue, 3 FROZEN)
### Anchors (vérifiés) : `resources/js/components/frontend/kiosk/*.vue` (48) · FROZEN `KioskWizardComponent.vue`/`KioskAppComponent.vue`/`KioskUpsellComponent.vue` · `FrontendOrderService` · token `kiosk:order`
### Sub 1.1 — Accueil + navigation menu
- T-S1.1.1 idle→start, langue, catégories (scroll/sélection), recherche item — capture chaque catégorie+bouton. acceptance: (test À CRÉER `tests/e2e/kiosk-nav.spec.js`) + capture `kiosk-idle/menu` 0 label brut.
- T-S1.1.2 item detail : variations/extras/suppléments, qty, allergènes modal — chaque option. acceptance: `tests/Feature/Kiosk*` + capture.
### Sub 1.2 — Wizard composer (FROZEN — lecture/abuse only, heal=LOCK)
- T-S1.1.3 wizard étapes (viande/sauce/pain/garniture/boisson), récap, prix-étape, edit ligne. abuse A6 : XSS nom, tamper option_ids. acceptance: capture wizard + `tests/Feature/Pos/QuoteBinding*` (le pricing SSOT couvre la borne).
### Sub 1.3 — Panier + upsell + paiement + suivi
- T-S1.1.4 panier (edit/suppr/qty), coupon apply, upsell accept/decline. acceptance: `tests/Feature/Coupon*` + capture.
- T-S1.1.5 paiement (card/TR defer-TPE / cash→Plan-B counter-route), confirm, queue number, tracker poll 15s. abuse: re-pay 409, abandon→0 orphelin, fiscal alloc kiosk-paid. acceptance: `tests/Feature/Abuse/*` + `kiosk.payment_route_all_to_counter`.

# §3 — S2 POS / Caisse (23 .vue + FROZEN pos-wizard.js)
### Anchors : `resources/js/components/admin/pos/*.vue` (23) · FROZEN `public/js/pos-wizard.js`+`.css`+`admin-pos-v4.blade.php`+`PaymentComponent.vue`+`PosV5TrancheRow.vue` · `OrderService::posOrderStore` · `Cash/CashDrawerService`
### Sub 2.1 — Prise de commande (wizard popup FROZEN)
- T-S2.1.1 wizard add (toutes catégories), cart-line edit/qty/suppr, discount manuel, coupon, customer attach. abuse: delivery_charge tamper (jumeau healed), nested-price, qty-cap. acceptance: `tests/Feature/Pos/` (529 passed baseline) + capture caisse16″.
### Sub 2.2 — Paiement + tiroir (NF525)
- T-S2.2.1 tender cash/card/split, change, keypad, encaissement modal. abuse: sous-paiement rejeté, double-encaissement→1 fiscal, re-pay 409. acceptance: `tests/Feature/Cash/*` + `tests/Feature/Abuse/*` + fiscal gap-free.
- T-S2.2.2 cash drawer open/close/reconcile, variance gate, Z/X report. acceptance: `tests/Feature/Cash/*` + chain OK.
### Sub 2.3 — Floorplan + parked + tracker + refund
- T-S2.3.1 floorplan table assign/transfer/free, parked save/resume, posOrders tracker, refund pre-Z (409 double). acceptance: `tests/Feature/*Refund*`/`*Table*` + capture.

# §4 — S3 KDS (7 .vue)
### Anchors : `admin/kitchenDisplaySystem/*.vue` (7) · `KitchenReleaseRule` · `KdsOrderCard.vue`
- T-S3.1 board V2 + **legacy rollback** (les 2 layouts), card render, station filter, bump accept→preparing→prepared, recall. abuse: bump UNPAID (release-guard), notif-fail survit. acceptance: `tests/js/kds*.spec.js` (kdsLineSemantics/Timer/Banner) + capture V2 **et** `?v2=0`.
- T-S3.2 allergènes badges (food-safety), timer escalation, offline banner, sync push/poll. acceptance: `tests/js/kdsAllergen*`/`sentinels/kds*` + capture.

# §5 — S4 OSS (orderStatusScreen)
### Anchors : `admin/orderStatusScreen/OrderStatusScreenComponent.vue` + `PreparingAndReadyComponent.vue`
- T-S4.1 mur public + authed, colonnes preparing/ready, ready chime (audio unlock authed), empty-state, poll 2s/60s + push. abuse: 4xx silencieux→toast, audio public. acceptance: `tests/Feature/*OrderStatusScreen*`/Oss + capture mur.

# §6 — S5 GESTION / Admin (~36 surfaces, 41 routes, 81 money .vue)
### Anchors : `resources/js/components/admin/` (36 dirs) · routes `admin.*` (41)
### Sub 5.1 — Catalogue (items/composer/studio/categories/ingredients/offers)
- T-S5.1.1 item CRUD (create/list/show/edit/delete), composer per-item, **studio wizard builder**, categories+sous-cat, ingredients, offers/upsell-rules. abuse: nested-variation prix négatif→422, category TOCTOU, image. acceptance: `tests/Feature/Catalog*`/`Composer*`/`AdminCrud*` + capture chaque modale.
### Sub 5.2 — Settings (×18 sous-pages)
- T-S5.2.1 chaque sous-page : analytic/branch/company/cookies/currency/language/license/mail/notification/otp/page/role/site/slider/tax/theme. abuse: TZ-validation, currency-position, cache-stale (vraie invalidation), defaults. acceptance: `tests/Feature/Settings*`/`Config*` + capture ×18.
### Sub 5.3 — RBAC (roles/permissions/administrators/employees/chefs/waiters)
- T-S5.3.1 role CRUD + permission matrix, comptes staff CRUD, gates `permission:settings`. abuse: privilege-escalation, self-permission, cross-branch, FormRequest `return true` ratchet (66). acceptance: `tests/Feature/Sentinels/FormRequestAuthzDrift*` + `tests/Feature/Rbac*`/`Branch*`.
### Sub 5.4 — Reports + Dashboard (money, NF525-adjacent)
- T-S5.4.1 dashboard KPI/widgets/Z-widget, salesReport, itemsReport, cashSessionReport, creditBalanceReport, transactions. abuse: refund-net, trafic-mirrors, money en-US→FR, period filters. acceptance: `tests/Feature/Dashboard*`/`*Report*`/`Analytics*` + capture.
### Sub 5.5 — Customers/Loyalty/Stock/Coupons/Messages/Orders
- T-S5.5.1 customers+loyalty (points/redeem/clawback), subscribers, stock rupture+ingredients, coupons/offers CRUD, messages (IDOR healed), push, onlineOrders/orderHistory/posOrders/tableOrders, observability/sync-overview. abuse: loyalty award-after-refund (healed), coupon scope (healed), stock oversell, message IDOR (healed). acceptance: `tests/Feature/Loyalty*`/`Coupon*`/`Stock*`/`*Order*` + capture.

# §7 — S6 LIVREUR (driver-app + admin + cash-sessions)
### Anchors : `Frontend/DeliveryBoyOrderController` · `api/frontend/delivery-boy-order/*` · `admin/deliveryBoys/*.vue` · `admin/deliveryBoyCashSession/*.vue` · `DeliveryBoyCashSessionService`
- T-S6.1 driver-app : auth (Sanctum+api-key), own-orders list/show, change-status OFD→DELIVERED, COD collect. abuse: IDOR A↔B (403 prouvé), double-deliver idempotent, status-99→422. acceptance: `tests/Feature/Delivery/*` + `tests/Feature/Abuse/Livreur*` + capture driver-app.
- T-S6.2 admin deliveryBoys CRUD + assign, cash-sessions open/close/reconcile, variance gate, refund-reversal. abuse: COD-no-session traçabilité (G-DELIV-CASH), variance bypass, cross-driver. acceptance: `tests/Feature/Delivery/*CashSession*`/`Livreur*` + capture admin.

---

# §X — VAGUES de convergence (surface-par-surface, boucle jusqu'à validé)
> Ordre = par criticité argent/NF525 puis largeur. Chaque vague = 1 système ; à l'intérieur, la boucle §A.2 par
> surface puis par feature. On NE PASSE PAS à la vague suivante tant que la précédente n'est pas VALIDÉE ×2.

- **W0 — Pré-vol** : rig :8766 up, daemons, seeds, baseline (Vitest/PHPUnit/frozen/chain), crée `reports/test-e2e/abuse-total-2026-06-20/`. Checkpoint : baseline verte enregistrée.
- **W1 — S2 POS/Caisse** (argent+NF525, le plus critique) : §3 toutes surfaces, boucle par feature. Checkpoint : 0 P0/P1 ×2, fiscal gap-free, frozen 0.
- **W2 — S6 LIVREUR** (argent COD, mandat owner robustesse) : §7. Checkpoint : IDOR/COD/idempotence/variance verts ×2.
- **W3 — S1 BORNE** (argent kiosk, frozen wizard) : §2. Checkpoint : pricing SSOT + paiement + 0 orphelin ×2.
- **W4 — S3 KDS + S4 OSS** (sync temps-réel, food-safety) : §4+§5. Checkpoint : sync prouvée + allergènes + 0 silent 4xx ×2.
- **W5 — S5 GESTION** (largeur : 36 surfaces) — sous-vagues séquentielles : W5a Catalogue · W5b Settings×18 · W5c RBAC · W5d Reports/Dashboard · W5e Customers/Stock/Coupons/Messages/Orders. Checkpoint par sous-vague.
- **W6 — Cross-surface E2E + loop-until-dry global** : parcours bout-en-bout (borne→KDS→OSS→encaissement→Z ; livreur→cash→reconcile ; online→refund→loyalty) + completeness-critic « qu'a-t-on raté ? » jusqu'à 2 rounds secs.
- **W7 — Convergence finale** : full-suite (PHPUnit + Vitest + E2E affectés) verte, frozen diff 0, NF525 chain OK, BRAIN MAJ, tag `v1.0.X-abuse-validated`.

### Protocole checkpoint (fin de chaque feature/surface/vague)
- [ ] toutes F de la surface : 2 cycles P0+P1=0 set-identique · [ ] captures Read+analysées propres · [ ] tests nommés verts · [ ] frozen diff 0 (ou LOCK cité) · [ ] NF525 chain inchangée/append-only si argent touché · [ ] BRAIN §2/§3 + `reports/test-e2e/abuse-total-2026-06-20/` MAJ · [ ] commit chemins-explicites.
### Protocole interrupt-resume (limite session)
- commit WIP `wip(<vague>): through <surface>/<feature>` + manifeste `reports/test-e2e/abuse-total-2026-06-20/INTERRUPT_<vague>_<ts>.md` (dernier SHA vert, surface/feature courante, work-list restante) + BRAIN §2. Reprise : lire manifeste → `git status` → re-smoke la dernière feature → continuer.
### Protocole convergence-failure (3 heals même cluster)
- STOP, spawn `Plan` (« pourquoi 3 cycles échouent ? pivot ou escalade »), écrire `STUCK_<surface>_<ts>.md`, escalader owner (accept-with-doc / pivot / défer V1.0.X / human-gate). Pas de 4e boucle silencieuse.

# §G — Owner gates (WHO / WHAT / WHERE)
| Gate | Description | WHO | WHAT | WHERE | Status |
|---|---|---|---|---|---|
| G-PUSH | pousser la branche LOCALE | owner | autorisation explicite | HEAD courant | PENDING |
| G-FROZEN | heal RÉEL en zone §7 (wizard/PaymentComponent/Fiscal…) | owner | LOCK doc countersign | `plans/LOCK_*` | per-finding |
| G-VISUAL-DATA | corrections DATA (VAT, images, libellés produits) vs code | owner | décision | BRAIN | per-finding |
| G-DELIV-CASH | COD sans session : record-anyway vs auto-open | owner | politique | BRAIN | PENDING |
| G-A11y-AA | seuils contraste/clavier à durcir (orange brand) | owner | seuil validé | BRAIN | PENDING |
| G-SCOPE | si une surface révèle un refactor large (>30 LOC/3 fichiers hors-feature) | owner | go/no-go | STUCK doc | per-case |

# §F — Règle finale
DONE = **CHAQUE surface des 6 systèmes** parcourue feature-par-feature, bouton-par-bouton, sous les **12 axes d'abuse**, par l'**armée 12 agents**, **bouclée jusqu'à VALIDÉ** (2 cycles P0+P1=0 set-identique + capture propre + tests verts) avant la suivante — puis cross-surface E2E + loop-until-dry global secs ×2 — full-suite verte, frozen 0 (sauf LOCK), NF525 chain OK, tag `v1.0.X-abuse-validated`. **Profondeur, pas surface : chaque micro-détail (prop/état/transition/i18n/a11y/sync/fiscal/visuel) est un item testé.** Production-perfect, jamais « presque ». La largeur (2280 boutons × 12 axes) × la profondeur (ultra-breakdown par feature) = l'échelle 1000h+ exigée, livrée par la boucle qui ne s'arrête JAMAIS avant le vert.

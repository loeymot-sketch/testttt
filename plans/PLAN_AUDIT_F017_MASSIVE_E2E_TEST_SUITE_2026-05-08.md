# PLAN_AUDIT_F017 — Massive E2E Test Suite Before Merge
**Severity:** P0 — Gate avant merge prod (preuve empirique end-to-end)
**Owner agent:** Agent QA (peut être exécuteur principal ou sub-agent dédié)
**Sprint:** S5+ (juste avant merge final)
**Estimated:** 4-6 jours-agent (suite complète) — peut être parallélisé
**Frozen-zone override:** NO (tests autorisés sur frozen wizards par mémoire owner)

---

## 0. RAISON D'ÊTRE

Avant le merge des 16 findings sur la branche prod et le go-live fast-food owner, on a besoin d'une **preuve empirique end-to-end** que :

1. Toutes les surfaces (POS, Kiosk, KDS, OSS, Admin) fonctionnent intégrées.
2. La synchronisation tient sous charge réelle (rush midi simulé : ~50-100 commandes simultanées).
3. La rupture stock se propage correctement sur **toutes** les surfaces concernées (items + extras + variations sur F-016).
4. Aucun corner case ne casse silencieusement (TPE decline, drawer fail, payment retry, Z close avec orders en cours).

**Sans ces tests, "1717 PHPUnit PASS" prouve que le code unitaire est OK — ne prouve PAS que le système intégré tient.** F-017 comble ce trou.

---

## 1. PÉRIMÈTRE — 10 suites de tests à livrer

### Suite 1 — POS Happy Path E2E (Playwright)

**Fichier :** `tests/e2e/pos-happy-path.spec.js`

**Scénarios** :
1. Login caissier (Manager) → landing POS
2. Ouvrir session caisse (cash drawer F-003 — opening_float 100€)
3. Naviguer catégorie "Burgers"
4. Cliquer "Cheeseburger" → wizard ouvre
5. Choisir variation "Standard" + extras "Cheddar" + "Sauce BBQ"
6. Add to cart (qté 2)
7. Ajouter "Frites" sans wizard (item simple)
8. Ajouter "Coca" (item simple)
9. Total visible = sum correct
10. Apply discount 10% avec motif "Promo manager"
11. Cliquer "Payer" → modal payment
12. Sélectionner Cash → entrer reçu 50€
13. Confirm → ticket fiscal imprimé (ESC/POS stub)
14. Drawer s'ouvre (kioskHardware.openDrawer stub)
15. Ticket NF525 affiché avec fiscal_sequence_no
16. Order apparaît en KDS dans <2s (vérifier autre tab)
17. Fermer session caisse → variance calculée

**Assertions** :
- Order créée avec fiscal_sequence_no non-null
- payment_status = PAID
- queue_number alloué monotonique
- Ticket fiscal contient HMAC signature
- KDS reçoit OrderCreated event en <2s
- cash_movement créé (F-003 hook)
- ActionLog enregistre l'action

### Suite 2 — POS Edge Cases E2E

**Fichier :** `tests/e2e/pos-edge-cases.spec.js`

**Scénarios** :
1. **Order avec coupon valide** — coupon "PROMO20" → discount 20% appliqué via CouponService
2. **Order avec loyalty redeem** — code "LOY-12345" → points déduits
3. **Order split payment** — 30€ cash + 20€ card
4. **Cancel order avant ACCEPT** — cancel avec reason "cashier_void" (F-004) → status=CANCELED + audit log
5. **Refund order via counter-entry** — order PAID → refund mirror NF525 (RefundWithCounterEntryService)
6. **Order avec stockout extra** (F-016) — sauce X marquée rupture → wizard ne la propose plus
7. **Discount supérieur au plafond cashier** → 422 (Spatie permission gate)
8. **Order avec idempotency key** — même key envoyée 2× → retourne le même order id (F-006)

### Suite 3 — Kiosk Happy Path E2E (Playwright avec stub Electron bridge)

**Fichier :** `tests/e2e/kiosk-happy-path.spec.js`

**Scénarios** :
1. Idle screen → tap "Commencer"
2. Sélectionner "Sur place" (KIOSK type) ou "À emporter" (TAKEAWAY)
3. Browser catégories
4. Cliquer "Tacos" → wizard kiosk
5. Étape 1 : choisir viande (poulet)
6. Étape 2 : choisir cuisson
7. Étape 3 : sauces (3 max)
8. Étape 4 : suppléments (cheddar)
9. Add to cart
10. Upsell prompt → décliner
11. Promo code → entrer "WELCOME10"
12. Cart final → Checkout
13. Payment screen → choisir "Carte"
14. TPE stub (browser sim) → approved + amount_cents_approved (F-002)
15. Backend payment-confirm OK
16. Confirmation screen → ticket imprimé
17. Drawer ne s'ouvre pas (card)
18. Order auto-promote à ACCEPT (finalizePaidKioskOrder)
19. KDS reçoit en <2s

**Assertions** :
- fiscal_sequence_no alloué (F-001 path b — TPE direct)
- amount_cents validation passe (F-002)
- Idempotency key envoyée par kiosk respectée
- transaction_id persisté
- Action log "Paiement carte confirmé (borne)"

### Suite 4 — Kiosk Edge Cases E2E

**Fichier :** `tests/e2e/kiosk-edge-cases.spec.js`

**Scénarios** :
1. **Inactivity timeout** — 3 min sans interaction → reset à idle, panier clear (BUSINESS_RULES)
2. **Still there modal** — 2 min 30 → modal apparaît
3. **Cancel mid-order** — user clique back en payment → order voided avec reason "tpe_cancel_user" (F-004)
4. **Cash kiosk flow** — cash payment → drawer signal → cash-acknowledge backend (F-009)
5. **TPE declined** — toggle `?tpe_force=declined` (F-014) → KioskErrorPaymentRefusedComponent affiché
6. **TPE timeout** — toggle `?tpe_force=timeout` → error timeout
7. **Network offline** — coupé pendant payment-confirm → localStorage persist (F-008) → boot retry
8. **Stockout item entre cart et confirm** — admin marque item rupture pendant que user finalise → order rejetée 422 ou item retiré du cart
9. **Loyalty opt-in** — entrer code loyalty + email → opt-in flow

### Suite 5 — KDS Sync E2E (multi-tab)

**Fichier :** `tests/e2e/kds-sync.spec.js`

**Setup** : 3 tabs ouvertes — Tab 1 POS, Tab 2 Kiosk, Tab 3 KDS

**Scénarios** :
1. **POS → KDS sync** : POS crée order → KDS reçoit en <2s (Outbox + Pusher)
2. **Kiosk → KDS sync** : Kiosk crée order après payment → KDS reçoit en <2s
3. **KDS state transitions** :
   - KDS ACCEPT → PREPARING → OSS reflète
   - KDS PREPARING → PREPARED → OSS reflète + bip sonore
   - KDS PREPARED → DELIVERED (POS) → KDS retire
4. **Multi-branche isolation** : KDS branch A ne voit pas orders branch B
5. **KDS reconnect après Pusher down** : 30s sans connexion → polling fallback prend le relais → recover via Pusher

### Suite 6 — Stock Rupture Multi-Surface Sync E2E (CRITIQUE pour F-016)

**Fichier :** `tests/e2e/stock-rupture-sync.spec.js`

**Setup** : 4 tabs — Admin Backoffice, POS branch A, Kiosk branch A, Kiosk branch B

**Scénarios** :

1. **Item rupture branch A** :
   - Admin tab : Stock view → toggle "Cheeseburger" rupture branch A
   - POS branch A : pastille rouge "Rupture" sur Cheeseburger en <2s
   - Kiosk branch A : Cheeseburger disparaît du menu en <2s
   - Kiosk branch B : Cheeseburger toujours visible (isolation)

2. **Extra rupture branch A (F-016)** :
   - Admin tab : Stock view → toggle extra "Sauce BBQ" rupture branch A
   - POS branch A : ouvrir wizard Cheeseburger → "Sauce BBQ" absente du choix sauces
   - Kiosk branch A : ouvrir wizard Tacos → "Sauce BBQ" absente
   - Kiosk branch B : "Sauce BBQ" toujours présente

3. **Variation rupture branch A (F-016)** :
   - Admin tab : toggle variation "XL" rupture branch A
   - Wizards POS+Kiosk branch A : taille XL filtrée
   - Branch B : XL toujours présente

4. **Re-disponible** :
   - Admin tab : toggle "Cheeseburger" disponible
   - POS+Kiosk branch A : Cheeseburger réapparaît en <2s

5. **Auto-86 sur quota jour** :
   - Admin tab : set max_daily_qty=3 sur "Cheeseburger" branch A
   - POS branch A : 3 commandes Cheeseburger
   - Trigger : 4ème commande → auto-86 broadcasté
   - Tous les surfaces branch A : Cheeseburger en rupture (badge / filter)
   - Lendemain (mock date+1) : daily_consumed_qty reset, dispo à nouveau

6. **Order rejetée si extra rupture pendant transition** :
   - Kiosk ajoute Cheeseburger + Sauce BBQ au cart
   - Admin marque Sauce BBQ rupture
   - Kiosk submit payment → backend rejette 422 "Sauce BBQ indisponible" (sécurité défensive AC7 du F-016)

### Suite 7 — Stress / Load Test (rush midi simulation)

**Fichier :** `tests/load/rush-midi-simulation.spec.php` (Laravel feature) + `tests/e2e/concurrent-orders.spec.js` (Playwright multi-context)

**Scénarios** :

1. **50 commandes POS concurrent même branche** :
   - 50 caissiers (simulés) postent `/api/admin/pos` en parallèle
   - Assertions : 50 orders créées, 50 fiscal_sequence_no distincts strictement croissants, 50 queue_numbers distincts (A0001..A0050), 0 collision, 0 lost event outbox

2. **50 commandes Kiosk concurrent même branche** :
   - 50 KioskMachine tokens postent `/api/frontend/order` avec idempotency keys uniques
   - Assertions : 50 orders distincts, 50 fiscal_sequence après payment-confirm, 0 race condition

3. **100 commandes mixtes (50 POS + 50 Kiosk) concurrent même branche** :
   - Mix POS et Kiosk simulé en parallèle
   - Assertions : 100 orders, fiscal séquence partagée monotone, queue partagée monotone, 0 collision

4. **10 branches concurrentes, 20 orders chacune** = 200 orders total :
   - Validation isolation branche (chaque branche a sa propre séquence)
   - 200 orders, 10 branches × ~20 séquences chacune

5. **Outbox sous charge** :
   - 100 events broadcast en 10 secondes
   - Assertions : tous dispatched_at remplis dans <30s, 0 last_error, retries fonctionnels si Pusher mock down

6. **Z report close pendant rush** :
   - Pendant 50 commandes en cours, admin close le Z
   - Assertion : aucune commande mid-flight ne se retrouve mal classée (in or out du Z window)

### Suite 8 — Multi-branche Isolation E2E

**Fichier :** `tests/e2e/multi-branch-isolation.spec.js`

**Scénarios** :

1. Caissier branch A login → ne voit que orders branch A (BranchScope)
2. Caissier branch A tente `GET /api/admin/pos-order/{id_branch_B}` → 404 (BranchScope filter)
3. Caissier branch A tente `POST /api/admin/pos-order/change-status/{id_branch_B}` → 403/404
4. Admin (branch_id=0) voit toutes les branches
5. Kiosk branch A token → ne peut créer que orders branch A
6. Toggle availability branch A ne broadcaste pas sur channel branch B
7. Z report branch A ≠ Z report branch B
8. Cash drawer session branch A ≠ branch B (F-003)

### Suite 9 — Fiscal NF525 Compliance E2E

**Fichier :** `tests/e2e/nf525-compliance.spec.js`

**Scénarios** :

1. **Day open** : POST `/api/admin/fiscal/z-report/open` → ZReport row INSERT, sequence_no monotonique
2. **N orders processed** : 20 commandes mixtes POS + Kiosk
3. **Day close** : POST `/api/admin/fiscal/z-report/close` → HMAC chain signature, total_sales = sum(orders.total)
4. **Z aggregate completeness** :
   - All POS orders included
   - All Kiosk orders with payment_status != UNPAID included (F-001 invariant)
   - cash_variance computed (F-003)
   - cash_unacknowledged_count visible (F-009)
5. **Audit chain integrity** : `verifyChain()` PASS
6. **Refund post-Z** : refund après Z fermé → counter-entry order créée (RefundWithCounterEntryService)
7. **Tentative destroy order PAID post-Z** → 409 (POS-9.4.BL.3)
8. **Receipt PDF** : GET `/api/admin/fiscal/z-report/{id}/pdf` → PDF avec signature

### Suite 10 — Reconciliation Flows E2E

**Fichier :** `tests/e2e/reconciliation-flows.spec.js`

**Scénarios** :

1. **Payment confirm retry queue (F-008)** :
   - Backend backend down → Frontend retry 3× exhausted
   - localStorage persist
   - Backend up
   - Boot retry → reconcile-pending endpoint → orders mark PAID
2. **TPE timeout** : Force timeout → reconcile path
3. **Cash acknowledge (F-009)** : Drawer ouvert → cash-acknowledge OK
4. **Drawer fail signal** : Drawer fail → log + report kiosk-event
5. **Idempotency replay POS+Kiosk (F-006)** : Same key, 2× — return existing
6. **Cash session close avec variance** (F-003) : actual_cash != expected → variance + reason required si > seuil

---

## 2. CRITÈRES DE SUCCÈS — Acceptance globale

| AC | Critère |
|---|---|
| AC1 | 10 suites livrées dans `tests/e2e/` ou `tests/load/` |
| AC2 | Total > 60 scenarios E2E concrets |
| AC3 | Toutes les suites passent en CI (Playwright + PHPUnit feature) |
| AC4 | Latence sync <2s observée sur 95% des cas (P95) |
| AC5 | 0 collision queue_number / fiscal_sequence_no sur stress 200 orders |
| AC6 | 0 régression sur les 1717 tests existants après ajout |
| AC7 | F-016 stock rupture sync prouvé bout-en-bout sur 6 scénarios Suite 6 |
| AC8 | F-001 NF525 invariant prouvé sur Suite 9 (kiosk paid orders dans Z) |
| AC9 | F-003 cash variance prouvée Suite 9 + Suite 10 |
| AC10 | F-008 reconcile-pending prouvé Suite 10 |
| AC11 | Multi-branche isolation prouvée Suite 8 (8 scénarios) |
| AC12 | Stress test 50 commandes POS + 50 Kiosk simultané passe |
| AC13 | Outbox 100 events sous 10s tous dispatched <30s |
| AC14 | Documentation `docs/E2E_TEST_SUITE.md` mise à jour |
| AC15 | Script `npm run test:e2e:full` lance toutes les suites |
| AC16 | Script `npm run test:e2e:smoke` lance subset critique pour CI rapide (<5min) |

---

## 3. OUTILS

| Suite | Outil principal |
|---|---|
| 1, 2, 3, 4 | Playwright (multi-tab) |
| 5 | Playwright multi-context |
| 6 | Playwright 4-tab + AvailabilityService backend |
| 7 | Hybrid : Playwright pour 5-10 contexts simultanés + PHPUnit Feature pour pure backend stress + Laravel artisan command custom pour 50+ contexts |
| 8 | Playwright + PHPUnit BranchIsolationTest extension |
| 9 | PHPUnit Feature (preferred for fiscal precision) |
| 10 | Mix Playwright + PHPUnit |

**Pour stress 50+ contexts simultanés** : créer une commande artisan `foodking:e2e:stress --orders=100 --branches=5` qui :
- Spin N tokens kiosk + cashiers
- Submit orders en parallèle via Guzzle async
- Mesure latence + collisions
- Output rapport Markdown

---

## 4. ESTIMATION EFFORT

| Suite | Effort |
|---|---|
| 1 POS happy path | 0.5 j |
| 2 POS edge cases | 1 j |
| 3 Kiosk happy path | 0.5 j |
| 4 Kiosk edge cases | 1 j |
| 5 KDS sync | 0.5 j |
| 6 Stock rupture sync (CRITIQUE F-016) | 1 j |
| 7 Stress / Load | 1 j |
| 8 Multi-branche isolation | 0.5 j |
| 9 Fiscal NF525 | 0.5 j |
| 10 Reconciliation flows | 0.5 j |
| Doc + scripts npm | 0.5 j |
| **TOTAL** | **7 jours-agent** |

→ Possibilité de paralléliser sur 2-3 sub-agents : **2-3 jours wall-clock**.

---

## 5. STRATÉGIE D'EXÉCUTION

### 5.1 Ordre

1. **Avant tout** : vérifier que l'env Playwright fonctionne (`npm run test:e2e` baseline existante).
2. **Suite 6 en priorité** (rupture stock — gate critique F-016).
3. **Suite 9 en priorité 2** (NF525 — gate fiscal absolu).
4. **Suites 1-5** parallèles (sub-agents si dispo).
5. **Suite 7 stress** en dernier (besoin que 1-5 soient stables).
6. **Suite 8 isolation + Suite 10 reconcile** parallèles avec 7.

### 5.2 Pré-requis avant lancement

- F-009 fermé ✅
- F-015 fermé ✅
- F-016 backend fermé (au moins F-016a même si UI dashboard pas finie) ✅
- Branch consolidée disponible
- DB seedée avec 2-3 branches + items + extras + variations + users de chaque rôle

### 5.3 En cas d'échec d'une suite

- Identifier le scénario fail
- Reproduire en local
- Si bug réel : créer ticket P0 ou P1 selon impact
- Si flaky test : améliorer attente / sélecteurs
- Ne pas marquer F-017 comme done si > 5% des scénarios sont flaky

---

## 6. RAPPORT DE LIVRAISON

`reports/execution/audit_2026-05-07/REPORT_F017_e2e_test_suite.md` :

```markdown
# REPORT F-017 — Massive E2E Test Suite

**Suites livrées :** 10/10
**Scénarios totaux :** XX
**Duration full run :** YY minutes
**Latence sync P95 :** ZZ ms
**Stress test 100 orders :** PASS / FAIL
**Régressions détectées :** liste
**Bugs P0/P1 découverts :** liste (et tickets ouverts)
**Coverage E2E :** % surfaces

## Détail par suite
[tableau]

## Métriques
[tableau]

## Bugs découverts
[liste avec sévérité]

## Recommandations
[avant merge prod]
```

---

## 7. ANTI-DRIFT

- [ ] Pas de modification frozen-zones (POS Vanilla, Kiosk wizard 8 composants Vue, OrderStateMachine domain) — **TESTS LIVRÉS DESSUS, code intact**
- [ ] Pas de modification de la logique métier juste pour faire passer un test — si test fail, c'est un bug
- [ ] Tests stables et déterministes (pas de flaky) — sinon améliorer waits / sélecteurs avant marquer done
- [ ] Pas de skip / xit silencieux

---

## 8. RISQUES

| Risque | Mit |
|---|---|
| Flaky tests sur Pusher latency | retry policy + waitFor selectors solides |
| Stress test crash CI environment | run dédié hors CI normal, scheduled nightly |
| Test data pollution (tests affectent autre tests) | RefreshDatabase / db:wipe entre suites |
| Pusher mock divergence vs prod | utiliser Soketi local pour env de test |
| Time-dependent tests (auto-86 daily reset) | mock Carbon / time travel |

---

## 9. POST-LIVRAISON

Après F-017 vert :
- Documenter `docs/E2E_TEST_SUITE.md` avec instructions run
- Ajouter `npm run test:e2e:smoke` à CI (subset rapide)
- Ajouter `npm run test:e2e:full` à scheduled nightly
- Ajouter dashboard métriques (latence, fail rate)

Ces tests deviennent le **filet de sécurité permanent** du projet — chaque modification future doit les passer.

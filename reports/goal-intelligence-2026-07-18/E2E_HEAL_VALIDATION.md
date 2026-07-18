# E2E HEAL VALIDATION — 5 fix du registre audit (2026-07-18)

**Verdict global : 5 / 5 PASS — les 5 heals tiennent en conditions réelles et ne cassent aucun parcours.**

- **Spec** : `tests/e2e/_teste2e-heal-audit-2026-07-18.spec.js` (Playwright, `PLAYWRIGHT_NO_WEB_SERVER=1`)
- **Serveur** : dev `:8000` (bundles frais) · **DB** : `foodking_e2e`
- **Registre source** : `reports/goal-intelligence-2026-07-18/REGISTRE_FINAL.md`
- **Résultat** : `5 passed` (aucun retry, aucun flaky) — run final propre + self-cleaning
- **Discipline** : aucun code applicatif modifié ; toutes les fixtures préfixées `HEALVAL-`, nettoyées en `beforeAll`+`afterAll` ; aucun paiement non-test finalisé.

| # | Fix (registre) | Domaine | Résultat | Preuve clé |
|---|----------------|---------|----------|------------|
| **V1** | P1-4 upsell borne | Revenu/UX | ✅ PASS | items 40 + 106 absents du pool ; pool = 6 boissons/desserts ; quote upsell = **200** ; item 40 nu = **422** |
| **V2** | P1-5 RBAC CA | Sécu | ✅ PASS | POS Operator = **403** · Admin = **200** sur `/admin/sales-report/overview` |
| **V3** | P1-3 web encaissable | NF525-adj | ✅ PASS | Accept → PENDING_COUNTER(15)+COUNTER_DEFERRED(6) → visible file caisse → confirm CASH → **PAID(5) + fiscal_seq alloué** |
| **V4** | P2-u loyalty scan | Sécu/PII | ✅ PASS | invité → **NEUTRE** (aucune PII) · vraie KioskMachine → **résout** (points=137) |
| **V5** | P2-e refund web | Sécu/RBAC | ✅ PASS | POS Operator `changePaymentStatus`→REFUNDED = **403** ; paiement inchangé (PAID) |

---

## V1 — Upsell borne (P1-4) — PASS

**Attendu** : l'écran upsell 1-tap ne propose AUCUN item à composition requise (40 « Menu Enfant Nuggets », 106 « Menu Enfant Chicken Burger »), et un item upsell simple mène au paiement sans 422.

**Preuve API** (`GET /api/frontend/item/kiosk-upsell?item_ids=34&limit=12&branch_id=1`, token kiosk explicite) :
- pool ids retournés = `[50,49,53,57,58,51,54,59,56,55,52]` → **40 absent, 106 absent**, pool non-vide.
- `POST /frontend/order/quote` avec un item upsell simple (boisson/dessert) → **HTTP 200** (bouton Payer vivant).
- Contre-preuve : `POST /frontend/order/quote` avec l'item 40 nu (variations VIDES) → **HTTP 422** « Sélectionnez au moins 1 Sauce (1ère Gratuite) (actuel : 0). » → c'est EXACTEMENT le dead-end que l'exclusion évite.

**Preuve visuelle** — `reports/goal-intelligence-2026-07-18/screenshots/V1-upsell-screen.png` :
écran « ET POUR TERMINER ? » réel (parcours borne : login kiosk → takeaway → panier garni → checkout), **6 cartes** — Glace, Orangina 33cl, Sprite 33cl, Fanta Orange 33cl, Coca-Cola 33cl, Oasis Tropical 33cl. Toutes simples (1-tap), prix `€` corrects, layout intact, aucune carte `Menu Enfant`. Assertions DOM : `kiosk-upsell-card-40` = 0, `kiosk-upsell-card-106` = 0, texte des deux menus enfant absent.

**Nuance vérifiée** : l'item 40 est exclu via l'attribut sauce requis (`min_select>=1`) ET via son profil composer publié ; l'item **106 n'a PAS d'attribut requis** → il est exclu par la branche **profil composer publié** de `composeRequiredItemIds()` (`ItemController.php:152-182`). Les deux volets du fix sont donc exercés.

---

## V2 — RBAC sur le CA net (P1-5) — PASS

**Attendu** : `/admin/sales-report/overview` gaté `permission:sales-report` (le heal a corrigé le nom de méthode `overview`→`salesReportOverview`, `SalesReportController.php:43`).

- **POS Operator** (rôle SANS `sales-report`) → `GET /api/admin/sales-report/overview` = **HTTP 403**.
- **Admin** (AVEC `sales-report`) → **HTTP 200**.

Chaque identité est testée dans un **contexte navigateur isolé** (le token de session persiste sinon et fausse le 2ᵉ login).

---

## V3 — Commande web encaissable au comptoir (P1-3) — PASS

**Attendu** : une commande web takeaway COD UNPAID, une fois « Acceptée », doit devenir encaissable au comptoir (le heal la bascule PENDING_COUNTER + complète le marqueur COUNTER_DEFERRED, et la file `counter-collect` + `assertCounterDeferredOrder` acceptent désormais `source='web'`).

Fixture : commande web (`source_surface='web'`, TAKEAWAY, CASH_ON_DELIVERY, UNPAID) + item de test dédié `HEALVAL Test Item`.

1. état initial → `payment_status = 10` (UNPAID)
2. `POST /admin/online-order/change-status/{id}` `{status:4}` → **200** ; après : `payment_status = 15` (PENDING_COUNTER), `pos_payment_method = 6` (COUNTER_DEFERRED)
3. `GET /admin/pos/counter-collect/pending` (POS Operator) → la commande **est présente** dans la file
4. `POST /admin/pos/counter-collect/{id}/confirm` `{mode:1,received:5}` → **200** ; après : `payment_status = 5` (PAID), **`fiscal_sequence_no` alloué** (> 0, ex. 2679)

Le cycle « web acceptée → file caisse → encaissement fiscal NF525 » est donc bout-en-bout fonctionnel.

---

## V4 — Loyalty scan durci (P2-u) — PASS

**Attendu** : `/api/frontend/loyalty/scan` ne divulgue la PII (prénom + solde + existence) qu'à une VRAIE KioskMachine (ou staff/propriétaire) ; un token invité porteur de `kiosk:order` obtient une réponse NEUTRE.

Fixture : un client cible (`loyalty_code=HEALVALX`, `loyalty_points=137`) + un token **invité** (`kiosk:order`, is_guest, sans ligne KioskMachine) + deux tokens QR signés (nonce single-use). Appels via `page.request` (headers explicites — indispensable, cf. « défauts harnais » ci-dessous).

- **Token invité** scanne le QR signé → **`{ ok:false, display_name:null, loyalty_balance_points:0, customer_token:null, error_code:"customer_not_found" }`** — indiscernable d'un « non trouvé », **zéro PII fuitée**.
- **Vraie KioskMachine** scanne le même code → **`{ ok:true, display_name:"HealvalTarget", loyalty_balance_points:137, customer_token:"lt_…" }`** — résolution légitime au comptoir borne.

La différence d'identité produit bien deux réponses opposées : le durcissement du choke-point anti-énumération (`LoyaltyController.php:786`) tient.

---

## V5 — Refund web gaté (P2-e) — PASS

**Attendu** : `OnlineOrderController::changePaymentStatus`→REFUNDED exige `pos-refund` (parité avec la sœur POS).

- Fixture : commande web PAID.
- **POS Operator** (a `online-orders`+`pos`, PAS `pos-refund`) → `POST /admin/online-order/change-payment-status/{id}` `{payment_status:20}` → **HTTP 403**.
- Après tentative : `payment_status` inchangé = **5 (PAID)** — aucun void off-book possible.

---

## Défauts / observations

Aucune régression produit trouvée : les 5 fix tiennent. Les défauts rencontrés étaient **dans le harnais de test** (documentés ici par honnêteté, corrigés) et **révèlent au passage 2 comportements produit sains** :

1. **[harnais] Interceptor axios écrase l'`Authorization`** — `resources/js/shared/axios-setup.js:85` pose `config.headers['Authorization'] = 'Bearer '+<token stocké>` inconditionnellement. Un 1ᵉʳ jet de V4 passait par `window.axios` → le token invité était remplacé par le token **kiosk** stocké → la borne résolvait (faux échec). Corrigé en appelant `/loyalty/scan` via `page.request` (headers explicites, hors interceptor). **Le fix P2-u lui-même est sain** (prouvé aussi en curl direct).
2. **[produit — sain] `cash_movements` immuable NF525** — le nettoyage des fixtures V3 a heurté le trigger `BEFORE DELETE` (`SQLSTATE 45000 : cash_movements is immutable`). C'est le **comportement correct** : on ne supprime jamais une écriture fiscale. Le cleanup a été aligné sur `cleanupKioskAuditOrders` (ne touche ni `cash_movements` ni `audit_logs`) ; les mouvements de caisse des encaissements de test restent en **orphelin immuable sur la DB e2e** (3 lignes), ce qui est attendu.
3. **[produit — sain] Garde router borne** — `kioskRoutes.js:85` redirige upsell→cart si panier vide ; c'est le bon garde (un écran upsell sans panier n'a pas de sens). Le test garnit donc un panier réel avant d'atteindre l'upsell.
4. **[harnais] double login même page** — deux `login*()` sur la même page échouent (session rémanente → `/login` redirige). Corrigé via contexte navigateur isolé pour la 2ᵉ identité (V2).

## Empreinte fiscale (DB e2e)

V3 réalise 3 encaissements CASH réels au fil des itérations → **3 `cash_movements` immuables** subsistent (order supprimée = orphelin, cf. P2-d du registre). Sur `foodking_e2e` c'est bénin (la chaîne e2e porte déjà des trous de test) et conforme à NF525 (immutabilité). **Aucune** écriture fiscale n'est réalisée hors DB e2e.

## Nettoyage final

Après le run : `HEALVAL-` orders = 0, items = 0, users = 0. Fixtures intégralement nettoyées ; seules subsistent les écritures fiscales immuables (par conception).

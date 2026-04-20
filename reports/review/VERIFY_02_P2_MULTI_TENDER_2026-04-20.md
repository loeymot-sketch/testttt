# VERIFY-02 — P2 Multi-tender (Titre-Restaurant + extension future)

- **Date :** 2026-04-20
- **Origine :** P2 (commit `a43c5b9e2`)
- **Mode :** AUDIT-ONLY (aucune modification de code applicatif)
- **Tâche :** `tasks/verify-2026-04-20/02_VERIFY_P2_MULTI_TENDER.md`
- **Auteur :** foodking-planner-orchestrator (Claude)

---

## 1. Plan d'attaque exécuté (5 lignes)

1. Lecture enum `PosPaymentMethod`, `PaymentService`, `OrderService::posOrderStore`, `PosOrderRequest`, `PosController`, `ZReportService`, `OrderDetailsResource`/`SimpleOrderResource`, `SalesReportExport` (Pass A — back).
2. Lecture `PaymentComponent.vue`, `ReceiptComponent.vue`, `PosComponent.vue`, `posPaymentMethodEnum.js`, i18n `lang/{fr,en,de,ar,bn}/pos_payment_method.php` + `resources/js/languages/fr.json`, tests `tests/js/*.spec.js` (Pass B — front).
3. Cartographie persistance : aucune table `order_payments`/`order_tenders`, schema `transactions` 2023_03_23 inchangé, colonne unique `orders.pos_payment_method` (int).
4. Vérification V1–V7 + matrice tender × surface (request × DB × Z × reçu × audit log).
5. Rédaction du présent rapport + verdict.

---

## 2. Sources scrutées (file:line)

| Domaine | Fichier | Lignes clés |
|---|---|---|
| Enum back | `app/Enums/PosPaymentMethod.php` | 7-16 (`TICKET_RESTAURANT = 5`) |
| Enum gateway kiosk | `app/Enums/PaymentGateway.php` | 10 (`TICKET_RESTAURANT = 5`) |
| FormRequest | `app/Http/Requests/PosOrderRequest.php` | 80-82, 117-121 |
| Service principal | `app/Services/OrderService.php` | 556 (`posOrderStore`), 593-605, 838-848, 921-949 |
| PaymentService | `app/Services/PaymentService.php` | 13-29 (`payment`), 31-72 (`cashBack` + audit) |
| Z report | `app/Services/Fiscal/ZReportService.php` | 207-235, 269-280 |
| AuditLog | `app/Services/Fiscal/AuditLogService.php` | (aucune mention `payment_method` côté creation) |
| Resource détail | `app/Http/Resources/OrderDetailsResource.php` | 47-53 |
| Resource liste | `app/Http/Resources/SimpleOrderResource.php` | 30-32 |
| Export ventes | `app/Exports/SalesReportExport.php` | 57-60 |
| Controller POS | `app/Http/Controllers/Admin/PosController.php` | 22-25 |
| Migration tx | `database/migrations/2023_03_23_143747_create_transactions_table.php` | 15-24 |
| Model Order | `app/Models/Order.php` | 42, 74 |
| i18n back | `lang/fr/pos_payment_method.php` | 10 (`TICKET_RESTAURANT => 'Titre-restaurant'`) |
| i18n back | `lang/en/pos_payment_method.php` | 10 (`Meal voucher`) |
| i18n back | `lang/de,ar,bn/pos_payment_method.php` | 10 (FR/EN/DE/AR/BN OK) |
| i18n front | `resources/js/languages/fr.json` | 426 (`tpe_tr` libellé seul) |
| Front enum | `resources/js/enums/modules/posPaymentMethodEnum.js` | 1-7 (**TR absent**) |
| Front composant | `resources/js/components/admin/pos/PaymentComponent.vue` | 26-41 (boutons CASH/CARD seuls), 71-72, 200-213 |
| Front reçu | `resources/js/components/admin/pos/ReceiptComponent.vue` | 161, 211-216 (**TR absent du mapping**) |
| Test back | `tests/Feature/PosTicketRestaurantPaymentTest.php` | 33-90 |
| Plan handoff | `plans/PLAN_P2_MULTI_TENDER_HANDOFF.md` | 67-72 (next step `order_payments`) |

---

## 3. Matrice tender × surface (TR = `pos_payment_method = 5`)

| Surface | Trace effective TR | Référence |
|---|---|---|
| API request validation | ✅ note obligatoire (`string`, `max:200`) | `PosOrderRequest.php:81` |
| Persistance Order | ✅ colonne `orders.pos_payment_method = 5` | `OrderService.php:593-605`, `Order.php:42,74` |
| Table `transactions` | ❌ aucune ligne créée pour ordre POS (TR ou autre) | `OrderService::posOrderStore` ne fait pas de `Transaction::create` ; seul `PaymentService::payment()` (gateways kiosk) le fait — `PaymentService.php:13-29` |
| Z-report `total_by_method` | ⚠️ agrégé par `pos_payment_method` (clé `5`) — un seul tender / commande | `ZReportService.php:233-234` |
| Audit log NF525 (HMAC) | ❌ aucun `pos.order_created` avec `payment_method` ; uniquement `discount_applied` & `cash_back_issued` | `OrderService.php:933-948`, `PaymentService.php:54-68` |
| ActionLog (non signé) | ⚠️ `Nouvelle commande POS` sans `payment_method` | `OrderService.php:921-926` |
| Reçu imprimé | ❌ `posPaymentMethodEnumArray[5]` non défini → champ vide | `ReceiptComponent.vue:211-216` |
| Export CSV/Excel | ✅ `trans('pos_payment_method.5')` → "Titre-restaurant" / "Meal voucher" | `SalesReportExport.php:59` |
| API resources | ✅ remontent l'entier brut, libellé résolu côté client | `OrderDetailsResource.php:50`, `SimpleOrderResource.php:32` |
| Front sélection UI | ❌ aucun bouton TR ; enum JS n'inclut pas la valeur 5 | `posPaymentMethodEnum.js:1-7`, `PaymentComponent.vue:26-41` |
| Front filtre / numpad | ❌ saisie note TR via `pos_payment_note` n'a pas de champ exposé | `PaymentComponent.vue:71-72, 200-213` |

---

## 4. Vérifications V1–V7

| ID | Critère | Verdict | Preuve |
|---|---|---|---|
| **V1** | Note TR obligatoire back **et** front | ⚠️ **PARTIEL** | Back ✅ `PosOrderRequest.php:81` (`required, string, max:200` quand `pos_payment_method == 5`). Front ❌ : aucun bouton TR exposé dans `PaymentComponent.vue:26-41`, donc pas de logique de désactivation conditionnelle non plus ; un cashier ne peut pas sélectionner TR via UI POS. |
| **V2** | TR n'apparaît pas comme "carte" dans `order.payment_method` | ✅ **OK** | `OrderService.php:593-605` persiste `pos_payment_method=5` tel quel ; `Order.php:74` cast `integer`. Test : `PosTicketRestaurantPaymentTest.php:86` (`assertSame(5, $order->pos_payment_method)`). Aucun mapping vers la colonne `payment_method` (réservée aux gateways kiosk). |
| **V3** | Reçu / impression affiche TR (i18n FR + EN) | ⚠️ **PARTIEL** | Back ✅ : 5 langues couvertes (`lang/{fr,en,de,ar,bn}/pos_payment_method.php:10`). Front ❌ : `ReceiptComponent.vue:211-216` n'a pas d'entrée `[posPaymentMethodEnum.TICKET_RESTAURANT]` → ligne `payment_type` rendue vide pour un ticket TR. Aussi, `posPaymentMethodEnum.js` ne contient pas la clé `TICKET_RESTAURANT`. |
| **V4** | Z-report agrège correctement TR | ⚠️ **PARTIEL — accepté** | `ZReportService.php:233-234` : `$method = $o->pos_payment_method` ; clé `5` apparaîtra dans `total_by_method`. Limite assumée : **1 tender / commande** (multi-tender futur cassera l'agrégat). Documenté `plans/PLAN_P2_MULTI_TENDER_HANDOFF.md:9, 22-50`. |
| **V5** | Pas de `match`/`switch` exhaustif cassé | ✅ **OK** | Recherche : aucun `match (PosPaymentMethod::*)` ou `switch` exhaustif sur l'enum dans `app/`. Tests existants (`PosDiscountTest`, `PosOrderTaxTest`, `POSComprehensiveTest`, etc.) utilisent uniquement `PosPaymentMethod::CASH` — ajout TR=5 n'introduit pas de cas non géré. |
| **V6** | Plan multi-tender liste `order_payments` | ✅ **OK** | `plans/PLAN_P2_MULTI_TENDER_HANDOFF.md:67-72` : "Phases recommandées (suite) — 1. Schéma : `order_payments` (`order_id`, `branch_id`, `method`, `amount`, `reference`, `created_at`)". |
| **V7** | Audit log écrit le tender | ❌ **FAIL** | `AuditLogService` n'est jamais invoqué avec `payment_method` à la création POS : `OrderService::posOrderStore` (lignes 921-949) ne logue qu'un `ActionLog` non signé (sans tender) puis un `audit_logs` `order.discount_applied` (uniquement si remise > 0, sans `payment_method` dans le payload). `PaymentService.php:54-68` logue le tender mais seulement sur `cashBack` (refund). → la chaîne HMAC ne contient aucune trace du tender choisi à l'encaissement. |

---

## 5. Matrice scénarios métier (cash + card + TR, partial overpay, refund partiel)

| Scénario | Back attendu | État réel | Référence |
|---|---|---|---|
| 100 % CASH (= total) | `pos_payment_method=1`, `pos_received_amount` ≥ total | ✅ | `OrderService.php:841-848` (validation serveur stricte) |
| 100 % CASH overpay (rendu monnaie) | `pos_received_amount` > total → rendu monnaie + `openDrawer()` | ✅ | `PaymentComponent.vue:139-143, 222-225` (calcul `cashChange` côté UI seul, montant tronqué côté back) |
| 100 % CARD (TPE) | `pos_payment_method=2`, note = 4 derniers digits | ✅ | `PosOrderRequest.php:81` (`min_digits:4, max_digits:4`) |
| 100 % TR (montant exact) | `pos_payment_method=5`, note référence chèque | ✅ back / ❌ UI POS | `PosTicketRestaurantPaymentTest.php:74-88` (back). Pas de bouton UI (`PaymentComponent.vue:26-41`). |
| TR + cash en complément (split) | 2 lignes tender / 1 commande | ❌ **NON SUPPORTÉ** | Schéma `orders.pos_payment_method` mono-valué ; pas de table `order_payments`. Voir `plans/PLAN_P2_MULTI_TENDER_HANDOFF.md:30-35` ("Backlog"). |
| Cash + Card même ticket | 2 lignes tender + agrégat Z par tender | ❌ **NON SUPPORTÉ** | Idem. |
| TR partial overpay (TR > total ; pas de rendu) | Refus ou montant absorbé | ⚠️ **NON ARMÉ** | Aucune règle métier : la validation serveur ne contrôle `pos_received_amount` que pour `CASH` (`OrderService.php:841`). TR n'a pas de notion de "montant reçu" ; juste une note. Si un cashier saisit un TR de 12 € sur un ticket de 10 €, le back accepte sans tracer la différence. |
| Refund partiel (RETURNED) sur TR | Trace tender refund + audit chain | ❌ **NON FONCTIONNEL POUR POS** | `OrderService.php:1508-1515` n'appelle `cashBack` que si `$order->transaction` existe. Or `posOrderStore` **ne crée jamais** de ligne `transactions`. Conséquence : un refund POS (TR ou tout autre tender) ne génère **ni Transaction**, **ni audit log NF525** `payment.cash_back_issued` (`PaymentService.php:31-68` jamais déclenché pour POS). Statut `RETURNED` est posé sur l'`Order` mais le mouvement financier inverse n'est pas matérialisé. |
| Coupon / remise + TR | Audit `order.discount_applied` (sans tender) | ⚠️ tracé partiel | `OrderService.php:933-948` : payload contient `discount_amount`, `subtotal_before`, `total_after` mais **pas** `payment_method`. |

---

## 6. Hypothèses challengées

| H | Verdict | Détail |
|---|---|---|
| **H1** TR contourne le contrôle de change | ✅ partiellement vrai (UX, pas exploit) | Le back ne contrôle `pos_received_amount` que pour CASH (`OrderService.php:841`) ; TR ne déclenche aucune logique de rendu monnaie. Pas de fuite vers cash, mais aucune trace de surpaiement. |
| **H2** TR mal mappé fiscalement / Z report | ❌ FAUX | Z report agrège correctement par `pos_payment_method` (clé `5`). Le ticket fiscal expose `pos_payment_method` brut, mappé en libellé via `lang/{fr,en}/pos_payment_method.php:10`. |
| **H3** Multi-tender futur cassera `posOrderStore` | ⚠️ vrai partiellement | La signature `posOrderStore(PosOrderRequest)` reste compatible mais le passage à `order_payments` exigera : (a) une migration, (b) refactor des resources `Order*Resource`, (c) refactor `ZReportService::aggregate` (clé tender + montant), (d) version du payload signé NF525 (déjà mentionné `plans/PLAN_P2_MULTI_TENDER_HANDOFF.md:81`). |
| **H4** Front autorise note TR vide via chemin alternatif | ❌ FAUX | Le front ne permet **aucun** chemin de saisie TR ; donc pas de bypass possible. Si un client API force `pos_payment_method=5` sans note, le back retourne 422 (`PosOrderRequest.php:81`). |

---

## 7. Findings & risques

### F-01 — UI POS sans entrée TR (P1)
- **Évidence :** `posPaymentMethodEnum.js:1-7` (manque `TICKET_RESTAURANT: 5`), `PaymentComponent.vue:26-41` (que CASH + CARD).
- **Impact :** la fonctionnalité TR est en pratique **inutilisable** en POS sauf appel API direct.
- **Sévérité :** P1 — fonctionnalité morte côté UI.

### F-02 — Reçu n'imprime pas le libellé TR (P1)
- **Évidence :** `ReceiptComponent.vue:211-216` ne définit pas `[5] = $t('label.ticket_restaurant')`.
- **Impact :** toute commande TR créée par API directe imprime un reçu avec le champ "Type de paiement" vide → non conforme NF525 (lisibilité du moyen de paiement requise).
- **Sévérité :** P1.

### F-03 — Audit log NF525 ne capture pas le tender à la création (P1)
- **Évidence :** `OrderService.php:921-949` — `ActionLog` sans `payment_method` ; `audit_logs` (HMAC) n'est invoqué que pour `discount_applied`.
- **Impact :** la chaîne tamper-evident NF525 ne permet pas de prouver post-hoc le mode de paiement ; un cashier malveillant peut muter `orders.pos_payment_method` sans casser la chaîne. Recommandation NF525 : `pos.order_created` avec `pos_payment_method`, `pos_payment_note`, `total`, `fiscal_sequence_no`.
- **Sévérité :** P1 (fiscal).

### F-04 — Aucun `Transaction` créé pour orders POS → refund POS muet (P1)
- **Évidence :** `PaymentService::payment()` n'est invoqué que par les chemins gateway/kiosk ; `posOrderStore` (`OrderService.php:556-986`) ne crée **pas** de ligne `transactions`. `OrderService.php:1508-1515` & `:1456-1462` (`cashBack`) sont gardés par `if ($order->transaction)`. → Refund POS = statut changé mais aucune trace fiscale d'inversion.
- **Impact :** trou Z-report majeur sur les retours/annulations POS, et l'audit `payment.cash_back_issued` (`PaymentService.php:54-68`) ne s'enclenche jamais pour POS.
- **Sévérité :** P1 (fiscal) — ce finding dépasse le scope strict de TR mais est révélé par l'audit P2.

### F-05 — Multi-tender absent (P2 → P3)
- **Évidence :** schema `orders.pos_payment_method` scalaire (`Order.php:42, 74`), pas de table `order_payments`. Plan documenté (`PLAN_P2_MULTI_TENDER_HANDOFF.md:67-72`).
- **Impact :** scénarios "TR + cash complément", "split bill", "cash+card+TR" infaisables.
- **Sévérité :** P2 (roadmap).

### F-06 — TR n'a pas de notion de surpaiement / contrepartie (P2)
- **Évidence :** validation serveur ne lit `pos_received_amount` que pour CASH (`OrderService.php:841`).
- **Impact :** un TR de 12 € sur ticket de 10 € est accepté sans tracer la perte (la valeur faciale d'un titre-restaurant n'est généralement pas rendue en monnaie : règle métier explicite à coder).
- **Sévérité :** P2.

### F-07 — `pos_payment_method` mono-valué dans `SimpleOrderResource` / `OrderDetailsResource` (P3)
- **Évidence :** `SimpleOrderResource.php:32`, `OrderDetailsResource.php:50` — exposent un seul int.
- **Impact :** la migration multi-tender exigera un nouveau champ collection `order_payments[]` avec rétrocompatibilité (versionning API ou flag).
- **Sévérité :** P3 (anticipé, non bloquant).

---

## 8. Verdict global et critères §6

Critères §6 du task file :
- ALL_GREEN si V1–V7 prouvés. → V1 partiel, V3 partiel, V7 fail → **non**.
- WARN si V4 limité à TR seul, avec ticket P. → V4 partiel/accepté ; **conditions WARN remplies**.
- FAIL si TR mal mappé fiscalement **ou** note contournable. → mapping fiscal correct (V2/V4 OK), note **non contournable** (V1 back OK) → **pas FAIL**.

### **GLOBAL : WARN**

Justification : le socle back-end TR est correct et la note est strictement enforced côté serveur (pas d'exploit). Les défauts sont (a) absence totale de l'UI TR côté POS (front), (b) reçu sans libellé TR, (c) audit log NF525 ne capture pas le tender à la création, (d) refund POS muet. Aucun n'est bloquant pour la conformité fiscale immédiate (puisque TR n'est pas accessible via UI POS, donc pas en production), mais ils interdisent la mise en service réelle de TR.

---

## 9. Cycles P proposés

| Cycle | Périmètre | Sévérité | Findings adressés |
|---|---|---|---|
| **P11_FRONT_TR_UI** | Ajouter `TICKET_RESTAURANT: 5` à `posPaymentMethodEnum.js`, bouton TR dans `PaymentComponent.vue` (icône lab-tr), champ note référence avec validation `required && length > 0` (bouton désactivé tant que vide), reset sur switch tender. Vitest spec `PaymentComponent.spec.js::tr_flow_requires_note`. | P1 | F-01 |
| **P11_RECEIPT_TR_LABEL** | Compléter `ReceiptComponent.vue:211-216` `posPaymentMethodEnumArray[5]` + clé i18n `label.ticket_restaurant` dans `resources/js/languages/{fr,en,de,ar,bn}.json`. | P1 | F-02 |
| **P11_AUDIT_TENDER_ON_CREATE** | Dans `OrderService::posOrderStore`, ajouter `app(AuditLogService::class)->write(['action' => 'pos.order_created', 'payload' => ['pos_payment_method', 'pos_payment_note' (hashé/4 derniers), 'total', 'fiscal_sequence_no']])` après `$this->order->save()`. NF525 conforme. | P1 (fiscal) | F-03 |
| **P11_POS_TRANSACTION_ROW** | Créer une ligne `Transaction` (`payment_method` = libellé du tender, `amount` = total, `type` = 'payment') dans `posOrderStore` après commit, pour qu'`OrderService::changeStatus` (RETURNED/CANCELED) puisse déclencher `cashBack` + audit `payment.cash_back_issued`. | P1 (fiscal) | F-04 |
| **P12_ORDER_PAYMENTS_FOUNDATION** | Migration `order_payments` (FK `order_id`+`branch_id`, `method`, `amount`, `reference`, `tendered_amount`, `change_amount`, `audit_chain_id`, `created_at`). Service `OrderPaymentSettlementService` (`Σ amount = total`). Refactor `ZReportService::aggregate` pour itérer sur `order_payments`. Nouveau champ resource API `payments[]` avec rétrocompat `pos_payment_method` lecture-seule. | P2 (roadmap) | F-05, F-07 |
| **P12_TR_OVERPAY_RULE** | Règle métier explicite : TR jamais rendu en monnaie ; surpaiement TR (note > total) = refusé en validation OU enregistré comme `discount_implicit_tr`. À discuter avec métier (CRT URSSAF). | P2 | F-06 |

---

## 10. Suite (per task §8)

- Pas FAIL → pas besoin de `P11_TR_FISCAL_FIX` immédiat.
- Créer en priorité **P11_AUDIT_TENDER_ON_CREATE** (NF525) et **P11_POS_TRANSACTION_ROW** (refund POS) — risques fiscaux non spécifiques à TR mais révélés par cet audit.
- Roadmap : **P12_ORDER_PAYMENTS_FOUNDATION** = pré-requis structurel pour vraie offre multi-tender (split, cash+card+TR, pourboire dédié, etc.).
- Avant toute mise en service TR en production : exécuter P11_FRONT_TR_UI + P11_RECEIPT_TR_LABEL + P11_AUDIT_TENDER_ON_CREATE.

---

**GLOBAL: WARN** — Cycles P proposés : `P11_FRONT_TR_UI`, `P11_RECEIPT_TR_LABEL`, `P11_AUDIT_TENDER_ON_CREATE`, `P11_POS_TRANSACTION_ROW`, `P12_ORDER_PAYMENTS_FOUNDATION`, `P12_TR_OVERPAY_RULE`.

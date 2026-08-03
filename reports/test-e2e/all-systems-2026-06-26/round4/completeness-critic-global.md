# COMPLETENESS-CRITIC GLOBAL — « Qu'avons-nous RATÉ sur 3 rounds ? » (round 4)

**Rôle** : méta-audit de complétude. READ-ONLY absolu (SELECT only, 0 écriture, 0 fichier touché).
**DB** : foodking_e2e (branch 1 = Le Cayenne). Serveur :8766.
**Méthode** : pour chaque angle mort candidat → vérif file:line / route:list / SELECT, PUIS
dédup contre TOUS les rapports de la mission (`round2/`, `round3/`, `_cross-cutting/`, `web-app/`,
`borne/`, `caisse/`, `kds-oss/`, `central/`). Ne reporte que des trous **prouvés** non-couverts.

## VERDICT : 4 angles morts prouvés (1 P2 opérationnel, 3 P3). Le cœur argent/fiscal est bien couvert ; les trous sont des SURFACES jamais ouvertes + des CHEMINS fiscaux mono-taux jamais exercés avec >1 taux.

---

## [P2] SURFACES ADMIN JAMAIS OUVERTES sur les 3 rounds — printers / payment-terminals / observability / settings sub-pages / coupons / offers / messages / transactions

**Preuve d'existence (route:list + Vue components)** :
- Routes API + pages SPA réelles : `admin/printers`, `admin/payment-terminals`,
  `admin/observability` (outbox + sync-overview), `admin/coupon`, `admin/offer`,
  `admin/message`, `admin/transaction`, `admin/timezone`, `admin/country-code`,
  `admin/default-access`, `admin/cash-overview`, `admin/cash-sessions-report`,
  `admin/credit-balance-report`, `admin/items-report`, `admin/menu-projection`.
- Composants Vue présents : `resources/js/components/admin/{settings,observability,coupons,offers,messages,transactions}` + `admin/settings/PaymentTerminals`.

**Preuve de NON-couverture** : `grep -rhoE "/admin/[a-z-]+"` sur TOUS les rapports de la mission
= surfaces citées {pos, pos-order, kitchen, kds, customers, item, license, administrators, waiters,
settings(×2 mentions génériques), online, table-order, stock, sales-report, role, oss, ingredients,
fiscal, encaissement, delivery, composer, cash}. **0 occurrence** de printers, payment-terminals,
observability, coupons(UI), offers, messages, transactions, timezone, country-code, default-access.

**Pourquoi V1-pertinent** (priorisé) :
1. **`admin/printers`** — la SEULE imprimante = row id 2 « SAGA Caisse (test) », `type=escpos_tcp`,
   `station=receipt`, `status=5` (= `Status::ACTIVE`, vérifié `app/Enums/Status.php:7`). C'est le
   chemin ticketing **encaissement + cuisine** (`PosReceiptPrintController.php:126` et
   `PrintKioskOrderToCounter.php:56` filtrent `status=ACTIVE`). La page d'édition de cette config
   (host/port/station/width_chars) n'a JAMAIS été ouverte → un opérateur qui mésédite host/station =
   ticket cuisine/client perdu silencieusement = commande perdue en pratique. Projet SAGA-USB en vol
   (mémoire 2026-06-24) rend cette surface chaude.
2. **`admin/observability`** (outbox + sync-overview) — tableau de bord OPS de la synchro. Si lui-même
   tombe en error/empty-state, l'opérateur vole à l'aveugle sur la santé sync borne↔caisse↔KDS. Jamais
   capturé → son empty/error state est inconnu.
3. **`admin/payment-terminals`** — config du TPE (row 1 « TPE Le Cayenne #1 », `gateway_type=simulation`).
   La logique collect a été testée (`PaymentService::confirmCounterPayment`) mais l'UI de gestion du
   terminal, non.

**Repro** : `route:list | grep -E "admin/(printers|payment-terminals|observability)"` → routes existent ;
`grep -ri "printers\|payment-terminals\|observability" reports/test-e2e/all-systems-2026-06-26` → 0 hit.
**Evidence** : `printers` row (escpos_tcp/receipt/status=5) ; `payment_terminals` row (simulation/status=1).
**Lentille** : completeness — surface jamais ouverte (état nominal + empty + error).
**Reco** : ouvrir chaque surface (Playwright :8766), capturer render + empty-state (filtrer pour 0 ligne)
+ error-state, et vérifier que la row printer réelle est correctement reflétée/éditable. NON-frozen.

---

## [P3] NF525 — décomposition TVA multi-taux (`z_reports.total_by_tax_rate`) JAMAIS exercée avec >1 taux

**Preuve** :
- Tous les items ACTIFS sont à 10 % : `items` actifs `tax_id=3` (VAT 10 %, 67 items) ; `tax_id=1`
  (No-VAT 0 %) n'a **aucun item status=1** ; la row `taxes` id 7 « VAT 5.5 » **existe mais 0 item**.
- `0` commande branch 1 ne mélange 2 taux : `SELECT COUNT(*) FROM (… GROUP BY order_id HAVING
  COUNT(DISTINCT tax_rate)>1) = 0`.
- `0` rapport de la mission ne mentionne `total_tva`, `total_by_tax_rate`, « multi-taux », ni « 5,5 ».

**Code (lu, non touché — frozen `ZReportService.php`)** : `total_by_tax_rate` EST la SSOT (LOCK l.425-432,
`round` par bucket l.439, `total_tva = array_sum` l.442, identité NF525 par construction). Le mirror
remboursement par taux est aussi codé (l.694-722, GROUP BY order_id,tax_rate). **La logique existe et
est verrouillée — mais elle n'a jamais tourné avec ≥2 buckets** ni en live ni dans un test cité.

**Pourquoi V1-pertinent** : en France la restauration mélange légitimement 10 % (chaud/sur place) et
5,5 % (froid/à emporter packagé) ; la row 5,5 % préexiste = dormant amorcé. Le 1er produit 5,5 %
ajouté par l'owner serait le **tout premier** test réel de la décomposition TVA du Z (ticket + Z).
**Repro/test proposé** : seeder un item `tax_id=7` (5,5 %), passer une commande mixte 10 %+5,5 %,
clôturer un Z, asserter `total_by_tax_rate` = 2 buckets dont la somme == `total_tva` et
`total_ttc == total_ht + total_tva`. **Lentille** : NF525 chemin non-prouvé. **Frozen** : oui (audit-only).

---

## [P3] CLASSE arrondi monétaire sur MULTI-QUANTITÉ jamais validée numériquement

**Preuve** : `order_items` branch1 avec `quantity>1` = **1764 lignes** réelles. En mode TTC (défaut
`pricing.tax_inclusive_prices=true`, `config/pricing.php:31`), la TVA est extraite **par LIGNE** sur le
total ligne (`TaxCalculator::lineTaxAmountFromTTC`, `round(...,2)` sur la TVA extraite), donc
qty est multipliée AVANT l'arrondi ligne ; puis le Z somme les `tax_amount` ligne déjà arrondis et
re-arrondit chaque bucket (`ZReportService` l.711-722, 439). **Aucun round de la mission n'a vérifié
numériquement** que, sur une commande réelle multi-qty / multi-ligne, `Σ tax_amount(ligne) == Z.total_tva`
sans dérive d'accumulation centime. La conception LOCK borne la dérive, mais elle reste **non mesurée**.

**Pourquoi V1-pertinent** : un écart d'1 centime entre la somme des tickets et le total TVA du Z est un
classique NF525 ; il est probablement inoffensif ici (arrondi cohérent) mais **non démontré**.
**Repro/test** : prendre un `order_id` réel multi-qty multi-ligne, recalculer la TVA par-unité ET
par-ligne, comparer au `total_tva` du Z couvrant cette commande, tolérance documentée.
**Lentille** : classe de bug (arrondi multi-quantité) jamais cherchée. **Frozen** : oui (PricingService/ZReportService — audit-only).

---

## [P3] RBAC — rôles malformés/non-exercés jamais audités (hygiène + chemin Waiter mort)

**Preuve (SELECT)** :
- `roles` contient une row **id 14 nommée littéralement « 3 »** + **doublon « Branch Manager » (id 6 ET id 9)** + `Stuff` (id 8) — pollution de seed.
- `model_has_roles` : **Waiter (id 4) = 0 utilisateur**, `Stuff` (8) = 0, role 9 = 0, role 14 = 0
  (rôle « 3 » = 0 permission). Seuls Admin(21)/POS Operator(44)/Branch Manager-6(5)/Chef(2)/Delivery(5)/Customer(12) ont des users.

**Pourquoi V1-pertinent** : le chemin RBAC **Waiter** (porteur des `table-orders` = jumeau refund
documenté round2) n'est JAMAIS exercé en live car 0 user + dine-in dormant → la garde supposée
« Waiter ne peut pas rembourser » n'est prouvée que par lecture de code, jamais par un user Waiter réel.
Le rôle « 3 » (0 perm, inoffensif) reste de la pollution non nettoyée jamais signalée.
**Repro** : `SELECT id,name FROM roles` (montre id14=« 3 », doublon BM) ; `model_has_roles GROUP BY role_id`
(Waiter/Stuff/9/14 absents = 0 user). **Lentille** : modalité RBAC non-exercée + hygiène data.
**Reco** : (a) test feature avec un user Waiter réel prouvant le refus refund ; (b) nettoyer roles 8/9/14
(seed). **Frozen** : non.

---

## Angles VÉRIFIÉS-SAINS (écartés, anti-bruit)
- **Timezone Z** : `config/app.php` tz = `Europe/Paris` ; fenêtre Z = `opened_at..closed_at` (session,
  `ZReportService` `Carbon::now()`), PAS un découpage minuit → pas de risque J-1/UTC. Edge DST (Z
  chevauchant 02:00→03:00) théorique, bornée, écartée (non-reportée faute de preuve d'impact).
- **Cluster money-FR** : déjà traité rounds 2-3 (heals catalogue/time/null-glue/money-5) — non ré-revendiqué.
- **Encoding/UTF-8 on-screen** : pas de colonne nom client exploitable dans `orders` (probe échouée) →
  aucune preuve live → NON reporté (anti-hallucination).

## Anti-hallucination
Chaque finding = route:list / file:line / SELECT exécuté + preuve de non-couverture par grep des rapports
frères. Les angles sans preuve live (encoding, DST) sont explicitement écartés, pas reportés.

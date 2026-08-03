# Audit adversaire LOGIQUE — GESTION Reports / Dashboard / Delivery / RBAC

Date : 2026-07-11 · Auditeur : logique (read-only + tinker) · Cible : pages gestion INDIRECTES
Backend live :8766 · DB réelle interrogée via `artisan tinker`.

Fichiers examinés : `DashboardService`, `AnalyticService`, `Delivery/DeliveryFeeService`,
`Delivery/DeliveryQuoteService`, `RoleService`, `DiningTableService`, `DeliveryBoyService`,
`Fiscal/XReportService`, `Fiscal/ZReportService::aggregate`, `Order` scopes, `OrderQuoteService`,
`OrderService` / `FrontendOrderService` (chemin livraison offerte), routes/`DashboardController`.

---

## RÉSUMÉ DES FINDINGS

| # | Sévérité | Sujet | Fichier:ligne |
|---|----------|-------|---------------|
| F1 | **P1** | Répartition canaux ne somme PAS à 100 % + POS caisse massivement sous-compté | `DashboardService.php:547` |
| F2 | **P1** | EOD synthèse (PDF gérant/comptable) : ventes POS étiquetées « Web/App » | `DashboardService.php:739` |
| F3 | **P2 (latent)** | EOD `total_ca` diverge du Z signé (filtre realized inline sans exclusion Uber) | `DashboardService.php:620-626` |
| F4 | **P2** | Ticket moyen « temps réel » : numérateur/dénominateur sur populations différentes | `DashboardService.php:429-436` |
| F5 | **P3** | `DeliveryFeeService` : distance négative/NaN → livraison GRATUITE silencieuse (0 €) | `DeliveryFeeService.php:29-31` |

Confirmés SAINS (pas de bug) : X-report (RO, ne consomme pas de séquence, cohérent Z),
DiningTable (occupy/transfer/release verrouillés + branch-guard + double-occupation déjà healée),
DeliveryBoy (branch-scope via `effectiveBranchId` + role-guard), RoleRequest (gate `can('settings')`
+ nom réservé « Tenant Admin » bloqué), formule frais livraison (4€ ≤5km +1€/km entamé — conforme
règle owner), cohérence quote↔order « livraison offerte ≥ seuil » (POS + web, POS-1 vérifié).

---

## F1 — P1 — Répartition par canal : Web+Kiosk+POS ne somme pas à 100 %, POS sous-compté

**Fichier** : `app/Services/DashboardService.php:547` (méthode `channelStatistics`), route
`GET /admin/dashboard/channel-statistics` (`DashboardController.php:184`) → pie chart gérant.

**Cause** : le bucket POS est clé sur l'entier LEGACY `source` :
```php
$posCount = $orders->where('source', \App\Enums\Source::POS)->count(); // Source::POS = 15
```
Or, en base, la QUASI-TOTALITÉ des commandes caisse portent `source_surface = 'pos'` mais
`source = 1` (valeur par défaut ; `OrderService.php:1153` pose `source_surface='pos'` mais NE pose
JAMAIS `source`). Le marqueur canonique du canal est `source_surface` (cf. commentaire kiosk
WG-3 dans la même méthode), mais seul le bucket Kiosk l'utilise ; POS et Web restent clés sur le
`source` int peu fiable. Une commande `source_surface='pos', source=1` :
- pas Kiosk (`source_surface != 'kiosk'`, `source != 10`),
- pas Web (`source != 5`),
- pas POS (`source != 15`),
→ comptée dans AUCUN bucket. Les % ne somment plus à 100.

**Repro DB réelle (cross-tab)** : `source_surface='pos'` → **1356** lignes avec `source=1`,
seulement **397** avec `source=15`.

**Repro fonction (jour réel, fenêtre Paris identique au code)** :
```
2026-07-02 : total=36  Web=0%  Kiosk=55.6%  POS=11.1%  → SUM=66.7 %  (12 commandes non comptées)
2026-07-01 : total=50  Web=0%  Kiosk=82%    POS=12%    → SUM=94 %    (3 non comptées)
Tout l'historique : SUM = 54.45 %, 1457 commandes droppées dont 1381 = vraie caisse (source_surface='pos')
```
Le gérant voit un camembert qui ne fait pas 100 % et un POS ridiculement bas (11 % un jour où la
caisse a fait l'essentiel du volume).

**Fix** : clé POS sur le marqueur canonique, comme Kiosk :
```php
$posCount = $orders->filter(fn($o) =>
    strtolower((string)($o->source_surface ?? '')) === 'pos'
    || (int)$o->source === \App\Enums\Source::POS
)->count();
```
et exclure les lignes pos-taggées du bucket Web (miroir de l'exclusion kiosk déjà présente).
Idéalement : router les 3 buckets sur `source_surface` et ne garder `source` que comme repli legacy.

---

## F2 — P1 — EOD synthèse : ventes POS caisse comptées comme « Web/App »

**Fichier** : `app/Services/DashboardService.php:739` (`bucketChannels`, appelée par `eodSynthesis`,
PDF `pdf/eod_synthesis.blade.php` destiné à l'owner/comptable, archivé 6 ans NF525).

**Cause** : MÊME racine que F1. `bucketChannels` fait :
```php
if (source_surface==='kiosk' || source===APP) -> Borne
elseif (source===POS/*15*/)                    -> POS Caisse
else                                            -> Web/App   // fourre-tout
```
Une commande caisse `source_surface='pos', source=1` tombe dans le `else` → **Web/App**.
Contrairement à F1 (elle disparaît), ici elle est **RÉAFFECTÉE à un mauvais canal** → le CA « Web/App »
du rapport de fin de journée est gonflé du CA caisse.

**Repro DB réelle (jeu realized, tout l'historique)** :
```
bucketChannels : Kiosk=826  POS=376  Web=1409
dont commandes source_surface='pos' rangées en Web/App = 1377
```
Le PDF affiche ~1409 ventes « Web/App » dont 1377 sont en réalité la caisse. Chiffre gérant faux
dans un document comptable archivé.

**Fix** : identique à F1 — brancher le bucket POS sur `source_surface==='pos'` avant le fallback Web.

---

## F3 — P2 (latent) — EOD `total_ca` peut diverger du Z signé (exclusion Uber manquante)

**Fichier** : `app/Services/DashboardService.php:620-626` (filtre `realized` inline de `eodSynthesis`).

**Cause** : le SSOT du CA réconcilié (`Order::scopeRealizedRevenue` L336 et son miroir collection
`Order::isRealizedRevenueRow` L358) exclut le canal non-fiscalisé Uber
(`source_surface != 'uber_eats'`) — fix P1 self-audit 2026-07-05, aligné sur le Z (qui exige
`fiscal_sequence_no NOT NULL`, absent pour Uber). MAIS le filtre inline d'`eodSynthesis` (écrit
2026-05-26, jamais mis à jour) NE reproduit PAS cette exclusion :
```php
$isLivePaidSale = payment_status===PAID && !in_array(status,$terminal); // <-- pas de test uber_eats
```
Donc `total_ca` du PDF EOD compterait les ventes Uber alors que le Z signé et le dashboard live les
excluent → EOD > Z (contradiction NF525 « agree with the Z »).

**Statut** : LATENT — aucune commande `source_surface='uber_eats'` en base aujourd'hui (surfaces
présentes : NULL, pos, kiosk, mobile, delivery, web, phone). Se déclenche dès la 1ʳᵉ vente Uber.

**Fix** : ajouter `&& $o->source_surface !== 'uber_eats'` à `$isLivePaidSale`, ou remplacer le filtre
inline par `Order::isRealizedRevenueRow($o)` (déjà à jour) pour éviter toute future dérive.

---

## F4 — P2 — Ticket moyen temps réel : numérateur et dénominateur sur populations différentes

**Fichier** : `app/Services/DashboardService.php:429-436` (`realtimeReport`).

**Cause** :
- numérateur `daily_sales` = `realizedRevenue()->sum('total')` → EXCLUT les annulées-payées,
  INCLUT les miroirs de remboursement (total négatif), EXCLUT Uber.
- dénominateur `daily_paid_orders` = `count(payment_status = PAID)` → INCLUT les annulées-payées,
  n'inclut PAS les miroirs (payment_status=20).

Les deux ensembles ne coïncident pas → `ticket_moyen = daily_sales / daily_paid_orders` légèrement
faussé les jours avec annulation-payée ou remboursement. Division par 0 correctement gardée
(`daily_paid_orders > 0`).

**Repro** : 39 commandes PAID+terminales (annulées/rejetées/retournées) et 6 miroirs REFUNDED en base
→ magnitude faible au jour le jour, mais l'incohérence conceptuelle demeure (le libellé « Ticket
moyen » suggère CA-encaissé / commandes-encaissées sur la même base).

**Fix** : aligner le dénominateur sur la même base que le numérateur (compter les ventes live PAID
non-terminales, hors miroirs), p. ex. réutiliser `Order::isRealizedRevenueRow` filtré sur
`parent_order_id === null`.

---

## F5 — P3 — DeliveryFeeService : distance invalide → livraison gratuite silencieuse

**Fichier** : `app/Services/Delivery/DeliveryFeeService.php:29-31`.
```php
if ($distance === null || ! is_finite($distance) || $distance < 0) {
    return 0.0;   // 0 € = livraison OFFERTE
}
```
Une distance négative/NaN/non-numérique renvoie **0 €** (gratuit) au lieu de lever une erreur ou
d'appliquer le minimum. `DeliveryQuoteService` (seul chemin client) valide `distance >= 0 && finite`
en amont (L56), donc non exploitable via l'API client. Mais le commentaire du service revendique
« 5+ call sites » ; tout appelant futur passant une distance corrompue facturerait 0 € sans alerte.

**Fix** : pour une entrée invalide, retourner le minimum configuré (`delivery_fee_minimum`/legacy 5€)
ou lever une exception, plutôt que 0 € silencieux.

---

## VÉRIFICATIONS SAINES (documentées pour non-régression)

- **X-report (item 4)** — `XReportService` ne contient aucun `create/save/insert/allocate/sequence`
  (grep vide) : purement read-only, NE consomme PAS de séquence fiscale. Il délègue à
  `ZReportService::aggregate($branch,$from,$to)` → un X est GARANTI cohérent avec le Z de la même
  fenêtre (invariant « cohérence intraday »). ✅
- **Formule frais livraison** — branche 1 configurée `base=4, per_km=1, min=4, free_km=5`.
  Vérif bornes : 0/5 km → 4 € ; 5.1 km → 5 € ; 6.1 km → 6 € (ceil km entamé au-delà du rayon offert).
  Conforme à la règle owner « 4€ ≤5km, +1€/km ». ✅
- **Livraison offerte ≥ seuil (quote↔order, pattern POS-1)** — seuil `Settings delivery.free_delivery_above`
  (défaut 30), test `>= seuil` sur `accumulatedSubtotal` (SSOT serveur, jamais client), appliqué de
  façon symétrique dans `OrderQuoteService.php:323-340` ET `OrderService.php:860-878` (POS) et
  `FrontendOrderService.php:538-543` (web). Pas de 409 total-mismatch résiduel. ✅
- **DiningTable (item 6)** — `occupy` valide l'order même-branche AVANT lock (L191-197), `transfer`
  lock par id ASC (anti-deadlock), `release`/`tryRelease` gardés sur `occupied_order_id`. Double
  occupation déjà healée (self-audit 2026-07-05). Aucune table en 2 états. ✅
- **DeliveryBoy (item 6)** — `store`/`update` posent `branch_id` via `effectiveBranchId(auth,req)`
  (trait `EnforcesOwnBranchScope`), `list` scopé par BranchScope global (User), `assertTargetRole`
  garde-fou rôle. Pas de livreur cross-branche injectable. ✅
- **RBAC / RoleService (item 5)** — `RoleRequest::authorize` = `can('settings')` ; nom « Tenant Admin »
  bloqué (`Rule::notIn`) ; unicité `roles.name` empêche recréer « Admin ». Routes `role.*` sous
  middleware `permission:settings`. Pas d'escalade via RoleService seul. (Attribution fine des
  permissions = lane audit sécu.) ✅
- **Division par 0** — toutes les moyennes/ratios gardés : `orderSummary` (total_order>0),
  `salesSummary` (count($dateRangeArray)≥1), `channelStatistics` (total===0→zéros), `realtimeReport`
  et `eodSynthesis` (paidCount>0). ✅

---

## RACINE COMMUNE F1+F2

Le canal réel d'une commande est porté par `source_surface` ('pos'|'kiosk'|'web'|'delivery'|'phone'),
FIABLE en base. L'entier legacy `source` (WEB=5/APP=10/POS=15) n'est PAS renseigné de façon fiable
par les chemins de création POS (reste à 1/0/NULL). Les fonctions dashboard n'ont adopté
`source_surface` QUE pour le bucket Kiosk (fix WG-3 2026-05-19) ; POS et Web sont restés sur `source`.
Le correctif structurel unique : router les 3 buckets (channelStatistics + bucketChannels) sur
`source_surface`, `source` en repli legacy. Fichiers non-frozen, hors NF525.

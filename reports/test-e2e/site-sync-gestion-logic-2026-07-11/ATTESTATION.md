# SITE / SYNCHRO / GESTION — CHASSE AUX PROBLÈMES DE LOGIQUE (toutes pages, même indirectes) — 2026-07-11
> /goal. 4 agents adversaires LOGIQUE disjoints (site web, synchro, gestion-catalogue, gestion-reports)
> + e2e. Chaque finding VÉRIFIÉ dans le code avant action.

## 7 BUGS DE LOGIQUE RÉELS CORRIGÉS + testés (0 frozen)
| # | Bug | Fichier | Fix |
|---|---|---|---|
| web-F1 | Suppléments `galette_only`/`galette_excluded` JAMAIS filtrés → « Boule gratinée » sur un Cayenne = **affiché 8,40€ / facturé 7,40€** (extra droppé résolution borne) | `wizard-v2.jsx` (2 repos) | filtre par catégorie (isGalette=cat 2). Vérifié navigateur |
| web-F2 | Aperçu points wizard `Math.round` → sur-promesse (7,60€→«+8» au lieu de 7) | `wizard-v2.jsx` (2 repos) | `LC.loyalty.earnPoints`/floor |
| P1-C | **Suppression catégorie orpheline les items actifs** (logique inversée : supprimait SI items actifs → items invisibles des grilles) | `ItemCategoryService:170` | bloque (422) si items actifs |
| P1-D | **Extra gratuit (0€) non éditable** (`IniAmount()` rejette ≤0 ; 78/377 extras à 0€ = crudités) | `ItemExtraRequest:35` | `IniAmount(true)` (aligné variations) |
| reports-F1 | **Répartition canal ne somme pas à 100 %, POS sous-compté** : bucket sur `source=15` rate les 1356 caisse (source_surface='pos'/source=1) | `DashboardService:547` | route sur source_surface (POS **396→1777**, somme 54%→97,7%) |
| reports-F2 | **Ventes POS étiquetées « Web/App » dans le PDF EOD** (archivé 6 ans NF525) | `DashboardService:739` | idem source_surface |
| P1-A/B | **Borne : coupon % lu comme fixe** (`discount_type==1` vs enum PERCENTAGE=10) + fallback ignore status/limites/scope | `KioskPromoService:82,98,74` | `DiscountType::PERCENTAGE` + `isUsableNow()` |

## E2E interactif (croisé DB avant conclusion — 2 fausses alertes écartées)
- Dashboard « CA Jour=0 / Commandes Jour=3 » = 3 cmd du jour toutes impayées → métriques créées vs payées, pas un bug ✅
- Web `priceFor` cohérent (sauces gratuites web = revert 1-sauce, formules +2,50/+1,50/+1,00) ✅

## Findings RÉELS documentés (à traiter — plus de portée / owner)
- **Sync P1 (SYNC-LOGIC-01)** : un item en rupture reste commandable comme **composant de menu** — la
  garde d'orderabilité `AvailabilityService::assertItemsOrderableForBranch` ne reçoit que les item_id
  de 1er niveau, pas les `addon_item_id` du snapshot (asymétrie lecture/écriture). Survente d'un
  composant 86 (pas de corruption stock : décrément throw+catch). Fix = injecter addon_item_id dans
  la garde (FrontendOrderService + OrderService, 2 chemins) — touche les paths d'écriture commande, à faire soigneusement.
- **web-F3** base « earn » incohérente sur 4 écrans (panier/checkout/historique) ; **F4** `data/loyalty.js`
  redeem linéaire vs backend multiples de 100 (latent) ; **F5** pas de clamp ≥0 + dérive centimes seuil livraison.
- **reports-F3** EOD total_ca n'exclut pas Uber (latent, 0 Uber) ; **F4** ticket moyen num/dénom populations
  différentes (magnitude faible) ; **F5** DeliveryFee distance négative→0€ (non exploitable API).
- **P2** offres non câblées au prix (dormant), coupons discount_type=2 hors-enum, OfferItemService anti-doublon fragile.
- Débris : 16 outbox loyalty poison (feature retirée), 16 commandes statut hors-enum (legacy).

## Invariants PROUVÉS verts (agents)
Sync : décrément idempotent, release re-crédit 1×, flip dispo à on_hand=0, concurrent atomique sans stock
négatif, statut KDS↔OSS non-divergent, cascade annulation, snapshot figé, idempotence outbox. Gestion :
cascade rupture ingrédient, rupture branche>global, bornes coupon jamais négatif, prix négatifs bloqués,
X-report cohérent Z, livraison 4€/≤5km/+1€/offerte≥30€ bornes exactes, quote↔order symétrique, RBAC gate settings.
Web : 38 produits sans fantôme, 78 images présentes, quantités ≥1, panier vide bloqué, double-submit gardé, QR robuste.

## Gates
0 frozen · NF525 chain clean 4 branches · régression Dashboard+Category+Extra+KioskPromo+Coupon VERTE.

## MàJ 2026-07-11 (suite « corrige et valide avec test-e2e »)
**Sync P1 (SYNC-LOGIC-01) — CORRIGÉ + TESTÉ** : composant de menu en rupture désormais inordonnable.
Helper `AvailabilityService::componentItemIdsFor()` résout les `addon_item_id` ; les 4 chemins d'écriture
(FrontendOrderService + OrderService ×3) fusionnent les composants dans la garde. Tests
`test_menu_component_out_of_stock_blocks_order` + contre-preuve `in_stock_allows_order`. Régression
Menu+Order+Kiosk+Pos verte. 0 frozen, NF525 clean. Commit `4a1b6648e` (NON poussé — branche porte 3
commits d'une session parallèle non-poussés). → **8 bugs logique corrigés au total** sur ce round.

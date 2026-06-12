# Lane D-B3-rapports-equipe — micro-audit 9 pages (2026-06-12)

Harnais :8767 / foodking_e2e. Login UI admin@lecayenne.fr + token Sanctum forcé (header) `2577|…`.
Engine: `d-b3-engine.cjs` → `d-b3-report.json` (par page: load ms, console, http≥400, rows, scan i18n/AM-PM/money, a11y, filtre→recherche-bidon→empty→clear→restore, recherche réelle, pagination p2, per-page 25, dropdown export).

## Verdicts par page (lentille ①-⑨)

| Page | Load | Console | HTTP≥400 | Recherche | Pagination | Per-page | Empty FR | i18n/format | Verdict |
|---|---|---|---|---|---|---|---|---|---|
| sales-report | 720 ms | 0 | 0 | bidon→0+«Aucune donnée», clear→10 ✅ | p2 change réellement (F2-RDM-4→1006264505) ✅ | 10→25 rows=25 ✅ | ✅ | 0 AM/PM, € virgule, d-m-Y, 24h ✅ | GREEN |
| items-report | 557 ms | 0 | 0 | (pas d'input texte — datepicker+selects, by design) | p2 change (Menu→Coca 33cl) ✅ | 25 ✅ | n/a | ✅ | GREEN |
| administrators | 577 ms | 0 | 0 | bidon→empty, clear→2, réelle «admin»→2 ✅ | n/a (2 rows) | n/a | ✅ | ✅ | GREEN |
| employees | 588 ms | 0 | 0 | bidon→empty, clear→10 ✅ | p2 change ✅ | 10→18 (total 18) ✅ | ✅ | ✅ | GREEN |
| chefs | 577 ms | 0 | 0 | bidon→empty, clear→1 ✅ | n/a | n/a | ✅ | ✅ | GREEN |
| customers | 575 ms | 0 | 0 | bidon→empty, clear→3 ✅ | n/a | n/a | ✅ | ✅ | GREEN |
| delivery-boys | 563 ms | 0 | 0 | bidon→empty, clear→1 ✅ | n/a | n/a | ✅ | ✅ | GREEN |
| delivery-boy-cash-sessions | 561 ms | 0 | 0 | page custom: filtre STATUT select, pas de recherche texte | n/a (6 rows) | n/a | n/a | dates fr-FR 24h compact (intentionnel, sans année) | GREEN + P3 IDs bruts |
| waiters | 561 ms | 0 | 0 | n/a (0 serveur) | n/a | n/a | **empty-state au load: «Aucune donnée disponible.» + illustration ✅** | ✅ | GREEN |

Latence: AUCUNE page >2s (max 720 ms) → 0 finding ④.
Boutons: Filtrer ouvre le panneau sur 8/8 pages concernées; Exporter ouvre le dropdown (Print/Excel) sur 8/8; DBCS «Voir» (détail déjà ✅ audit petits-systèmes 06-11). 0 bouton mort.
Tri: aucune colonne triable dans ce pattern de codebase (pas un no-op — l'affordance n'existe pas). Tri serveur sanitizé (sanitizeOrderColumn).

## Véracité data (⑨) — TOUT EXACT (vérifié au même instant API vs tinker)
- sales-report total «2395» = Orders non-soft-deleted exact (2395=2395; les 13 manquants du 1er comptage = soft-deleted). Écart écran 2389 vs DB = lanes concurrentes qui créent des commandes pendant l'audit.
- Cartes overview: total_earnings 34 797,37 € = SUM(total) scope realizedRevenue exact; remises 4,00 € exact; livraison 5,00 € exact. (H-03/SALES-NET-01 tiennent.)
- employees «18 entrées» = 17 POS Operator + 1 Branch Manager ✅.
- items-report «45 entrées» = 45 items non-deleted ✅ (= SSOT 45 items V1). units_sold top = somme realizedRevenue (10814 API vs 10816 tinker qq secondes après = dérive concurrente). Ligne Total = full-dataset (REP-ITEMS-TOTAL-03) ✅ (11348 > somme page 11085 = normal).
- DBCS #7: expected 140,00 − compté 137,50 = écart −2,50 € exact (colonnes opening/closing/expected/variance DB).

## Findings (preuves grep + captures)
1. **D-B3-01 P3** — Caisses Livreur affiche les **IDs bruts** au lieu des noms: colonne LIVREUR = «10», FILIALE = «1». `resources/js/components/admin/deliveryBoyCashSession/DeliveryBoyCashSessionListComponent.vue:75-76` (`{{ session.delivery_boy_id }}` / `{{ session.branch_id }}`), idem ShowComponent.vue:20,25. Capture `D-B3-delivery-boy-cash-sessions.png`.
2. **D-B3-02 P3 (a11y ⑧)** — Filtre Date: `<label for="searchStartDate">` orphelin — vue-datepicker rend un `<input class="dp__input">` **sans id/aria-label/placeholder** → input non labellisé. `resources/js/components/admin/salesReport/SalesReportListComponent.vue:49-58` + `resources/js/components/admin/itemsReport/ItemsReportListComponent.vue:59-68`. Preuve: sonde DOM a11y (d-b3-report.json `.a11y`) sur les 2 pages.
3. **D-B3-03 P3 (format ⑦)** — Téléphones affichés `+330600000003` (concat naïve `country_code + phone` qui garde le 0 national → format international invalide) et incohérents (employees sans préfixe «0603025505»). `administrators/AdministratorListComponent.vue:108`, `chefs/ChefListComponent.vue:105`, `employees/EmployeeListComponent.vue:113`. Captures administrators/chefs/customers. Mi-data (seeds avec 0 initial) mi-affichage.

## DEDUP-suspect (1 ligne, non creusés)
- AM/PM sur UI FR = AB14-02 (data-op owner TIME_FORMAT sur .env opérant) — **non reproduit ici** car .env.e2e=H:i: tout est 24h ✅ (le fix tient).
- sales-report filter/export panels = déjà ✅ petits-systèmes 06-11 (revérifiés ici: opens).
- dbcs-detail / customer-detail = déjà ✅ petits-systèmes 06-11.

## Observations non-findings
- DBCS «07/06 22:56» = formatTimestamp fr-FR volontaire sans année (ListComponent:266) — compact assumé, pas une dérive d-m-Y.
- Rôle Spatie «Branch Manager» dupliqué cross-guard en DB (id6 sanctum + id9 web) — observation data, aucun impact UI constaté.
- Error-state non forcé (pas d'injection de panne sur clone partagé) ; pattern AdminController catch→422 + toasts couvert par audits antérieurs.

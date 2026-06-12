# Lane D-B1-coeur-gestion — micro-audit 2026-06-12

## Étape 1 — pass 1 (load + sinks + screenshots)
- 7 pages chargées via login UI réel (Chrome system, viewport 1280x900).
- Latences load (networkidle): dashboard 822ms, items 815ms, ingredients 580ms, attribute 587ms, extra 576ms, addon 598ms, stock-rupture 580ms → AUCUN >2s, pas de P2 latence.
- Console+HTTP propres sur 6/7 pages.
- /admin/stock/rupture → 401 GET /api/admin/default-access + 401 /api/admin/setting/branch puis redirect /login. SUSPECT FLAKE: token concurrent-kill (lanes parallèles relogin admin → revoke). À re-vérifier avec token forcé (lib.cjs route override).

## Étape 2 — passes 2-7 (interactions réelles, token forcé)
- Token forcé minté tinker (2584|...) + route override /api/ → immunisé contre les revoke concurrents. PROUVÉ nécessaire: passes 1 et 4 sans override → 401 /api/admin/default-access en plein run (lane parallèle relogin admin → revoke), SPA auto-logout. = FLAKE HARNAIS, pas un bug produit (single-token by design §9).
- ITEMS: recherche réelle OK (Filtrer→#name "Tacos"→Rechercher → ["Big Tacos","Tacos"]) ; vide FR "Aucune donnée disponible." ; Effacer→10 rows ; page 2 → "Affichage de 11 à 20 sur 45 entrées" first=Glace (données changent) ; limite 25 → 25 rows "1 à 25 sur 45" ; AUCUN tri possible (0 affordance th, cursor auto).
- PIÈGE SCRIPT (2 passes perdues): les KPI metric ont aria-label "Filtrer N ..." → getByRole(button,/Filtrer/).first() clique le KPI, pas le toggle ; et #name dupliqué (drawer création AVANT filtre dans le DOM) → fill('#name')+Enter A SOUMIS le form création caché → 2× 422 POST /api/admin/item (mutations rejetées, DB intacte).
- INGREDIENTS: tabs role=tab fonctionnent (Tous=38, Attributs=9, Suppléments=26, Add-ons=3 — tous = API/DB) ; deep-links attribute/extra/addon OK (addon "blank" pass-2 = race de capture pendant boot SPA, réfuté pass-5: 3 rows, tab actif Add-ons à 3.9s).
- DRAWER USAGE: contradiction liste vs drawer — "Jambon de dinde" liste "Utilisé dans 8 produit(s)" MAIS drawer "Non utilisé / Aucun produit ni catégorie n'utilise cet ingrédient." API: list used_by_count=8, /usage used_by_count=0. Root cause IngredientService.php:98 (count des rows ItemExtra) vs :210-214 (usedByRowsForExtra return [] si group_label null). 49/230 extras (7 lignes UI) affectés.
- STOCK RUPTURE: charge OK (token forcé), search "Tacos" filtre vraiment, vide FR "Aucun produit ne correspond à votre recherche.", catégories/compteurs cohérents.
- DASHBOARD: datepicker s'ouvre (console clean) MAIS calendrier EN ("Jun", "Mo Tu We Th Fr Sa Su") + input "06/12/2026 - 06/12/2026" = m/d/Y US (12 juin) — 4 widgets @vuepic/vue-datepicker sans prop locale/format.
- VÉRACITÉ DB (tinker): items 45/45 actifs/12 catégories = KPI page items EXACT ; orders 2383 affiché vs 2407 live = drift écritures concurrentes lanes (plausible) ; "Meilleurs Clients" liste 2 clients à 0 commande (topCustomers limit(8) sans filtre >0).
- FORMATS FR: € virgule partout, Z "10/06/2026 07:06:45" 24h, zéro AM/PM sur les 7 pages.
- LATENCE: API server 37-42ms ; data-visible full-reload 3.3-5.2s (1er appel API à t≈3.2s post-goto = boot SPA) — mesuré sous loadavg ~8 (lanes parallèles), nav SPA interne rapide. P2 avec caveat charge partagée.
- KPI "INDISPONIBLES" tronqué "INDISPONIB" (clientW 46 < scrollW 89) visible captures D-B1-items.png.

## Verdict batch: 1 P2 produit (drawer usage), 1 P2 latence (caveat charge), 4 P3. Pagination/recherche/tabs/filtres = fonctionnels prouvés. 0 P0/P1.

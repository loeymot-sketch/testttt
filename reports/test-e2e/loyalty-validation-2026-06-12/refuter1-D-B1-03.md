# REFUTER-1 — D-B1-03 (datepickers dashboard EN / format US)

## Verdict: NON RÉFUTÉ — finding CONFIRMÉ, sev P3 maintenue

### 1. file:line vérifiés (Read/grep)
- `resources/js/components/admin/dashboard/CustomerStatsComponent.vue:9` Datepicker, `:27` import — EXACT
- `OrderStatisticsComponent.vue:7` / `SalesSummaryComponent.vue:9` / `OrderSummaryComponent.vue:9` — Datepicker présent
- Lecture des tags COMPLETS (multi-lignes) : AUCUN prop `locale` ni `format` sur les 4. Props présents: uid/name/hideInputIcon/autoApply/enableTimePicker/utc/range/preset-ranges/aria-labels.
- Aucune config globale vue-datepicker (grep app.js/bootstrap.js/setLocale/globalProps = 0).

### 2. Repro live :8767 (browser locale FORCÉ fr-FR — exclut le flake locale-navigateur)
Script `refuter1-db103.cjs`, login admin@lecayenne.fr, /admin/dashboard, clic #dp-input-orderStatisticsDate.
- Inputs DOM extraits: orderStatisticsDate="06/12/2026 - 06/12/2026" (12 juin → format US m/d/Y, FR attendu 12/06/2026); les 3 autres="06/01/2026 - 06/30/2026" (même format US).
- Calendrier ouvert: mois "Jun" (EN, FR=juin), jours "Mo Tu We Th Fr Sa Su" (EN, FR=Lu Ma Me Je Ve Sa Di).
- Contraste probant: les presets adjacents SONT en FR ("Aujourd'hui","Ce mois","Mois dernier","Cette année") → la page est FR, seul l'interne du datepicker reste EN défaut en-US.
- Capture: `refuter1-DB103-orderstats-calendar.png` (lue visuellement: confirme Jun + Mo..Su + 06/12/2026).

### 3. DEDUP
- PARTIEL, pas un duplicate: commit `8a0a78a75` (uniquement sur `release/v1-2026-06-10`, PAS ancêtre de HEAD courant) a fixé locale fr + dd/MM/yyyy sur PosOrders/Historique/Sessions caisse/Vue d'ensemble caisse — les 4 widgets DASHBOARD n'étaient pas couverts même là-bas. Sur cette branche: aucun fix.
- Le wrapper/pattern FR existe donc déjà dans l'historique → recommandation du finding (locale fr + dd/MM/yyyy) alignée avec le précédent.

### 4. Sévérité
P3 juste: cosmétique i18n sans impact fonctionnel/NF525, mais violation réelle du mandat FR (ADR-007) + ambiguïté m/d/Y (06/12 lisible "6 décembre" par un gérant FR). Pas un finding cloud/SaaS sur-coté. corrected_sev=P3.

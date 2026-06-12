# REFUTER-1 — D-B1-04 (KPI 'INDISPONIBLES' tronqué à 1280)

## Vérification file:line — CONFIRMÉ
- `resources/js/components/admin/items/ItemListComponent.vue:33` = `<small>{{ $t('label.catalog_metric_unavailable') }}</small>` (lignes 31-34 = 4e bouton metric --alert). Vérifié par awk NR 25-40.
- FR: `resources/js/languages/fr.json:664` → "indisponibles" (13 chars, mot insécable).
- CSS `.catalog-control-plane__metric small` (~bloc 775-806): font-size 11px / weight 800 / uppercase, AUCUN overflow/text-overflow/white-space → overflow:visible computed. Grid metrics = `repeat(4, minmax(0,1fr))` → shrink sous la largeur du contenu autorisé.
- Mitigation existante: `:title` + `aria-label` complets déjà posés sur le bouton (commit iter15 A-010) → la reco "+ title" du finding est DÉJÀ en place ; seul l'ellipsis/abréviation manque.

## Repro live :8767 (e2e clone) — CONFIRMÉ
- Script: `refuter1-db104b.cjs`, viewport 1280x900, login admin UI, /admin/items.
- Mesure DOM identique à l'evidence du finding: `{text:'indisponibles', smallClientW:46, smallScrollW:89, truncated:true, btnClientW:66, overflow:visible, title:'0 indisponibles'}`.
- Visuel `refuter1-db104-plane-1280.png` (lu): le label rend "INDISPONIB" — coupé mid-word (le panneau actions adjacent à fond opaque recouvre le débordement). Bonus: "CATÉGORIES" aussi légèrement coupé ("CATÉGORIE").

## Dédup
- DÉJÀ CONNU: `reports/test-e2e/abuse-e2e-2026-06-01/round-1/wave-E-findings.json:20` — même défaut ("INDISPONIB" clipped), classé NON-blocking "UX-quality only" car tooltip+aria présents. D-B1-04 est donc un re-signalement d'un défaut connu et toujours ouvert (pas un dup intra-lot du run courant, mais dup historique 2026-06-01).

## Verdict
- refuted = false (reproduit avec preuve DOM + visuelle, file:line exacts).
- Sévérité: P3 correcte (cosmétique, admin-only, tooltip/aria mitigent, V1 mono-poste). corrected_sev = P3.

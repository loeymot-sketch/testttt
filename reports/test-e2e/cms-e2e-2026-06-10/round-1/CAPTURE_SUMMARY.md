# CAPTURE SUMMARY — CMS E2E round-1 (2026-06-10)

Env : http://127.0.0.1:8767 (DB jetable `foodking_e2e`). Script : `round-1/cms-capture.mjs` (+ probe read-only `probe-step.mjs`).
Quartet par état : `<state>.png` (fullpage) + `<state>.dom.html` + `<state>.console.json` + `<state>.network.json`.

## États capturés — 17/17 OK, 0 KO

| État | Verdict | Note |
|---|---|---|
| A1-category-list | OK | liste initiale, 13 catégories |
| A2-create-modal | OK | modal #categoryModal + select parent visible |
| A3-subcategory-created | OK | « ↳ E2E-AUDIT Sub » + badge « sous-catégorie de Galette » + toast création réussie |
| A4-galette-edit-parent-locked | OK (capture) / **ANOMALIE fonctionnelle** | voir AN-1 |
| A5-studio-sidebar | OK | E2E-AUDIT Sub indentée sous Galette dans l'arbre studio |
| A6-product-created | OK | carte « E2E-AUDIT Prod », catégorie E2E-AUDIT Sub, prix `5,00 €` FR, badge Actif (item id 77) |
| A7a→A7d (cleanup item + catégorie) | OK | confirmations SweetAlert capturées, suppressions effectives |
| B1-stock-rail | OK | rail hiérarchique complet (↳ Tacos Signature sous Tacos, buckets ingrédients) |
| B2-tacos-products | OK | bucket cat-5 → Tacos EN STOCK + Big Tacos EN STOCK |
| B3-rupture-toggled | OK | Big Tacos badge RUPTURE |
| B4-back-in-stock | OK | retour EN STOCK |
| B5-composer-drawer | OK | drawer studio + iframe /admin/categories/5/composer chargée (wizard Tacos publié) |
| B6-step-taille-panel | OK | step « Choisis la taille » sélectionné (probe : labelInputValue confirmé), presets + panneau prix dans le viewport (`B6-panel-elements.json` tous true) ; DOM iframe dans `B6-step-taille-panel.iframe.dom.html` |
| B7-no-mutation | OK | aucun publish/unpublish/delete cliqué sur le wizard Tacos |

## Preuves DB
- **wave-A/cleanup.json** : item 77 « E2E-AUDIT Prod » `deleted_at=2026-06-10 15:08:49` ; catégorie 22 « E2E-AUDIT Sub » `deleted_at=2026-06-10 15:08:57` → **les 2 soft-deleted** ✅
- **wave-B/sync-proof.json** : après toggle `is_available=0`, après re-toggle `is_available=1` ; `domain_events` derniers = `ItemAvailabilityChanged` + `CatalogChanged` aux deux bascules ✅

## Anomalies relevées (pour le reviewer)

1. **AN-1 (A4) — parent select NON verrouillé** : Galette a désormais un enfant, mais son select « Catégorie parente » offre toujours **10 options actives** (Menu enfant, Boissons, …, Sandwich Cayenne) en plus d'« Aucune » — attendu : uniquement « Aucune ». Options non-disabled (DOM A4). Preuve : `wave-A/A4-parent-options.json` + `A4-...dom.html`. (Galette elle-même et sa descendance sont bien exclues.)
2. **AN-2 (B5/B6) — clés i18n brutes dans le builder** : fieldset « Logique des choix » rend les clés brutes `label.composer.choice_logic`, `label.composer.preset_single`, `label.composer.preset_multiple`, `label.composer.preset_custom` (fallback du `t(key, fallback)` non appliqué — visible PNG B5+B6, DOM iframe). « Options et prix (lecture seule) » est, lui, bien résolu.
3. **AN-3 (B6) — contenu du panneau prix incohérent avec le step** : pour le step « Choisis la taille » (source = « Toutes les options »), le panneau « Options et prix (lecture seule) » liste les **viandes/sauces** (Poulet mariné, Poulet curry, … Algérienne, Andalouse), pas des tailles ; et pour « Choisis tes viandes » le panneau est **vide** (probe-step.mjs). À qualifier par le reviewer (mapping source→options du panneau lecture seule).
4. **AN-4 (B5 console) — CSP report-only** : `Framing 'http://127.0.0.1:8767/' violates report-only CSP "frame-ancestors 'none'"` au chargement de l'iframe composer dans le studio — si cette CSP passe en enforce, le drawer builder casse.
5. **AN-5 (transverse, connu)** : WebSocket `ws://127.0.0.1:6001` échoue sur chaque page (soketi non lancé sur le harnais e2e) — connu SYNC-WS-01, fallback polling.
6. Aucune réponse réseau ≥400 sur l'ensemble des 17 états ; aucune erreur console hors AN-4/AN-5.

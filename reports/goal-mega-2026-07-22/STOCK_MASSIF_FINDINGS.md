# Test massif STOCK cross-surfaces — registre final (2026-07-22)

## Verdict : SYSTÈME SAIN — 0 P0/P1 produit. Sync prouvée LIVE au chiffre.

### Volet 1 — PREUVE LIVE VPS (téléphone → web) ✅
Sonde navigateur réel (`tests/e2e/mobile-stock-live-probe-2026-07-22.spec.js`, 1 passed) :
- `/m` + PIN 2580 → toggle **Perrier (117)** → serveur `200 {branch_id:1, is_available:false, reason:stock_rupture}`
- **Menu web public (`/api/frontend/item?branch_id=1`) reflète la rupture en 153 ms.**
- Restore → 200 → web `is_available:true`. **Zéro résidu** (vérifié).
- Contre-preuve serveur (tinker VPS) : toggle service → API web reflète en <3 s ; restore idem.

### Volet 2 — TEST PROFOND LOCAL (4 surfaces) ✅
`tests/Feature/Stock/StockCrossSurfaceSyncTest.php` **10/10 (76 assertions)** : toggle → event
`ItemAvailabilityChanged` (bon branch) → menu borne (KioskMenuService) reflète → catalog-overview
admin reflète → double-toggle rapide = 0 doublon (UNIQUE) → extras/variations (stock_levels) idem.

### Anti-hallucination — artefacts de MA sonde (pas des bugs produit), documentés :
1. curl toggle 419 = rotation CSRF post-regenerate mal rejouée en curl (la vraie page gère nativement).
2. « propagation JAMAIS » = mon sélecteur DOM cliquait le bouton du BLOC catégorie (item 52, togglé
   puis restauré proprement — réponses réseau à l'appui) au lieu de la ligne Perrier. Sélecteur `.row` exact → 153 ms.
3. API web sans `branch_id` = repli flag global (by design) ; le site envoie `branch_id` (api.js:714-722).

### Findings retenus (déjà dans le registre caisse vague 2, agents en cours) :
- pos-86-propagation-dead-no-poll (filet poll caisse si worker down) — agent STOCK en cours.
- quota-daily-reenable-cron-only (box éteinte la nuit) — agent STOCK en cours.
- panel-manual-86-reason-collision · photo-authz · global-flag-visibilité — agent STOCK en cours.

### Actions machine owner (rappel) : worker de file en service permanent (survit au reboot).

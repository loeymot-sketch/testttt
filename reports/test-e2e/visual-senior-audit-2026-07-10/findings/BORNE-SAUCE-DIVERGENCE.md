# FINDING — Borne sauce divergence (P2, gate owner)
Règle owner : multi-sauce, 1ère offerte, +0,50 chacune en plus.
- CAISSE ✓ : pos-wizard.js `SAUCE_EXTRA_PRICE=0.5` (client) → multi +0,50 (prouvé visuel 9,50 pour 2 sauces).
- WEB ✓ : ajouté ce cycle (data/menu.js priceFor + wizard max 12).
- BORNE ✗ : attr#5 « Sauce (1ère Gratuite) » max_select=1 → 1 seule sauce, pas de +0,50.
FIX (align borne) = 3 changements dont 2 FROZEN :
1. DB config attr#5 max_select 1→N (non-frozen).
2. KioskWizardComponent.vue (FROZEN §7) — afficher multi + badge +0,50.
3. PricingService (FROZEN §7, NF525) — charger +0,50/sauce sup côté kiosk (ou mécanisme extra comme la caisse).
→ Gate owner + LOCK obligatoire (§7/§10). Décision owner : aligner la borne ou la laisser à 1 sauce.

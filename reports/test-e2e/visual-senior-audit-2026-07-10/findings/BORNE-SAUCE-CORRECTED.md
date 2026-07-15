# FINDING CORRIGÉ — Borne sauces (2026-07-10)
Analyse antérieure ERRONÉE (attr#5 raw max_select=1). Le composer RÉEL borne (item_wizard_steps) :
- Profils « Choix de la sauce » (9,10,11,13,14,15) : min=1 **max=2** → 2 sauces permises.
- Profils « Choisis ta sauce » (22,23,24,25) : min=1 **max=1** → 1 seule.
- 26 sauce-steps au total, 2 libellés + 2 maxes = duplication/incohérence profils.
Preuve visuelle : borne wizard sauce = CHECKBOXES + « 1re sauce gratuite » + boutons « + » = multi-select.

## Écart RÉEL vs owner-rule (multi, 1ère offerte, +0,50 chacune) :
1. **PRIX** (P2) : PricingService (FROZEN NF525) n'a AUCUNE logique +0,50 sauce → sur la borne la 2ᵉ
   sauce est GRATUITE, alors que caisse (pos-wizard.js SAUCE_EXTRA_PRICE=0.5) ET web facturent +0,50.
2. **INCOHÉRENCE profils** (P3) : certains items borne = max 2 sauces, d'autres = max 1 (2 générations
   de profils composer). À unifier.
3. **CAP** : borne max 2 vs caisse/web illimité — à décider (2 suffit peut-être).

## FIX (align borne sur la règle) — gate owner + LOCK :
- PricingService (FROZEN) : +0,50/sauce sup côté kiosk (ou mécanisme « extra » comme la caisse).  ← LOCK
- item_wizard_steps : unifier profils sauce (max cohérent) + prix. ← non-frozen (data).
- KioskWizardComponent (FROZEN) : afficher le +0,50 sur les sauces sup. ← LOCK
DÉCISION OWNER requise (toucher NF525/frozen).

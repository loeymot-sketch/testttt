# STATE — GOAL WEB+APP SYNC BORNE (2026-07-08)
> Manifest de reprise. Toute session qui reprend : lire ce fichier + plans/GOAL_WEB_APP_SYNC_BORNE_2026-07-08.md.

## Position
- Wave courante : **W1 cartographie** (workflow `wf_99239829-ced`, 29 agents read-only, en cours)
- Dernier checkpoint : W0+W2 (fixture + GOAL doc + baselines)
- Prochaine étape : synthèse W1 → lancer W3 (web) + W4 (mobile) + W5 (backend) selon findings

## Baselines
- testttt HEAD départ : `58e852697` (branche `pos/category-first-caisse-2026-06-23`)
- web repo départ : `e251665`
- NF525 : audit_logs=4930 (last_id=4941), z_reports=25 — append-only requis
- Fixture canonique : `reports/goal-web-app-sync/catalog-canonical.json` (9 cat, 42 items)

## Gates fermés / ouverts
- [x] W0 fixture + baselines
- [x] W2 GOAL doc
- [ ] W1 cartographie mergée
- [ ] W3 web vert · [ ] W4 mobile vert · [ ] W5 backend vert
- [ ] W6 cycle 1 propre · [ ] W6 cycle 2 propre (convergence)
- [ ] W7 BRAIN + mémoire + rapport owner

## Divergences déjà connues (pré-W1)
- Mirrors web+mobile = 31 produits vs canonical 42 (boissons 15, frites cheddar ×4…)
- Revert crudités Tacos backend 2026-07-07 non répercuté mirrors
- loyalty-v2.jsx web = démo hardcodée (Ikyes Benzaid) ; mobile data/loyalty.js = démo

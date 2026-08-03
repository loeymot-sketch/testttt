# STATE — GOAL WEB+APP SYNC BORNE (2026-07-08)
> Manifest de reprise. Toute session qui reprend : lire ce fichier + plans/GOAL_WEB_APP_SYNC_BORNE_2026-07-08.md.

## Position
- **CONVERGÉ 2026-07-08** — GOAL terminé. Rapport `CONVERGENCE.md`. Toutes vagues W0-W7 done.
- W6 e2e : R1 (25 agents, 8 réels healés wf_fbf30d83) + R2 (10 agents, 10/10 PASS wf_236ef5f2) = 2 cycles propres P0+P1=0.
- Restes : gates owner §10 (push testttt+web, deploy VPS) · P2 routes/api.php churn pré-existant (ne pas committer) · G4 scan physique borne (futur).

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

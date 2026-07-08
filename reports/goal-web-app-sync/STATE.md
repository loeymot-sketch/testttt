# STATE — GOAL WEB+APP SYNC BORNE (2026-07-08)
> Manifest de reprise. Toute session qui reprend : lire ce fichier + plans/GOAL_WEB_APP_SYNC_BORNE_2026-07-08.md.

## Position
- Wave courante : **W3/W4/W5 IMPLÉMENTATION** (workflow `wf_85802218-5ea`, 14 implémenteurs + 3 intégrateurs, en cours)
- W1 TERMINÉE : 29/29 agents, 235 findings (15 P0 = boissons manquantes + capri 1.50 ; 64 P1) → `w1/SYNTHESIS.md` + `w1/by-spec/*.json`
- Contrat implémenteurs : `CONTRACTS.md` (flags normatifs, endpoints, QR mint-on-display, OTP bypass vérifié)
- Décisions révisées post-advisor : GOAL doc §0.4bis (patchs chirurgicaux + gate par NOM ; points→€ sans rewards ; scan = G4 futur)
- Prochaine étape : synthèse WF-2 → W6 intégrité/e2e (~50 agents) → boucle convergence

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

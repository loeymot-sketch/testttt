# CAMPAGNE — test-e2e + ultra-plan « ce qui reste » (2026-07-09)

> Reprise : lire ce fichier. Base = GOAL WEB+APP SYNC BORNE convergé 2026-07-08
> (reports/goal-web-app-sync/CONVERGENCE.md). But de cette session : re-prouver
> l'état convergé (preuve + logique + adversaire) et ULTRA-PLANIFIER le reste go-live.

## Environnement live (cette session)
- Laravel :8766 (APP_URL baked) + :8000 (pour agents workflow) — LIVE.
- Web standalone servi :8096 (python http.server) → API :8766, CORS 204 OK.
- MySQL live : items=81, orders=2852, audit_logs=4938, z_reports=25 (= baseline convergence).

## Gates déterministes déjà VERTS (preuve directe, cette session)
- [x] Parity web+mobile = 0 divergence (`tools/parity/check-parity.mjs --surface=all`, exit 0).
- [x] Frozen §7 diff (12 chemins) vs 7470535a6~1 = 0 ligne.
- [x] Seul app/ committé par le goal = Stripe.php.
- [x] routes/api.php churn = SÛR : 639→639 définitions de routes (0 perdue), route:list build clean, tous contrôleurs résolvent.

## Preuve VISUELLE (captures lues, run captures/)
- [x] Borne idle (:8766) — propre, on-brand, portrait intact, 0 i18n brut, hero+carousel+CTA OK. (401 /api/menu = besoin auth kiosk ; ws:6001 refusé = pas de Reverb local, dégradation attendue.)
- [x] Web Fidélité — règle correcte « 1€=1pt, 100pts=1€ », auth-gated « Créer mon compte », **0 résidu démo Ikyes**, adresse branche OK.
- [x] Web Menu — « 8 catégories · 38 créations » (= 42 borne − 4 frites-cheddar wizard, attendu), prix exacts (Big Burger 9,00 / Cheese 6,00), images réelles, filtres, panier a11y.

## Workflow preuve+adversaire EN COURS
- runId `wf_59ad8ece-9a3` (task wf0e7hcx9). 6 dims × (prouve→réfute) : parity-frozen-boot, pricing-formule-422, loyalty-sync, stripe-off, nf525-chain, cross-surface-sync.
- Résultats → à consolider dans PROOF_MATRIX.md.

## RESTE (carte go-live — à ultra-planifier)
1. **Hygiène git** — commit `a693aa096 "p"` = fourre-tout (routes/api.php + .claude/brain + worktrees + .playwright-mcp incl PDF 1.2MB). Viole §3quater (jamais `git add -A`). Churn routes = sûr mais non-revu. → DÉCISION OWNER : garder / nettoyer (amend/split) avant push.
2. **Push testttt** — 8 commits d'avance sur origin (`pos/category-first-caisse-2026-06-23`). Gate §10.
3. **Push web standalone** — commits locaux 8051ce8/68c03e4/31a4d71. Gate §10.
4. **Deploy VPS backend** — SSH `lecayenne` → `tools/deploy-vps.sh` (build bundle COMPLET, migrate, triggers NF525 install+verify, queue:restart, rollback auto). Leçon : jamais de SCP partiel.
5. **Deploy web standalone** → lecayenne.fr (site public).
6. **Reverb/broadcasting prod** — vérifier ws:6001 UP en prod (queue:restart + reverb), sinon sync temps-réel dégrade en polling.
7. **G4 (futur)** — câblage physique scanner QR → UI borne (zone frozen) ; endpoint /loyalty/scan + QR déjà prouvés.

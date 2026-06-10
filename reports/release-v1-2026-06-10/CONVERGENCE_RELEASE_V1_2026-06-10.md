# CONVERGENCE — release/v1-2026-06-10 (exécution du plan superviseur)

> GOAL : ultra-planifier + intégrer + build/test réel + test-e2e en boucle + adversaires. Branche `release/v1-2026-06-10` (worktree isolé, base spine `059c20db7`). 0 push (gate owner).

## Ce qui est PROUVÉ (release intégrée)
- **Superset LITTÉRAL** (3 branches ⊂ release, `merge-base --is-ancestor` ✓) : spine `heal/pre-cloud-exec-2026-06-05` + `feat/pos-printer-saga-autoprint` (0 conflit) + `goal/cms-gestion-2026-06-10-spine` (conflits docs seulement) + fix W6 `source_ref`. HEAD `36e83badf`.
- **Frozen §7 = 0 ligne** (15 fichiers, `git diff --numstat` vide) — vérifié + ré-attesté par l'adversaire.
- **Build prod OK** (`npx mix --production`, 14/14 entrées mix-manifest, bundles == working tree, fix source_ref compilé dans admin-shell.js).
- **PHPUnit 3125/0** · **Vitest 2111/0** sur l'arbre intégré (foodking_test, DEVDB-GUARD).
- **Live e2e 2 cycles identiques** sur :8768/foodking_e2e (arbre release) : central sweep **8/8** (dashboard/stock-rupture/customers/encaissement/oss/kds/historique, 0 crash/console err) + **sync fraîche kiosk→KDS→OSS** (orders 4490/4491, outbox order.created+status_changed dispatchés, status 4→7→8).
- **Impression NF525** : chaîne ESC/POS présente+autoloadable, `pos:configure-receipt-printer` enregistrée, 9 listener tests + 4 renderer + netting TVA verts → « il ne reste que l'imprimante » VRAI.
- **CMS gestion** : routes câblées (CRUD catégories, composer per-catégorie, W5b delete-wizard 409-si-publié), guards hiérarchie (2 niveaux, anti-cycle, anti-depth-3) testés.

## Bugs d'intégration cross-ligne ATTRAPÉS + healés (la valeur du build/test réel)
1. **Vendor-shadow** (cause des 28 faux « classe inexistante ») : vendor symlinké → PSR-4 `App\` pointait sur `pre-cloud-exec/app` sans les classes des merges → dé-shadow (copie réelle) + dump-autoload isolé. (worktree-infra, gitignored)
2. **Test stale `lab-cog`** (glyphe INEXISTANT) → `lab-settings` (réel, fixé par le sweep glyphes cms). Feature bouton intacte.
3. **Stub `ItemCategoryResourceTest` sans `parent_id`** (vrai bug : la feature hiérarchie cms ajoute `parent_id` à la resource ; vieux stub stdClass crashait) → stub schema-exact.
Heals 2+3 confirmés LÉGITIMES par l'adversaire (pas de masquage).

## Adversaire final : EXHAUSTED — release solide
0 P0/P1/P2. Seul RADV-1 (P3) = 2 commits doc/captures cms postérieurs au merge → **absorbés** (re-merge `36e83badf`) → superset désormais littéral. Aucune perte de code, frozen 0, build cohérent, CMS+impression vivants.

## RESTE = GATES OWNER (le plan superviseur P-3/P-5)
- **GATE-DATA-1** : DB fiscale propre + vrai catalogue 45 Le Cayenne (la box dev `foodking` = install étranger 63 items, verify-chain crash). À exécuter par l'owner sur un clone/OVH.
- **GATE-PUSH-1** : `release/v1-2026-06-10` prête à pousser → branche `production` → `deploy.sh`. **C'est le seul vecteur de mise en ligne** (OVH tourne 204 commits derrière). Préalable : vérifier `deploy.sh:99` warn hot-patches OVH. Push = GO owner.
- **Décision mobile-update** : app mobile (`heal/mobile-update-2026-06-10`) = branche produit standalone, à merger dans la release ou garder séparée (ne sert pas depuis le backend).
- Post-go-live (non bloquant) : imprimante physique, LCEN 29, loyalty redeem cross-produit, soketi /health/ready liveness, apps client build prod.

## Verdict
La release est un **superset fonctionnel unique, build+test+e2e prouvé, frozen intact** — l'arbre shippable que le verdict superviseur réclamait n'existait pas, **il existe maintenant**. Il ne manque que les 2 gates owner (DATA-1, PUSH-1) pour mettre TOUT le travail validé en ligne.

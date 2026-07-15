# ULTRA-PLAN — « ce qui reste » vers le go-live (2026-07-09)

> Base : GOAL WEB+APP SYNC BORNE **convergé 2026-07-08** puis **re-prouvé 2026-07-09**
> (PROOF_MATRIX : 6/6 dims PASS, adversaire 0 réfutation, visuel 3 surfaces, 2 P3 bénins).
> Ce plan couvre uniquement le **reste** : hygiène git → push → deploy → temps-réel → G4.
> Chaque phase : **preuve** (ce qui est déjà vrai), **logique** (pourquoi), **adversaire**
> (comment ça peut casser), **gate** (qui décide). Aucun code produit n'est cassé.

---

## ÉTAT PROUVÉ (pré-requis go-live) — ✅ VERT
| Preuve | Résultat |
|---|---|
| Parity web+mobile | 0 divergence (gate non-no-op vérifié) |
| Frozen §7 (12 chemins) | 0 ligne |
| Seul app/ committé | Stripe.php (guard 503) |
| Pricing formule | ordres live 5618-5626 exacts, 0×422 |
| Loyalty | 83/83, QR signé, balance web=mobile=backend |
| Stripe | 34/34, OFF triple-verrou, flag runtime réel |
| NF525 chain | verify-chain OK, append-only, z=25 |
| Cross-surface | ordre 5622 borne→caisse→KDS cohérent |
| Visuel | borne idle + web fidélité + web menu propres |
| routes/api.php (churn committé) | **sûr** : 639→639 routes, table build clean |

**Verdict pré-push : GO technique côté BACKEND.** Le red-team (agent adversaire) a cassé
la moitié WEB du plan + requalifié 1 « non-bloquant » en blocker. Corrigé ci-dessous.

---

## 🔴 CORRECTIFS RED-TEAM (agent adversaire — tous VÉRIFIÉS par moi)
| # | Sévérité | Gap confirmé (preuve) | Correctif |
|---|---|---|---|
| 1 | **P0** | Web `index.html:11` `api-base-url="http://127.0.0.1:8766"` **ET** `:17` `menu-image-base="http://127.0.0.1:8766/images/menu/"` — localhost + `http://`. Déployé tel quel sur `https://lecayenne.fr` = site mort + images cassées + **mixed-content bloqué**. | Phase 4 : **ÉDITER les 2 meta** → `https://<backend-prod>` (pas juste « vérifier »). |
| 2 | **P1** | Web repo (`web/sync-caisse-2026-06-26`) = **aucun remote, aucun upstream**. `git push` échoue (« no push destination »). | Phase 2 : définir/créer le remote + le chemin de publication vers lecayenne.fr AVANT de pousser. |
| 3 | **P1** | Boot guards prod = `RuntimeException` DURS (`AppServiceProvider.php:186/205/223/244/263/282/294/300/317`). `BROADCAST_DRIVER` null en prod → **l'app refuse de booter** → chaque `php artisan` du deploy plante → **rollback auto**. « dégrade en polling » = FAUX. | Nouvelle **Phase 0.6** : asserter les 8 env prod AVANT deploy. |
| 4 | **P1** | `deploy-vps.sh` ne fait QUE `verify-chain` (L88), **jamais** `fiscal:install/verify-immutability-triggers`. `deploy-final-2026-07-07.sh:19-21` le fait (« répare le gap dump-sans-triggers que migrate ne voit pas »). Triggers hard-delete NF525 absents+non-détectés. | Phase 3 : utiliser **deploy-final** OU ajouter les 2 étapes triggers à deploy-vps.sh. |
| 5 | **P2** | CORS `config/cors.php:18` = `env('FRONTEND_WEB_DOMAIN')` (non set local), patterns = localhost seul. lecayenne.fr bloqué. | Phase 0.6 : `FRONTEND_WEB_DOMAIN=https://lecayenne.fr` en prod. |

**Ce qui TIENT (adversaire n'a pas pu casser)** : branche deploy = branche courante (0 drift) ·
**0 nouvelle migration** dans les 8 commits (backend deploy = risque minimal) · `p` sans secret/`.env` ·
frozen 13 chemins = 0 ligne · `8a8167638` ancêtre de HEAD.

---

## PHASE 0.6 — ASSERT ENV PROD (nouveau, pré-deploy — bloque le rollback aveugle)
Avant tout deploy, sur le VPS vérifier `.env` prod : `POS_SIMULATION_HARDWARE=false`,
`APP_DEBUG=false`, `IDEMPOTENCY_MIDDLEWARE_ENABLED=true`, `CACHE_DRIVER` (pas array/null),
`QUEUE` (pas sync), `APP_URL` non vide, `BROADCAST_DRIVER` (pusher|redis),
`LOYALTY_QR_SECRET` set, `FRONTEND_WEB_DOMAIN=https://lecayenne.fr`. Un seul manquant = boot
refusé → deploy plante. (Si le VPS tourne déjà, ces valeurs sont probablement OK ; à confirmer.)

---

**Reste (résumé) = opérations owner (§10) + 1 décision hygiène + les 5 correctifs ci-dessus.**

---

## PHASE 0 — DÉCISION HYGIÈNE : le commit `a693aa096 "p"`  ⚠️ owner-gate
**Preuve.** `p` (auteur owner, aujourd'hui) est un fourre-tout : `M routes/api.php` (churn
sûr) + `A .claude/brain/*` + `D .claude/worktrees/*` (nettoyage) + `A .playwright-mcp/`
(dont **cloture-jour-2026-06-14.pdf ~1,2 Mo binaire**, cats.md, kds-board.json) +
suppression d'anciens snapshots. Message = « p » (non descriptif).
**Logique.** Fonctionnellement inoffensif (route:list clean, pas de secret détecté).
MAIS : viole §3quater (`git add -A`), grossit l'historique d'un PDF fiscal binaire + des
artefacts `.playwright-mcp`/`.claude` qui **ne sont pas** dans `.gitignore`.
**Adversaire.** (a) Le PDF de clôture = donnée métier fiscale — ne devrait pas vivre dans
git. (b) Sans `.gitignore`, chaque session re-committe des artefacts → pollution récurrente.
(c) Un message « p » rend l'audit d'historique impossible.
**Options (owner tranche) :**
- **A. Nettoyer avant push (recommandé)** : `git reset --soft HEAD~1`, ajouter
  `.playwright-mcp/` + `.claude/brain/` + `.claude/worktrees/` à `.gitignore`, re-committer
  UNIQUEMENT `routes/api.php` (+ le nettoyage worktrees) avec un message clair. ~5 min, 0 risque.
- **B. Pousser tel quel** : le plus rapide, historique pollué mais fonctionnel.
- **C. Revert routes/api.php à la baseline** : NON nécessaire (churn prouvé sûr).

---

## PHASE 1 — PRÉ-PUSH GATE (déjà vert, re-confirmable en 1 commande)
`node tools/parity/check-parity.mjs --surface=all && php artisan fiscal:verify-chain --all`
+ frozen diff = 0. → tous verts ci-dessus. **Rien à corriger.**

## PHASE 2 — PUSH (owner, §10 — jamais auto)
**Preuve.** testttt = **8 commits d'avance** sur origin (`pos/category-first-caisse-2026-06-23`).
web standalone = 3 commits locaux (8051ce8/68c03e4/31a4d71).
1. `git push origin pos/category-first-caisse-2026-06-23`  (backend)
2. `cd /Users/1millnonstop/Downloads/web && git push`      (web standalone)
⛔ jamais `--force`, jamais `--no-verify`.
**Adversaire.** Si Phase 0 = option A, pousser APRÈS le re-commit propre.

## PHASE 3 — DEPLOY VPS BACKEND (borne/caisse/KDS)
**Preuve.** `tools/deploy-vps.sh` : backup HEAD → `git reset --hard origin/branch` → **build
bundle COMPLET** → migrate → `verify-chain` → `queue:restart` → **rollback auto**. MAIS
red-team #4 : il ne (RE)INSTALLE PAS les triggers d'immutabilité NF525 (deploy-final le fait).
0 nouvelle migration à jouer (prouvé) → deploy backend = risque minimal.
1. SSH `lecayenne` (→ `/var/www/lecayenne`). Lancer **`deploy-final-2026-07-07.sh`** (il fait
   install+verify triggers) — OU `deploy-vps.sh` + ajouter manuellement
   `php artisan fiscal:install-immutability-triggers && php artisan fiscal:verify-immutability-triggers`.
2. Post-deploy : `fiscal:verify-chain --all` (CHAIN OK), `verify-immutability-triggers` = 8/8,
   `curl /kiosk/idle` = 200, fraîcheur bundles (mix-manifest).
**Adversaire.** (a) **Jamais de SCP partiel** (écran-blanc : app.js neuf + vendor.js stale). (b)
Oublier `queue:restart` = broadcasts morts. (c) Triggers hard-delete = seul rempart si la DB
prod vient d'un dump (les dumps ne portent pas les triggers).

## PHASE 4 — DEPLOY WEB STANDALONE → lecayenne.fr (site public)
**Preuve.** Web = statique (index.html + jsx Babel-in-browser + data + assets). Red-team #1+#2 :
`index.html:11/:17` = `http://127.0.0.1:8766` (localhost + http) ET le repo web n'a **aucun remote**.
1. **ÉDITER** `index.html:11` `api-base-url` + `:17` `menu-image-base` → `https://<backend-prod>`
   (URL prod, **https** obligatoire sinon mixed-content bloqué). Committer.
2. **Définir le chemin de publication** vers lecayenne.fr (aucun remote git n'existe — l'owner
   doit dire : rsync/SFTP ? remote git à créer ? CI ?). Sans ça, « push web » n'a pas de cible.
3. Backend prod : `FRONTEND_WEB_DOMAIN=https://lecayenne.fr` (CORS) — cf. Phase 0.6.
4. Smoke prod : fidélité charge (0 erreur console), checkout counter-only (Stripe OFF),
   menu 38 items, images menu OK (menu-image-base résolu), CORS 204 sur l'origine publique.
**Adversaire.** `api-base-url` localhost OU `http://` sur page https = site public mort. Repo
web sans remote = étape push impossible telle quelle.

## PHASE 5 — TEMPS-RÉEL PROD (Reverb / broadcasting)
**Correction red-team #3.** En prod ce n'est **PAS** « non bloquant » : le boot guard
`AppServiceProvider.php:294` **JETTE** si `BROADCAST_DRIVER ∈ {null,'null'}` → app refuse de
booter. Donc `BROADCAST_DRIVER` DOIT être set (cf. Phase 0.6, pré-deploy).
1. `BROADCAST_DRIVER=pusher|redis` en prod (sinon deploy plante au 1er `php artisan`).
2. Serveur websocket (Reverb/Pusher) UP + `queue:restart`. Si le worker est absent mais le
   driver set, les events sont émis mais non poussés → clients retombent en polling (dégradation
   SYNC_CONTRACT réelle, non-bloquante). Le blocker, c'est le DRIVER null, pas le worker down.

## PHASE 6 — G4 (FUTUR, hors go-live) — owner-gate + LOCK
**Preuve.** Scanner QR physique → UI borne : endpoint `/loyalty/scan` + QR signés `lqr.`
déjà **prouvés** (loyalty-sync PASS). Reste = câblage matériel dans la borne (**zone frozen**).
Nécessite LOCK + sign-off owner pour toucher `KioskWizard/App`. À planifier après go-live.

---

## PRÉ-EXISTANTS NOTÉS (P3, hors périmètre — ne bloquent pas)
- `PricingService.php:207-217` commentaire prix obsolète (3.00/1.20 vs 2.50/1.00) — 0 impact
  logique. Fix trivial 1-ligne non-frozen si on veut la propreté.
- Gap `orders.fiscal_sequence_no` branche 1 (2506-2508) — rollback 2026-06-19/20, chain OK,
  pré-existant. À documenter, pas à « corriger » (réécrire une séquence fiscale = interdit).

## CHEMIN CRITIQUE
Phase 0 (décision owner) → Phase 2 (push) → Phase 3 (deploy backend) → Phase 4 (deploy web)
→ Phase 5 (vérif temps-réel). Phase 6 = après. Tout est owner-gated ; je ne pousse/deploie rien sans feu vert.

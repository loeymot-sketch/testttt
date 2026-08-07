# Runbook de déploiement — 2026-08-07

Tout est prêt. Ce document existe pour que « deploy » ne coûte qu'un mot, et pour que le
retour arrière coûte une commande si quelque chose se passe mal.

## État constaté (vérifié en lecture seule, ce jour)

| | Constat |
|---|---|
| `https://www.lecayenne.fr/` | HTTP 200 — le site tourne |
| `funnel.jsx` **servi en production** | **0 occurrence** des correctifs → **les 3 défauts signalés sont toujours en ligne** |
| `https://vps-418872ac.vps.ovh.net/healthz` | HTTP 200 — le backend tourne |
| Web, commits locaux en avance | **22** (`origin/main..HEAD`) |
| Backend, commits locaux en avance | **6** |

## ⚠️ À savoir AVANT de dire « deploy »

1. **Le déploiement backend n'emporte pas que mon travail.** Une session concurrente a
   commité sur la même branche. Les 6 commits en avance contiennent notamment une
   migration qui n'est pas de moi :
   `database/migrations/2026_08_07_042528_add_kitchen_ticket_printed_at_to_orders.php`.
   Déployer le backend, c'est donc aussi mettre en ligne ce travail-là. **À arbitrer par toi.**
   Le déploiement **web** (les 22 commits) est en revanche entièrement celui de cette mission.
2. Ces changements touchent le **chemin de paiement**. Le bon moment est un creux de
   service, avec un téléphone sous la main pour le test réel juste après.
3. Le service est **découplable** : on peut déployer le web seul (tes 3 plaintes) et
   remettre le backend à plus tard. Les portefeuilles ont besoin des DEUX (le web envoie
   le moyen de paiement, le backend le transmet à Mollie).

## Déploiement WEB seul (tes 3 plaintes — panier, champs carte, récap)

```sh
cd "/Users/1millnonstop/Downloads/lecayenne-web-deploy/Site lecayenne"
git log --oneline origin/main..HEAD | cat     # relire ce qui part (22 commits)
git push origin main                          # Vercel déploie sur push
```
Vérifier ensuite que la production sert bien le nouveau code :
```sh
curl -s https://www.lecayenne.fr/funnel.jsx | grep -c "lcf-summary-remove"   # doit être > 0
```
(Si la propagation Vercel n'est pas automatique — cas déjà rencontré le 06/08 — il faut
`vercel login` puis `vercel --prod` depuis ce dossier ; c'est interactif, donc à toi.)

## Déploiement BACKEND (nécessaire pour Apple Pay / Google Pay)

```sh
cd /Users/1millnonstop/Downloads/projet/foodking-web/web/testttt
git log --oneline origin/pos/category-first-caisse-2026-06-23..HEAD | cat   # 6 commits, dont 1 migration d'une autre session
git push origin pos/category-first-caisse-2026-06-23
# puis sur le VPS :
#   git pull --ff-only && php artisan migrate --force
#   php artisan config:clear && php artisan cache:clear && php artisan queue:restart
```
Vérifier : `curl -s -o /dev/null -w "%{http_code}" https://vps-418872ac.vps.ovh.net/healthz` → 200.

## Retour arrière

Le web est le plus simple : Vercel garde les déploiements précédents, un « Rollback » depuis
son tableau de bord remet l'ancienne version en une minute. En ligne de commande :
```sh
cd "/Users/1millnonstop/Downloads/lecayenne-web-deploy/Site lecayenne"
git revert --no-edit fb1208c..HEAD && git push origin main
```
Backend : `git revert` du même intervalle puis pull sur le VPS. La migration ajoutée est
additive (une colonne) — la reverter n'est pas nécessaire pour revenir en arrière côté code.

## Juste après le déploiement — 5 minutes de contrôle

Suivre `tests-e2e/PROTOCOLE-TEST-APPAREIL-REEL.md` (dans le dépôt web). Priorité absolue au
**point 5** : écrire une note « allergie arachide » sur une vraie commande et vérifier qu'elle
apparaît sur le **ticket cuisine** et sur la carte en caisse. C'est le seul point où une
erreur ferait mal à quelqu'un — tout le reste n'est qu'affichage.

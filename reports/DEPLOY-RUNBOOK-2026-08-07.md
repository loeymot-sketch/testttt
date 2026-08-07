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

> 🚨 **CORRIGÉ LE 2026-08-07, APRÈS ESSAI RÉEL : « Vercel déploie sur push » est FAUX.**
> Le push GitHub a été fait (`fb1208c..e863353`) et **la production n'a pas bougé** :
> `index.html` servi 32 845 o contre 54 982 en local, zéro marqueur récent ; `funnel.jsx`
> servi 82 527 o contre 141 346, empreinte introuvable sur 250 commits. Il n'existe
> **aucune liaison GitHub→Vercel** : la production est un instantané orphelin.
> Vérifications faites : pas de `VERCEL_*` dans l'environnement, `auth.json` du CLI **vide**,
> `vercel whoami` sans identifiants, aucun crochet de déploiement, aucun autre dépôt du
> compte ne porte le site. **Le déploiement web exige donc une action interactive owner.**

```sh
cd "/Users/1millnonstop/Downloads/lecayenne-web-deploy/Site lecayenne"
git log --oneline origin/main..HEAD | cat     # relire ce qui part
git push origin main                          # met GitHub à jour — NE DÉPLOIE PAS
```

**Pour que ça arrive réellement en ligne, au choix :**
1. *Définitif* — dashboard Vercel → le projet du site → **Settings → Git → Connect Git
   Repository** → `loeymot-sketch/Site-lecayenne`, branche `main`. Ensuite chaque push déploie.
2. *Ponctuel* — `vercel login` puis `vercel --prod` depuis le dossier du site (login interactif).

**Ne JAMAIS conclure « déployé » depuis un push.** La seule preuve valable :
```sh
curl -s "https://www.lecayenne.fr/index.html?x=$(date +%s)" | grep -c nbArticles   # doit être > 0
curl -s "https://www.lecayenne.fr/funnel.jsx?x=$(date +%s)" | wc -c                # doit ≈ 141000
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

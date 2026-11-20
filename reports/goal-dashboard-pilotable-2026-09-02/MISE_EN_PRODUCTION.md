# Mise en production — bibliothèque de pages de wizard

Commit : `5f0f1d405` · branche `pos/category-first-caisse-2026-06-23` · 2026-09-02

---

## 1. Pourquoi je n'ai pas pu déployer moi-même

| Ce qu'il faudrait | Ce que j'ai trouvé |
|---|---|
| Un accès SSH au VPS | `~/.ssh/foodking_deploy_ed25519` **n'existe pas** ; la connexion à `vps-418872ac.vps.ovh.net` est refusée (relevé le 2026-08-30 : `Permission denied (publickey,password)`) |
| Un inventaire Ansible réel | `deploy/ansible/inventory/production.ini` porte encore le gabarit `ansible_host=__OVH_VPS1_IP__` |
| Le bon dépôt dans la config | `deploy/ansible/group_vars/all.yml` déploie `git@github.com:foodking/foodking-web.git` — l'origine réelle est `loeymot-sketch/testttt` |
| Un coffre Ansible rempli | `group_vars/vault.yml` avec ses 8 secrets : absent |
| Qu'un `git push` suffise | **Il ne suffit pas.** Vérifié le 2026-08-30 : six sondages après un push en avance rapide, la production servait toujours l'ancien fichier. Et `public/.gitignore` ignore `/js/*.js` : les bundles ne sont pas versionnés, ils se construisent **sur la machine cible** |

La production répond (HTTP 302 sur le VPS) : elle tourne, mais je n'ai aucun chemin pour y écrire.

**Et la branche ne peut pas être poussée telle quelle** : `pos/category-first-caisse-2026-06-23` est
**26 en avance et 207 en retard** sur son propre distant. Un push est refusé ; un push forcé est
interdit (CLAUDE.md §3quater) ; fusionner 207 commits est précisément l'opération où deux correctifs
justes du même défaut se contredisent — à faire de tête reposée, pas en fin de course.

---

## 2. Ce qui est sur GitHub

Branche **`feat/wizard-pages-2026-09-02`** (poussée le 2026-09-02, sur autorisation explicite).

⚠ **Elle porte 30 commits, pas 2.** C'est la pointe de la branche de travail : mes deux commits
(`5f0f1d405`, `e149dd493`) plus 28 d'autres sessions déjà présents dessus — dont deux arrivés
pendant ma session (`41025322f` « contrôler tout le service sans jamais quitter la caisse »,
`56b4a41ec`). Déployer cette branche déploie **tout cela**. Si vous ne voulez que la bibliothèque de
pages, il faut extraire mes deux commits sur une branche partie de la production actuelle — dites-le,
je le fais.

---

## 3. La marche à suivre sur le VPS

Deux migrations et une reconstruction des assets, sur une caisse NF525 en service. Les valeurs
ci-dessous viennent de `deploy/ansible/group_vars/all.yml` et du gabarit supervisor du dépôt
(`app_root=/var/www/foodking`, PHP 8.2, programmes `foodking-queue` / `foodking-soketi`) : **à
confirmer sur la machine**, cette configuration est un modèle qui n'a jamais été branché sur
l'origine réelle.

**À faire hors service** (aucune commande en cours, tiroir fermé, Z du jour passé).

```bash
ssh <utilisateur>@vps-418872ac.vps.ovh.net
cd /var/www/foodking

# 0. SAUVEGARDE D'ABORD — non négociable
php8.1 artisan foodking:backup-daily
php8.1 artisan fiscal:verify-chain --all        # doit dire CHAIN OK AVANT de commencer
git rev-parse HEAD > /tmp/foodking-revision-precedente.txt   # pour le retour arrière

# 1. Code
git fetch origin
git checkout feat/wizard-pages-2026-09-02

# 2. Dépendances + assets (les bundles ne sont PAS versionnés : ils se construisent ici)
composer install --no-dev --optimize-autoloader
npm ci && npm run production

# 3. Base — les deux migrations de cette livraison
php8.1 artisan migrate --force
#   2026_09_02_100000_create_wizard_pages_tables      (2 tables neuves + 1 colonne nullable)
#   2026_09_02_110000_bootstrap_wizard_pages_library  (données seules, idempotente, rejouable)

# 4. Caches + services
php8.1 artisan config:cache && php8.1 artisan route:cache && php8.1 artisan view:cache
sudo supervisorctl restart foodking-queue
sudo systemctl reload php8.1-fpm nginx

# 5. Contrôle : la bibliothèque s'est construite depuis VOTRE catalogue
php8.1 artisan tinker --execute='echo \App\Models\WizardPage::count()." pages, ".\App\Models\WizardPageChoice::count()." choix";'
php8.1 artisan composer:materialize --all --dry-run   # LIRE le plan en entier avant d'appliquer
```

### ⚠ L'étape suivante, elle, change la carte servie au client

Une fois le plan lu et accepté : `php8.1 artisan composer:materialize --all`. Elle aligne **chaque produit** sur les pages de sa catégorie.
Sur la base de développement, ce passage n'a produit **que des créations** (306 lignes, `~0 −0` :
aucun prix réécrit, aucune option retirée) — mais sur une base qui a divergé davantage, il peut
**ramener un prix saisi à la main au prix de la page** et **retirer de la vente** une option ajoutée
hors page. Rien n'est supprimé définitivement (désactivation), mais il n'y a pas de retour
automatique.

**Donc : lire le `--dry-run` en entier, et ne lancer l'application que si le plan est celui voulu.**
Depuis l'écran, le bouton « Synchroniser les produits » fait la même chose mais montre le plan et
demande confirmation dès qu'une ligne serait réécrite ou retirée.

### Retour arrière

```bash
cd /var/www/foodking
php8.1 artisan migrate:rollback --step=2    # supprime les 2 tables + la colonne nullable
git checkout "$(cat /tmp/foodking-revision-precedente.txt)"
composer install --no-dev --optimize-autoloader && npm ci && npm run production
php8.1 artisan config:cache && php8.1 artisan route:cache && php8.1 artisan view:cache
sudo supervisorctl restart foodking-queue && sudo systemctl reload php8.1-fpm nginx
```
Les migrations sont réversibles par construction (deux tables neuves + une colonne nullable sur
`item_wizard_steps`). Ce qui n'est PAS réversible automatiquement, ce sont les lignes écrites par
`composer:materialize` sur les produits — d'où la sauvegarde de l'étape 0.

---

## 4. Contrôles après bascule

```bash
php8.1 artisan fiscal:verify-chain --all               # CHAIN OK sur chaque branche
php8.1 artisan composer:materialize --all --dry-run    # doit dire « 0 changement »
```
Puis, dans le navigateur, en tant qu'admin :
- `/admin/wizard-pages` liste les pages avec leur nombre de choix ;
- `/admin/categories/5/composer` affiche « En caisse : version N — X produit(s) à jour » ;
- une commande de bout en bout à la caisse sur un Tacos (pain, viandes, sauce) ;
- une commande de bout en bout sur la borne.

---

## 5. Ce qui reste à décider

Cible retenue par le propriétaire le 2026-09-02 : **le VPS OVH**. Le code y est prêt (branche
poussée), mais l'exécution du §3 demande un accès que je n'ai pas. Deux façons d'avancer :

1. **Quelqu'un exécute le §3 sur le VPS** — la marche à suivre est complète, y compris le retour
   arrière. C'est la voie la plus courte.
2. **On me donne le chemin d'accès** (clé SSH de déploiement, utilisateur, chemin réel de
   l'application) et je le fais, avec les contrôles du §4 à l'appui.

Deux points à trancher au passage : (a) déployer les 30 commits de la branche ou seulement mes deux
(§2) ; (b) lancer ou non `composer:materialize --all` après la bascule — sans lui, les nouveaux
écrans existent mais les produits ne sont pas encore alignés sur les pages.

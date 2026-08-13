# Passation — la roue, après la mission du 2026-08-13

**Pour la session suivante.** Tout est committé et **poussé** : `af9035856` sur
`origin/pos/category-first-caisse-2026-06-23`. Rien n'attend dans un arbre de travail.

**Il reste UNE chose : déployer.** Le reste est fait, testé, et regardé écran par écran.

---

## 1. CE QU'IL FAUT FAIRE — dans cet ordre

### 1.1 Déployer (le seul vrai reste)

Le code est sur origin ; il suffit de le tirer sur le VPS.

**Le mur exact, mesuré** : `ssh root@vps-418872ac.vps.ovh.net` rend
`Permission denied (publickey,password)`. `~/.ssh/config` ne déclare rien pour cet hôte, et
`~/.ssh/id_ed25519` est rejetée. **Ce n'est pas la sécurité de la session Claude** — elle laisse
passer la commande — **c'est le serveur qui n'autorise pas cette machine.**

Deux issues :
- le propriétaire ajoute la clé publique de la machine dans `/root/.ssh/authorized_keys` du VPS ;
- ou il lance lui-même le script prêt (voir §4).

**Après le déploiement, la seule vérification qui compte est le CONTENU SERVI**, jamais « le push
est passé » — c'est le piège qui a gelé le site deux jours le 7 août. Concrètement : `grep` sur un
marqueur de la version neuve ET sur un marqueur de l'ancienne.

Marqueurs utiles pour la roue :
- présent attendu : `wizard-roue-link` dans `/js/pos-wizard.js`, `menu.roue` dans le bundle admin,
  `gagnants` / `g-lot` dans `/admin/roue-borne` ;
- absent attendu : `Aujourd'hui, on distribue` et `class="pastille"` (l'acte supprimé).

### 1.2 Recompiler le front AVANT de conclure

`npm run production`. Le paquet admin est un fragment haché : sans recompilation, l'entrée de menu
et l'onglet caisse **n'existent pas** pour le navigateur, même si le code est sur le serveur.
`public/js/pos-wizard.js` est en revanche servi **tel quel** (Vanilla, non compilé par Mix) — il
n'a pas besoin de build.

### 1.3 Ouvrir le jeu au public — décision du propriétaire, EN DERNIER

`WHEEL_ENABLED=true` dans le `.env` du VPS, puis `php artisan config:clear`.

⚠️ **Ne pas l'ouvrir avant d'avoir vérifié `/admin/roue-reglages`** : tant que la production servait
l'ancienne version, la roue distribuait **80 % de points** et 20 % de vrais produits (mesuré : lots
`50 points`/`100 points` à poids 30+18, les deux lots en remise neutralisés par
`pos.coupon_codes_enabled=false`). C'est exactement la roue que le propriétaire a fait retirer.
Après déploiement ce doit être les **7 produits réels**.

### 1.4 La clé d'aperçu de production est MORTE

Mesuré : `/api/frontend/wheel/config?preview=audit-roue-2026-08-10` rend **404** en production.
Le propriétaire ne peut donc pas tester avant d'ouvrir. Reposer `WHEEL_PREVIEW_KEY` dans le `.env`
du VPS si l'on veut un aperçu privé.

---

## 2. CE QUI EST FAIT — 16 commits, tous verts

| Quoi | Où |
|---|---|
| Vitrine : **liste des lots répétée SUPPRIMÉE**, 7 photos en médaillon | `resources/views/admin/wheel/borne.blade.php` |
| Photos résolues par le chemin canonique + **adresses absolues** | `WheelService::photosParLot()` |
| Derniers gagnants (prénom seul, masqué si aucun) | `WheelReportService::derniersGagnants()` |
| Roue client : médaillons + **fond animé qui dérive** | `lecayenne-web-deploy/Site lecayenne/roue.html` |
| **Passe signée** (60 s, usage unique) — plus de code à retaper | `WheelAccessController::screenPass()` |
| **Recherche par CODE** au comptoir | `WheelSpin::parCode()` |
| **Historique** ligne par ligne (remis / dû / expiré / code) | `/admin/roue-historique` |
| **3 accès admin** : barre caisse, menu latéral, wizard | `CaisseSecondaryNav.vue`, `BackendMenuComponent.vue`, `pos-wizard.js` |

**Preuves** : PHPUnit Roue **247 ✓** · Pos **870 ✓** · Fiscal **342 ✓** · E2E navigateur **9 ✓**
(paysage + portrait) · parcours complet **3 ✓** · Vitest **2894 ✓** · NF525 **CHAIN OK ×4** ·
zones gelées §7 **0 ligne** hors LOCK · **5 mutations posées, 5 détectées**.

---

## 3. LES PIÈGES PAYÉS — à ne pas repayer

### 3.1 Trois fois sur quatre, le test rouge accusait LE BANC, pas le code

1. Un test attendait le texte « Code incorrect. » ; le serveur avait répondu **429**. La garde
   anti-force-brute faisait son travail. **Éprouver l'EFFET (la porte reste close), jamais la forme
   du refus.**
2. Puis 4 échecs d'un coup : `wheel-pin` n'accepte que **5 saisies/min/IP** et les 9 tests
   ouvraient chacun la porte. **Un banc qui se tire dessus fait passer une garde saine pour un
   défaut.** Corrigé par une page partagée par groupe, en série : 10,4 min et 4 rouges → **9,5 s et
   9 verts**.
3. **Un P1 a été annoncé au propriétaire, et il n'existait pas** : « on peut retourner la roue
   jusqu'à gagner ». Faux — `WheelService::drawPending()` rend le MÊME lot dès que le jeton a
   tourné. L'erreur : avoir lu un **code de retour 200** au lieu de **comparer le lot rendu**.
   ⚠️ **Ne jamais conclure d'un statut HTTP.**

### 3.2 Un test peut être vert pour la mauvaise raison

La garde des photos a dû être écrite **trois fois** : sans assertion (« risky ») ; puis verte
**même correctif retiré** parce qu'elle éprouvait le chemin du pack (déjà absolu) ; enfin juste, en
attachant un vrai média Spatie — seul chemin qui produit l'URL relative. **Poser la mutation avant
de croire un vert.**

### 3.3 Le défaut invisible depuis le serveur

`Item::getThumbAttribute()` rend une adresse **absolue** via le pack détouré, **RELATIVE**
(`/storage/…`) via un média téléversé. Caisse et borne : les deux marchent. Roue client, servie par
un autre domaine : **2 lots sur 7 sans photo**. **Toute adresse publiée hors du serveur qui la sert
doit porter son domaine.**

### 3.4 Zones gelées : le crochet lit le commit PRÉCÉDENT

`.git/hooks/pre-commit` autorise un fichier gelé si le message rendu par `git log -1` cite un
`LOCK_*.md` — donc **le commit d'avant**, pas celui en cours. Marche à suivre : committer le LOCK
signé (son nom de fichier dans le message), PUIS le fichier gelé. **Ne pas utiliser `--no-verify`**,
interdit par CLAUDE.md §3quater.

### 3.5 Autres

- Un commentaire inséré **après** un `*/` existant rend la règle CSS invalide : la roue est repassée
  à 720 px bruts, hors cadre, logo chassé. Invisible en relecture, évident en mesurant.
- Canvas : fondre le quart de tour dans la rotation **qui précède** la translation change la
  DIRECTION de celle-ci → vignettes à l'opposé de la roue. **Se placer d'abord, s'orienter ensuite.**
- Une session Claude peut committer **sur la même branche pendant qu'on travaille** — un commit
  d'une autre session a avalé une route au passage. Relire `git log origin/<branche>..HEAD` juste
  avant de pousser.
- Un agent **ne peut pas s'accorder une permission** (ajouter une règle SSH dans
  `.claude/settings.local.json` est refusé) — et c'est juste.

---

## 4. LE SCRIPT DE DÉPLOIEMENT

`<scratchpad de la session>/deploy-roue-2026-08-13.sh` — syntaxe validée, non exécuté.

Il fait : trouver le dossier de l'app · noter le commit courant (**point de retour**) · sauvegarder
le `.env` · **lister les fichiers non committés du VPS** et demander confirmation (le VPS en portait
12 d'une autre session le 10 août) · `git pull --ff-only` · `composer install --no-dev` ·
`npm ci && npm run production` · `migrate --force` · vider les caches · vérifier le contenu servi et
la chaîne NF525.

**L'ouverture au public est l'étape 9, imprimée à la fin et SÉPARÉE** — jamais dans le même geste
que le déploiement.

---

## 5. CE QUI N'EST PAS FAIT, ET QUI N'EST PAS BLOQUÉ

- **Réglage des lots par produit** (choisir quels produits sont sur la roue depuis l'écran) : la
  spec le décrit (`docs/superpowers/specs/2026-08-13-roue-*`), il n'est pas construit. Aujourd'hui
  le propriétaire règle **probabilité et quantité**, pas la liste des produits.
- **Plafond du jour réglable** et **simulateur « sur 100 tours »** : décrits, non construits.
- **Bouton « nouvelle campagne »** (remise à zéro des compteurs) : décrit, non construit.
- **Photos des produits sur la vitrine tablette dans l'acte des gagnants** : non fait, et sans doute
  inutile — la roue les porte déjà.

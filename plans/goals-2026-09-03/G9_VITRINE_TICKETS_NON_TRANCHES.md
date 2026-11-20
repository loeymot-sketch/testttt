# G9 — Vitrine : les dix-sept tickets dont le dépôt ne garde aucune trace

Dépôt : `/Users/1millnonstop/Downloads/lecayenne-web-deploy/Site lecayenne`
Dépendances : aucune. À exécuter après G8, qui porte les défauts déjà prouvés.

---

## Pourquoi ce GOAL est séparé

La vérification a pu trancher 41 des 58 tickets Grok, en retrouvant leurs énoncés dans les corps
de commit des huit correctifs qui les citent par numéro.

**Dix-sept n'ont laissé aucune trace** : ni dans `git log --all`, ni dans `git fsck`, ni dans un
fichier du dépôt. La branche `audit/grok-2026-08-28` est un marqueur sans commit propre. Ces
tickets ne sont donc **ni vrais ni faux** : ils n'ont pas été mesurés.

Les énoncés ci-dessous sont recopiés du rapport d'origine transmis par le propriétaire. Ils
permettent la vérification ; ils ne la remplacent pas. **Aucun ne doit être corrigé avant d'avoir
été reproduit dans le dépôt courant** — c'est exactement la faute que cette mission a trouvée
chez les deux audits externes.

## Les dix-sept, avec leur énoncé d'origine

| ID | Gravité annoncée | Énoncé | Où chercher |
|---|---|---|---|
| T09 | P2 | Petite et grande frites portent la même photo — indiscernables en grille | `data/menu.js`, `assets/menu/` |
| T15 | P1 | Trois identités visuelles dans le même site : SPA (nuit, 5 liens), pages SEO (crème, 8 liens), pages légales (pastille LC, 2 liens) | `index.html`, `plat/*.html`, `legal/*.html` |
| T17 | P2 | Carrousel d'accueil : pendant le fondu, deux plats se superposent et le cartouche reste figé | `.lcx-reel` dans `styles-v7-braise.css`, `screens.jsx` |
| T19 | P2 | « Click & collect » en anglais sous le bouton Commander | `screens.jsx` (`.lcx-voie-note`, `.lcx-oeil`, `.lcx-fait-v`) |
| T20 | P2 | Le mot-symbole affiche « SANDWICHES » et « BOWLS » en anglais, peint dans le PNG | `assets/brand/logo-cayenne.png` |
| T21 | P2 | Emojis système dans le rail de catégories, seul endroit du site qui en porte | `data/menu.js` (`CATEGORIES[].icon`) |
| T24 | P1 | Fiches produit générées avec un comparatif absurde : « 2ᵉ position sur 4 par le prix », « Combien coûte le Cayenne au Cayenne ? » | `plat/*.html` (26 fiches) |
| T25 | P2 | Le numéro en E.164 (`+33365678291`) apparaît dans le texte destiné aux moteurs | `plat/*.html`, `index.html` |
| T26 | P2 | « Savoureux » comme argument, à côté de trois faits mesurables | prerendu d'accueil |
| T30 | P2 | « Pepper Club » n'est nommé que dans les CGV ; l'accueil parle de « fidélité » sans le nom | `legal/cgv.html` art. 12, `index.html` |
| T31 | P2 | Les mêmes cinq questions de FAQ rejouées sur chaque page SEO | pages SEO |
| T35 | P2 | `sitemap.xml` : 41 URL, toutes en `lastmod=2026-08-07`, alors que CSS/JS sont datés du 28/08 | `sitemap.xml` |
| T36 | P2 | `robots.txt` publie des commentaires internes (pièges, dates d'audit, noms de robots) | `robots.txt` |
| T39 | P2 | `Access-Control-Allow-Origin: *` servi sur le HTML d'un site restaurant | `vercel.json` |
| T40 | P2 | Deux redirections successives : apex → `https://` → `www` | `vercel.json`, DNS |
| T41 | P2 | Accueil : ~312 Ko de CSS sur 10 feuilles, ~1 Mo de JS sur 18 fichiers, 7 scripts sans `defer` | `index.html` |
| T57 | P2 | Menu Enfant Nuggets : une seule étape, récap à 4,90 €, aucun upsell — voulu ou oublié ? | `data/menu.js` item 1101, `wizard-v2.jsx` |

## Méthode imposée

Pour chacun, dans cet ordre, sans raccourci :

1. **Reproduire** dans le dépôt courant. Citer `fichier:ligne` ou la mesure (octets, nombre de
   requêtes, en-tête HTTP réel).
2. **Trancher** : ENCORE VRAI · DÉJÀ CORRIGÉ · RÉFUTÉ · DÉCISION PROPRIÉTAIRE.
3. Ne corriger que les ENCORE VRAI, et seulement après avoir écrit le banc ou la mesure qui
   rougit sans le correctif.

Trois d'entre eux ne sont pas des défauts mais des **choix** : T21 (emojis), T30 (nom du
programme de fidélité), T57 (absence d'upsell sur le menu enfant). Les remonter au propriétaire
en tant que choix, pas les « corriger » d'autorité.

Deux touchent la marque et sortent du code : T20 (le mot anglais est peint dans un PNG) et T09
(deux photos à refaire ou recadrer). Ils demandent un fichier, pas un correctif.

## Ce qui manque encore

Le rapport Grok original n'est pas dans le dépôt. Le propriétaire l'a transmis en conversation,
et ce fichier en conserve les énoncés — mais **pas les captures**, qui sont la preuve de la
plupart de ces tickets.

Si les captures `tests-round2/*.png` existent quelque part, les verser dans
`reports/audit/grok-2026-08-28/`. Sinon, chaque ticket visuel devra être re-mesuré au navigateur,
ce qui est de toute façon la bonne méthode.

## Acceptation

- Les 17 tranchés, avec preuve, dans `reports/audit/VERIF_VITRINE_LOT2_<date>.md`.
- Les ENCORE VRAI corrigés, chacun avec son banc ou sa mesure.
- Les décisions propriétaire posées, avec la recommandation et son coût.
- Artefacts compilés et jetons de cache à jour pour tout correctif touchant un `.jsx`.

## Condition de sortie

Zéro ticket restant en « non mesuré ». Un ticket peut finir RÉFUTÉ ou DÉCISION PROPRIÉTAIRE — il
ne peut pas finir ignoré.

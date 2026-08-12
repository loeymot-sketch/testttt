# Refonte du référencement — rapport technique

**Date : 7 août 2026** · Dépôt : `lecayenne-web-deploy/Site lecayenne` (branche `main`, Vercel)
**Statut : prêt à déployer, non déployé** — la production sert encore l'état d'avant.

---

## 1. L'état trouvé

Mesuré contre la production, pas déduit :

| Contrôle | Résultat |
|---|---|
| URL indexables | **1 seule** — les routes de l'application sont en `#hash`, que Google ignore |
| `<title>` | « Le Cayenne — Site officiel », identique partout |
| `meta description` | **absente** |
| Données structurées | **absentes** |
| `canonical`, Open Graph, image de partage | **absents** |
| `robots.txt` | **renvoyait 200 avec 55 Ko d'application React**, en `text/html` |
| `sitemap.xml` | idem |
| URL inexistante (`/nimportequoi-xyz`) | **HTTP 200** + l'application (faux 404) |

Autrement dit : un client cherchant « tacos hénin-beaumont » n'avait aucune raison
d'atterrir ici, et un lien partagé sur WhatsApp n'affichait aucune vignette.

## 2. Ce qui a été construit

**Sept pages de contenu, en HTML pur, sans une ligne de JavaScript** — l'application
React reste intacte à côté :

| Page | Ce qu'elle traite |
|---|---|
| `carte.html` | Les 38 produits, tous les prix, les 7 viandes, les 14 sauces, les crudités, les suppléments, les règles de prix, les allergènes, une FAQ |
| `tacos.html` | Les deux tailles, pourquoi il n'y a pas de crudités, la formule |
| `burgers.html` | Les six burgers comparés, la personnalisation |
| `sandwichs-galettes.html` | Pain ou galette, le nombre de viandes par référence |
| `bols-et-frites.html` | Bols, option gratiné, styles de frites |
| `horaires.html` | Horaires 7j/7, dernières commandes, **accès réel** (arrêt de bus, gare, sorties d'autoroute) |
| `commander.html` | Les trois façons de commander, paiement, fidélité, temps de préparation |

**Les prix ne sont pas recopiés.** Un générateur (`gen_seo.py`) les lit dans
`data/menu.js`, la source du site. Recopier 38 prix à la main, c'est se garantir une
faute — et un prix faux indexé par Google, c'est un client qui arrive au comptoir avec
un montant que la caisse ne pratique pas.

**Plus** : `robots.txt` réel, `sitemap.xml` (13 URL + **47 photos de plats** déclarées
pour Google Images), données structurées `Restaurant` / `Menu` / fil d'Ariane / FAQ sur
chaque page, canoniques, Open Graph, image de partage 1200×630, canoniques ajoutées aux
5 pages légales, et un repli sans JavaScript sur l'accueil.

## 3. La contre-expertise

Cinq audits adversaires indépendants ont attaqué ce travail. Ce qu'ils ont **confirmé** :

- **56/56 fiches produit et 56/56 prix des données structurées exacts**, 11 calculs
  dérivés recalculés justes — et, contrôle le plus fort, **38/38 prix identiques au
  catalogue réel de la caisse**.
- **Pas de pages satellites** : similarité maximale mesurée entre pages **5,84 %**,
  35 questions de FAQ dont aucune dupliquée.
- **Le déploiement est sûr** : la documentation Vercel et la mesure en production
  confirment que les fichiers réels sont servis **avant** la règle attrape-tout. La
  politique de sécurité du site autorise déjà tout ce dont les pages ont besoin.
- Clé de TVA et SIRET **arithmétiquement valides**.

Ce qu'ils ont **cassé**, et qui a été corrigé :

| Gravité | Défaut | Correctif |
|---|---|---|
| **P0** | L'orange de marque `#F4501E` ne fait que **3,49:1** sur blanc — sous le seuil d'accessibilité de 4,5:1. Concernait **le prix** (38 fois par page) et le bouton **« Commander »** | Couleur de texte dédiée `#C0400F` (**5,27:1**), déjà présente dans la charte. L'orange de marque reste sur les grands titres, où il est conforme |
| P1 | 226 Ko de polices tierces, dont **118 Ko tirés par le seul caractère « œ »** | Polices auto-hébergées, sous-ensemble latin seul : **90 Ko**, et plus aucune origine tierce |
| P1 | Vignettes servies à 3,5× la résolution affichée | Régénérées à 384 px : **1679 → 635 Ko** |
| P1 | `fetchpriority="high"` posé sur une image, alors que le plus grand élément de l'écran est **du texte** | Retiré ; la CSS est intégrée à la page à la place |
| P1 | La barre d'action fixe débordait de ~34 px sur iPhone à encoche | `calc(74px + env(safe-area-inset-bottom))` |
| P1 | Cinq affirmations fausses dans mes textes | Corrigées (voir §4) |
| P2 | Pages non liées ; les 13 sections de la carte ne renvoyaient vers aucune page catégorie | Liens ajoutés depuis la carte et depuis le pied de l'application |
| P2 | URL en `<produit>-<ville>.html` — le motif même qui invite à la dérive vers des pages satellites | Raccourcies : `tacos.html`, `burgers.html`, `horaires.html`… La ville reste dans les titres |
| P2 | Cibles tactiles de 17 px | 37 px, sans changement visuel |
| P2 | Tableaux défilants inatteignables au clavier | `tabindex` + `role="region"` + libellé |
| P1 | `acceptsReservations: "False"` — **hors spécification** : schema.org n'admet qu'un booléen, une URL, ou les chaînes `Yes`/`No` | Booléen `false` |
| P1 | `hasMenu` seul — schema.org a rendu `menu` obsolète au profit de `hasMenu`, mais **Google ne documente que `menu`** | Les deux sont déclarées |
| P2 | `isPartOf` pointait vers un `@id` défini seulement sur l'accueil — le validateur schema.org confirme qu'il résout vers un **nœud vide** | Nœuds `Restaurant` et `WebSite` autonomes sur chaque page |

### Validation des données structurées

Le JSON-LD des 8 pages a été confronté au **vocabulaire officiel schema.org**
(`schemaorg-current-https.jsonld`, 3 219 entrées), propriété par propriété, en tenant
compte de l'héritage de types :

> **280 nœuds · 881 usages de propriété · 0 défaut.**

Une seule anomalie avait été trouvée à la première passe (`inLanguage` sur `EntryPoint`,
non déclarée dans le vocabulaire) — corrigée.

**Sur `FAQPage`** : Google a **retiré les résultats enrichis FAQ de la recherche le
7 mai 2026**, pour tous les sites. Le balisage est conservé malgré tout — mesuré, il
coûte **137 octets par page une fois compressé** (gzip le déduplique contre la FAQ déjà
présente en HTML visible) et il donne aux moteurs de réponse IA des paires
question/réponse propres. **Mais il ne faut pas le compter comme un gain Google.**

**Sur les horaires** : `closes: "23:59"` et surtout pas `"00:00"`. La documentation Google
est formelle — *« To show a business is closed all day, set both opens and closes
properties to 00:00 »*. Écrire minuit annoncerait un restaurant **fermé sept jours sur
sept**. `23:59` est la convention Google pour « fin de journée ».

## 4. Les cinq affirmations fausses, corrigées

Elles ne portaient pas sur les prix mais sur les phrases autour :

1. « Le Chicken Burger est le moins cher de **toute la carte** » — faux (Eau plate 1,00 €).
   → « le **burger** le moins cher ».
2. Le tableau des suppléments annonçait le **Boursin sur les galettes**, dont il est exclu.
3. « Paiement **sans redirection** » — faux : le 3-D Secure et les portefeuilles Apple/Google
   redirigent bel et bien. → formulation honnête.
4. « Les bols peuvent se commander **sans viande** » — faux : l'étape est obligatoire et
   bloquante. *(Cette promesse existe aussi dans la FAQ de l'application — à corriger là-bas.)*
5. « dimanches **et jours fériés** compris » — non sourcé. → retiré.

## 5. Les mesures

| | Avant | Après |
|---|---:|---:|
| `carte.html`, premier affichage | 362 Ko | **129 Ko** |
| `bols-et-frites.html` | 412 Ko | **152 Ko** |
| `horaires.html` | 229 Ko | **54 Ko** |
| Feuilles de style bloquantes | 2 (dont 1 tierce) | **0** (intégrée) |
| Requêtes vers un tiers | 2 origines | **0** |
| Contraste du prix | 3,49:1 ✗ | **5,27:1** ✓ |

## 6. La porte de contrôle

`tests-e2e/verif-seo.mjs` — **12 contrôles, verts**. Il vérifie titres, descriptions,
canoniques, `h1` unique, validité du JSON-LD, cohérence du plan du site, et la **parité
des 38 prix avec `data/menu.js`**.

**Il a été prouvé non complaisant** : en injectant un seul prix faux (7,40 → 7,90 €) dans
le vrai fichier, il passe au rouge (code de sortie 1) ; restauré, il repasse au vert.

Lancer `node tests-e2e/verif-seo.mjs --prod` **après** déploiement : il compare alors le
contenu réellement servi. Un `git push` ne prouve pas un déploiement — la mémoire du
projet garde la trace d'un déploiement resté silencieusement mort pendant deux jours.

## 7. Déploiement — ce qui est décidé et ce qui ne l'est pas

**À pousser (sûr, additif) :** les 7 pages, `robots.txt`, `sitemap.xml`, `seo.css`,
`fonts.css`, `assets/fonts/`, `assets/menu/seo/`, `assets/brand/og-cayenne.jpg`,
`index.html`, `components.jsx`, les 5 pages légales, `tests-e2e/verif-seo.mjs`.

⚠️ Fichiers à ajouter **un par un** (`git add <fichier>`), jamais `git add .` (CLAUDE.md §3quater).

**À NE PAS toucher dans ce lot :** `vercel.json`. Le faux 404 est réel et mérite d'être
corrigé, mais le correctif change le routage, et il fera échouer deux assertions de
`tests-e2e/prod-toutes-pages.regression.js`. **Déploiement séparé**, pour que le lot SEO
ne soit pas otage d'une régression de routage.

**Après déploiement, dans l'ordre :**
1. `node tests-e2e/verif-seo.mjs --prod`
2. `curl -sI https://www.lecayenne.fr/carte.html | grep content-length` → doit valoir la
   taille de la page, **pas** 54982 (qui signifierait que l'application est servie à sa place)
3. Déclarer le site à la **Google Search Console** et y soumettre le plan du site

**Ce qui reste au propriétaire** — et qui pèse plus que tout ce qui précède :
voir `ACTIONS_PROPRIETAIRE_2026-08-07.md`.

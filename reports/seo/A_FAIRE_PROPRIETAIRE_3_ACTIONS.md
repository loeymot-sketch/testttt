# ⏳ AVANT TOUT — `legrillhouse.fr` expire dans 22 JOURS

**Relevé au registre public le 8 août 2026 : `legrillhouse.fr` expire le
`30/08/2026 à 21h40 UTC`.** Registraire OVH, le même que `lecayenne.fr`.

Ça change l'ordre des priorités, parce que ce domaine a **3 ans et 11 mois
d'ancienneté** (créé le 30/08/2022) quand `lecayenne.fr` en a **63 jours**
(créé le 05/06/2026). C'est le seul actif d'ancienneté que vous possédiez.

### Pourquoi c'est urgent et pas seulement important
- **Un domaine qui expire ne transmet rien.** L'ancienneté n'est pas transférée,
  elle est perdue. Définitivement.
- **Une redirection 301 ne fonctionne que si le domaine reste enregistré.**
  L'ordre est donc : **renouveler d'abord, rediriger ensuite.** Rediriger un
  domaine qu'on laisse expirer ne sert à rien.
- **Un domaine de commerce local qui tombe peut être repris par un tiers.** Il
  s'intitule « Fast-food à Hénin-Beaumont », publie votre adresse et a quatre
  ans d'ancienneté. Entre les mains de quelqu'un d'autre, il pointe où cette
  personne le décide.

### À faire, dans l'ordre
1. **Espace client OVH → Domaines → `legrillhouse.fr` → Renouveler.**
   Prenez plusieurs années : c'est quelques euros par an pour conserver quatre
   ans d'ancienneté. Activez le renouvellement automatique.
2. **Ensuite seulement**, la redirection 301 décrite en ACTION 1 ci-dessous.

Si vous n'avez pas les accès à ce domaine, dites-le-moi : il est chez OVH au
même titulaire, il y a des recours, mais ils prennent du temps — et il en reste
vingt-deux jours.

---

# Les 3 actions qui décident — mode d'emploi exact

**8 août 2026.** Tout ce qui pouvait être fait dans le code est fait et déployé :
36 pages, ~16 600 mots lisibles par Google et par les IA, données structurées sans
défaut, URL poussées à Bing à chaque mise en ligne.

**Ces trois actions-ci ne peuvent être faites que par vous.** Elles pèsent, à elles
seules, plus lourd que tout ce que j'ai écrit. Voici comment les faire, pas pourquoi.

---

## ACTION 1 — Rediriger `legrillhouse.fr` (≈ 20 minutes)

### Le problème en une phrase
`legrillhouse.fr` est **en ligne**, affiche **votre adresse**, s'intitule
« **Fast-food à Hénin-Beaumont** », publie **un autre numéro de téléphone**
(09 81 41 06 79) et a **cinq ans d'ancienneté**. Il vous concurrence sur vos propres
requêtes, et il explique pourquoi les assistants donnent parfois un mauvais numéro.

### Ce que j'ai identifié pour vous
Le site tourne chez **OVH, sur Apache** (serveurs de noms `ns17.ovh.net`, IP
213.32.5.6, en-tête `x-hap: yes`). C'est le même hébergeur que votre VPS — vous avez
donc probablement déjà les accès.

### La manipulation
Connectez-vous à l'espace client OVH → *Hébergements* → `legrillhouse.fr` →
*FTP - SSH*. À la racine du site (souvent `www/`), **remplacez le contenu du fichier
`.htaccess`** par ceci :

```apache
# Le Grill House a laissé la place au Cayenne, au même endroit.
# Redirection permanente : elle transfère l'ancienneté du domaine
# au lieu de laisser les deux sites se concurrencer.
RewriteEngine On
RewriteCond %{HTTP_HOST} ^(www\.)?legrillhouse\.fr$ [NC]
RewriteRule ^(.*)$ https://www.lecayenne.fr/$1 [R=301,L]
```

> ⚠️ **301, pas 302.** Le 301 est permanent : c'est lui qui transfère l'ancienneté.
> Un 302 ne transfère rien.
>
> ⚠️ **Ne supprimez pas le domaine, ne le laissez pas expirer.** Un domaine supprimé
> perd tout. Un domaine redirigé vous donne ses cinq ans. Gardez-le renouvelé.

### Vérifier que ça marche
Depuis un terminal, ou demandez-moi de le faire :
```
curl -sSI https://www.legrillhouse.fr/nos-tacos | head -3
```
Vous devez lire `HTTP/… 301` et `location: https://www.lecayenne.fr/nos-tacos`.

### Et ensuite
- **Faire radier le SIRET E.MAX FOOD** (90199256000010) auprès du greffe s'il ne sert
  plus. Tant qu'il est actif à votre adresse, les annuaires continueront d'y puiser.
- **Renommer la page Facebook** : son adresse est encore
  `facebook.com/p/Le-Grill-House-61558444376048/`. Dans les paramètres de la page,
  modifiez le nom d'utilisateur.
- **Corriger OpenStreetMap** : compte gratuit sur openstreetmap.org, ouvrir le point
  `12194119215`, remplacer le nom, ajouter téléphone, site et horaires. C'est OSM qui
  alimente Apple Plans, Bing, Facebook et Uber.

---

## ACTION 2 — Créer la fiche Google Business (≈ 1 h + validation)

C'est le **premier facteur de classement local**, mesuré. La fiche capte à elle seule
environ 42 % des clics, et la moitié des gens qui cherchent un restaurant sur mobile
ne quittent jamais Google.

### Dans l'ordre
1. **`business.google.com`** → *Gérer maintenant*. Cherchez d'abord « Le Grill House
   437 rue Élie Gruyelle » : **si une fiche existe déjà, REVENDIQUEZ-LA et renommez-la.**
   N'en créez surtout pas une seconde — deux fiches à une même adresse déclenchent une
   suspension.
2. **Catégorie principale** — c'est le champ le plus lourd de tous, et il prend dix
   secondes. Choisissez la plus précise qui existe : « Restaurant de tacos » si
   proposé, sinon « Restaurant de type fast-food ».
   **Catégories secondaires** : « Restaurant de hamburgers », « Sandwicherie »,
   « Restaurant à emporter ».
3. **Nom de la fiche : « Le Cayenne ». Rien d'autre.**
   ⛔ Pas « Le Cayenne Tacos Hénin-Beaumont Ouvert Minuit ». Google interdit
   explicitement les mots-clés dans le nom, et la sanction est la **suspension** —
   la fiche disparaît de Search et de Maps pendant une à six semaines.
4. **Horaires : 18h00 – 00h00, les sept jours.** Et **saisissez les jours fériés**
   à l'avance : si vous ne le faites pas, Google affiche de lui-même un avertissement
   « les horaires peuvent être différents » les jours de plus gros chiffre d'affaires.
5. **Attributs** : à emporter ✓, commande en ligne ✓, pas de service sur place,
   pas de livraison propre. Moyens de paiement : espèces, carte, titres-restaurant.
6. **Lien menu** : `https://www.lecayenne.fr/carte.html`
   **Lien commande** : `https://www.lecayenne.fr/`
7. **10 à 15 photos**, prises **le soir** : la devanture éclairée, l'intérieur, et
   chaque produit signature. C'est votre décor naturel — les photos de jour ne vous
   ressemblent pas.

---

## ACTION 3 — Lancer la collecte d'avis (2 h, puis en continu)

Vous avez six semaines d'existence et zéro avis. Vos voisins en ont entre 88 et 983.
**Il ne vous en faut pas 983 : 40 à 60 avis récents et une note haute suffisent** pour
entrer dans le pack local.

### Ce qui est autorisé et efficace
- **Un QR code sur le ticket de retrait.** L'imprimante est déjà en place et sait
  imprimer un code nominatif — c'est le même mécanisme.
- Le lien court est dans votre fiche Google, onglet *Demander des avis*.
- **Demander de vive voix au moment de donner la commande**, une fois, sans insister.

### Ce qui est interdit, et pourquoi c'est sérieux
⛔ **Aucune contrepartie.** Pas de réduction, pas de boisson offerte, pas de tirage au
sort contre un avis. Pas de quota au personnel. Pas de tablette au comptoir pour faire
saisir l'avis sur place.

Deux raisons, et la seconde est la plus dangereuse pour vous : Google supprime les avis
incités **et** sanctionne le profil — vous perdriez l'actif que vous essayez de
construire. Et en France, les faux avis relèvent de la pratique commerciale trompeuse.

### Répondre
Répondez à **tous** les avis, y compris les mauvais, calmement et sans se justifier.
La fraîcheur des avis et le fait d'y répondre sont eux-mêmes des facteurs mesurés.

---

## Dans quel ordre, et à quoi s'attendre

| Quand | Action | Effet visible |
|---|---|---|
| **Aujourd'hui** | Action 1 — la redirection | 1 à 3 mois |
| **Cette semaine** | Action 2 — la fiche Google | 2 à 6 semaines après validation |
| **Dès la fiche créée** | Action 3 — les avis | 3 à 9 mois, c'est le plus lent |

**Faites l'action 1 en premier.** Tant que `legrillhouse.fr` est en ligne, chaque page
que j'écris sert à vous faire arriver deuxième derrière vous-même.

---

## Ce que je peux faire dès que vous aurez agi

- **Vérifier la redirection** par requête directe et vous confirmer qu'elle est bien
  en 301.
- **Déclarer le site à la Google Search Console** dès que vous m'aurez donné accès —
  sans elle, personne ne sait ce que Google fait réellement de vos 36 pages.
- **Ajouter votre fiche Google et votre page Facebook** au champ `sameAs` des données
  structurées : c'est la colle qui relie le site à votre entité. Il ne contient
  aujourd'hui qu'Uber Eats, faute d'avoir les autres adresses.
- **Supprimer les 3 Mo de compilateur JavaScript** de la page d'accueil (mesuré :
  10,8 s avant de pouvoir commander en 4G lente). Je ne l'ai pas fait parce que ça
  change le mode de déploiement pour l'autre session en cours — à arbitrer avec vous.

---

## ACTION 4 — Cinq produits affichent la photo d'une AUTRE marque (≈ 30 minutes)

Ajouté le **8 août 2026**, après vérification à l'œil de chaque photo du catalogue
(38 produits croisés avec leur image, planche contact inspectée une par une).

Le client voit une marque et en reçoit une autre. Au-delà de la déception au
comptoir, une image qui ne correspond pas au produit vendu relève de la pratique
commerciale trompeuse — le même régime que les faux avis.

| Produit vendu | Photo réellement affichée aujourd'hui | Fichier |
|---|---|---|
| **Orangina 33cl** | une canette **Tropico** | `tropico.webp` |
| **Fuze Tea 33cl** | un **Lipton** Ice Tea framboise | `lipton-framboise.webp` |
| **Hawaï 33cl** | un **Fanta** Fruit du Dragon | `fanta-fraise.webp` |
| **Perrier 33cl** | une bouteille d'eau plate **Cristaline** | `eau.webp` |
| **Glace** | un pot **Ben & Jerry's** Strawberry Cheesecake | `ben-jerrys.webp` |

**Aucune photo correcte n'existe dans le dossier** — j'ai vérifié les 76 fichiers
de `assets/menu/`. Je ne peux pas la fabriquer : une image de marque ne s'invente
pas et ne se prend pas sur internet (droits d'auteur).

### Ce qu'il faut faire
Photographiez les cinq produits tels que vous les vendez, posés sur un fond uni,
et envoyez-les. Je les intègre. Une photo prise au téléphone suffit largement.

À défaut, dites-le-moi et je retire l'image : **pas de photo vaut mieux que la
photo d'un concurrent**.

### Deux points à confirmer, sans gravité
- **Galette Normale** et **Galette Cayenne** partagent la même photo. Normal si
  elles se ressemblent, à corriger sinon.
- **Ben & Jerry's** : si vous vendez bien cette marque, il faut nommer le produit
  « Ben & Jerry's » et non « Glace ». Si vous vendez une autre glace, il faut
  changer la photo.

---

## ACTION 5 — Une question sans réponse : l'accès en fauteuil roulant (2 minutes)

Vérifié le 8 août 2026 : j'ai testé, sur les pages telles que les robots de
ChatGPT les reçoivent, **18 questions que les clients posent réellement** à un
assistant. Dix-sept trouvent leur réponse en clair, sans JavaScript : horaires,
adresse, téléphone, livraison, moyens de paiement, titres-restaurant, plats sans
viande, menu enfant, frites maison, allergènes, stationnement, fidélité, et même
la certification halal — que la page « À propos » traite honnêtement en disant
qu'aucune n'est revendiquée.

**Une seule reste sans réponse : l'accessibilité en fauteuil roulant.**

C'est une question fréquente, et c'est aussi un attribut de la fiche Google
Business. Quand l'information manque, un assistant fait l'une de deux choses :
il ne répond pas, ou il devine. Les deux vous desservent.

**Dites-moi simplement :**
- L'entrée est-elle de plain-pied, ou y a-t-il une marche ? (et combien de cm)
- La porte fait-elle plus de 80 cm de large ?
- Y a-t-il une place de stationnement adaptée à proximité ?

Je l'écris sur la page « Horaires & accès » et dans les données structurées. Si
le local n'est pas accessible, on l'écrit aussi : une information juste vaut
mieux qu'une absence, et quelqu'un qui se déplace pour rien ne revient pas.

À saisir également dans la fiche Google, onglet *Attributs* → *Accessibilité*.

# Brief designer — Borne kiosque FoodKing (UI/UX complet)

> Document à transmettre au designer professionnel.
> Langue : français. Deadline : à fixer avec le client.
> Tout ce qui suit décrit le comportement **déjà codé** : le design doit
> s'y conformer à 100 % (pas de fonctionnalité nouvelle à inventer).

---

## 1. Contexte produit

**FoodKing** est un SaaS de restauration (Laravel 9 + Vue 3). La borne
(« kiosk ») est un écran tactile en libre-service dans le restaurant :
le client prend seul sa commande sans passer par la caisse, paie au
TPE, reçoit un ticket avec un numéro d'appel, puis attend son plat.

La borne cohabite avec une **caisse POS** (utilisée par le personnel),
un **KDS** (écran cuisine) et un **OSS** (écran d'appel client). Le
design attendu concerne **uniquement la borne**.

Catégorie de produit : restauration rapide halal / tacos / sandwichs /
burgers / assiettes / snacking / boissons — public familial, volume
élevé, parcours rapide (objectif : commande bouclée en < 2 min).

---

## 2. Contraintes hardware

| Paramètre | Valeur |
|---|---|
| Orientation | **Portrait (vertical)** |
| Résolution de référence | **1080 × 1920** (ratio 9:16) |
| Tailles à prévoir | 1080×1920 principal + 720×1280 fallback |
| Dalle physique | 21,5" à 32" tactile capacitif |
| Distance d'utilisation | 30–60 cm (client debout) |
| Pas de souris, pas de clavier | Clic tactile uniquement |
| Hauteur d'écran utile pour la main | Zone basse privilégiée pour les CTA principaux (ergonomie debout) |
| Wrapper d'exécution | Electron sur borne physique (la SPA est en full-screen) |

**Conséquence design clé** : les actions primaires (Valider, Payer,
Continuer) doivent être **dans le tiers inférieur** de l'écran. La
zone haute sert à l'information (titre, progression, total), le centre
au choix.

---

## 3. Identité de marque (à respecter)

- **Couleur principale** : rouge FoodKing écarlate `#E8001C` (ou
  `rgb(232, 0, 28)`). Utilisée pour CTA, progression active, focus.
- **Fond principal** : blanc `#FFFFFF` + nuances `#F5F6F8` pour les
  cartes inactives.
- **Texte principal** : `#1A1A1A`. Texte secondaire : `#5A5A5A`.
- **Succès** : vert `#16A34A`. **Erreur** : rouge `#DC2626`
  (distinct du rouge marque). **Warning** : ambre `#F59E0B`.
- **Police** : sans-serif moderne, très lisible à 1 m. Recommandation :
  `Inter`, `SF Pro`, `DM Sans` ou équivalent. Graisses utiles : 400,
  600, 700.
- **Icônes** : style linéaire épais (2 px stroke) ou solide arrondi.
  Éviter l'iconographie trop fine.
- **Coins** : radius généreux (16–24 px sur cartes, 28–32 px sur CTA
  principaux).
- **Ombres** : douces, basses (pas de glow). Ex. :
  `0 8px 24px rgba(20,20,20,0.08)`.

Émoji food autorisés dans les vignettes de fallback (quand aucune
image produit n'est disponible), pas dans les CTA.

---

## 4. Règles ergonomiques non négociables

1. **Cible tactile minimale** : 72 × 72 px (au-delà des 44 px WCAG
   car les clients ont parfois les mains sales, gantées, sont pressés).
   CTA primaires : 96 px de haut minimum.
2. **Espacement entre cartes cliquables** : ≥ 16 px (évite les tap
   accidentels).
3. **Feedback immédiat** : toute card doit avoir un état visuel
   `actif`, `sélectionné`, `désactivé`, `chargement` (spinner) et
   `focus clavier` (même si tactile, pour l'accessibilité).
4. **Langue lisible à 1 m** : titres ≥ 36 px, body ≥ 22 px, prix
   ≥ 28 px.
5. **Jamais de scroll horizontal.** Le scroll vertical est réservé aux
   grilles produits et aux listes de sauces si > 8 items.
6. **Bandeau de progression** toujours visible dans le wizard (étape
   X/N + barre de progression).
7. **Bouton retour** toujours en haut-gauche, jamais caché.
8. **Total en temps réel** toujours visible en bas de l'écran pendant
   le wizard et le cart.
9. **Zéro pop-up modale dégoûtante** : les dialogs doivent être
   plein-écran ou en bottom-sheet, pas en modal classique.
10. **Multi-langue** : FR (défaut), EN, AR. L'arabe est **RTL** — tout
    le layout doit être miroir quand `dir="rtl"`.

---

## 5. Parcours complet — 15 écrans à designer

> Chaque écran = 1 frame au minimum (plus pour les états alternatifs
> listés). Livraison attendue : Figma avec composants, variantes, et
> prototype cliquable reproduisant le flux.

### Écran 1 — Attract loop (idle)

- **But** : attirer le client quand personne n'utilise la borne.
- **Contenu** : vidéo/slideshow full-screen avec logo, promos, plats
  phares. CTA géant en bas : « **Commander ici — touchez l'écran** ».
- **Interaction** : n'importe quel tap → écran 2 (catégories).
- **États** : playing, paused (quand maintenance staff entre).

### Écran 2 — Choix de la catégorie (catalogue)

C'est **l'écran central** d'où part tout le parcours.

**Layout imposé** : barre latérale gauche (catégories) + zone droite
(produits de la catégorie active). Barre inférieure avec panier +
CTA paiement.

- **Sidebar gauche** (largeur ~ 240 px) :
  - Logo FoodKing en haut
  - Liste verticale de catégories : vignette + nom (ex. Tacos,
    Burgers, Sandwichs, Assiettes, Snacking, Boissons, Desserts,
    Salades, Omelettes, Menu Enfant)
  - Catégorie active : fond rouge léger + barre gauche rouge pleine
  - Scroll vertical si > 10 catégories
- **Zone produits (droite)** :
  - Titre de la catégorie en haut + compteur « 12 produits »
  - Grille 2 colonnes de cards produit
  - Chaque card : photo, badge optionnel (« Nouveau », « Populaire »,
    « Menu »), nom, description courte (60 car max), prix, bouton `+`
    rouge en overlay sur l'image
  - Card désactivée (rupture) : grayscale + badge « Indisponible »
- **Bandeau inférieur** (sticky bas) :
  - Gauche : panier (icône + nombre d'articles + « 3 articles »)
  - Centre : total en gros (`12,80 €`)
  - Droite : 2 boutons — « Annuler la commande » (secondaire gris)
    et « Payer » (primaire rouge, désactivé si panier vide)
- **Sélecteur de langue** : FR / EN / AR en haut-droite (3 pastilles)

**États à livrer** :
- Grille chargée (default)
- Catégorie sans produits
- Erreur de chargement catalogue + bouton « Réessayer »
- Mode cache hors-ligne : bandeau jaune discret « Menu en cache —
  prix indicatifs » sous le titre
- Card produit en cours d'ajout (spinner dans le bouton `+`)

### Écran 3 — Wizard produit (pop-in plein écran)

Quand le client tape une card produit, un wizard plein-écran s'ouvre.
Il contient **N étapes** selon le type de produit. **Le designer doit
prévoir 9 types d'étapes** (voir section 6).

Structure commune du wizard :

- Header : bouton retour (← gauche), titre produit, bouton fermer (×)
- Barre de progression : dots ou segments avec labels (ex.
  `Pain · Viande · Sauce · Garnitures · Suppléments · Menu · Récap`)
- Zone centrale : le contenu de l'étape (section 6)
- Footer sticky : prix courant du produit + CTA « Suivant » (ou
  « Ajouter au panier » sur la dernière étape).
  Le CTA est **désactivé tant que l'étape n'est pas validée**.
- Un hint de validation apparaît au-dessus du CTA si l'utilisateur
  tape le bouton sans sélection (ex. « Choisissez une sauce »).

### Écran 4 — Récapitulatif produit (dernière étape du wizard)

Avant d'ajouter au panier, le client voit un résumé :
photo, nom, toutes les options sélectionnées listées (pain, viandes,
sauces, garnitures, suppléments, menu, boissons), sélecteur de
quantité (− 1 +), champ instructions optionnel, total ligne, CTA
« Ajouter au panier ».

### Écran 5 — Panier

- Liste verticale des articles : miniature, nom, options en sous-
  texte, prix, boutons (− qty +), icône poubelle
- **Sélecteur Sur place / À emporter** (2 grosses cards en haut du
  panier avec icônes assise/sac — choix obligatoire)
- Ligne « Sous-total », ligne « TVA », ligne « Total » en gras
- Bouton « Ajouter un article » (retour catégories)
- Bouton « Code promo » (ouvre une bottom-sheet clavier numérique)
- CTA primaire bas : « Continuer » (vers loyalty/upsell/paiement)
- Bouton secondaire : « Annuler la commande » (confirmation plein
  écran avant de vider)

### Écran 6 — Loyalty / Fidélité (optionnel, selon config)

Écran proposant au client de saisir son code fidélité ou son numéro
de téléphone pour cumuler/utiliser des points.

- Titre : « Cumulez des points »
- Pavé numérique tactile grand format
- Affichage du solde en temps réel après validation
- Option « Utiliser mes X points » (toggle)
- Bouton « Passer cette étape » bien visible (non obligatoire)

### Écran 7 — Upsell (suggestions dernière minute)

Avant le paiement, la borne propose 3–6 produits complémentaires
(boisson, dessert, sauce extra).

- Titre : « Et avec ça ? » / « Vous allez aimer »
- Carrousel ou grille 2×3 de cards produit
- CTA « Ajouter » sur chaque card (ajout direct sans wizard si simple)
- CTA bas : « Non merci, passer au paiement »

### Écran 8 — Choix du mode de paiement

- 2 grosses cards (card bancaire, espèces) — voire 3 si titres-
  restaurant activés (« TR »)
- Chaque card : icône, nom, sous-texte (« Sans contact, Apple Pay »
  pour CB ; « Remise au comptoir » pour espèces)
- Card sélectionnée : bord rouge + checkmark
- Total en gros au-dessus : « À payer : 18,40 € »
- CTA bas : « Valider et payer »

### Écran 9 — Paiement CB en cours (overlay TPE)

Lorsque le client choisit la CB, un **overlay plein-écran** s'affiche :

- Animation centrale : carte bancaire qui approche d'un TPE, avec
  3 anneaux concentriques pulsants
- Titre : « **Suivez les instructions sur le terminal** »
- Sous-titre : « Insérez, approchez ou glissez votre carte »
- Spinner
- Bouton « Annuler » discret en bas (timeout 90 s)
- États alternatifs à livrer :
  - Paiement accepté (checkmark vert qui grossit + son optionnel)
  - Paiement refusé (croix rouge + message + bouton « Réessayer »)
  - Timeout (message « Délai dépassé » + bouton retour)

### Écran 10 — Paiement espèces (instruction comptoir)

- Icône caisse / tickets
- Message : « Rendez-vous au comptoir avec ce numéro pour régler »
- Numéro d'ordre en gros (ex. `N° 42`)
- Total à régler
- CTA « J'ai compris »
- Note : la commande part en cuisine seulement après validation par
  le staff caisse (le design doit refléter cette attente)

### Écran 11 — Waiting / Order pending

Entre le paiement et la confirmation définitive (quelques secondes le
temps que le backend persiste la commande et que la caisse l'accepte
pour les règlements espèces).

- Animation de cuisson (GIF ou Lottie discret)
- Titre : « Nous préparons votre commande… »
- Spinner

### Écran 12 — Confirmation finale

L'écran le plus important : c'est celui que le client photographie.

- Gros checkmark vert animé (springy bounce)
- Titre : « Commande confirmée ! »
- **Numéro d'appel en ÉNORME** (ex. `42`, 240 px de haut, rouge)
- Message : « Retirez votre commande quand votre numéro s'affiche »
- Total payé
- Type : « Sur place » ou « À emporter » (badge)
- Ligne « Un reçu imprimé vient de sortir » avec icône ticket
- Compte à rebours 15 s avant retour à l'écran idle (écran 1)
- Bouton « Retour à l'accueil » pour skip le compte à rebours

### Écran 13 — Écran « Vous êtes toujours là ? » (inactivity)

Après **2 min d'inactivité** pendant le wizard/cart :

- Overlay semi-transparent
- Titre : « Vous êtes toujours là ? »
- Sous-titre : « Votre commande sera annulée dans 30 secondes »
- Grand bouton « Oui, je continue »
- Bouton secondaire « Recommencer »

Après **3 min d'inactivité** totale : retour forcé à l'écran idle et
vidage du panier.

### Écran 14 — Erreurs globales (4 variantes)

Le design doit couvrir ces états d'erreur en plein écran :

1. **Perte réseau** — « Pas de connexion. La borne fonctionne en mode
   limité. » (icône wifi barré, bouton Réessayer)
2. **Menu indisponible** — « Catalogue temporairement indisponible.
   Merci de patienter. »
3. **Produit retiré pendant le wizard** — « Ce produit n'est plus
   disponible. Choisissez autre chose. » (retour catégories)
4. **Paiement refusé définitivement** — « Paiement non abouti. Merci
   de vous adresser au comptoir. »

### Écran 15 — Interface staff / Admin borne (mode maintenance)

Accédé par triple-tap dans un coin ou PIN staff. **Esthétique
sobre, pas pour le client.**

- Login staff (PIN à 4–6 chiffres)
- Écran admin :
  - Bouton « Pause » (la borne affiche « Hors service »)
  - Bouton « Vider cache menu »
  - Statut réseau / backend
  - Statut TPE
  - Impression test ticket
  - Quitter (retour idle)

---

## 6. Les 9 types d'étapes du wizard (à designer chacune)

Chaque étape est une frame différente. Le wizard enchaîne les étapes
selon le **template produit** (voir section 7).

| # | Type | Quand ? | Règles de sélection |
|---|------|---------|---------------------|
| 1 | **Taille** | Tacos génériques uniquement | 1 choix obligatoire parmi M / L / XL / XXL (+ prix delta) |
| 2 | **Pain** | Sandwichs uniquement | 1 choix obligatoire : galette / pain classique / etc. |
| 3 | **Viande** | Si le produit a ≥ 1 viande | N choix obligatoires (N = nb de viandes du produit, ex. tacos 3V = 3 sélections parmi 6). Même viande peut être choisie plusieurs fois |
| 4 | **Sauce** | Si le produit a un attribut sauce | ≥ 1 obligatoire ; 1ère gratuite, suivantes au tarif « sauce en extra » |
| 5 | **Garnitures** | Si extras prix=0 | 0 à N (optionnel) — checkboxes |
| 6 | **Suppléments** | Si extras prix>0 | 0 à N (optionnel) — avec prix affiché |
| 7 | **Menu** (formule) | Sur sandwich / burger / tacos | 4 cards : `Menu complet` (frites+boisson), `Frites seules`, `Boisson seule`, `Aucun menu`. **La card « Boisson seule » ne s'affiche PAS** sur sandwich/burger/tacos (formule illogique) |
| 7.a | **Upgrade frites** (sub-step) | Si menu choisi | Frites standard (inclus) / Frites+cheddar (+1 €) / Frites+cheddar+crispy (+2 €) |
| 7.b | **Choix boisson** (sub-step) | Si menu `complet` ou `boisson seule` | 1 boisson gratuite incluse — grille de boissons avec visuels |
| 7.c | **Sauce frites** (sub-step) | Si menu `complet` ou `frites seules` | ≥ 1 sauce (ou « sans sauce »). Liste sauces complète, pas limitée |
| 8 | **Récap** | Toujours dernière étape | Voir écran 4 |

Chaque type d'étape doit avoir son **propre template visuel** mais
partager le **même chrome** (header + progression + footer).

### Détails visuels par étape

**Taille / Pain / Menu** — grille 2×2 de grosses cards (48 % de la
largeur chacune), chaque card = emoji/icône + nom + sous-texte.

**Viande** — grille 3 colonnes de cards viande avec photo, nom,
compteur `× 2` quand sélectionné plusieurs fois. Affichage compteur
« 2 / 3 viandes sélectionnées » en bas.

**Sauce** — grille 4 colonnes de petites cards (photo/emoji + nom),
sélection multiple, badge numéroté `1`, `2`, `3` par ordre de
sélection. Ligne info : « 1ère gratuite, sauces suivantes +0,80 € ».

**Garnitures / Suppléments** — liste verticale ou grille 3 colonnes
selon nombre. Supplément : card avec prix `+1,50 €` visible. Toggle
visuel clair (checkbox ronde remplie rouge).

**Upgrade frites / Boisson / Sauce frites** — sous le choix Menu,
3 sous-sections dans le même scroll, avec sous-titres.

---

## 7. Les 8 templates produit (routing conditionnel)

Le wizard n'affiche **pas les mêmes étapes** selon le type de produit.
Chaque template est détecté automatiquement côté code. Le designer
doit fournir un parcours type pour **chacun** :

| Template | Étapes affichées (dans l'ordre) |
|---|---|
| **tacos** | [Taille*] → Viande → Sauce → Garnitures → Suppléments → Menu → Récap |
| **sandwich** | Pain → [Viande*] → Sauce → Garnitures → Suppléments → Menu → Récap |
| **burger** | [Viande*] → Sauce → Garnitures → Suppléments → Menu → Récap |
| **assiette** | [Viande*] → Sauce → Garnitures → Suppléments → Récap |
| **snacking** (chicken, tenders) | Sauce → Suppléments → Récap |
| **omelette** | Garnitures → Suppléments → Récap |
| **salade** | Garnitures → Sauce → Suppléments → Récap |
| **simple** (boisson, dessert) | Suppléments → Récap (souvent juste Récap) |

\* = étape conditionnelle selon le produit.

Le nombre d'étapes varie donc de **1 à 7** selon le produit. La barre
de progression doit savoir gérer N étapes dynamiques (prévoir les 3
variantes : 3 étapes, 5 étapes, 7 étapes dans le design system).

---

## 8. Composants à livrer dans le design system Figma

Le designer doit produire un fichier Figma structuré avec :

**Foundations**
- Palette (primary/secondary/success/error/warning + nuances)
- Typographie (H1, H2, H3, body, caption, price, number XXL)
- Spacing scale (4 / 8 / 16 / 24 / 32 / 48 / 64 / 96)
- Radius scale (8 / 16 / 24 / 32)
- Shadow scale (sm, md, lg)
- Icon library (≥ 40 icônes cohérentes)

**Atoms**
- Button primary (default / hover / pressed / disabled / loading)
- Button secondary, Button ghost, Button danger
- Badge (available / unavailable / new / popular / menu)
- Chip de sauce / chip de garniture
- Quantity selector (− 1 +)
- Progress bar (segmented with labels)
- Toast (success / error / info)
- Keypad numérique (pour code promo, loyalty, PIN staff)

**Molecules**
- Card produit (liste + grille)
- Card catégorie (sidebar)
- Card option (sauce, viande, etc.)
- Card menu (full / frites / boisson / rien)
- Line item panier
- Payment method card
- TPE overlay
- Inactivity overlay

**Organisms**
- Sidebar catégories
- Grille produits
- Header wizard (breadcrumbs + close)
- Footer sticky (total + CTA)
- Bottom bar panier
- Numéro d'appel géant (confirmation)

**Screens**
- Les 15 écrans listés en section 5, chacun avec ses états alternatifs
- Prototype cliquable qui reproduit le flux complet d'un achat
  (idle → catégories → wizard tacos → panier → paiement → confirmation)

---

## 9. Contenus et données à utiliser dans les maquettes

> Le designer peut (doit) utiliser ces vrais contenus pour que le
> rendu soit fidèle à la production.

**Catégories types** (ordre recommandé) :
1. Tacos
2. Burgers
3. Sandwichs
4. Assiettes
5. Snacking (nuggets, tenders, chicken)
6. Salades
7. Omelettes
8. Boissons
9. Desserts
10. Menu Enfant

**Exemples de produits à utiliser dans les maquettes** :
- Tacos M (1 viande), Tacos L (2 viandes), Tacos XL (3 viandes)
- Burger Cheese, Burger BBQ Bacon, Burger Crispy
- Sandwich Poulet, Panini Jambon-Fromage
- Assiette Kebab, Assiette Mixed Grill
- Tenders × 4, Nuggets × 6, Wings × 10
- Coca 33 cl, Oasis 33 cl, Eau Cristaline, Ice Tea, Fanta
- Cookie, Brownie, Tiramisu

**Prix réalistes (euros)** : 4,50 € à 12,90 € produit principal,
+1 € upgrade frites cheddar, +2 € upgrade cheddar+crispy,
+0,80 € sauce extra au-delà de la 1ère, +1,50 € supplément fromage.

**Viandes types** : Poulet, Bœuf, Cordon Bleu, Escalope, Merguez,
Nuggets, Tenders, Steak Haché, Kefta.

**Sauces types (liste complète, 13 sauces)** : Algérienne, Andalouse,
Biggy, Blanche, BBQ, Cocktail, Curry, Fromagère, Harissa, Ketchup,
Mayonnaise, Samouraï, Poivre, Tartare.

**Garnitures types (prix 0 €)** : Salade, Tomate, Oignon, Cornichons,
Olives, Maïs.

**Suppléments types (prix > 0)** : Cheddar +1 €, Chèvre +1,50 €,
Œuf +1 €, Double viande +2,50 €, Frites cheddar +1 €.

**Boissons types (pour step menu)** : Coca 33 cl, Coca Zero, Fanta
Orange, Oasis, Ice Tea, Sprite, Eau Plate, Eau Gazeuse.

---

## 10. Accessibilité et i18n

- **WCAG 2.1 AA minimum** : contraste texte/fond ≥ 4.5:1, focus ring
  toujours visible, cible tactile ≥ 44 px (le brief impose 72 px).
- **Lang FR / EN / AR** : le designer doit montrer le rendu dans les
  3 langues pour **au moins** : écran catégories, écran wizard
  (1 frame tacos), écran panier, écran confirmation.
- **RTL (arabe)** : tout le layout mirroré. Icônes directionnelles
  mirrorées (flèche retour). Chiffres restent LTR.
- **Texte dynamique** : prévoir 20 % d'expansion (FR → EN peut
  rallonger, EN → AR aussi). Tester avec des noms longs (« Sandwich
  classique pain complet »).

---

## 11. Animation et micro-interactions

Le designer fournit soit Lottie, soit des prototypes Figma, soit une
spec textuelle des animations clés :

- **Transition entre étapes du wizard** : slide horizontal 280 ms
  ease-out
- **Sélection d'une card** : scale 1 → 0.97 → 1 (tap bounce), 150 ms
- **Ajout au panier** : card qui « vole » vers l'icône panier en bas
- **Panier +1** : icône panier qui pulse, badge compteur qui bump
- **Checkmark confirmation** : stroke-draw 400 ms + scale bounce
- **Numéro d'appel** : apparaît en fade + scale up
- **TPE ring** : pulse en boucle (3 anneaux décalés)
- **Inactivity** : fade in de l'overlay 200 ms

Éviter : parallaxe lourd, animations > 500 ms qui ralentissent le
parcours. La borne doit sentir **rapide**.

---

## 12. Son (optionnel mais utile)

La borne peut jouer des sons courts. Si le designer veut intégrer
une palette sonore : tap, confirmation, erreur, paiement réussi,
numéro d'appel. Sinon on les ajoutera côté dev.

---

## 13. Livrables attendus du designer

1. **Fichier Figma structuré** avec :
   - Page `01. Foundations` (palette, typo, spacing, icons)
   - Page `02. Components` (atoms + molecules + organisms)
   - Page `03. Screens — FR` (les 15 écrans, tous états)
   - Page `04. Screens — EN` (minimum 4 écrans clés)
   - Page `05. Screens — AR RTL` (minimum 4 écrans clés)
   - Page `06. Prototype` (flow cliquable bout-en-bout)
   - Page `07. Specs & notes` (notes de transmission dev)
2. **Assets exports** (SVG + PNG 2x) :
   - Logo FoodKing (horizontal, vertical, monochrome)
   - Jeu complet d'icônes UI
   - Illustrations (vide panier, erreur réseau, cuisson, checkmark)
3. **Spec de développement** (token exports JSON style Tailwind ou
   Style Dictionary) :
   - `colors.json`, `typography.json`, `spacing.json`, `radius.json`,
     `shadows.json`
4. **Prototype Figma** accessible par lien à l'équipe dev + client.

---

## 14. Contraintes techniques d'intégration (à respecter côté design)

Le design sera intégré dans une codebase **Vue 3 existante**. Pour
faciliter la reprise :

- Les composants Figma doivent avoir des noms qui matchent les
  composants Vue existants quand c'est pertinent :
  - `KioskCategoriesComponent` → sidebar + grille produits
  - `KioskWizardComponent` → shell du wizard
  - `KioskStepMenuComponent`, `KioskStepSauceComponent`,
    `KioskStepPainComponent`, `KioskStepViandeComponent`,
    `KioskStepTailleComponent`, `KioskStepGarnituresComponent`,
    `KioskStepSupplementsComponent`
  - `KioskOrderSummaryComponent` → récap produit
  - `KioskCartComponent` → panier
  - `KioskPaymentComponent` → paiement (avec overlay TPE)
  - `KioskWaitingComponent`, `KioskConfirmationComponent`,
    `KioskIdleScreenComponent`, `KioskLoyaltyComponent`,
    `KioskUpsellComponent`, `KioskAdminComponent`
- Les classes CSS actuelles suivent la convention `kiosk-*`
  (ex. `kiosk-product-card`, `kiosk-menu-card`, `kiosk-boisson-card`,
  `kiosk-option-card`). Le design doit être compatible pour qu'on
  remplace uniquement le style sans toucher la structure HTML.
- Les images produits ont des dimensions variables (400×400 à
  800×800). Prévoir les crops 1:1.

---

## 15. Hors scope — ce que le designer n'a PAS à faire

- POS caisse (interface personnel)
- KDS (écran cuisine)
- OSS (écran d'appel client en salle)
- Admin dashboard
- Site web e-commerce
- Backend, API, logique métier
- Intégration finale dans le code (c'est nous qui intégrons)

---

## 16. Validation et itérations

- **Sprint 1 (1 semaine)** : foundations + components + 3 écrans clés
  (idle, catégories, wizard tacos complet)
- **Revue 1** avec le client
- **Sprint 2 (1 semaine)** : les 12 écrans restants + états alternatifs
- **Revue 2** avec le client
- **Sprint 3 (3 jours)** : EN + AR + prototype cliquable + exports
- **Validation finale**

---

## 17. Questions à poser au client avant de démarrer

Si le designer a un doute, qu'il pose ces questions :

1. Y a-t-il un kit de marque existant (logo, polices achetées) ?
2. Couleur rouge exacte = `#E8001C` ou une autre référence Pantone ?
3. Photos produits disponibles, ou doit-on mocker avec des
   placeholders qualité AI ?
4. Sons existants à intégrer, ou palette sonore à créer ?
5. Langue par défaut au démarrage : FR forcée, ou détection IP ?
6. Faut-il prévoir un mode sombre / nuit ?
7. Le client veut-il un écran « Mes favoris » ou « Mon compte »
   (non couvert ci-dessus) ?
8. Intégration future d'un scanner QR-code fidélité — prévoir la
   place ou pas ?

---

**Fin du brief.** Ce document doit être lu en entier par le designer
avant qu'il ne commence. Les 15 écrans + 9 types d'étapes + 8 templates
produit + les 15 composants du design system = le livrable complet.

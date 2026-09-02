# DISPUTE_UX — Barre de contrôle de la caisse

**Rôle** : designer UX de caisse **adverse**, lecture seule. Aucun fichier du produit n'a été modifié.
**Date** : 2026-09-02 · **Entrées** : `BRIEF_DESIGN.md`, captures `captures-avant/01..04`, code lu.
**Règle que je m'impose** : toute affirmation sur le code existant porte un `file:line` que j'ai ouvert.
Les seules affirmations non vérifiables dans ce dépôt sont les 3 références externes, explicitement
marquées comme telles.

---

## A. VERDICT

### P2 — tiroir latéral droit, 4 files : **ADOPTÉ**, avec 6 amendements dont 2 qui contredisent le brief

| # | Amendement | Effet |
|---|---|---|
| A1 | **Onglets**, pas sections empilées, pas colonnes | la barre d'onglets EST le compteur permanent des 4 files |
| A2 | **Recouvrement** (overlay) du panier, pas de split | le panier n'est jamais rétréci ni le catalogue reflowé |
| A3 | **Deux densités de carte** selon la question posée par la file | 3 cartes riches OU 10 lignes compactes visibles |
| A4 | Le rang cuisine = **« 2ᵉ sur 4 »**, jamais une durée prédite | pas de promesse invérifiable au client |
| A5 | Une commande à encaisser qui cuit est dans **les deux** files | les deux compteurs ne s'additionnent jamais |
| A6 | **Pas de recherche** dans le tiroir en V1 | 44 px permanents rendus à la liste |

**La contradiction que j'assume avec les mots du propriétaire.** Il dit « en petite barre à droite ».
Le bord droit est **déjà** occupé en permanence par `#pos-cart` (`PosComponent.vue:939`), large de
`md:w-[340px] lg:w-[360px] xl:w-[400px]`, et l'aire produits est calculée pour lui
(`PosComponent.vue:116` : `xl:w-[calc(100%-376px)]`). À 1280 px : 904 px de catalogue, 400 px de panier.
Une **deuxième** barre permanente de 360 px ramènerait le catalogue à ~540 px — 42 % de l'écran pour le
travail principal, alors que DEF-7 dit déjà que ce travail passe sous la ligne de flottaison.
Je refuse donc la barre permanente et je sers l'intention (« sans changer de page, à droite, dynamique »)
par : **un tiroir à la demande au bord droit** + **un compteur permanent sur le ticket en cours** (P4).
→ **C'est une décision qui appartient au propriétaire.** Elle doit lui être posée en ces termes :
« la petite barre permanente coûte 4 tuiles produit par écran ; on la remplace par un tiroir qui s'ouvre
au bord droit et par 3 chiffres sur votre ticket. »

### P4 — compteur cuisine sur le ticket : **ADOPTÉ**, mais **le chiffre proposé est rejeté**

Le brief propose « 4 en cuisine · attente ≈ X min (X = âge de la plus ancienne) ». L'âge de la plus
ancienne n'est **pas** une attente : c'est le temps déjà écoulé pour quelqu'un d'autre. Annoncer
« ≈ 14 min » au client qui commande alors que la commande de 14 min sort dans 30 secondes est un
mensonge commercial, et il n'existe dans ce dépôt **aucun modèle de débit cuisine** pour en fabriquer un
vrai (la seule sonde temporelle existante est `aging` = « pas encore prête après 15 min »,
`PosSystemHealthPill.vue:39-45` — c'est du **retard constaté**, pas de la prévision).

Ce que le ticket doit dire, et qui est vrai :

```
🍳 4 en cuisine · la plus ancienne depuis 14 min · vous serez le 5ᵉ
```

Trois faits mesurés, zéro prédiction. Le caissier compose lui-même la phrase orale
(« il y a 4 commandes devant, comptez un petit quart d'heure ») — il connaît son coup de feu,
l'application ne le connaît pas.

---

## B. SPÉCIFICATION RECOMMANDÉE

### B1 — Géométrie et mécanique du tiroir

**Recouvrement, pas poussée.** Trois raisons vérifiées, pas une préférence :

1. **Le catalogue ne doit pas se reflower.** Les tuiles sont en `grid-cols-2 lg:grid-cols-3 xl:grid-cols-4`
   (`ItemComponent.vue:10`) et les seuils sont ceux du **viewport**, pas du conteneur : pousser la colonne
   principale ne change pas le nombre de colonnes, mais change la **largeur** de chaque tuile et donc la
   **position** de chaque produit. En coup de feu, la mémoire spatiale des tuiles est le canal de saisie le
   plus rapide du caissier ; la casser à chaque ouverture est un coût par ouverture, payé toute la journée.
2. **Le panier ne peut pas rétrécir.** `tests/e2e/goal-caisse-b014-defilement-decouvrable.spec.js:1-21`
   documente qu'à 720 px de haut, l'en-tête du panier chargé **cache déjà 243 de ses 450 px (54 %)** —
   type de commande, nom du client, téléphone, « Programmer », « Annuler la dernière ligne ». Lui prendre
   de la largeur aggrave un défaut déjà instruit.
3. **Le recouvrement est déjà la mécanique en place et le propriétaire l'utilise.** `.kiosk-cash-panel-overlay`
   est `position: fixed; inset: 0; z-index: var(--pos-v5-z-modal)` (=2000) et `.kiosk-cash-panel` fait
   `width: 420px; height: 100vh` (`PosComponent.vue:7210-7228`). Le panier est en `md:z-10`
   (`PosComponent.vue:939`) : il passe donc **dessous**. Captures 02 et 03 : le panier est intégralement
   recouvert, et personne ne s'en est plaint.

**Géométrie exacte — le tiroir prend la boîte du panier, élargie :**

```
largeur            420 px   (identique à .kiosk-cash-panel:7212 — la main connaît déjà la croix)
                            max-width: 100vw
haut               64 px    (identique à #pos-cart md:top-[64px] — l'entête admin reste atteignable)
hauteur            calc(100dvh - 64px)      → 656 px à 720 p ; 1016 px à 1080 p
bord droit         12 px    (identique à #pos-cart ltr:md:right-3)
z-index            var(--pos-v5-z-modal)    (2000, comme le panneau borne)
bordure gauche     2 px solid var(--pos-v5-brand-red)   ← rend lisible le bord panier/tiroir
rayon              var(--pos-v5-radius-xl) à gauche uniquement (comme :7217-7219)
ombre              var(--pos-v5-shadow-modal)
voile              rgba(26,26,26,.42) + backdrop-filter blur(2px)   (identique à :7205-7208)
animation          translateX(100%)→0, var(--pos-v5-duration-base) (220 ms) ease-decelerate
                   annulée par @media (prefers-reduced-motion) — déjà géré par les tokens (:191-198)
```

**Le conflit d'espace avec le panier est réel et se traite par une phrase, pas par un layout.**
Le caissier a peur de perdre son ticket en cours. Le tiroir porte donc, sous son titre, un bandeau
permanent, non cliquable, hauteur 32 px :

```
🧾 Votre ticket en cours est conservé — 3 articles · 24,80 €
```

C'est 32 px contre l'angoisse de perdre une saisie. Le tiroir ne démonte rien : il se superpose,
`v-if` sur lui seul.

> **Référence externe** (non vérifiable dans ce dépôt) : les caisses tablette modernes de type
> **Lightspeed K / Toast Go** placent le panneau de commandes en **feuille latérale superposée** et non
> en colonne poussante, précisément parce que la grille produit d'un point de vente rapide est apprise
> par position. Je m'en sers ici pour **un seul choix** : overlay plutôt que split.

---

### B2 — Onglets, et non sections empilées ni colonnes

**Colonnes : rejet immédiat, arithmétique.** Le suivi utilise 4 colonnes (capture 04) parce qu'il dispose
de 1280 px. Dans 420 px, 4 colonnes font 99 px chacune : ni un numéro en 22 px ni « Poulet mariné » n'y
tiennent. Rejeté.

**Pile de sections : rejet argumenté.** Le service semé (BRIEF §Faits mesurés) contient 3 à encaisser,
4 en cuisine, 2 prêtes, 2 livrées = 11 cartes. À ~155 px la carte riche, la pile fait ~1 700 px de contenu
dans 472 px de liste utile : **le caissier voit 30 % de l'état et ne sait pas ce qu'il ne voit pas.**
L'argument « la pile montre tout d'un coup » est faux dès le premier coup de feu.

**Onglets : adopté, à une condition qui répond à l'objection « les onglets cachent ».**
La barre d'onglets **est** le tableau de bord : les 4 nombres y sont toujours peints. Ce qui est masqué,
ce sont les cartes, jamais les compteurs. Un onglet dont le compteur change pendant qu'on est ailleurs
pulse une fois (240 ms, halo, `--pos-v5-duration-base`).

```
┌──────────────────────────────────────────────────────────────┐  ← 420 px
│  Contrôle des commandes                                  ✕   │  56 px
│  🧾 Votre ticket en cours est conservé — 3 art. · 24,80 €    │  32 px
├──────────────┬──────────────┬──────────────┬─────────────────┤
│  💶  3       │  🍳  4       │  🛎️  2       │  ✅  9          │  52 px  ← onglets
│  À encaisser │  En cuisine  │  Prêtes      │  Livrées        │         96×52 px chacun
└──────────────┴──────────────┴──────────────┴─────────────────┘
```

*Dimensions* : 420 − 2×12 de marge = 396 px ; 4 onglets de 96 px + 3 gouttières de 4 px = **396 px exactement**.
Hauteur 52 px ≥ 44 px de zone tactile. Ligne 1 : pictogramme 16 px + compteur **18 px / 800**, tabulaire.
Ligne 2 : libellé **11 px / 700**, `--pos-v5-ink-soft`. Onglet actif : fond `--pos-v5-brand-red-soft`,
soulignement 3 px `--pos-v5-brand-red`, `aria-selected="true"`.
`role="tablist"` / `role="tab"` / `role="tabpanel"`, flèches ←→ pour naviguer entre onglets (ARIA APG).

**Onglet ouvert par défaut : « À encaisser ».** C'est la douleur n°1 énoncée et la seule file où
l'inaction coûte de l'argent. Le tiroir **ne mémorise pas** le dernier onglet entre deux ouvertures :
en coup de feu, un état persistant invisible est un piège (on rouvre, on croit voir la file argent,
on voit les livrées). Exception : si le tiroir est ouvert par « Voir plus » d'un panneau raccourci,
il s'ouvre sur l'onglet correspondant.

---

### B3 — Anatomie des cartes : deux densités, décidées par la question posée

**Règle qui gouverne toute la spec** : *la densité d'une file suit la question à laquelle elle répond.*

| File | Question du caissier | Densité |
|---|---|---|
| 💶 À encaisser | « laquelle est celle du client devant moi ? » | **carte riche** (identification) |
| 🛎️ Prêtes | « laquelle je donne à celui-là ? » | **carte riche** (identification) |
| 🍳 En cuisine | « combien devant, et depuis quand ? » | **ligne compacte** (dénombrement) |
| ✅ Livrées | « celle-là, je l'ai bien servie ? combien ? » | **ligne compacte dépliable** (contrôle) |

#### B3.1 — Carte riche (À encaisser, Prêtes)

Ce que le client dit en arrivant, dans l'ordre de fréquence réelle en fast-food : **son numéro**
(la borne le lui imprime), **son prénom** (téléphone/web), sinon **ce qu'il a pris**. La hiérarchie
typographique suit cet ordre exactement — pas la hiérarchie comptable.

```
┌──────────────────────────────────────────────┬──────────────┐
│ N°A9038   🖥️ Borne              il y a 16 min│              │  ← 292 px | 96 px
│ 👤 Sofiane · 07 98 76 54 32                  │   22,60 €    │
│ 2× Cayenne                                   │              │
│    Poulet mariné · Algérienne · +Cheddar     │ ┌──────────┐ │
│ 1× Grande Frites                             │ │ Encaisser│ │  44 px
│ + 2 articles                                 │ └──────────┘ │
│ 🍳 2ᵉ sur 4 en cuisine · commandée à 08:28   │              │
└──────────────────────────────────────────────┴──────────────┘
  ↑ tout le corps est UN bouton → ouvre « Voir tout »
```

| Élément | Taille / graisse | Couleur (token) | Toujours ? |
|---|---|---|---|
| `N°A9038` | **22 px / 900**, `font-variant-numeric: tabular-nums` | `--pos-v5-ink` | oui |
| Pastille canal `🖥️ Borne` | 11 px / 700, pilule 22 px | fonds §B10 | oui |
| `il y a 16 min` | 12 px / 600 | `--pos-v5-ink-muted` ; **ambre ≥ 10 min, rouge ≥ 20 min** | oui |
| `👤 Sofiane` | 15 px / 700 | `--pos-v5-ink` | si connu |
| `07 98 76 54 32` | 13 px / 600, `<a href="tel:">` | `--pos-v5-brand-red` | si connu |
| `2× Cayenne` | 13 px / 700 | `--pos-v5-ink` | oui, 3 lignes max |
| composition | **11 px / 500**, retrait 16 px | `--pos-v5-ink-soft` | si non vide |
| `+ 2 articles` | 11 px / 600 italique | `--pos-v5-ink-muted` | si > 3 lignes |
| `🍳 2ᵉ sur 4 en cuisine` | 11 px / 700 | `--pos-v5-info` sur `--pos-v5-info-soft` | si en cuisine |
| `commandée à 08:28` | 11 px / 500 | `--pos-v5-ink-muted` | oui |
| **Montant dû** | **18 px / 800** tabulaire | `--pos-v5-brand-red` si dû, `--pos-v5-ink` sinon | oui |
| CTA | 13 px / 800, **44 px de haut**, 88 px de large | §B10 | oui |

**Masqué par défaut, accessible par « Voir tout »** (le panneau `ouvrirContenu` **existe déjà**,
`PosOrdersTrackerComponent.vue:365-390`) : prix à la ligne, variations détaillées, instructions,
allergènes, n° fiscal `order_serial_no`, **Annuler**. Motif : « Annuler » à côté d'« Encaisser » sur
une dalle tactile en coup de feu est une annulation par erreur qui attend son heure ; il vit derrière
un écran de plus, comme aujourd'hui dans le suivi.

**Modèle tactile, deux cibles seulement** — corps de carte (≈ 292 × 120 px) = *regarder* ;
colonne droite (96 px) = *agir*. Aucune cible < 44 px sur la carte.

**Hauteur mesurée** : 26 + 17 + (17+15) + 17 + 16 + 16 + 2×10 de rembourrage + gouttières ≈ **155 px**.
Liste utile = 656 − 56 − 32 − 52 − 40 = **476 px** → **3 cartes riches visibles** à 1280×720,
**6 à 1920×1080**. Je le dis tel quel plutôt que de prétendre que 5 tiennent : avec le service semé
(3 à encaisser), le propriétaire voit **toute** sa file argent sans défiler. Au-delà, la liste défile,
avec l'ombre de bord exigée par `goal-caisse-b014-defilement-decouvrable.spec.js` (même doctrine).

#### B3.2 — Ligne compacte (En cuisine, Livrées) — 48 px

```
 1ᵉʳ  N°A9041  🖥️  14 min   Galette Cayenne + 1              🔔
 2ᵉ   N°A9037  🖥️   9 min   Tacos M + 1                      🔔
 3ᵉ   N°A9039  🛒   6 min   Cheese Burger + 1
 4ᵉ   N°A9043  📞   3 min   Tacos L + 1                      🔔
```

Rang **16 px / 900** `--pos-v5-ink`, N° 15 px / 800, âge 13 px / 700 (ambre ≥ 10 min, rouge ≥ 20 min),
résumé 12 px / 500 `--pos-v5-ink-soft`, 🔔 = **aussi à encaisser**. Ligne entière = 48 px de cible,
tapable → ouvre « Voir tout ». **Aucune action** dans « En cuisine » : le bump appartient au KDS,
et un bouton qui doublerait le chef créerait deux vérités.
« Livrées » : même ligne, **plus récente d'abord**, `--pos-v5-ink-muted`, chevron pour déplier le contenu.

#### B3.3 — Réutilise-t-on le suivi tel quel ? Oui pour le rendu, **non pour l'emplacement du code**

À réutiliser **sans en réécrire une ligne** (ils sont justes, et ils ont été payés cher) :
`nomProduit` (`PosOrdersTrackerComponent.vue:2437-2441`), `resumeComposition` (`:2446-2470`),
`compoAffichee` + la constante **exportée** `BUDGET_COMPO = 58` (`:1010`, `:2301-2334`),
`itemsPreview`/`extraItemsCount` (`:2409-2416`), `aDuContenuAVoir` (`:2489-2499`),
`customerLabel`/`customerPhone` (`:2380-2408`), `elapsedShort` (`:2553-2564`), `sourceIcon` (`:2369-2378`).

**Mais ce sont aujourd'hui des `methods:` d'un composant.** Les recopier dans le tiroir garantit la
divergence au premier correctif : la troncature « +N » a déjà été corrigée deux fois
(`FIX-6/A-006` puis `A-016`, commentaires `:352-366` et `:2303-2310`) et un doublon aurait raté la
seconde. **Exigence** : les extraire dans un module partagé (`resources/js/support/compositionCommande.js`)
que le suivi **et** le tiroir importent. C'est la condition de la réutilisation, pas un détail.

**Ce que je ne réutilise pas** : les **pastilles** produit du panneau borne actuel
(`.kiosk-cash-item-pill`, `PosComponent.vue:1937-1944`). Capture 02, commande A0010 :
« 2× Coca-Cola 33cl » « 1× Hawaï 33cl » « 1× Oasis Tropical 33cl » « +1 autres » — trois **boissons**
affichées et **le plat caché** derrière « +1 autres », **sans aucune composition**. Deux « Tacos M »
commandés différemment y sont strictement identiques : c'est *littéralement* le défaut que le
propriétaire décrit. Les pastilles perdent la relation quantité→produit→options que les lignes
préservent. **Rejet des pastilles, partout.**

> **Référence externe** (non vérifiable dans ce dépôt) : sur les afficheurs équipiers **McDonald's**,
> le **numéro** est l'élément le plus grand de la fiche, plus grand que les noms de produits, parce que
> c'est le seul jeton que le client prononce. Je m'en sers pour **un seul choix** : `N°` en 22 px / 900,
> au-dessus et plus gros que les produits — l'inverse de la carte du suivi actuelle, où le numéro et le
> nom du produit sont proches en poids visuel (capture 04).

---

### B4 — Le rang cuisine : « 2ᵉ sur 4 », et il compte bien les ACCEPT

**Forme retenue : « 2ᵉ sur 4 en cuisine ».** Contre « 2 devant » : ambigu (est-ce que la mienne est
comptée ?), et il ne donne pas la profondeur totale de la file — or c'est la profondeur qui permet
d'annoncer une attente. Contre « ≈ 8 min » : voir §A. « 2ᵉ sur 4 » est auto-vérifiable : le caissier
voit la position **et** le total, il peut recouper avec l'onglet 🍳 4.

**Faut-il compter les ACCEPT non encore PREPARING ? OUI — et le contraire serait un bug.**
Vérifié : `KitchenReleaseRule::visibleStatuses()` (`app/Domain/Kds/KitchenReleaseRule.php:16-23`) rend
visibles au KDS **ACCEPT, PREPARING et PREPARED**, et `itemBoardStatuses()` (`:28-34`) retient
**ACCEPT + PREPARING** pour le tableau par article. Une commande à ACCEPT est donc **sur l'écran du chef
et devant vous dans la file**. L'exclure sous-estimerait l'attente — exactement la plainte du propriétaire.

**Prédicat exact du rang, miroir strict du serveur** (ne pas improviser) :

```
en_cuisine(o) ⟺  statut(o) ∈ {ACCEPT, PREPARING}                  ← visibleStatuses() moins PREPARED
             ET  paiement libéré pour le tableau                   ← isReleasedForBoard(), :100-112
             ET  non remboursée                                    ← miroir de :1337 du suivi
rang(o)      =  1 + |{ x : en_cuisine(x) ET created_at(x) < created_at(o) }|
```

`isReleasedForBoard()` (`KitchenReleaseRule.php:100-112`) admet `PAID`, `PENDING_COUNTER`, et
`POS + CASH`. **Un `status === PREPARING` naïf serait faux** : le suivi range toute commande
cash-pending dans la voie `accept` **quel que soit son statut cuisine**
(`PosOrdersTrackerComponent.vue:1362-1365` : `if (isCashPending(o) && !isTerminalStatus(s)) { buckets.accept.push(o); continue; }`).
C'est précisément pourquoi la capture 04 affiche « EN PRÉPARATION 1 » pendant que 4 commandes cuisent (DEF-4).
Le tiroir doit calculer **la file du chef**, pas recopier le bucket de l'écran de suivi.

**La commande « à encaisser » qui cuit déjà (borne, Plan B) : dans LES DEUX files, avec badge.**
Ce n'est pas un arbitrage esthétique, c'est une lecture de la règle. `isReleasedForBoard()` admet
explicitement `PENDING_COUNTER`, et son commentaire (`:87-91`) le dit mot pour mot :
« the Plan B kiosk→counter encashment flow (config `kiosk.payment_route_all_to_counter`), where the
**kitchen starts preparing while the customer pays at the till** ». Elle **cuit vraiment**.

- La retirer de 💶 → on perd l'argent de vue (le défaut d'origine).
- La retirer de 🍳 → **on falsifie le rang de toutes celles derrière** : la 4ᵉ se croit 2ᵉ. C'est le pire
  des deux, parce que c'est une erreur *silencieuse* qui contamine les autres cartes.

Donc : dans 💶 avec un badge `🍳 2ᵉ sur 4 en cuisine`, et dans 🍳 avec un badge `🔔` (à encaisser).
**Conséquence à assumer dans le dessin** : les compteurs d'onglets **ne s'additionnent pas** au nombre
de commandes. Le tiroir ne doit donc **jamais** afficher un total agrégé (« 9 commandes ») qui inviterait
à faire la somme — c'est un rejet explicite, cf. §C.

> **Référence externe** (non vérifiable dans ce dépôt) : les KDS multi-postes contemporains
> (**Toast**, **Square KDS**) affichent une même commande simultanément sur plusieurs tableaux
> (poste chaud / expéditeur) sans jamais publier de total agrégé des tableaux, pour cette raison exacte.
> Et **Square KDS** compte le temps **écoulé** depuis la prise de commande avec des seuils de couleur —
> il n'affiche pas d'estimation. Je m'en sers pour **deux choix** : la double appartenance avec badge,
> et l'âge mesuré plutôt que l'attente prédite (seuils 10/20 min de la §B3.1).

---

### B5 — Les commandes d'autres jours (DEF-3) : ni cachées, ni mélangées — annoncées, datées, en bas

Le défaut est côté service : `admin/pos/counter-collect/pending` (`routes/api.php:1009`) n'a **aucun
filtre de date** et trie `->orderBy('created_at')` **croissant** (`routes/api.php:1066`), plafond 200.
Une file triée « le plus ancien d'abord » sur **tout l'historique** met donc mécaniquement une commande
morte en tête. Captures 01 et 02 : A0010 et A0011, **20:09 d'un autre jour**, occupent les deux
premières lignes ; la commande téléphone du jour est reléguée derrière « Voir plus (1) ». Le suivi, lui,
en compte **584 en souffrance** (capture 04).

**Les cacher** : non — ce sont des ventes non encaissées, et `/admin/encaissement` est la file all-time
légitime. **Les montrer avec la date dans la file du jour** : non — 584 lignes noieraient les 3 du jour.

**Réutiliser le motif qui existe déjà et qui a raison.** Le suivi a résolu ce problème exact :
une pastille ambre « N en souffrance » (`PosOrdersTrackerComponent.vue:57-68`, `data-testid="tracker-stale-pill"`)
qui ouvre un panneau daté et **hors des voies** (`:625-670`), avec le motif écrit dans le code
(`:169-175`) : « sans les rapatrier dans les colonnes, ce qui écraserait le signal du jour et volerait
leurs cartes aux voies ». Le tiroir applique la même règle, en **pied** de l'onglet 💶 :

```
────────────────────────────────────────────────
⏳  584 plus anciennes, avant aujourd'hui
    Ouvrir l'encaissement  →                     48 px, tabulaire
────────────────────────────────────────────────
```

Jamais en tête, jamais mêlées, jamais tues. Le tiroir affiche **la journée de service** ; le
`from_date`/`to_date` de `admin/pos-order` (BRIEF §Chaîne d'alimentation) le permet sans requête de plus.

---

### B6 — Bouton d'ouverture, badge, et le sort de « Suivi commandes »

**Emplacement** : cluster « Commandes » (`PosComponent.vue:173-180`), **en première position**, avant
« Encaissement ». C'est le seul bouton du cluster qui n'entraîne aucune navigation : il mérite la place
que l'œil balaie en premier.

**Libellé** : `Commandes` (+ pictogramme `📋`). Pas « Contrôle » (jargon), pas « Barre de contrôle »
(nom de projet, pas nom d'objet), pas « Tiroir » (déjà pris par le tiroir-caisse, `Ouvrir tiroir`,
capture 01 — collision de vocabulaire à bannir).

**Badge : deux pastilles, jamais trois.** Le brief propose « 4 🍳 · 2 🛎️ · 3 💶 ». Rejeté, mesuré :
`.pos-v5-btn__badge` est une pilule de 22 px de haut, 11 px de police, `min-width: 22px`
(`PosV5Button.vue:195-210`). Cette chaîne fait ~17 caractères dont 3 émojis → ~120-130 px de pilule sur
un bouton qui porte déjà une icône et un libellé, dans une barre qui **s'empile déjà en deux rangs
sous 1439 px** (`resources/css/pos-v5.css:144-153`). C'est un troisième rang garanti à 1280 px, donc
DEF-7 aggravé par la chose censée le corriger.

Retenu — le slot `badge` existe (`PosV5Button.vue:20-21`), le type accepte `String` (`:89`) :

```
📋 Commandes  [ 3 💶 ][ 2 🛎️ ]      ← 2 pilules, ≈ 72 px au total
```

**Deux chiffres, jamais un seul.** Ils ne sont pas de même nature : `3 💶` = de l'argent non encaissé,
`2 🛎️` = un client debout qui attend son sac. Les additionner en « 5 » (ce que fait le badge actuel du
suivi, `activeOrdersStats.active`, `PosComponent.vue:4601-4612`) produit un nombre auquel aucune action
ne correspond. **Le compteur cuisine n'est pas sur le bouton** : il est sur le ticket (P4), là où le
regard est déjà posé pendant la saisie. Ton `ready` (halo vert pulsant, mécanisme déjà en place
`:235`) dès que 🛎️ > 0.

**« Suivi commandes » doit-il ouvrir le tiroir ? OUI — et il doit rester un lien.**
Aujourd'hui c'est un `router-link` (`PosComponent.vue:229-241`) vers `admin.pos-orders.tracker`, dont la
route dans le paquet caisse est un **stub qui force `window.location.assign`** (`resources/js/pos-app.js:126-130`) :
rechargement complet, ~4,1 s mesurées (DEF-5). Deux boutons qui veulent tous deux dire « voir les
commandes » sont *la* définition du « je me perds ».

Recommandation précise, qui ne casse ni les accès ni les bancs :

- Le bouton **reste** un `router-link`, **même `:to`, même `data-testid="pos-tracker-open"`** — les specs
  qui vérifient sa présence et son `href` restent vertes, et le clic-milieu / Ctrl-clic ouvre toujours la
  page complète dans un onglet.
- On **intercepte le clic simple** : `@click` qui laisse passer `metaKey/ctrlKey/shiftKey` et le bouton
  non-gauche, et qui sinon `preventDefault()` + ouvre le tiroir.
- Le tiroir porte en pied : `Ouvrir la page complète →` (filtres par canal, recherche, ticket promo,
  sortie stock, remboursement, en souffrance, écran client — tout ce que le tiroir ne fera pas).
- **Seul banc à casser** : celui qui affirme « clic → navigation vers /admin/pos-orders-tracker ».
  C'est exactement le comportement que le propriétaire demande de changer. À nommer dans le commit.

**Les « Voir plus » des raccourcis** (`PosComponent.vue:519`, `:617-623`) ouvrent le tiroir sur le bon
onglet au lieu de naviguer / d'ouvrir l'ancien panneau.

**L'ancien panneau borne reste**, testids conservés (`kiosk-cash-open` `:186`) : c'est un chemin de repli
et une masse de bancs. Mais il n'est plus l'entrée principale ; **candidat au retrait dans un second
temps**, une fois le tiroir éprouvé en service — pas dans le même lot.

---

### B7 — Clavier, focus, a11y

```
role="dialog"  aria-modal="true"  aria-labelledby="pos-drawer-title"
```

- **Ouverture** : le focus va sur le titre `<h2 tabindex="-1">`, pas sur la croix (le premier lecteur
  d'écran doit entendre « Contrôle des commandes », pas « Fermer »).
- **Échap** : ferme, **et rend le focus au bouton d'ouverture**. À implémenter sur la racine du tiroir
  (`@keydown.esc`), **pas** en écouteur global : la caisse a des champs de saisie (client, téléphone,
  recherche produit) et un écouteur `document` volerait des touches.
  **À noter** : le panneau borne actuel n'a **ni Échap ni piège de focus** — l'overlay ne porte que
  `@click.self` (`PosComponent.vue:1884`) ; les seuls `@keydown.esc` du fichier sont ailleurs
  (`:1270`, `:2040`). Le tiroir corrige, il ne copie pas.
- **Piège de focus** : Tab cycle dans le tiroir. Ordre : titre → onglets (un seul tabulable, flèches ←→
  entre onglets, motif ARIA APG) → cartes → pied → croix.
- **Clic sur le voile** : ferme (cohérent avec `:1884`).
- **`aria-live="polite"` sobre** : une seule région, en pied, qui n'annonce **que** les changements de
  compteurs, phrase entière, au plus une fois toutes les 10 s :
  `« 4 à encaisser, 2 prêtes »`. Ne jamais annoncer l'arrivée de chaque carte : à 5 s de cadence
  (`PosComponent.vue:4151-4155`, `_kioskPollingInterval()` renvoie 5000 sans socket), ce serait un
  bavardage continu.
- **Aucune cible < 44 px.** Attention au jeton : `--pos-v5-tap-min: 40px` est commenté
  « WCAG AA minimum » (`resources/css/foundations/pos-v5-tokens.css:166`) — **c'est faux dans les deux
  sens** (WCAG 2.5.5 AAA = 44 px ; WCAG 2.2 SC 2.5.8 AA = 24 px). Utiliser
  **`--pos-v5-tap-comfort` (48 px)** pour tous les CTA du tiroir, et 44 px comme plancher absolu.
  Pour mémoire, l'existant est très en dessous : `.pos-shortcuts__cta` = `padding: 6px 12px` +
  `font-size: 12px` (`PosComponent.vue:7100-7110`) ≈ **26 px de haut**.
- **Aucune information portée par la seule couleur** : chaque canal a fond + liseré + **pictogramme de
  forme distincte** + nom accessible — la doctrine est déjà écrite dans ce dépôt
  (`PosOrdersTrackerComponent.vue:3244-3249`), on l'applique.
- **Mouvement** : les tokens neutralisent déjà les durées sous `prefers-reduced-motion`
  (`pos-v5-tokens.css:191-198`). Rien à ajouter, rien à contourner.

---

### B8 — États vides, fraîcheur, et « temps réel coupé »

**Un état vide ne doit jamais affirmer le calme sans dater sa mesure.** C'est le mandat Q10 déjà acquis
(« distinguer une période calme d'une panne de polling », `PosComponent.vue:449-456`). Chaque onglet vide :

```
              ✓
   Aucune commande à encaisser.
      Vérifié il y a 4 s
```

Libellés déjà présents : `pos.tracker.empty_accept`, `empty_preparing`, `empty_prepared`,
`empty_delivered` (`resources/js/languages/fr.json`) ; horodatage par `_formatLastRefresh`
(`PosComponent.vue:4685-4698`) et ses trois clés `label.pos_shortcut_last_refresh_*`.
**Aucun compteur ni minuterie de plus** : `_lastRefreshTick` est déjà incrémenté toutes les 5 s
(`PosComponent.vue:3383-3388`) et fait re-rendre les libellés ; les « il y a X min » des cartes s'y accrochent.
C'est ce qui rend tenable le « zéro requête propre » du brief.

**« Temps réel coupé » : surtout pas de bandeau rouge.** En production il n'y a **aucun serveur de
sockets** : `_kioskPollingInterval()` renverra donc 5000 en permanence (`PosComponent.vue:4151-4155`).
Un bandeau « temps réel perdu » serait allumé **toute la journée, tous les jours** — il deviendrait du
papier peint, et c'est le défaut que le suivi a déjà dû corriger en le masquant en dev
(`PosOrdersTrackerComponent.vue:160-171`). Ne pas monter non plus `PosSystemHealthPill` dans le tiroir :
il a **sa propre boucle de 45 s** (`PosSystemHealthPill.vue:171`), ce qui violerait « zéro requête propre ».

À la place, **une seule ligne de fraîcheur en pied**, qui dit la vérité en escalade :

| Âge du dernier rafraîchissement réussi | Rendu |
|---|---|
| < 30 s | `Vérifié il y a 4 s` — 11 px, `--pos-v5-ink-muted` |
| 30–89 s | `Vérifié il y a 42 s` — `--pos-v5-warning` |
| ≥ 90 s | `⚠ Données figées depuis 2 min — vérifiez le réseau` + bouton **Actualiser** (48 px), `--pos-v5-danger` |

Un seul signal, toujours vrai, jamais crié. Et il ne s'invente pas un état : il lit `lastCashRefresh` /
`lastReadyRefresh`, déjà estampillés **uniquement dans le chemin de succès** (`:4808`, `:5069`) — un
échec ne réestampille pas, ce qui est exactement ce qu'il faut pour que la panne remonte.

---

### B9 — P4 sur le ticket en cours : où, exactement

Dans l'en-tête du panier, sous « Commande en cours » (`PosComponent.vue:948-952`), au-dessus du
sélecteur client — **une seule ligne, 32 px**, présente même à panier vide (c'est là qu'on prend la
commande, donc là qu'on annonce l'attente) :

```
🍳 4 en cuisine · la plus ancienne depuis 14 min · vous serez le 5ᵉ
```

12 px / 700 sur `--pos-v5-info-soft`, liseré gauche 3 px `--pos-v5-info`, chiffres tabulaires.
À zéro : `🍳 Cuisine libre` en `--pos-v5-ink-muted` — jamais masqué, sinon son absence est ambiguë
(rien en cuisine, ou bloc cassé ?). Tapable → ouvre le tiroir sur 🍳.

**Attention à `#pos-cart` (§B1, point 2)** : cet en-tête cache déjà 54 % de lui-même à 720 px
(`goal-caisse-b014-defilement-decouvrable.spec.js`). Ces 32 px doivent être pris **au-dessus** de la
zone défilante, en `flex-shrink: 0` collant — sinon on ajoute la ligne la plus utile de l'écran dans la
partie qu'on ne voit jamais.

---

### B10 — Système visuel : ce qu'on réutilise, et les deux choses à déplacer

**Jetons (`resources/css/foundations/pos-v5-tokens.css`), aucune couleur en dur :**

| Usage | Jeton |
|---|---|
| Fond tiroir / cartes | `--pos-v5-bg-panel` (#FFFFFF) |
| Fond onglets, lignes compactes | `--pos-v5-bg-subtle` (#F7F3EC) |
| Bordures | `--pos-v5-border` (#EEE6D9) / `--pos-v5-border-strong` |
| Texte | `--pos-v5-ink` / `--pos-v5-ink-soft` / `--pos-v5-ink-muted` |
| **À encaisser** (accent + CTA) | `--pos-v5-brand-red` #F4501E, `--pos-v5-brand-red-soft`, `--pos-v5-shadow-cta` |
| **Prêtes** (accent + CTA « Livré ») | `--pos-v5-success` #1B8A3A, `--pos-v5-success-soft` |
| **En cuisine** (accent, sans CTA) | `--pos-v5-info` #2563EB, `--pos-v5-info-soft` |
| **Livrées** (sourdine) | `--pos-v5-ink-muted` sur `--pos-v5-bg-subtle` |
| Retard 10 / 20 min | `--pos-v5-warning` #B8730B / `--pos-v5-danger` #C21E2F |
| Rayons / ombres / durées / z | `--pos-v5-radius-md|xl`, `--pos-v5-shadow-modal`, `--pos-v5-duration-base`, `--pos-v5-z-modal` |
| Cibles tactiles | **`--pos-v5-tap-comfort` (48 px)**, jamais `--pos-v5-tap-min` (40 px, cf. §B7) |
| Chiffres | `.pos-v5-tabular` (`pos-v5-tokens.css:236-240`) sur tous les N°, montants, âges, rangs |

**Couleurs par canal : elles existent, mais elles ne sont pas atteignables.**
Les 6 variantes sont bien définies — `--pos` #DEE2E6, `--kiosk` #C3CEFF, `--online` #FDECEA,
`--phone` #BFEBD3, `--platform` #FFE8CC, `--delivery` #E4CCFF, chacune avec son liseré
(`PosOrdersTrackerComponent.vue:3250-3258`), et le raisonnement qui les a produites est écrit juste
au-dessus (`:3229-3249`). **Mais ce bloc est dans un `<style scoped>`** (`:2718`) : un nouveau composant
ne peut pas les utiliser. **Exigence** : promouvoir ces 6 classes (+ les 6 pictogrammes de `sourceIcon`,
`:2369-2378`) dans `resources/css/pos-v5.css`, et faire pointer le suivi dessus. Sinon deux jeux de
couleurs de canal cohabiteront, ce qui détruit la seule chose qu'un code couleur doit garantir.

**Deuxième anomalie, gratuite à corriger** : les panneaux raccourcis peignent avec
`var(--pos-v5-surface, #fff)`, `var(--pos-v5-text, #1a1a1a)`, `var(--pos-v5-surface-2, #faf6f1)`
(`PosComponent.vue:6963`, `:6995`, `:7018`) — **ces trois jetons n'existent nulle part** (vérifié :
aucune définition de `--pos-v5-surface:` ni `--pos-v5-text:` dans `resources/`). Ils tombent donc
toujours sur leurs valeurs de repli, qui ne sont **pas** celles du DS (`#faf6f1` vs
`--pos-v5-bg-subtle` #F7F3EC ; `#eadfd2` vs `--pos-v5-border` #EEE6D9). Le tiroir ne doit pas hériter
de cette dérive ; les panneaux devraient être réalignés dans le même lot.

**Clair uniquement**, comme tout le POS (`.pos-v5-shell`, `pos-v5-tokens.css:214-224`). Aucun mode sombre.

---

### B11 — DEF-7 : ce que je fais des panneaux raccourcis (mandat propriétaire)

**Ce que dit le mandat, mot pour mot** (`PosComponent.vue:441-447`, 2026-05-21) : « Caissier ne doit PAS
naviguer vers /admin/pos-orders-tracker pour valider commandes prêtes ou encaisser borne. Sur page
principale POS, 2 zones notifications compactes. » Complété par Q10 (`:449-456`) : toujours rendues, même
vides, parce qu'un panneau vide est un **signal de santé**.

**Fait mesuré que le brief ne dit pas** : il n'y a plus 2 panneaux mais **jusqu'à 4** dans la source —
`pos-shortcuts-ready` (`:470`), `pos-shortcuts-cash` (`:534`), `pos-shortcuts-web` (`:641`),
`pos-shortcuts-web-paid` (`:718`). Les deux derniers sont **postérieurs au mandat** (2026-07-13 et
2026-08-10) et ne sont donc pas couverts par « 2 zones ». Chacun fait `flex: 1 1 320px; min-width: 280px`
(`:6960-6962`) : dans les 904 px de l'aire principale à 1280 px, **deux tiennent par rang**, donc
3 ou 4 panneaux = **un second rang**, soit ~+120 px pris au catalogue.

**Mes trois réponses, par ordre de coût :**

**(1) Sans aucune permission — les deux panneaux mandatés restent, à l'identique.** Rien n'est retiré,
même hauteur, mêmes testids. Deux changements seulement : « Voir plus » ouvre le **tiroir** (plus de
navigation), et chaque ligne gagne **l'unique information qui manque pour reconnaître un client** — le
nom s'il est connu, sinon le premier produit. C'est DEF-2 corrigé sans toucher au mandat.

**(2) Le vrai gain DEF-7, hors mandat — replier les DEUX panneaux web dans le tiroir.**
« Commandes web à traiter » → onglet 💶 ; « Web payées — en cuisine » → onglet 🍳. Gain : le second rang
de panneaux disparaît (~120 px), et l'aire produits remonte au-dessus de la ligne de flottaison.
**Condition non négociable** : le panneau « Web payées » existe parce qu'une commande de 31,40 € est
passée « sans un bruit » (`:706-713`). Le replier ne doit pas la remuter. Donc : le bouton `📋 Commandes`
doit prendre le ton `ready` et le chemin de notification existant `_notifyPolledNewOrders`
(`:4813`) doit rester **strictement intact**. Sans cette garantie, **ne pas replier**.

**(3) Passer de 4 à 2 lignes par panneau — seulement avec une porte propriétaire explicite.**
Gain ≈ 60 px par panneau (une ligne ≈ 26-28 px + gouttière). **Ce changement fait rougir un banc** :
`tests/js/sentinels/posShortcutsSentinel.spec.js:86-89` exige au moins deux occurrences de
`.slice(0, 4)` dans le fichier. C'est un mandat propriétaire matérialisé, pas un détail de test :
il faut la porte **et** la mise à jour du banc dans le même commit.

**Ce que je refuse : rendre les panneaux repliables.** C'est la seule modification que le mandat
interdit *dans son esprit*. Q10 existe pour qu'un panneau **vide** reste visible et prouve que la
boucle tourne ; un panneau **replié** est indiscernable d'un panneau replié-et-cassé. On rétablirait
exactement le défaut que Q10 a corrigé, et on le rétablirait **caché**, ce qui est pire.

**Et l'os le plus gros n'est pas là.** Capture 01 : la barre d'actions porte **12 boutons sur 2 rangs**
avant le premier panneau, et `.pos-v5-operator-bar` a `min-height: 80px` (`pos-v5.css:78`) puis s'empile
sous 1439 px (`:144-153`). Le tiroir en rend 3 redondants *depuis l'écran principal* : `Encaissement`
(`PosComponent.vue:196-206`), `Écran client` (`:281-291`), `Historique/Archives` (`:266-276`).
**Ne pas les supprimer** — les descendre en pied de tiroir. Un rang de moins dans la barre vaut plus
que toutes les micro-optimisations des panneaux réunies.

---

## C. CE QUE JE REJETTE DU BRIEF, ET POURQUOI

| # | Proposition du brief | Rejet | Motif |
|---|---|---|---|
| C1 | « attente ≈ X min (X = âge de la plus ancienne) » (P4) | **Rejeté** | L'âge du plus ancien n'est pas l'attente du prochain. Aucun modèle de débit cuisine n'existe ici ; la seule sonde temporelle du dépôt (`PosSystemHealthPill.vue:39-45`) mesure du **retard constaté**. Remplacé par 3 faits mesurés (§B9). |
| C2 | Badge « 4 🍳 · 2 🛎️ · 3 💶 » | **Rejeté** | ~120-130 px dans une pilule de 22 px / 11 px (`PosV5Button.vue:195-210`), sur une barre qui s'empile déjà sous 1439 px (`pos-v5.css:144-153`) → 3ᵉ rang à 1280 px. On aggraverait DEF-7 avec le remède. Remplacé par 2 pilules (§B6). |
| C3 | Composition en pastilles | **Rejeté** | Le panneau borne actuel le fait (`PosComponent.vue:1937-1944`) et capture 02 montre le résultat : 3 boissons affichées, le plat derrière « +1 autres », **zéro composition**. C'est le défaut décrit par le propriétaire. Lignes + composition (§B3.1). |
| C4 | Colonnes dans le tiroir | **Rejeté** | 4 colonnes dans 420 px = 99 px. Ni un N° en 22 px ni « Poulet mariné » n'y tiennent. |
| C5 | Sections empilées | **Rejeté** | 11 cartes du service semé ≈ 1 700 px dans 476 px utiles → 30 % de l'état visible, sans dire ce qui manque. Les onglets, eux, affichent les 4 compteurs en permanence. |
| C6 | « Recherche instantanée (N°, nom, produit) » | **Rejeté en V1** | Coûte 44-52 px permanents (8-11 % de la liste) pour une saisie clavier plus lente que le balayage de 3-5 cartes. La recherche existe déjà là où elle sert (page de suivi, capture 04 ; `pos.tracker.search_placeholder`), atteignable en pied de tiroir. **À rouvrir** si la file 💶 du jour dépasse ~10 régulièrement. |
| C7 | « raccourci clavier » pour ouvrir le tiroir | **Rejeté** | Caisse tactile ; CLAUDE.md §3ter rappelle que `keyboard.press('F1'..'F12')` est **inerte sans affichage** — un raccourci non affiché est un raccourci qui n'existe pas. Et un écouteur `document` volerait des touches aux champs client/téléphone/recherche. Seul **Échap** est conservé (obligation a11y, §B7). |
| C8 | Tiroir en **split** (poussant la caisse) | **Rejeté** | Reflow des tuiles (mémoire spatiale) + rétrécissement d'un panier qui cache déjà 54 % de son en-tête (`goal-caisse-b014-defilement-decouvrable.spec.js`). §B1. |
| C9 | Barre **permanente** à droite (mots du propriétaire) | **Contesté, arbitrage propriétaire** | 360 px permanents en plus des 400 px du panier = catalogue à ~540 px sur 1280. Contradiction frontale avec DEF-7. §A. |
| C10 | Un total agrégé du tiroir (« 9 commandes ») | **Rejeté** | Une commande à encaisser qui cuit est comptée dans 💶 **et** 🍳 (§B4). Un total inviterait à une somme fausse. Aucun total, jamais. |
| C11 | Réutiliser les helpers du suivi **par recopie** | **Rejeté** | `resumeComposition`/`compoAffichee` sont des `methods:` d'un composant ; la troncature « +N » a déjà été corrigée deux fois (`:352-366`, `:2303-2310`). Un doublon aurait raté la seconde. Extraction en module partagé exigée (§B3.3). |
| C12 | « composition dépliable » réservée aux Livrées | **Amendé** | Le repli doit exister sur **toutes** les cartes via le panneau « Voir tout » **qui existe déjà** (`:365-390`), pas comme un mécanisme neuf propre à un onglet. |

**Deux exigences que le brief ne pose pas et qui conditionnent le résultat** :
promouvoir les 6 classes de canal hors du `<style scoped>` du suivi (§B10) ; et ne pas hériter des
3 jetons fantômes `--pos-v5-surface` / `--pos-v5-text` / `--pos-v5-surface-2` (§B10).

---

## D. LIBELLÉS FR EXACTS (aucun anglais)

**Déjà présents dans `resources/js/languages/fr.json` — à réutiliser tels quels, ne pas recréer :**

| Clé | Texte |
|---|---|
| `pos.tracker.col_accept` | À encaisser |
| `pos.tracker.col_preparing` | En préparation |
| `pos.tracker.col_prepared` | Prêts à servir |
| `pos.tracker.col_delivered` | Livrés |
| `pos.tracker.cash_due_label` | À encaisser |
| `pos.tracker.empty_accept` | Aucune commande à encaisser. |
| `pos.tracker.empty_preparing` | Aucune commande en cuisine. |
| `pos.tracker.empty_prepared` | Aucune commande prête. |
| `pos.tracker.empty_delivered` | Aucune commande livrée pour l'instant. |
| `pos.tracker.source_pos` / `_kiosk` / `_online` / `_phone` / `_platform` / `_delivery` | Caisse / Borne / En ligne / Téléphone / Plateforme / Livraison |
| `pos.tracker.now` | à l'instant |
| `pos.tracker.compo_more_aria` | Voir la composition complète de cet article |
| `label.pos_shortcut_cash_cta` | 💳 Encaisser |
| `label.pos_shortcut_delivered_cta` | ✓ Livré |
| `label.pos_shortcut_last_refresh_now` / `_sec` / `_min` | Mis à jour à l'instant / il y a {seconds}s / il y a {minutes} min |
| `label.cancel` | Annuler |

**Nouveaux libellés — texte définitif :**

| Emplacement | Texte |
|---|---|
| Bouton d'ouverture | `Commandes` |
| Infobulle du bouton | `Voir les commandes à encaisser, en cuisine, prêtes et livrées — sans quitter la caisse` |
| Titre du tiroir | `Contrôle des commandes` |
| Bandeau ticket conservé | `Votre ticket en cours est conservé — {n} articles · {montant}` |
| Onglet 1 (2 lignes) | `À encaisser` |
| Onglet 2 | `En cuisine` |
| Onglet 3 | `Prêtes` |
| Onglet 4 | `Livrées` |
| Sous-titre onglet 1 | `Paiement au comptoir — borne, caisse, téléphone, web` |
| Sous-titre onglet 2 | `Dans l'ordre où la cuisine les prépare` |
| Rang cuisine (1ᵉʳ) | `1ᵉʳ sur {total} en cuisine` |
| Rang cuisine (autres) | `{rang}ᵉ sur {total} en cuisine` |
| Âge | `il y a {n} min` · `il y a {h}h{mm}` · `à l'instant` |
| Heure de commande | `commandée à {HH:MM}` |
| Badge « aussi à encaisser » | `À encaisser` (infobulle du 🔔) |
| Compteur ticket (P4) | `{n} en cuisine · la plus ancienne depuis {m} min · vous serez le {rang}ᵉ` |
| Compteur ticket, à zéro | `Cuisine libre` |
| Ligne « plus anciennes » | `{n} plus anciennes, avant aujourd'hui` |
| Lien associé | `Ouvrir l'encaissement` |
| Lien pied de tiroir | `Ouvrir la page complète` |
| Articles en trop | `+ {n} articles` |
| Voir le détail | `Voir tout` (existant) |
| Fraîcheur | `Vérifié il y a {n} s` · `Vérifié il y a {n} min` |
| Données figées | `Données figées depuis {n} min — vérifiez le réseau` |
| Bouton de secours | `Actualiser` |
| Fermer | `Fermer` (`aria-label`) |
| Annonce `aria-live` | `{n} à encaisser, {m} prêtes` |
| Vide, onglet Livrées + filtre | `Aucune commande livrée pour l'instant.` |

**Vocabulaire interdit** (collisions ou anglicismes) : *Contrôle* seul comme libellé de bouton ;
*Tiroir* (déjà pris par `Ouvrir tiroir`, le tiroir-caisse, capture 01) ; *Queue*, *Rush*, *Board*,
*Live*, *Pending*, *Ready*, *Drawer*, *Tracker*. Le libellé visible reste `Suivi commandes` pour le
bouton existant : le propriétaire le connaît, on ne renomme rien.

---

## Résumé exécutif

1. **P2 adopté** : tiroir en **recouvrement** au bord droit, **420 px**, **onglets** dont la barre est le
   tableau de bord permanent, **deux densités de carte** selon la question posée.
2. **P4 adopté, chiffre remplacé** : jamais d'attente prédite ; trois faits mesurés sur le ticket.
3. **La découverte qui change une règle** : `KitchenReleaseRule::isReleasedForBoard()`
   (`app/Domain/Kds/KitchenReleaseRule.php:100-112`) admet `PENDING_COUNTER` — une borne à encaisser
   **cuit vraiment**. Elle va dans **les deux** files, et le tiroir n'affiche **aucun total**.
4. **Le rang se calcule sur la règle serveur**, pas sur le bucket du suivi (`:1362-1365`), qui est
   précisément la cause de DEF-4.
5. **Le vrai gain DEF-7** n'est pas de rogner les 2 panneaux mandatés : c'est de replier les **2 panneaux
   web** (hors mandat) et de descendre **3 boutons** de la barre d'actions dans le pied du tiroir.
6. **Trois arbitrages appartiennent au propriétaire** : la barre permanente contre le tiroir (§A) ;
   le passage de 4 à 2 lignes dans les panneaux, qui rougit
   `tests/js/sentinels/posShortcutsSentinel.spec.js:86-89` (§B11-3) ; le repliement des panneaux web,
   conditionné au maintien de l'alerte sonore (§B11-2).

# V6 — FICHE COMMANDE + IMAGES (2026-07-29, backlog S2 vidé)

Reprise des deux défauts laissés ouverts dans ma voie par la vague 4.

## 1. Fiche commande `/admin/pos-orders/show/{id}` — CORRIGÉE
Reproduit sur les **3** types de commande (à emporter #6037, caisse #5715, borne #5853).

| Défaut observé | Racine (mesurée) | Correctif |
|---|---|---|
| Badges de statut **VIDES** sur une commande annulée/remboursée | `orderStatusEnumArray` omettait PENDING/CANCELED/REJECTED ; `paymentStatusEnumArray` omettait PENDING_COUNTER/REFUNDED. Le *sélecteur* n'est volontairement pas exhaustif (annulation et remboursement passent par leurs modales) — mais la **carte d'affichage** avait été calquée dessus | les 2 cartes couvrent tout l'enum → « Remboursé » + « Annulé » visibles |
| « Mayonnaise: » **sans valeur** | l'instantané NF525 **inverse les rôles** : `variation_name` porte le CHOIX et `attribute_name` l'intitulé, l'inverse de l'ancienne forme lue en brut | passage par `normalizeReceiptVariations` — le normaliseur **déjà** utilisé par le ticket (une seule vérité, DISCIPLINE §9) |
| « Extras: **,** » (virgule orpheline) | l'instantané nomme les extras `extra_name`, pas `name` → deux entrées anonymes séparées par la virgule | `normalizeReceiptExtras` (il écarte les entrées sans nom) + quantité affichée si > 1 |
| Ligne « Instruction: » **vide sur chaque article** | `instruction` vaut **NULL** en base, or le garde testait `!== ''` — toujours vrai | garde sur le contenu réel (`hasInstruction`) |
| Bloc « **Informations de livraison** » sur un À EMPORTER et sur une commande BORNE | titre inconditionnel, alors que l'adresse elle-même est déjà conditionnée à la livraison | titre « Informations client » hors livraison (`isDeliveryOrder`) |
| « **Heure de livraison** » sur un à emporter | idem — c'est le créneau de RETRAIT | libellé selon le type + ligne masquée si aucun créneau (`label.pickup_time` ajouté FR/EN) |
| Texte « **avat** » au milieu de la fiche | `<img :src="orderUser.image">` avec `image` vide chez un client de passage → texte alternatif rendu | avatar rendu seulement s'il existe, sinon pastille icône |
| Ligne e-mail vide (icône seule) | pas de garde sur `orderUser.email` | garde ajoutée |

**Preuve visuelle** : `tests/captures/goal-s2-v6-2026-07-29/fiche-6037-emporter.png` (LUE) —
« Remboursé / Annulé », « Heure de retrait », « Sauce (1ère Gratuite) : Mayonnaise »,
« Extras : Cheddar, Viande supplémentaire », « Informations Client », aucune ligne vide.
**Régression** : `tests/js/posOrderShowComposition.spec.js` (6 tests, dont la compatibilité
descendante avec l'ancienne forme et la couverture exhaustive des deux enums).

## 2. Cartes « Gestion Produits & Stock » sans NOM — CORRIGÉE
Écran dont le seul rôle est d'activer/désactiver **un produit précis**… et les cartes étaient
**anonymes** (photo + 📷 + « EN STOCK », sans nom).

**Racine mesurée** (et non devinée) : le nom est bien dans le DOM (« Cayenne »), mais
`getBoundingClientRect().width = **0**`. À 3 colonnes la carte offre ~190 px utiles, les
actions sont `flex-shrink-0` (~150 px) et l'image fait 48 px : le nom, **seul élément
flexible**, absorbait tout le manque et se réduisait à néant.

> Fausse piste écartée en chemin : j'ai d'abord soupçonné la minification de supprimer
> `-webkit-box-orient` (bug classique qui écrase un `-webkit-line-clamp`). **Vérifié dans le
> bundle compilé : la propriété survit.** Hypothèse abandonnée avant tout correctif.

**Correctif** : la rangée passe en `flex-wrap` (les actions descendent d'une ligne plutôt que
d'écraser l'identité du produit) + plancher `min-width: 6rem` sur le nom.
**Mesure après** : largeur **132 px**, noms visibles — capture `stock-rupture.png` LUE
(Cayenne, Suprême, Méga, Terminator).

## 3. « Images manquantes » — **RÉFUTÉ** (3 sous-findings)
| Affirmation de la vague 1/4 | Vérification | Verdict |
|---|---|---|
| Tuiles POS et pastilles de catégories en placeholder beige | **85/85 items actifs** ont un `thumb` ET le fichier **présent sur disque** (0 manquant) ; **9/9 catégories actives** ont une image. Les 16 items sans image sont **tous** du bruit de factory (noms latins « voluptatem nam », « quia recusandae »… + 1 réf. de test) | **RÉFUTÉ** pour le menu réel |
| Les 4 cartes Sandwichs ont la **même** image | 4 fichiers **distincts** (empreintes MD5 différentes) : `sandwich-cayenne-maxi/supreme/mega/terminator.webp`. Quatre photos au cadrage semblable, pas un doublon | **RÉFUTÉ** |
| 404 `/storage/7/conversions/frites-thumb.png` | Le fichier **existe** sur disque. Le 404 venait de **ma worktree** sans lien `public/storage` ; le checkout principal sert la même URL en **200** | **RÉFUTÉ — artefact d'environnement** |

**Seul vrai défaut d'image restant** : un `<img alt="flag">` **sans attribut `src`** dans le
sélecteur de langue de l'en-tête admin (pastille vide de 16 px, présente sur toutes les pages).
`layouts/backend/**` = voie CENTRAL → noté en handoff, non touché.

## Vérifications
vitest **2652 / 0 échec** (369 fichiers) · captures relues à 1280×900 · frozen-diff 0.

## 4. Cycle adversarial sur cette vague — mené par moi (l'agent RED est mort sur la limite de session)

| Angle attaqué | Méthode | Verdict |
|---|---|---|
| **Perte d'information sur les commandes anciennes** (le normaliseur écarte les entrées sans nom) | requête DB : **38** `order_items` sans instantané, **0** portant des variations/extras → risque nul sur cette donnée | **RIEN** — et rendu impossible par construction : un supplément non nommable est désormais **annoncé** (« N supplément(s) non identifié(s) ») au lieu d'être masqué |
| **Cartes stock cassées à d'autres largeurs** | sonde géométrique réelle à **1920 / 1440 / 1280 / 390** px | **RIEN** — nom visible partout (207 / 185 / 132 / 254 px), bascule « EN STOCK » cliquable, aucun débordement de carte, bouton photo présent |
| **Bascule de titre au chargement** (`isDeliveryOrder` faux tant que la commande n'est pas chargée → « client » puis « livraison ») | 14 échantillons pendant le chargement de `/admin/pos-orders/show/6037` | **RIEN** — un seul titre observé : « Informations client » |
| **Le nouveau spec passe-t-il par accident ?** | les 3 méthodes testées (`normalizedVariations`, `normalizedExtras`, `hasInstruction`, `unnamedExtrasCount`) **n'existaient pas** avant le correctif : le spec ne peut pas passer contre l'ancien code (erreur de méthode indéfinie). Les assertions portent sur les valeurs exactes (`'Sauce (1ère Gratuite)'` / `'Mayonnaise'`) que la lecture brute rendait vides | **VALIDE** — nuance honnête : le spec couvre les **méthodes**, pas le gabarit ; c'est la capture relue qui atteste le rendu |
| Affirmations du rapport (images) | re-vérifiées par commande : 85/85 fichiers présents, 4 empreintes MD5 distinctes, 200 sur le checkout principal | **TIENNENT** |

Vérifications finales : PHPUnit `Pos|Stock` **846 / 0 échec** · vitest **2653 / 0** (369 fichiers) ·
frozen-diff **0** · captures relues.

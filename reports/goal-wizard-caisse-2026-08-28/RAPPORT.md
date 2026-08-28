# GOAL WIZARD CAISSE — 2026-08-28

Demande propriétaire : sauces incomplètes et dans un ordre différent d'un produit
à l'autre, tuiles trop petites et sans couleur, horaire programmé pénible à
saisir, et impossibilité de dupliquer une ligne du panier avec sa personnalisation.

---

## 1. Ce qui n'allait pas — mesuré, pas supposé

Chaque article porte **sa propre copie** des sauces dans `item_variations`. Ces
copies avaient divergé. Relevé sur les **59 articles vendables** (`status = 5`,
non supprimés) le 2026-08-28 **avant** correction — **cinq profils différents** :

| Profil | Articles | Contenu |
|---|---|---|
| 1 | 12 | 13 sauces, « Sans sauce » incluse |
| 2 | 2 | 12 sauces, **sans** « Sans sauce », ordre A (Galette Normale, Chicken Burger) |
| 3 | 1 | 12 sauces, **sans** « Sans sauce », ordre B (Tacos M) |
| 4 | 2 | **2 sauces seulement** (Bol Frites, Bol Riz) |
| 5 | 10 | 12 sauces, **sans** « Sans sauce », ordre C (Tacos L/XL, tous les burgers) |

Soit : **13 articles sans « Sans sauce »**, **2 articles avec 2 sauces au lieu de
13**, et **4 ordres d'affichage incompatibles**. Trois écritures coexistaient pour
deux sauces (« Sauce fromagère maison » / « Fromagère maison » ; « Spicy » /
« Sauce spicy » / « Spicy maison »).

« Américaine » n'existait dans **aucune** des listes — ni en base, ni dans les
mirrors du menu. Ce n'était donc pas un défaut d'affichage : la sauce n'était pas
au menu. Ajout décidé par le propriétaire le 2026-08-28.

---

## 2. Correction

**Une seule source de vérité** : `config/pos_sauces.php` (liste, ordre, couleurs,
alias). Elle alimente les trois consommateurs :

- `App\Support\Menu\SauceCatalog` — tri canonique ;
- `ItemResource` (caisse) et `NormalItemResource` (borne) — même ordre partout ;
- `POS_WIZARD_CONFIG.sauceStyles` — couleurs des tuiles, aucune couleur en dur
  dans le wizard.

**Réparation des données** : `php artisan foodking:sauces:sync` (`--dry-run`
disponible). Idempotente, transactionnelle. Elle complète, renomme les alias et
réactive — elle **ne supprime ni ne désactive jamais** une ligne existante, et
n'ajoute d'attribut sauce à aucun article qui n'en a pas.

Premier passage : **24 renommées, 113 créées**. Second passage : **0**.
Après ajout d'« Américaine » : **38 créées**, puis **0**.

---

## 3. Preuves

- **Convergence** : 27 listes de sauces sur les articles vendables, **0 divergente**.
  Ordre rendu : `Ketchup | Mayonnaise | Blanche | Algérienne | Samouraï |
  Andalouse | Américaine | Barbecue | Curry | Harissa | Hannibal |
  Fromagère maison | Spicy maison | Sans sauce`.
- **Ordre des étapes du wizard inchangé** : attributs avant/après identiques sur
  Cayenne, Tacos M/L/XL, Bol Frites, Sandwich Classique, Petite Frites.
- **Sonde de dérive** : `foodking:sauces:sync --dry-run` doit rendre
  `0 renommées · 0 réactivées · 0 créées`. Toute autre sortie = la base a redérivé.
- **Sentinelle** : `tests/Feature/Sentinels/SauceCatalogCanonicalOrderSentinelTest.php`
  (5 tests). **Morsure vérifiée** : défaut réintroduit volontairement → 2 tests
  échouent ; défaut retiré → 5 verts.
- **Tests** : PHPUnit 30/30 (183 assertions) · Vitest 13/13.
- **Zones gelées** hors wizard : **zéro ligne** touchée (kiosk, PaymentComponent,
  PosV5TrancheRow, Fiscal, BranchScope, Idempotency, PricingService,
  OrderStateMachine).
- **Navigateur réel** (`:8766`, arbre principal) : 14 tuiles colorées, cadres
  Crudités et Sauce de **468 px chacun** (écart 0), duplication produisant deux
  lignes distinctes (Ketchup / Samouraï) à partir d'une seule saisie.

---

## 4. Deux corrections de mes propres erreurs

Consignées parce qu'un rapport qui ne publie que ses succès ment sur son taux d'erreur.

1. **Tri intransitif.** Ma première version triait globalement en mélangeant
   « rang canonique » (entre sauces) et « position d'origine » (ailleurs). Les
   lignes d'un attribut n'étant pas contiguës en base, cela donnait
   `Curry < Pain < Barbecue < Curry` : `usort` rendait un ordre arbitraire, et
   Barbecue restait mal placé sur Cayenne et Tacos M. Corrigé en triant **par
   groupe d'attribut**. Le cas est figé par un test dédié.

2. **Rattachement de sauces à « Frites Seules » (annulé).** J'avais relevé que
   l'article 2 n'offrait aucune sauce et je lui ai attaché les 13 — à tort.
   Vérification faite ensuite : les articles 1/2/3 appartiennent à la catégorie 27
   « Technique (interne — upsell) » (`status = 10`). Ce sont des articles
   techniques de formule, jamais ouverts en caisse ; `api/admin/item/details/2`
   répond **404 par construction**. Les 13 lignes créées ont été **supprimées**,
   `force_attach` remis à vide, et le constat consigné dans la config.

Signalé aussi, **non modifié** : l'attribut 9 « Style frites » porte 6 variations
toutes **désactivées** (`status = 10`) — remplacées par des articles dédiés
(107-110). Inerte à dessein ; le réactiver dupliquerait l'offre cheddar.

---

## 5. Trois pièges d'instrument rencontrés (cf. CLAUDE.md §3ter)

Aucun n'a donné lieu à un signalement produit, mais tous trois ont failli :

- **Deux boutons « Ajouter au panier »** coexistent : celui de Vue (caché) et
  celui du wizard (`.wizard-btn-cart`). Un `find()` par texte tombe sur le
  premier, contourne le pont wizard, et la commande enregistre la **première
  sauce par défaut**. J'ai bien cru à une régression « Samouraï → Ketchup » :
  c'était mon test.
- **Coordonnées d'écran mises à l'échelle** : capture 1493 px pour un viewport de
  1728 px (facteur 0,864). Les clics « ratés » ne prouvaient rien sur le produit.
- **Tuiles viande apparemment vides** sur une capture JPEG : le zoom montre des
  images correctes. Artefact de compression.

---

## 6. Reste à l'arbitrage du propriétaire

- `PROJECT_BRAIN.md` est **en conflit de fusion non résolu** (marqueurs `<<<<<<<`
  / `>>>>>>>` stagés dans l'index, lignes 50 → 700), hérité d'une session
  antérieure. Non résolu ici : ce n'est pas mon travail et l'arbitrage détruirait
  potentiellement du contenu.
- Article 1 « Menu (Frites + Boisson) » : article technique sans sauce. Laissé tel
  quel — la sauce d'une formule vient du produit principal.

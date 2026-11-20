# LOCK_POS_WIZARD_OPTIONS_LISIBLES — choix de personnalisation : plus grands, et colorés

> Override frozen §7 (`public/css/pos-wizard.css`). Status **APPROVED** — demande DIRECTE du
> propriétaire le 2026-08-28, pour sa caissière : « les choix, on a dit de lui mettre plus
> grand pour occuper tout l'espace quand y en a, ainsi que mettre des couleurs, comme ça
> c'est plus facile à choisir pour le caissier ».
>
> Gate §6 satisfaite par instruction owner directe — même forme que
> `LOCK_KIOSKUPSELL_IMG_CONTAIN_2026-07-07`.

## §1 Identification

- LOCK ID : `LOCK_POS_WIZARD_OPTIONS_LISIBLES`
- Créé/approuvé : 2026-08-28 (instruction propriétaire directe)
- Fichiers frozen : `public/css/pos-wizard.css` (bloc `.sauce-chips-grid` / `.sauce-chip`)
  et `public/js/pos-wizard.js` (une expression : la classe posée sur la pastille)

## §2 Changement (chirurgical, un bloc de style)

| | Avant | Après |
|---|---|---|
| Marge de la pastille | `5px 12px` | `12px 18px` |
| Taille du texte | `12px` | `15px` |
| Bordure | `1.5px` | `2px` |
| Largeur | contenu, tassées à gauche | `flex: 1 1 auto` + `min-width: 120px` — elles remplissent la ligne |
| Fond | `white`, identique pour les douze | huit teintes pastel, cycliques par position |
| État choisi | rouge plein | rouge plein `!important` + ombre — il prime sur toutes les teintes |

**La couleur suit la POSITION, pas le nom.** Elle est donc stable tant que la carte ne
bouge pas, et la mémoire du geste s'installe — « l'algérienne, c'est l'orange, en premier ».
Un nom aurait donné une couleur qui saute dès qu'on renomme une sauce.

### Ce qui n'est PAS touché

Aucune autre règle du fichier. Ni la mise en page de l'assistant, ni les crudités, ni les
viandes, ni le pied de page, ni le pont vers le modal Vue. Le diff est un bloc.

## §3 Le défaut, tel que le propriétaire l'a vu

Douze sauces en pastilles **blanches identiques**, texte de 12 px, serrées en haut à droite
d'un panneau à moitié vide. Rien ne les distinguait sinon un mot à relire à chaque commande.

Les crudités, **juste à côté**, sont déjà colorées (vert = ajouté, rouge = retiré). Le
propriétaire ne demandait donc pas une invention : il demandait d'étendre au reste ce qui
marchait déjà sous ses yeux.

## §4 Acceptance (binaire)

- [x] Les douze sauces portent chacune une teinte, visible à l'écran
- [x] La teinte est stable par position (cycle de 8)
- [x] Le choix retenu reste rouge plein, dominant sur toutes les teintes
- [x] Les pastilles remplissent la largeur au lieu de se tasser
- [x] Aucune autre règle du fichier modifiée
- [x] Capture lue et analysée
- [x] Vitest complet vert (502 fichiers, 4051 tests)

## §5 Rollback

`git revert <sha>` — le patch frozen est isolé. Retour immédiat aux pastilles blanches.

Aucune donnée, aucun état à restaurer : le changement est purement d'affichage. Aucun prix,
aucune composition, aucune écriture fiscale n'en dépend.

## §6 Sign-off

**Propriétaire : APPROVED par instruction directe du 2026-08-28**, citée en tête.

Couche d'affichage pure. Aucun impact prix, logique, ou fiscal.

## §7 Amendement du 2026-08-28 — les couleurs refusées, et refaites

Le propriétaire a revu le premier jet et l'a refusé, à juste titre :

> « les couleurs, j'aime pas trop comment t'as fait. Je voudrais mieux mettre selon les
> couleurs de chaque chose : ce qui est blanche ça reste blanc, harissa c'est rouge,
> algérienne c'est orange… pourquoi t'as mis le curry en rouge ? Et je veux la même taille
> que les crudités — l'autre, c'est en face, la même taille. »

**Ce que j'avais fait** : huit teintes qui tournaient par POSITION. C'est stable, mais
arbitraire — le curry tombait sur du rouge par le seul hasard de son rang. Une teinte
arbitraire n'apprend rien au caissier ; il doit toujours lire le mot.

**Ce qu'il fallait faire** : la couleur de la SAUCE. Le curry est jaune, la harissa rouge,
la blanche blanche. Là, la couleur se reconnaît sans lire — c'est tout l'intérêt.

Cela demande de savoir QUELLE sauce est dans la pastille : le fichier JS gelé pose donc
désormais une classe tirée du nom (accents et casse retirés). Une expression, une variable.
Une sauce absente de la table garde le fond blanc : mieux vaut sobre que faux.

**Et la taille** : les pastilles reprennent exactement le gabarit des crudités de la colonne
d'en face — `display:block; width:100%; padding:10px 12px`, empilées. Les deux colonnes se
répondent au lieu de se contredire.

## §8 Note de méthode — trois fausses pistes avant la bonne

Écrit ici parce que ça fera gagner du temps à la prochaine personne : **l'assistant de la
caisse a trois chemins de rendu qui coexistent**, et les trois portent des noms voisins.

1. `.wizard-option` dans `pos-wizard.css` — ce sont les **suppléments** (Cheddar, Raclette…),
   pas les sauces. Vérifié en listant les `option-name` du DOM capturé.
2. `.sauce-grid.compact` dans le bloc `<style>` injecté par `pos-wizard.js` — **aucun élément
   ne porte cette classe** dans le rendu réel. Le sélecteur existe, la cible non.
3. `.sauce-chip` dans `pos-wizard.css` — **c'est celui-là**, et c'est le seul.

J'ai modifié les deux premiers avant de trouver le troisième ; les deux ont été intégralement
restaurés. La leçon : sur cet écran, ne pas déduire la cible du nom d'une classe — lire la
classe portée par l'élément qui affiche le mot cherché.

## §9 Amendement du 2026-08-28 (2) — « non pas encore ! »

Le propriétaire a regardé l'écran et a répondu **« non pas encore ! »** à mon rapport de
livraison. Il avait raison sur les deux points, et la cause des deux est la même : **j'ai
écrit la table des couleurs de mémoire au lieu de lire la carte.**

### Défaut 1 — cinq sauces restées blanches

Les noms réels, relevés dans `item_variations` (attribut 5, « Sauce (1ère Gratuite) ») :

| Sauce | Ce que j'avais écrit | Effet à l'écran |
|---|---|---|
| **Cayenne** | rien | blanche |
| **Poivre** | rien | blanche |
| **Tandoori** | rien | blanche |
| **Spicy** | `spicy maison` seulement | blanche |
| **Sauce fromagère maison** | `fromagère maison` seulement | blanche |

Cinq oublis sur dix-neuf. C'est le taux d'erreur d'une liste écrite de tête — et il était
évitable : la carte est en base, il suffisait de la lire. Les cinq règles sont ajoutées,
avec la couleur de la sauce réelle (le piment de Cayenne est rouge, le tandoori brique, la
sauce au poivre grise-brune).

### Défaut 2 — le bloc « 🍟 Sauce pour frites » n'avait aucune couleur

L'assistant rend les sauces par **deux chemins** : celui du sandwich et celui des frites.
Les deux partagent la grille `.sauce-chips-grid`. Le second avait donc hérité de la
nouvelle taille, **mais pas de la classe qui porte la couleur**. Résultat : des pastilles
blanches juste sous des pastilles colorées, dans la même grille. C'est ce que le
propriétaire voyait.

Le §8 de ce document avertissait déjà que cet écran a plusieurs chemins de rendu qui
portent des noms voisins. Je n'ai pas appliqué mon propre avertissement.

### Vérification (les deux défauts, prouvés)

- Couverture : les **19** sauces de la carte ont chacune leur règle — chaque nom réel
  passé par le même calcul de clé que le JS, puis cherché dans le CSS. 19/19.
- `node --check public/js/pos-wizard.js` → valide.
- Nouvelle sentinelle `tests/js/sauceChipsCouleurs.spec.js` (5 contrôles) : elle vérifie
  qu'**aucun** chemin ne construit une pastille sans sa classe de couleur. Contrôle
  **structurel**, pas nominatif — il ne connaît aucune sauce et ne cassera donc pas quand
  la carte changera.
- **La sentinelle a été vue mordre** : défaut des frites remis → 1 échec, avec le message
  attendu ; correctif restauré → 5/5 verts. Un banc vert qui n'a pas été vu échouer ne
  prouve rien.

### Fichiers frozen touchés

- `public/css/pos-wizard.css` — cinq règles ajoutées à la suite du bloc de couleurs
- `public/js/pos-wizard.js` — une variable + la classe posée sur la pastille des frites

Aucune autre règle, aucune autre fonction. Couche d'affichage pure : aucun prix, aucune
composition, aucune écriture fiscale n'en dépend. Rollback : `git revert <sha>`.

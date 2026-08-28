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

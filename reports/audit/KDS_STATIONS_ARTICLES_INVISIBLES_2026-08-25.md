# KDS — 11 articles vendables sans poste cuisine

- **Tâches** : T-3.2.1 / T-3.2.2 du GOAL `CONSOLIDATION_V1_PRODUCTION_20260825` (vague W4)
- **Date** : 2026-08-25 · relevé sur la base réelle · **aucune donnée modifiée**
- **Test qui épingle le comportement** : `tests/js/kdsStationFiltreCouverture.spec.js` (7 tests)

---

## 1. Le mécanisme, établi par lecture de code

`resources/js/helpers/kdsDisplay.js` :

```js
normalizeKdsStation(raw)   // null | '' | valeur inconnue  →  'none'
orderMatchesStationFilter(order, filter)
    → items.some((line) => normalizeKdsStation(line.kds_station) === filter)
```

Le menu déroulant du KDS (`KitchenDisplaySystemComponent.vue:309-312`) propose **quatre** entrées :
`all`, `bar`, `cuisine_chaude`, `cuisine_froide`. **Il n'y a aucune option `none`.**

Donc : **une commande composée uniquement d'articles `none` n'apparaît que si l'opérateur est resté
sur « toutes les stations »**. Et ce filtre est persisté par utilisateur en `localStorage`
(`persistKdsUiPrefs`) : une fois « bar » choisi, le choix colle d'un service à l'autre.

Le défaut par défaut est donc bénin — `all` est la valeur initiale. Il devient réel dès qu'un
opérateur filtre une fois.

---

## 2. Le relevé — 59 articles vendables

| Poste | Articles |
|---|---|
| `cuisine_chaude` | 37 |
| `bar` | 8 |
| `cuisine_froide` | 3 |
| **`none`** | **11** |

### Les 11 articles en `none`

| # | Article | Question |
|---|---|---|
| 1 | Menu (Frites + Boisson) | **Des frites se cuisent.** Aucun poste de cuisine ne le voit. |
| 2 | Frites Seules | **Idem — c'est le cas le plus net.** |
| 3 | Boisson Seule | Cohérent avec `none` ? ou `bar` comme les autres boissons ? |
| 119 | Coca Cherry 33cl | Boisson |
| 120 | Tropico 33cl | Boisson |
| 121 | Ice Tea Pêche 33cl | Boisson |
| 122 | Fanta Citron 33cl | Boisson |
| 123 | Fuze Tea 33cl | Boisson |
| 124 | Hawaï 33cl | Boisson |
| 125 | Perrier 33cl | Boisson |
| 161 | E2E_PLAYWRIGHT_STUDIO_ITEM | Fixture de test — sans objet |

### L'incohérence la plus parlante

**Huit boissons sont en `bar`** : #52 Coca-Cola, #53 Coca-Cola Zero, #54 Fanta Orange, #55 Sprite,
#56 Oasis Tropical, #57 Orangina, #58 Eau Plate 50cl, #59 Capri-Sun.

**Sept boissons de même nature sont en `none`** : #119 à #125.

Les numéros parlent d'eux-mêmes : #52-59 forment un lot d'origine correctement rattaché, #119-125
un lot ajouté plus tard **sans que le poste soit renseigné**. Ce n'est pas un choix, c'est un oubli.

**Conséquence concrète** : un barman qui a filtré sur « bar » sert les Coca-Cola et ne voit jamais
passer les Coca Cherry.

---

## 3. Ce que je n'ai pas fait, et pourquoi

⛔ **Je n'ai réattribué aucun poste.** Décider qu'un produit relève du bar ou de la cuisine chaude
est une décision d'exploitation. CLAUDE.md §3bis interdit explicitement d'inventer ou de deviner
des données menu — la table `items` est la source de vérité, pas mon jugement.

⛔ **Je n'ai pas ajouté d'option `none` au menu déroulant.** Rendre ce seau atteignable est un
choix de conception : faut-il un poste « non assigné » visible, ou faut-il qu'aucun article n'y
reste ? Les deux réponses se défendent, et ce n'est pas à moi de trancher.

---

## 4. Décision demandée

- **A)** **Rattacher les 7 boissons #119-125 à `bar`**, aligné sur #52-59, et trancher séparément
  le cas des frites (#1, #2). *(recommandé — corrige un oubli manifeste sans changer de conception)*
- **B)** Rattacher les boissons **et** envoyer les frites en `cuisine_chaude`, pour qu'aucun article
  cuisinable ne reste hors poste.
- **C)** Ajouter une option **« non assignés »** au menu déroulant du KDS, pour qu'aucune commande
  ne puisse disparaître quel que soit le filtre. *(complémentaire de A ou B — c'est le filet)*
- **D)** Ne rien changer : assumer que `none` = « sort directement, sans passer par un poste », et
  documenter cette règle pour que la prochaine session ne la reprenne pas comme un défaut.

**Ma recommandation : A + C.** A corrige l'oubli constaté ; C garantit qu'un futur oubli ne rende
plus jamais une commande invisible. Le cas des frites (#1, #2) mérite votre arbitrage explicite :
je ne sais pas si, dans votre organisation, les frites partent d'un poste distinct.

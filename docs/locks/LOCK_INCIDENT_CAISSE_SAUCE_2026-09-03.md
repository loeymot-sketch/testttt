# LOCK — Incident caisse : la 2ᵉ sauce empêchait d'encaisser

**Identifiant :** `LOCK_INCIDENT_CAISSE_SAUCE_2026-09-03.md`
**Date :** 2026-09-03 · **Zone gelée touchée :** `app/Services/Pricing/PricingService.php`
**Gate propriétaire :** obtenue — « urgent ! corrige la cause que les commandes ne passent pas !! »
(capture jointe : `IMG_2292.HEIC`).

---

## 1. Le symptôme

Au moment d'encaisser, la caisse refuse :

> Composition : le choix #450 n'appartient pas au profil publié.

Le ticket est construit, le montant affiché (9,80 €), la monnaie calculée (0,20 € sur 10 €) —
et le paiement est impossible. Le service est à l'arrêt sur cet article.

## 2. La cause, mesurée

Le wizard de caisse facture la 2ᵉ sauce 0,50 € via un extra générique « Sauce
supplémentaire » qui **appartient bien à l'article** (`LOCK_CAISSE_SAUCE_SEAL` du
2026-07-16). Cet extra porte le groupe `sauce`.

Le profil composeur publié, lui, ne décrit d'étapes `extra_group` que pour `crudite` et
`supplement` : les sauces GRATUITES passent par une étape `item_attribute`. **Aucune étape ne
couvre donc l'extra PAYANT du groupe `sauce`.**

Vérifié sur le catalogue réel, article « Cayenne » (22), profil publié #55 :

| Extra de l'article | Groupe | Étape qui le couvre | Résultat |
| --- | --- | --- | --- |
| #398 Viande supplémentaire | `supplement` | oui | **encaissable** |
| #428 Sauce supplémentaire | `sauce` | AUCUNE | **vente bloquée** |

Deux extras du même article, tous deux actifs, tous deux proposés par le wizard. La seule
différence est qu'une étape existe pour l'un et pas pour l'autre — un détail de configuration
qui décidait si le restaurant pouvait encaisser.

`assertComposerSelectionsBelongToPublishedProfile()` n'autorisait que les choix listés par les
étapes projetées. Le garde a été écrit pour empêcher l'INJECTION d'options ; il refusait en
fait tout ce que le profil ne décrivait pas, y compris ce que le produit vend légitimement.

## 3. Le changement

Le profil contraint **ce qu'il décrit**. Ce qu'il ne décrit pas retombe sur la frontière du
catalogue : l'option doit appartenir à l'article, être active, et visible sur cette surface.

- `item_extras` : repli seulement si **aucune étape ne couvre le groupe** de l'extra
  (correspondance identique à la projection : égalité, `default`, alias déclarés).
- `item_variations` / `item_addons` : repli seulement si le profil ne décrit **aucune** étape
  de cette famille.

Ce qui reste refusé, sans changement :
- un choix appartenant à un AUTRE article (l'injection, le risque réel) ;
- un extra désactivé ou masqué sur la surface ;
- un choix retiré d'une étape que le profil décrit — le contrat de
  `ProfilePublishMidCartRejectionTest` est intact.

## 4. Pourquoi la zone gelée

`PricingService` est gelé parce qu'il est la source unique des prix (NF525, CLAUDE.md §8).
Ce correctif ne touche **aucun calcul de prix** : il ne modifie que le contrôle
d'appartenance des options. Les montants restent calculés depuis la base, jamais depuis la
charge utile — un extra accepté est facturé à son prix en base, comme avant.

## 5. Preuves

- Banc de régression : `tests/Feature/Pricing/SauceSupplementaireBloqueLaVenteTest.php`.
  **Rouge avant correctif**, avec le message exact de la capture ; vert après. Il couvre
  aussi les trois refus qui doivent subsister.
- `--filter 'Composer|Pricing|Wizard|Sauce|Frites|Supplement|Extra'` : **539 tests, 0 échec**.
- `--filter 'Fiscal|Pos|Kiosk|Order|Nf525'` : **2 598 tests, 0 échec**.

## 6. Retour arrière

`git revert` du commit de correctif. Le banc de régression redeviendrait rouge — c'est le
signal attendu, il documente l'incident.

## 7. Ce qui reste à décider (hors urgence)

La cause profonde est une configuration : le groupe `sauce` n'a pas d'étape dans les profils
publiés. Ce correctif rend la caisse opérationnelle sans y toucher. Si le propriétaire veut
que la 2ᵉ sauce apparaisse comme une étape guidée du parcours, c'est une décision de
catalogue, à prendre à froid — pas pendant un service.

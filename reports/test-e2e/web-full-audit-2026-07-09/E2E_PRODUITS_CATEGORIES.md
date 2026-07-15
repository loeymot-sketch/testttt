# TEST-E2E — parcours de CHAQUE produit & catégorie (web) — 2026-07-10

Cible = site déployé `Site lecayenne` servi standalone :8099 (aucun backend). Méthode « smart » :
validation programmatique (tous les produits via `window.LC.menu`) + confirmation visuelle des
6 templates de wizard.

## Validation programmatique — 38 produits, 9 catégories
| Contrôle | Résultat |
|---|---|
| **Images** (résolues + chargées, 0 × 404) | **38 / 38** ✅ |
| **Prix de base** valides (>0) | **38 / 38** ✅ |
| **Sauce** — 1ère offerte / +0,50 € par sauce en plus | **16 / 16** items à sauce ✅ |
| **Formule menu** — full +2,50 / frites +1,50 / boisson +1,00 | **14 / 14** ✅ |
| **Viande supplémentaire** +2,50 € | **16 / 16** ✅ |
| **Suppléments** (+prix) | **16 / 16** ✅ |
| Catégories | Sandwichs 4 · Galette 2 · Burgers 6 · Tacos 2 · Bols 2 · Frites 2 · Desserts 3 · Boissons 15 · Menu enfant 2 |

## Confirmation visuelle — 6 templates de wizard (captures dans captures/)
| Template | Produit testé | Parcours | Verdict |
|---|---|---|---|
| **sandwich** | Cayenne | 🥖/🌯 pain → sauce (fromagère défaut, multi +0,50) → crudités → suppléments → viande sup → menu | ✅ |
| **burger** | Big Burger | sauce (multi, 2 sauces = 9,50 €) → … | ✅ |
| **tacos** | Tacos M | choisis 1 viande (7) → sauce → crudités → suppléments → viande sup → menu | ✅ |
| **bol** | Bol Frites | viande → sauce → suppléments du bol → boisson → viande sup | ✅ |
| **frites** | Grande Frites | style (Nature / Cheddar +1,00 / Cheddar+Oignons +2,00) → récap 4,00 € | ✅ |
| **simple** | Coca-Cola | ajout direct au panier 1,90 € | ✅ |

## Résultat
- **0 bug web** trouvé — tout le parcours de chaque produit/catégorie fonctionne **comme voulu**.
- Images ✅ · pain/galette ✅ · sauces (règle owner) ✅ · toutes les tarifications ✅ · aperçu
  live + total + points ✅ · **0 erreur console** partout.
- Add-to-cart prouvé (Coca 1,90 €, Cayenne 7,40 €).

## Reste (hors web — déjà signalé)
- **Sauce borne/backend** : la borne fait encore « 1 sauce max » (attr#5 max=1, PricingService
  sans +0,50). Le web = ta règle. Aligner la borne = toucher **PricingService (FROZEN, §7)** →
  gate owner + LOCK requis. Voir `COMPARE_WEB_VS_BORNE.md`.

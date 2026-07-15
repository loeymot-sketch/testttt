# Comparaison WEB ↔ BORNE (2026-07-09) — après fixes web

Source de vérité = config backend (`item_attributes` / `item_variations` / PricingService) que
la borne ET la caisse utilisent. Le web est censé la refléter.

| Point | Borne / backend (SdV) | Web (après fix) | Verdict |
|---|---|---|---|
| **Photos produits** | servies par `:8766/images/menu/` | mêmes photos, embarquées local (`assets/menu/`) | ✅ **identiques** (mêmes fichiers, téléchargés depuis le backend) |
| **Pain / Galette** | attr#6 « Type de Pain », **max=1**, Pain 0€ / Galette 0€ | choix simple 🥖/🌯, single, gratuit | ✅ **cohérent** (web = présentation plus propre, même logique) |
| **Sauce** | attr#5 « Sauce (1ère Gratuite) », **max=1**, 12 sauces à **0€**, **PricingService = AUCUNE logique +0,50** | **multi (max 12), 1ère offerte, +0,50 €/sauce en plus** | ❌ **DIVERGENCE** |

## Le point sauce — analyse
- L'attribut backend s'appelle **« Sauce (1ère Gratuite) »** → l'INTENTION était bien « 1ère
  gratuite + payantes ». Mais il est **configuré max=1** et **PricingService n'a aucune logique
  de surcoût**. Donc la borne (et la caisse via PricingService) = **1 sauce, gratuite. Point.**
- Le web fait maintenant ce que TU veux (règle confirmée : multi, 1ère offerte, +0,50 €).
- **Conclusion** : ce n'est pas le web qui est faux — c'est la **borne/backend qui n'a jamais
  implémenté** la règle « 1ère gratuite + 0,50 ». Le web est le 1er à la faire.

## ⚠️ Risque si on branche le web sur la vraie caisse tel quel
PricingService valide `max_select=1` → une commande web à **2 sauces serait REFUSÉE (422 :
« Attribut Sauce : maximum 1 sélection »)**. Aujourd'hui pas de souci (le site Vercel n'est pas
branché au backend), mais dès qu'on le branchera, il faut que le backend soit aligné AVANT.

## Pour tout aligner « comme voulu » (borne = caisse = web)
Il faut, côté backend testttt :
1. `item_attributes` attr#5 : **max_select 1 → 12** (autoriser plusieurs sauces).
2. **PricingService** : ajouter « 1ère sauce gratuite, +0,50 € par sauce en plus ».
   → ⛔ **PricingService est une ZONE FROZEN (§7, SSOT prix NF525)** : modif = **gate owner
   explicite + LOCK** obligatoire. Je ne le touche pas sans ton feu vert.

## Ce qui est déjà cohérent + livré (web)
Photos ✅ · Pain/Galette ✅ · Sauce = règle owner ✅ (web). Reste : décider d'aligner la borne.

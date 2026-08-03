# HEAL — Mobile + Web standalone : purge fantômes + parité Capri-Sun (round3, 2026-06-26)

Périmètre : `mobile/` (JSX) + `/Users/1millnonstop/Downloads/web/` (JSX). STANDALONE, data/render only, **aucun wireup**, **0 commit**.
Canon = `OwnerMenuUpdate20260623Seeder` (31 produits / 9 cats / 7 viandes). Capri-Sun **1,50** (confirmé live kiosk + seeder).

## Capri-Sun — TRANCHÉ = 1,50 €
- mobile `data/menu.js:429` 1,90 → **1,50** (item 1008). web `data/menu.js:400` était déjà 1,50 (laissé). Parité atteinte.
- **Découverte** : le sentinel `mobile/tests/menu.spec.js:105` codait « toute boisson cat-10 sauf eau = 1,90 » → il exigeait Capri=1,90. Il était **DÉJÀ ROUGE sur web** (qui avait le bon 1,50) AVANT ma session. Sentinel périmé, pas la donnée. Aligné au canon : exclu `capri-sun` de la règle canettes + ajouté check `Capri-Sun 1,50€`. Test repassé **vert (0 fail)** sur les 2 fichiers.
- `priceForDrinkAddon('d-capri')=1.90` laissé tel quel dans LES DEUX (parité ; addon bol = hors scope item-price ; observation, non touché).

## Fichiers touchés (avant → après)
**mobile/data/menu.js** : Capri 1,90→1,50 ; commentaire « 25 produits »→« 31 produits ».
**mobile/data/orders.js** (cmd active C-1234) : `Big Cayenne`→`Suprême` ; `Bowl Frites Poulet curry` 10,90→`Bol Riz` 7,90 (extras→« Viande au choix · Sauce fromagère ») ; items_summary→`Suprême · Tacos L · Bol Riz · Coca-Cola` ; total 29,80→**26,80** (9,50+7,90+7,90+1,50).
**mobile/data/loyalty.js** : reward 7 `Big Cayenne −50 %` (item 102)→`Cayenne −50 %` (item **101**, icône 🌶️ cohérente) ; historique : `Big Cayenne·…·Bowl Frites`→`Suprême·Tacos L·Bol Riz` ; `Burger gratuit (Big Chicken)`→`(Chicken Burger)` ; `Galette Cayenne · Bowl Riz`→`· Bol Riz` ; 2× `Sandwich Classique`→`Suprême` / `Cayenne · Coca-Cola`.
**mobile/data/dev-helpers.js:215** (seed test) : `Big Cayenne`→`Méga`, `Bowl Frites`→`Bol Frites`.
**mobile/screens-main.jsx** : featured `findItem('tacos-xxl')`→`'tacos-l'` (slug mort) ; blurb « bols gourmands »→« bols Frites/Riz ».
**mobile/screens-item-steps.jsx** : commentaires « 15 sauces »→« 12 sauces » ; exemple « Big Cayenne »→« Cayenne ».
**mobile/screens-onboarding.jsx:84** : « bols gourmands »→« bols Frites/Riz ».
**web/screens.jsx** : Home featured « Big Cayenne XL · 9,50 € » + `onItem('big-cayenne')` (CTA morte) → **Méga · 8,00 € · `onItem('mega')`** (h3/desc/prix/alt/emoji 🥖) ; offre du jour `onItem('sandwich-cayenne-classique')`→`'cayenne'` ; About « Bols Gourmands…/Big Cayenne XL »→« Bols…/Méga » ; « Bol Gourmand 8,90€ »→« Bol 7,90€ » + « Cayenne 7,00€ »→« 7,40€ ».
**web/orders.jsx** : `Big Cayenne XL`→`Méga` (🥖) ; `Bowl Frites Curry + Gratiné`→`Bol Riz + Gratiné`.
**web/data/menu.js** : « 25 produits »→« 31 produits ». **web/components.jsx:116** : « bols gourmands »→« bols Frites/Riz ».
**mobile/tests/menu.spec.js** : sentinel Capri aligné canon (cf. ci-dessus).

## Checks (evidence)
- `node --check` : 5/5 data .js **OK**.
- `esbuild` parse (JSX) : 6/6 .jsx **OK**.
- `node mobile/tests/menu.spec.js` : **ALL CHECKS PASSED (0 failures)**, EXIT 0, mobile+web, « → 31 produits, 9 catégories ».

## 0 fantôme résiduel
Grep final (Big Cayenne / Big Chicken / Sandwich Classique / Bowl / tacos-xxl / Bols Gourmands / big-cayenne / sandwich-cayenne-classique) = **0 rendu/data**. Restes = uniquement : commentaires de purge, **sentinels de test** (asserts d'absence), checks de slug **morts** (`bowl-frites-`/`bowl-riz-` — jamais matchés par le canon, logique wizard = hors scope « data/render no-wiring », classés P3-trivial par l'audit), nom de fichier asset `cat-bols-gourmands.png`, et adjectif générique « Desserts gourmands ». Aucun n'est un produit fantôme rendu au client.

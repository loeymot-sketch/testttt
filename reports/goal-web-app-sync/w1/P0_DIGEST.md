# P0 DIGEST (15)

## [parity:Boissons] WEB — item vendable manquant : Coca Cherry 33cl (canonique id 119, 1,90 €, is_available=true)
- /Users/1millnonstop/Downloads/web/data/menu.js:416
- EVIDENCE: Canonique: id 119 'Coca Cherry 33cl' flat_price '1.90' currency_price '1,90 €' desc 'Coca-Cola Cherry' available=true. Mirror: le tableau DRINKS (menu.js:416-425) s'arrête à mkItem(1008,'capri-sun') ; grep -in 'cherry' sur tout le fichier = 0 résultat.
- RECO: Ajouter mkItem(1009,'coca-cherry',10,'Coca Cherry 33cl',1.90,'Coca-Cola Cherry',{…time:0}) dans DRINKS.

## [parity:Boissons] WEB — item vendable manquant : Tropico 33cl (canonique id 120, 1,90 €)
- /Users/1millnonstop/Downloads/web/data/menu.js:416
- EVIDENCE: Canonique: id 120 'Tropico 33cl' 1,90 € desc 'Tropico' available=true. Mirror: 'tropico' n'apparaît qu'en tant qu'IMAGE d'Orangina (menu.js:82 'orangina': 'tropico.png' et :214 d-orangina image tropico.png), jamais comme item DRINKS.
- RECO: Ajouter l'item Tropico 33cl 1,90 € dans DRINKS.

## [parity:Boissons] WEB — item vendable manquant : Ice Tea Pêche 33cl (canonique id 121, 1,90 €)
- /Users/1millnonstop/Downloads/web/data/menu.js:416
- EVIDENCE: Canonique: id 121 'Ice Tea Pêche 33cl' 1,90 € desc 'Ice Tea saveur pêche' thumb lipton-peche.webp. Mirror: grep -i 'ice tea' = 0 résultat dans menu.js.
- RECO: Ajouter l'item Ice Tea Pêche 33cl 1,90 € dans DRINKS.

## [parity:Boissons] WEB — item vendable manquant : Fanta Citron 33cl (canonique id 122, 1,90 €)
- /Users/1millnonstop/Downloads/web/data/menu.js:419
- EVIDENCE: Canonique: id 122 'Fanta Citron 33cl' 1,90 € desc 'Fanta Citron'. Mirror: seul Fanta Orange existe (menu.js:419 mkItem(1003,'fanta',10,'Fanta Orange 33cl',1.90,…)) ; grep -i 'citron' = 0 résultat.
- RECO: Ajouter l'item Fanta Citron 33cl 1,90 € dans DRINKS.

## [parity:Boissons] WEB — item vendable manquant : Fuze Tea 33cl (canonique id 123, 1,90 €)
- /Users/1millnonstop/Downloads/web/data/menu.js:416
- EVIDENCE: Canonique: id 123 'Fuze Tea 33cl' 1,90 € desc 'Fuze Tea' thumb lipton-framboise.webp. Mirror: grep -i 'fuze' = 0 résultat.
- RECO: Ajouter l'item Fuze Tea 33cl 1,90 € dans DRINKS.

## [parity:Boissons] WEB — item vendable manquant : Hawaï 33cl (canonique id 124, 1,90 €)
- /Users/1millnonstop/Downloads/web/data/menu.js:416
- EVIDENCE: Canonique: id 124 'Hawaï 33cl' 1,90 € desc 'Hawaï' thumb fanta-fraise.webp. Mirror: grep -i 'hawa' = 0 résultat.
- RECO: Ajouter l'item Hawaï 33cl 1,90 € dans DRINKS.

## [parity:Boissons] WEB — item vendable manquant : Perrier 33cl (canonique id 125, 1,90 €)
- /Users/1millnonstop/Downloads/web/data/menu.js:423
- EVIDENCE: Canonique: id 125 'Perrier 33cl' 1,90 € desc 'Perrier (eau gazeuse)'. Mirror: seule 'Eau Plate 50cl' 1.00 existe (menu.js:423 mkItem(1007,'eau-plate',…)) ; grep -i 'perrier' = 0 résultat — aucune eau gazeuse vendable sur le web.
- RECO: Ajouter l'item Perrier 33cl 1,90 € dans DRINKS.

## [parity:Boissons] MOBILE — item vendable manquant : Coca Cherry 33cl (canonique id 119, 1,90 €)
- /Users/1millnonstop/Downloads/projet/foodking-web/web/testttt/mobile/data/menu.js:421
- EVIDENCE: Canonique: id 119 'Coca Cherry 33cl' 1,90 € available=true. Mirror mobile: DRINKS (menu.js:421-430) = 8 items 1001-1008 seulement ; grep -in 'cherry' = 0 résultat.
- RECO: Ajouter l'item Coca Cherry 33cl 1,90 € dans DRINKS mobile.

## [parity:Boissons] MOBILE — item vendable manquant : Tropico 33cl (canonique id 120, 1,90 €)
- /Users/1millnonstop/Downloads/projet/foodking-web/web/testttt/mobile/data/menu.js:421
- EVIDENCE: Canonique: id 120 'Tropico 33cl' 1,90 €. Mirror mobile: 'tropico' n'existe que comme image d'Orangina (menu.js:93 et :206), pas comme item.
- RECO: Ajouter l'item Tropico 33cl 1,90 € dans DRINKS mobile.

## [parity:Boissons] MOBILE — item vendable manquant : Ice Tea Pêche 33cl (canonique id 121, 1,90 €)
- /Users/1millnonstop/Downloads/projet/foodking-web/web/testttt/mobile/data/menu.js:421
- EVIDENCE: Canonique: id 121 'Ice Tea Pêche 33cl' 1,90 €. Mirror mobile: grep -i 'ice tea' = 0 résultat.
- RECO: Ajouter l'item Ice Tea Pêche 33cl 1,90 € dans DRINKS mobile.

## [parity:Boissons] MOBILE — item vendable manquant : Fanta Citron 33cl (canonique id 122, 1,90 €)
- /Users/1millnonstop/Downloads/projet/foodking-web/web/testttt/mobile/data/menu.js:424
- EVIDENCE: Canonique: id 122 'Fanta Citron 33cl' 1,90 €. Mirror mobile: seul Fanta Orange (menu.js:424 mkItem(1003,'fanta',10,'Fanta Orange 33cl',1.90,…)) ; grep -i 'citron' = 0 résultat.
- RECO: Ajouter l'item Fanta Citron 33cl 1,90 € dans DRINKS mobile.

## [parity:Boissons] MOBILE — item vendable manquant : Fuze Tea 33cl (canonique id 123, 1,90 €)
- /Users/1millnonstop/Downloads/projet/foodking-web/web/testttt/mobile/data/menu.js:421
- EVIDENCE: Canonique: id 123 'Fuze Tea 33cl' 1,90 €. Mirror mobile: grep -i 'fuze' = 0 résultat.
- RECO: Ajouter l'item Fuze Tea 33cl 1,90 € dans DRINKS mobile.

## [parity:Boissons] MOBILE — item vendable manquant : Hawaï 33cl (canonique id 124, 1,90 €)
- /Users/1millnonstop/Downloads/projet/foodking-web/web/testttt/mobile/data/menu.js:421
- EVIDENCE: Canonique: id 124 'Hawaï 33cl' 1,90 €. Mirror mobile: grep -i 'hawa' = 0 résultat (hors 'Hawaï' absent, seul 'HAWAII' inexistant aussi).
- RECO: Ajouter l'item Hawaï 33cl 1,90 € dans DRINKS mobile.

## [parity:Boissons] MOBILE — item vendable manquant : Perrier 33cl (canonique id 125, 1,90 €)
- /Users/1millnonstop/Downloads/projet/foodking-web/web/testttt/mobile/data/menu.js:428
- EVIDENCE: Canonique: id 125 'Perrier 33cl' 1,90 € desc 'Perrier (eau gazeuse)'. Mirror mobile: seule Eau Plate 50cl 1.00 (menu.js:428) ; grep -i 'perrier' = 0 résultat.
- RECO: Ajouter l'item Perrier 33cl 1,90 € dans DRINKS mobile.

## [parity:Bols] Capri-Sun facturé 1,90 € au lieu de 1,50 € comme boisson de bol (WEB + MOBILE)
- /Users/1millnonstop/Downloads/web/data/menu.js:309
- EVIDENCE: Canonique : item 59 « Capri-Sun » flat_price 1.50 (fixture cat 10) ; les mirrors eux-mêmes listent mkItem(1008,'capri-sun',…,1.50) (web:424, mobile:429). MAIS priceForDrinkAddon mappe 'd-capri': 1.90 (web/data/menu.js:309 et mobile/data/menu.js:319), et 'd-capri' EST sélectionnable comme boisson (FORMULE_DRINKS web:216, mobile:208). Le fallback par défaut est aussi 1.90.
- RECO: Corriger le map 'd-capri': 1.50 dans les deux mirrors (web:309, mobile:319).

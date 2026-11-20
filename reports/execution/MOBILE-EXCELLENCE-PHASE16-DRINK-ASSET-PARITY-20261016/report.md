# MOBILE-EXCELLENCE-PHASE16-DRINK-ASSET-PARITY-20261016

## Remédiation ciblée — Fuze Tea

Le packshot Fuze Tea pêche a été copié à l’identique depuis la source canonique
du checkout de la borne :

`/Users/1millnonstop/Downloads/projet/foodking-web/web/testttt-kiosk/frontend public/public/upload_files/09-2022/fe1b1ad469e24302aed19e29dbcb7f86.Fuze_Tea_Peche.png`

SHA-256 source = destination :

`fec2d19201b58435ce18feb3170a2bfca6e6098a914c7ef2a8fc8babe393709b`

## Verdict d’exécution

**IMPLEMENTED — VALIDATION CIBLEE PASS**

Les visuels boissons disponibles côté mobile sont maintenant des copies
binaires exactes des assets canoniques du site et de la borne. Aucun faux visuel
de marque n’a été introduit pour une référence dont le packshot exact manque.
Les prix, les slugs, les IDs, le catalogue backend et les flux de commande
restent inchangés.

## Fichiers de l’allowlist

- `mobile/assets/menu/` — parité binaire pour les 11 assets du site, plus le
  packshot Fuze Tea canonique de la borne :
  `coca.png`, `coca-zero.png`, `coca-cherry.png`, `tropico.png`,
  `lipton-peche.png`, `fanta-citron.png`, `fanta-orange.png`, `oasis.png`,
  `sprite.png`, `eau.png`, `capri-sun.png`, `fuze-tea.png` ;
- `mobile/data/menu.js` — commentaire de vérité asset et liste des références
  sans packshot canonique ;
- `mobile/tests/mobile-catalog-visual.spec.js` — SHA-256 source/destination
  pour les 11 boissons du site et Fuze Tea borne, mappings brand-corrects et
  liste des absences ;
- `tests/mobile-e2e/catalog-visual-critical.spec.js` — vérification runtime
  des mappings disponibles et des neutres Hawaï/Perrier ;
- ce rapport.

## Preuve de parité SHA-256

| Asset | SHA-256 source = destination |
|---|---|
| `coca.png` | `84857946c519c1fa6f16a5b83411fa121444af977d7c5ea2f8dd2feb785e2a55` |
| `coca-zero.png` | `97b5a33509b9851ca42a4a13c2e5e511853e669dd6e521f6366714cdcd3a9361` |
| `coca-cherry.png` | `597a106884be87a13bf859510fbbb065ed14eec0092195e667007795c903f12a` |
| `tropico.png` | `27f4ae1d3564203f7c413f1a9d5eddf3d09515e1d3938a5d0b603b4d184d1a20` |
| `lipton-peche.png` | `edc2ab61952e107711a324b099ab585216d6ac7403a5570ac4bd08e4fbb810bc` |
| `fanta-citron.png` | `edb2c1435b77783351064f53205763de2bf57748cd9980fa22f47174ffa47b30` |
| `fanta-orange.png` | `188aa036197644d8e20c5e0b9bcbd9e95d002eab05ed1a13764d3655e3df6188` |
| `oasis.png` | `fe59c849c9e9f25c238cda7b9a7ff072226ea6077b7aa7edeead5fe8acc58329` |
| `sprite.png` | `39af922dddcf3fd6b1b4fb67fc95b962db62bdeeaa0fab8d7129146767bf838b` |
| `eau.png` | `6b2683dcc462318dffdbaefdc6822696f6a8e777486db6f63b36ac42831d735b` |
| `capri-sun.png` | `3459c8ec316b90304f31b11cd89535f36fa755dc2e79c6e2cedb8b82d17fa8ad` |
| `fuze-tea.png` (source borne checkout) | `fec2d19201b58435ce18feb3170a2bfca6e6098a914c7ef2a8fc8babe393709b` |

## Assets exactement absents — aucun remplacement inventé

- `Hawaï 33cl` → `item-default.svg` dans le catalogue et les choix de formule ;
- `Perrier 33cl` → `item-default.svg` dans le catalogue et les choix de formule ;
- `Schweppes Agrumes` → aucun SKU canonique/asset disponible dans ce pack ;
- `Oasis Cassis` → aucun SKU canonique/asset disponible dans ce pack ;
- `Coca bouteille` → aucun SKU canonique/asset disponible dans ce pack.

Ces références restent à fournir ou confirmer par le propriétaire avant toute
intégration ; elles ne sont pas ajoutées au catalogue dans cette mission.

## Validation

- `bash .cursor/hooks/safety-check.sh` — **PASS** ; zones gelées intactes.
- `node mobile/tests/mobile-catalog-visual.spec.js` — **PASS** ; visuels
  produits, catégories, 15 boissons, parité wizard, SHA site + borne et
  neutres Hawaï/Perrier contrôlés.
- `npx playwright test tests/mobile-e2e/catalog-visual-critical.spec.js --config=tests/mobile-e2e/playwright.config.js` — **PASS**, 4/4 ; aucun appel d’écriture.
- `git diff --check` — **PASS**.

## Validation mobile étendue — 2026-10-16

- `npx playwright test tests/mobile-e2e --config=tests/mobile-e2e/playwright.config.js --reporter=line --retries=0` — **29/30 PASS** ; un seul timeout sur `loyalty-03-qr-refresh` pendant la création de la session OTP réelle, avec la page restée sur l'accueil. L'API OTP locale a ensuite répondu HTTP 200 avec `dev_code`.
- Rejeu isolé de `tests/mobile-e2e/loyalty-03-qr-refresh.spec.js` — **1/1 PASS** en 16,7 s ; le token `lqr.*` est généré puis renouvelé, le compte à rebours est ré-ancré.
- `inspect-contrast.spec.js` — **PASS**, `VIOLATIONS: []` dans la campagne étendue.

Le timeout de la campagne étendue est donc une instabilité de throttle/session de test,
pas une régression du catalogue ou des assets boissons. Il reste à traiter dans un
cycle E2E/auth séparé si l'on veut une campagne complète répétable sans état externe.

## Invariants vérifiés

- backend = source de vérité des prix ; aucun prix modifié ;
- aucun changement `OrderStatus`, `branch_id`, dispatch ou service de commande ;
- aucun changement backend, paiement, auth/SMS, fidélité, suppression de
  compte, packaging iOS ou zones POS/kiosk ;
- aucune substitution de marque ; Hawaï/Perrier restent neutres et Fuze Tea
  utilise son packshot exact de la borne ;
- modifications non liées conservées.

`EXECUTE_DELEGATION: foodking-routine-implementer`

`FALLBACK_REASON: exécution bornée locale ; aucun canal d’extension disponible dans cette session.`

`VERDICT: PASS (validation ciblée uniquement; audit formel et clôture restent à effectuer)`

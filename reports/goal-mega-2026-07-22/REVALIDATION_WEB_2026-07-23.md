# REVALIDATION E2E RÉELLE — surfaces LIVE (2026-07-23)

**Mission** : validation navigateur (Playwright 1.58.2, chromium + Pixel 7) de TOUT le livré session
mega-goal 2026-07-22, sur les surfaces LIVE. READ-ONLY : zéro fix, zéro commit, zéro résidu.

**Cibles** : WEB `https://site-lecayenne.vercel.app` (bundle `v=20260722b`) · VPS `https://vps-418872ac.vps.ovh.net`.

**Sondes** (nouvelles, non commitées) :
- `tests/e2e/revalidation-web-2026-07-23.spec.js` (R1 money-path + R2 boissons)
- `tests/e2e/revalidation-legal-2026-07-23.spec.js` (R5, 5 pages)
- `tests/e2e/revalidation-mobile-2026-07-23.spec.js` (R3, Pixel 7)
- `tests/e2e/revalidation-vps-2026-07-23.spec.js` (R4 /m + R6 kiosk)

**Captures + obs JSON** : `tests/e2e/__screenshots__/revalidation-2026-07-23/` (14 PNG lus visuellement + 5 obs-*.json).

**Runs** : 10/10 tests verts (2 + 6 + 2), 0 retry, 0 console error, 0 réponse ≥400 sur tout le parcours web.

---

## Tableau PASS/FAIL

| # | Item | Verdict | Preuve clé |
|---|------|---------|-----------|
| 1 | Prix formule 1,90 + commande réelle money-path | **PASS** | Étape « FAIRE UN MENU ? » : Menu complet **+2,50 €** · Ajouter Frites **+1,90 €** · Ajouter Boisson **+1,90 €** · Sans formule (capture `R1-03-etape-formule.png` lue). Commande RÉELLE **#230726193 = 10,40 €** : Cayenne 6,90 base+crudités → 2ᵉ sauce +0,50 → Menu complet +2,50. Chaîne intègre : recap 10,40 = panier 10,40 = bouton Payer 10,40 = confirmation 10,40 (= `order.total` backend, POST `/api/frontend/order` **201** avec garde `expected_total` fail-loud active). QR réel + « ENVOYÉE EN CUISINE ». Zéro drop d'extra. |
| 2 | Boissons regroupées home menu | **PASS** | Section « 🥤 Boissons · 15 au frais » séparée sous les plats : **5 aperçus** + « VOIR TOUTES → » (page dédiée = 15 cartes). **0 canette** dans la grille plats (7 noms canettes sondés = 0 hit). Captures `R2-01/02` lues. |
| 3 | Nav mobile web (Pixel 7) | **PASS** | Burger → tiroir `#lc-mobile-menu` **plein écran** (largeur 100 %, hauteur 92,8 % = tout sous le header) ; **4 liens** Accueil/Menu/Commandes/Fidélité, chacun 380×52 px (tapable) ; tap « Menu » → grille rendue + tiroir fermé ; scrollWidth 412 = clientWidth 412 → **0 débord horizontal** (fermé ET ouvert). Capture `R3-02-tiroir-ouvert.png` lue. |
| 4 | `/m` PIN 2580 + propagation stock (Pixel 7) | **PASS** | PIN 2580 → catalogue stock rendu (rows par catégorie). Toggle **Perrier 33cl → rupture** : toast « Marqué en rupture » + Perrier apparaît dans « À ACHETER » (capture lue). API web `/api/frontend/item?branch_id=1` : `is_available=false` en **102 ms** (poll 2 s, budget 20 s). **RESTORE** : re-toggle → API `is_available=true` re-confirmé + bandeau « Aucune rupture — tout est en stock ✅ » (capture `R4-04` lue). **Zéro résidu** : état API final == initial (`obs-R4.json` : apiBefore/apiAfter identiques). |
| 5 | Pages légales | **PASS** | 5/5 en HTTP 200, contenu réel, **0 « À COMPLÉTER »**, 0 label brut : mentions (E.DELICE SAS · SIREN 104170501 · SIRET 10417050100019 · **RCS Béthune** · **APE 5610C** · TVA FR19104170501 · Vercel Inc hébergeur — capture lue), cgv (médiateur CM2C, 8 608 chars), privacy (OTP/RGPD, 7 937), cookies (5 054), allergens (halal, 4 973). |
| 6 | `/kiosk/idle` VPS | **PASS (avec limite infra)** | HTTP **200** + rendu propre (capture lue) : redirige vers `/kiosk/login` qui affiche l'écran de repli brandé « Borne de commande — Connexion machine non configurée côté serveur. Borne momentanément indisponible. Merci de passer commande en caisse 🙏 » + note sécurité « borne publique : aucun mot de passe à l'écran » + bouton Réessayer. **Limite infra documentée** (creds machine borne non seedés sur le VPS staging — cf. registre secrets), PAS un bug produit : dégradation gracieuse conforme, aucun label brut, aucune erreur crue. |

## P0 / P1

**Aucun P0, aucun P1.** L'intégrité numérique (panier == confirmation == backend scellé) est prouvée sur commande réelle ; aucune 4xx/5xx silencieuse sur les 4 parcours.

Notes mineures (hors périmètre bug) :
- **INFRA** : borne VPS non configurée côté serveur (item 6) — action owner déjà tracée (seed machine + rotation creds).
- **P3 owner-data** : mentions légales « Capital social : à confirmer par le gérant » — donnée entité restante owner-gated (déjà au registre ~15 données), ce n'est pas un placeholder « À COMPLÉTER ».
- **Artefact de sonde** (pas produit) : le hook JSON brut du POST order lit `id/serial/total` à la racine alors que la réponse est enveloppée → champs `undefined` dans `obs-R1.json` ; la preuve scellée passe par la confirmation (`funnel.jsx` lie `order.total` backend) + statut 201 + garde `expected_total`.

## Commande réelle passée

- **N° 230726193** — **10,40 €** — Cayenne (pain, Fromagère maison incluse + Mayonnaise +0,50, crudités Salade/Tomate/Oignon, Menu complet +2,50, boisson formule) — paiement au comptoir, statut « Envoyée en cuisine », téléphone d'audit 0699000723 (dev-OTP `?dev`).

## Discipline

- Visual-first : 8 captures clés LUES (wizard sauce/formule, confirmation, boissons, tiroir mobile, /m rupture+restore, kiosk idle, mentions) — layout intact, palette brand, aucun `kiosk.x`/`undefined`/`NaN`.
- Read-only respecté : aucun fichier produit modifié, rien commité ; seul side-effect = 1 commande de test réelle (#230726193, à encaisser/annuler en caisse) + toggle Perrier restauré (zéro résidu prouvé).

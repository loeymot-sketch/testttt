# E2E PARITÉ OPTIONS BORNE — validation navigateur réel (site web)

**Date** : 2026-07-19 · **Méthode** : Playwright Chromium (navigateur réel, viewport **mobile 392×850, DPR 2**),
site web standalone servi en local, API pointée sur le backend testttt live **:8000** (DB `foodking_e2e`).
**Objet** : valider les fixes de parité borne du commit **`eee151a`** (+ le fix drop `52d2469`/`06a0cf2`).

## 0. VERDICT — 4 / 4 **PASS** ✅

| # | Scénario | Option proposée | Option choisie | Panier | Payé (bouton) | Backend (commande) | Drop |
|---|---|---|---|---|---|---|---|
| 1 | **Menu Enfant Nuggets** (D3) | ✅ étape SAUCE (12 sauces, min 1, « OBLIGATOIRE ») | Samouraï | **4,90 €** | **4,90 €** | **4,90 €** (ordre 5825) | 0 |
| 2 | **Galette Cayenne** (D1) | ✅ **Cornichon** dans crudités (5 crudités) | Cornichon (gratuit) | **7,00 €** | **7,00 €** | **7,00 €** (ordre 5827) | 0 |
| 3 | **Bol Frites** (D2) | ✅ **Option Gratiné +2,00 €** | Option Gratiné | **9,90 €** | **9,90 €** | **9,90 €** (ordre 5826) | 0 |
| 4 | **Régression drop** (Galette + 2 sauces + 1 supp) | ✅ 2ᵉ sauce +0,50 · supplément +0,90 | Mayo+Ketchup + Champignons | **8,40 €** | **8,40 €** | **8,40 €** (ordre 5828) | **0** |

Pour chaque scénario : **panier affiché == total du bouton payer == `expected_total` envoyé == total commande backend == options présentes dans la composition NF525**. Aucun drop, aucune sauce auto-remplie.

---

## 1. SETUP (reproductible, repo réel intact)
- **Site** : copie de `lecayenne-web-deploy/Site lecayenne/` dans un scratchpad, `php -S 127.0.0.1:8090`. Override `<meta name="api-base-url">` → `http://127.0.0.1:8000` **dans la copie SEULEMENT** (repo réel vérifié inchangé : `index.html:11` reste `:8766`, `git status` propre).
- **CORS** OK (`config/cors.php` pattern `#^http://(localhost|127\.0\.0\.1):\d{2,5}$#` → origine `:8090` autorisée). `X-API-Key` web == `MIX_API_KEY` `.env`.
- **Auth** : token invité réel `kiosk:order` (30 j) frappé pour un compte guest préfixé **`E2E-PARITE Guest`** (user 262, `is_guest`, branch 0), injecté en `localStorage` (`lecayenne.authToken`) pour éviter le throttle `verify 3/5min`. **Le placement de commande reste 100 % navigateur + `api.js`** (`window.LC.api.placeOrder`, exactement le chemin du bouton « Confirmer la commande » du funnel).
- **Navigateur** : Playwright Chromium 1.58.2, mobile 392×850. React 18 + Babel standalone chargés depuis unpkg (connectivité OK). 0 erreur console.
- **Parcours joué en entier au navigateur** : menu → recherche → détail → **Personnaliser** → wizard (clics réels sur les tuiles d'option) → récap → panier → **Passer commande** → upsell → checkout → paiement → **confirmation** (ticket QR). Commandes réelles `POST /api/frontend/order` HTTP **201**.

---

## 2. SCÉNARIO 1 — Menu Enfant Nuggets : la SAUCE est DEMANDÉE (D3) ✅
- **Avant (bug)** : `has_sauce:false` → template `simple` (DirectAdd), sauce auto-remplie en silence par le top-up `min_select` = **Algérienne** (1ʳᵉ option attr5, backend var 578).
- **Après** : `has_sauce:true` + `wizard_template:'burger'` → **une étape SAUCE s'ouvre**.
- **Preuve visuelle** (`s1-01-sauce-step.png`) : en-tête « **1 / 2 · MENU ENFANT NUGGETS · OBLIGATOIRE** », titre **SAUCE**, « 1ʳᵉ sauce incluse », « Min : 1 · Sélection : 0 / 4 », **12 sauces listées** (Mayonnaise, Ketchup, Blanche, Hannibal🌶, Samouraï🌶, Algérienne, Andalouse, Curry, Barbecue, Harissa🌶, Fromagère maison, Spicy maison).
- Sauce choisie = **Samouraï** (≠ défaut Algérienne). Récap `s1-03` : « ÉTAPE 1 · SAUCE : Samouraï · Inclus · 4,90 € ». Panier `s1-04` : « Menu Enfant Nuggets · + Samouraï · 4,90 € ». Confirmation `s1-05` : ticket #1907265825, TOTAL **4,90 €**, ENVOYÉE EN CUISINE.
- **Backend (ordre 5825, composition_snapshot NF525)** : `item_id 40`, variation attr5 = **« Samouraï » (var 586)** — **PAS** Algérienne (578). `expected_total 4.9` == `total 4.9`.
- ✅ La sauce choisie par le client est respectée (fin de l'auto-remplissage silencieux).

## 3. SCÉNARIO 2 — Galette Cayenne : Cornichon offert (D1) ✅
- **Preuve visuelle** (`s2-01-crudites.png`) : « 3 / 6 · GALETTE CAYENNE · CRUDITÉS » → **5 crudités** : Salade✓, Tomate✓, Oignon✓ (défauts), **Cornichon**, Oignons cuits. `s2-02` : **Cornichon coché** (gratuit), footer « Continuer 7,00 € ».
- **Backend (ordre 5827)** : `item_id 24`, extras = Salade/Tomate/Oignon + **Cornichon (extra 80 @0,00)**. Panier 7,00 == `expected_total 7` == `total 7`.
- ✅ Cornichon proposé (galette-only, miroir backend items 23/24), sélectionné, **gratuit**, présent dans la commande.

## 4. SCÉNARIO 3 — Bol Frites : Option Gratiné +2,00 offerte (D2) ✅
- **Preuve visuelle** (`s3-01`/`s3-02`) : « 3 / 6 · BOL FRITES · SUPPLÉMENTS DU BOL », sous-titre « Optionnel · suppléments +0,90 € · **gratiné +2,00 €** ». Tuile **« Option Gratiné » +2,00 €** (badge ★ Populaire) **cochée**, footer « Continuer 9,90 € ».
- **Backend (ordre 5826)** : `item_id 41`, variations Viande Mexicanos + Sauce bol, extra = **Option Gratiné (extra 462 @2,00)**. Panier 9,90 == `expected_total 9.9` == `total 9.9`.
- ✅ Gratiné proposé sur Bol Frites (l'ancien flag faux `riz_only` retiré), sélectionné, **facturé +2,00**, présent dans la commande.

## 5. SCÉNARIO 4 — Régression drop (produit + 2 sauces + 1 supplément) ✅
- Galette Cayenne : viande Mexicanos (incluse) + **2 sauces** (Mayonnaise incluse + Ketchup +0,50) + **1 supplément** (Champignons +0,90) + Sans formule.
- **Preuve visuelle** — Récap `s4-03.png` : ÉTAPE 2 SAUCE « Mayonnaise, Ketchup » **+0,50 €** · ÉTAPE 4 SUPPLÉMENTS « Champignons » **+0,90 €** · bouton **Ajouter au panier 8,40 €**. Panier `s4-04` 8,40 €.
- **Backend (ordre 5828)** : `item_id 24`, variation Mayonnaise (1ʳᵉ incluse), extras = **Sauce supplémentaire @0,50** (pour Ketchup) + Salade/Tomate/Oignon + **Champignons @0,90**. `item_extra_currency_total 1,40 €`. Panier 8,40 == `expected_total 8.4` == `total 8.4`.
- ✅ **Toutes les options présentes, 0 drop** : base 7,00 + 0,50 + 0,90 = 8,40 = payé = backend.
- Bonus parité vérifié : la liste des suppléments galette compte **8 items SANS « Boursin »** (`galette_excluded`, miroir exact backend item 24) → aucun supplément fantôme droppable.

---

## 6. PREUVE BACKEND consolidée (composition_snapshot NF525, DB `foodking_e2e`)
```
ORDER 5825  total 4.90  item 40 (Menu Enfant Nuggets)
   VAR Sauce (1ère Gratuite): Samouraï @0            ← D3 (choix respecté, ≠ Algérienne défaut)
ORDER 5826  total 9.90  item 41 (Bol Frites)
   VAR Viande 1: Mexicanos @0 · VAR Sauce bol: Fromagère maison @0
   EXTRA Option Gratiné @2.00                         ← D2
ORDER 5827  total 7.00  item 24 (Galette Cayenne)
   VAR Viande: Mexicanos @0 · VAR Sauce: Mayonnaise @0
   EXTRA Salade/Tomate/Oignon @0 · EXTRA Cornichon @0 ← D1 (gratuit)
ORDER 5828  total 8.40  item 24 (Galette Cayenne)
   VAR Viande: Mexicanos @0 · VAR Sauce: Mayonnaise @0
   EXTRA Sauce supplémentaire @0.50 · EXTRA Champignons @0.90 ← drop-regression (0 drop)
```
Prix 100 % SSOT `PricingService` ; `expected_total` (témoin) == total backend pour les 4 → aucun 422, aucune sur/sous-facturation.

---

## 7. DÉFAUTS / RESTE
- **Aucun défaut fonctionnel** trouvé sur les 4 scénarios. Les 4 fixes de parité (D1/D2/D3 + drop) sont **prouvés en navigateur réel + confirmés en base**.
- **Fixtures** : menu **réel** utilisé (0 produit créé). 1 compte guest de test préfixé **`E2E-PARITE Guest`** (user 262) + 4 commandes de test (5825–5828, `source=web`) dans la DB de test `foodking_e2e` (non-production). Le serveur `php -S :8090` de test est arrêté en fin de run.
- **Repo web réel non modifié** : override d'URL uniquement dans la copie scratchpad (jamais committé). Pour la prod Vercel, régler `api-base-url` (+ `menu-image-base`) sur l'URL HTTPS du backend (finding connu, hors scope de cette validation).

## 8. SCREENSHOTS (analysés, `reports/goal-drop-prix-2026-07-19/parite-screens/`)
`s1-01-sauce-step` (12 sauces demandées) · `s1-03-recap` · `s1-04-cart` · `s1-05-confirm` (4,90 €) ·
`s2-01-crudites` (Cornichon proposé) · `s2-02-cornichon-chosen` · `s2-03-recap` · `s2-04-cart` · `s2-05-confirm` (7,00 €) ·
`s3-01-bol-supp` · `s3-02-gratine-chosen` (Option Gratiné +2,00 cochée) · `s3-03..05` (9,90 €) ·
`s4-01-2sauces` · `s4-02-supp` · `s4-03-recap` (breakdown +0,50 / +0,90) · `s4-04-cart` · `s4-05-confirm` (8,40 €).

# 04 — SYSTÈME WEB + APP MOBILE + MAJ MENU — plan test-e2e abusif

**Contract** : apps client **STANDALONE** (mobile `mobile/` JSX + web
`/Users/1millnonstop/Downloads/web/` JSX) + storefront backend client. Lentille =
🧑 **CLIENT**. ⛔ **AUCUN wireup API V1** (mandat owner — ne PAS câbler les APIs).
**Cette vague a des ÉCRITURES** (mise à jour `data/menu.js`) sur des arbres
**disjoints** du central → peut tourner **en parallèle de la vague CENTRAL**.
Palette mobile = **NOIR/ORANGE/JAUNE/BLANC** (PAS le rouge Cayenne `#F4501E`).

**Menu canonique (SSOT)** = `database/seeders/OwnerMenuUpdate20260623Seeder.php`.

**Anchors (vérifiés)** : `mobile/data/menu.js` (629 l), `mobile/screens-*.jsx`,
`mobile/data/{loyalty,orders}.js` ; `/Users/1millnonstop/Downloads/web/data/menu.js`
(499 l, mirror exact de mobile), `web/{screens,screens-v3,funnel,wizard-v2,account-v2,
loyalty-v2,orders}.jsx`, `web/tests/`, `web/web-validation-profonde-2026-06-10.spec.js` ;
storefront `resources/js/components/frontend/{account,auth,checkout,components,page,search}`
(vitrine home/menu/offers **SUPPRIMÉE**, commit `08ba2bae0`, routes→`/login`).

---

## INVENTAIRE PAGES

### Mobile (`mobile/`, JSX, NO API)
| Écran (fichier) | Rôle |
|---|---|
| Splash/Onb1-4/Login/OTP — `screens-onboarding.jsx` | splash, carousel 4 étapes, tél+OTP |
| ScreenHome `screens-main.jsx:80` / ScreenMenu `:208` / ScreenItem `:304` | accueil, catalogue grille, détail produit |
| ScreenCart `:594` / ScreenConfirm `:719` / ScreenOrders `:784` | panier, confirmation, historique |
| ScreenProfile `:916` / ScreenLoyalty `:1001` | compte, Pepper Club (QR, points, redeem) |
| ScreenItemWizard + 11 steps `screens-item-steps.jsx` | composition (Viandes/Sauce/Crudités/Suppléments/Menu/Drink/Frites/Bol/Recap) |
| modals `screens-modals.jsx` | choix paiement, points, redeem, carte, wallet |

### Web (`/Users/1millnonstop/Downloads/web/`, JSX, NO API)
| Écran | Rôle |
|---|---|
| WebHome `screens.jsx:74` / WebMenu `:369` / ItemDetailModal `screens-v3.jsx:195` | landing, catalogue 11 cats, détail+allergènes |
| WizardFlow `wizard-v2.jsx` / CartDrawer `flows.jsx:8` / Funnel `funnel.jsx:44` | composer, panier+promo, checkout (créneau→paiement→QR) |
| OrdersPage `orders.jsx:13` / WebLoyalty `screens.jsx:504` / AccountFlow `account-v2.jsx:12` | historique+reorder, Pepper Club, login/signup/OTP |
| WebAbout `screens.jsx:755` / `legal/*.html` | histoire, mentions/CGV/RGPD/cookies/allergènes |

### Storefront backend (Vue, token Sanctum **client**)
| Route | Composant | Rôle |
|---|---|---|
| `/home`,`/menu`,`/offers` → **redirect `/login`** | vitrine SUPPRIMÉE | — |
| `/page/:slug`, `/search` | `page/*`, `search/*` | CMS, recherche |
| `/edit-profile`,`/my-orders`,`/checkout`,`/address`,`/change-password` | `account/**`, `checkout/**` | compte + checkout (auth) |
| auth | `auth/**` (Login/Signup/Verify/Reset/Guest) | auth client |

---

## 🔴 DELTA MENU (le livrable critique — mobile = web, mirror exact)

⚠️ Les 2 apps portent l'**ANCIEN menu expérimental** ; le seeder canonique les a désavoués.

| Produit/cat affiché | Prix actuel | État vs canonique |
|---|---|---|
| Cat "Sandwich Cayenne" (id1) | — | ❌ renommer **"Sandwichs"** |
| Sandwich Cayenne `:403` | 7,00 | ❌ canon **"Cayenne" 7,40** |
| Big Cayenne `:407` | 9,50 | 👻 désactivé (item 36) — supprimer |
| Suprême 7,00 / Méga 8,00 (2 viandes) / Terminator 9,00 (2 viandes) | — | ➕ **3 MANQUANTS** |
| Cat "Galette" (id2) | 6,50/7,00 | ⚠️ conservée (gate owner) ; réaligner 7 viandes + 12 sauces |
| Cat "Sandwich Classique" (id3) + Big | 6,50/9,00 | 👻 masquée + items 25/37 INACTIVE — supprimer la cat |
| Chicken Burger `:436` | 6,90 | ❌ canon **4,90** |
| Big Chicken `:439` | 8,90 | 👻 désactivé (item 39) — supprimer |
| Cheese/Double Cheese/Fish/Big/Grill Burger | — | ➕ **5 burgers MANQUANTS** (6,00/7,00/6,00/9,00/8,00) |
| Tacos M `:448` / Tacos L `:452` | 6,90 / **8,90** | M ✅ ; **L doit être 7,90** (pas 8,90) |
| Bols Gourmands 8 items `:460-467` | 8,90 | 👻 6 fantômes : canon **2** (Bol Frites 7,90 / Bol Riz 7,90 gratiné), renommer cat "Bols", viandes 7 (pas poulet-only) |
| Cat Suppléments (9 vendables) `:481-491` | 0,90 | 👻 masquée — suppléments = options wizard |
| "Oignon frais" `:489` | 0,90 | ❌ canon **"Oignons frits"** |
| Desserts | 3,80 | ❌ canon **3,50** |
| Boissons canettes | 1,50 | ❌ canon **1,90** (Eau 1,00 ✅) |
| Menu Nuggets `:514` | 6,00 | ❌ canon **2 SKU @ 4,90** (Nuggets + Burger) |
| VIANDES (4 poulet-only) `:130-135` | — | ❌ canon **7 mixtes** (Mexicanos, Cordon Bleu, Viande Hachée, Nuggets, Tenders, Fricadelle, Poulet mariné) |
| SAUCES (11) `:138-150` | — | ❌ canon **12** (réaligner libellés) |
| CRUDITÉS (4, incl. Cornichon) `:153-158` | — | ❌ canon **3** (Salade/Tomate/Oignon) |
| Formule menu `:184` | **3,00** | ❌ canon **+2,50** |
| Viande supplémentaire | absent | ➕ extra **+2,50** (décision owner) |

**Résumé chiffré** : mobile = **~14 prix/noms à corriger** / **~17 fantômes à
supprimer** / **~9 produits manquants** + 3 jeux d'options faux (viandes 4→7,
sauces 11→12, formule 3,00→2,50) + 2 cats à renommer. **Web = identique** (mirror).
✅ Palette : **0 `#F4501E`** détecté (NOIR/ORANGE/JAUNE/BLANC conforme).

---

## DÉCOMPOSITION (4 sous-systèmes)

### Sub 4.a — MAJ `mobile/data/menu.js` au canon
- T-4.a.1 Viandes 4→7, sauces 11→12, crudités 4→3 (réaligner sur le seeder).
- T-4.a.2 Prix/noms : Cayenne 7,40, Chicken Burger 4,90, Tacos L 7,90, Desserts 3,50, Boissons 1,90, formule +2,50.
- T-4.a.3 Structure : Bols 8→2, +5 burgers, +3 sandwichs, Menu Enfant 1→2 SKU, extra viande +2,50, purge 17 fantômes, renommer 2 cats.
**Acceptance** : *(test À CRÉER `mobile/tests/menu.spec.js` — aucun harnais mobile présent)* + diff vs seeder + render visuel si servi.

### Sub 4.b — MAJ `web/data/menu.js` (idem, mirror)
- T-4.b.1 Appliquer le MÊME delta (même valeurs ligne-à-ligne).
**Acceptance** : `web-validation-profonde-2026-06-10.spec.js` étendu (assertions prix/produits) PASS sur 2 viewports (390/1280).

### Sub 4.c — Audit parcours client mobile (UX + palette)
- T-4.c.1 Onboarding→menu→wizard→cart→confirm cohérent ; palette NOIR/ORANGE/JAUNE/BLANC (0 rouge Cayenne) ; responsive portrait ; cibles tactiles.
**Acceptance** : captures Read+analysées + *(spec a11y À CRÉER)*.

### Sub 4.d — Audit storefront backend
- T-4.d.1 account/auth/checkout/search (vitrine déjà supprimée→`/login`) ; FR propre ; 0 raw-label.
**Acceptance** : Vitest `tests/js` + Playwright `/login`,`/checkout` verts.

---

## GERMES ADVERSAIRES (🧑 CLIENT mobile/web)
1. **Prix faux affichés (le pire)** : Tacos L 8,90 vitrine vs 7,90 caisse ; Chicken Burger 6,90 vs 4,90 ; Desserts 3,80 vs 3,50 ; canettes 1,50 vs 1,90 ; formule 3,00 vs 2,50 → litige + perte de confiance.
2. **Produits fantômes** : Big Cayenne/Big Chicken/6 Bols/Sandwich Classique/9 suppléments → client commande l'inexistant.
3. **Produits manquants** : Suprême/Méga/Terminator/5 burgers absents → moitié de carte invisible.
4. **Viandes incohérentes** : 4 poulets affichés vs 7 mixtes réelles → off-canon total.
5. **Libellés** : "Oignon frais"→"Oignons frits", "Bols Gourmands"→"Bols", "Sandwich Cayenne"→"Sandwichs".
6. **Palette** : vérifier 0 `#F4501E` (✅ actuel) ; brand orange `#FF5A1F`, jaune `#FFD93D`.
7. **A11y/responsive** : web a axe-core A+AA ; **mobile sans test** → créer spec.

⛔ **NO API wireup V1** : standalone, ne PAS proposer de câbler les APIs backend.

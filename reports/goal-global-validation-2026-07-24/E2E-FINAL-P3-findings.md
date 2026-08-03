# E2E-FINAL Phase 3 — Scan Facture + Conso&Stock unifiée + non-régression

**Date** 2026-07-24 · **Outil** Playwright (chromium Desktop + Pixel 7), navigateur réel ·
**Cible** SPA servie `127.0.0.1:8000` (API base configurée `:8766`, même app/DB) ·
**Spec** `tests/Playwright/e2e-final-p3-2026-07-24.spec.js` · **Résultat** 2/2 passed ·
**Captures** `tests/captures/e2e-final-p3-2026-07-24/` (11 PNG lus visuellement)

## Verdict par écran

| Écran | Rendu | Technique | Verdict |
|---|---|---|---|
| Scan Facture `/admin/purchasing/scan` | Bandeau démo, upload, 4 propositions (badges IA+score), dropdowns peuplés, qté/prix éditables, cartes mobile OK | `targets`/`scan`/`validate` = 200, 0 console error, validation **réelle** au stock | **PASS** |
| Conso & Stock `/admin/stock/unified` | À-acheter en haut (12), 2 rayons, totaux, bandeau coût manquant, recherche, cartes mobile 2-col | `unified-overview` 200, 0 label brut, 0 erreur | **PASS** |
| POS `/admin/pos` (non-rég) | Caisse complète : Ticket, À-encaisser borne (5), Commandes web (9) | pas de login, panneaux live | **PASS** |
| KDS `/kds` (non-rég) | Board rendu (carte, bump « Prêt », impression) | pas de login | **PASS** |
| Catalog-hub `/admin/catalog-hub` (non-rég) | 2 onglets (Catalogue actif + Produits & Stock) + grille | pas de login | **PASS** |

## Preuve anti « faux succès » (attaque centrale)

La validation applique VRAIMENT au stock — prouvé end-to-end, pas de faux succès :
- `POST /purchasing/3/validate → 200` ; bandeau « Entrée en stock validée — 2 matière(s),
  1 produit(s), 1 charge(s) » ; doc → **appliqué** ; lignes verrouillées ; bouton retiré.
- **La vue unifiée reflète l'écriture** : VALEUR STOCK `0,00 € → 30,00 €`, Boissons `(0) → (1)`,
  À-racheter `13 → 12`, bandeau coût-manquant `13 → 12`. Cohérent au centime : Cheddar =
  ligne Poulet (3×6) + ligne Cheddar (100×0,12) = 30,00 €.
- **Dropdown modifié** délibérément (Poulet → Cheddar) et respecté par la validation.
- IA cohérente : Poulet/Cheddar→Matière (100 %), Coca→Produit revendu/boisson (90 %),
  Sac→Charge (75 %). 0 label brut `admin.unified_stock.*` (35 clés fr.json résolues).

## Findings

**P2 — Endpoint `validate/apply` non gardé par `permission:items_create`.**
`PurchasingScanController::__construct` fait `->only(['scan','validate','targets'])`, mais la
méthode réelle est **`apply`** (route `purchasing.validate` → `apply`). Laravel ignore
`'validate'` (méthode inexistante) → le middleware permission ne s'applique PAS à l'application
au stock. Le groupe (`routes/api.php:311`) n'a que `auth:sanctum`+`apiKey`, sans gate permission.
Donc tout utilisateur authentifié SANS `items_create` peut appliquer une entrée stock
(`avg_cost`/niveaux) sur un doc à id devinable. **Impact V1 LOCAL faible** (opérateur admin
unique = toutes perms), mais incohérence d'autorisation réelle (scan gardé, validate non) +
risque latent V2 multi-users. **Fix** : `->only(['scan','apply','targets'])`. Additif, hors NF525.

**P3 — cosmétiques / non bloquants** : chevauchement texte en-tête POS V4 (« CAISSE LE
CAYENNE / rapide », pré-existant, zone POS frozen, hors Phase 3) ; select natif mobile
« Matière premiè… » tronqué (comportement natif acceptable).

## Artefacts de sonde (écartés — PAS des défauts)

- `tiles:0` sur POS = mon sélecteur ratait la grille vanilla-JS ; caisse rend (panier+panneaux).
- 1er run `validate_enabled:false` = bug de ma sonde (placeholder pris pour option réelle) →
  corrigé → validation réelle au 2e run.
- Bandeau succès hors cadre capture 04 = layout admin à scroll interne ; confirmé au DOM.
- API `:8766` ≠ prompt `:8000` = base API configurée du SPA, même app/DB.
- Données de test (catégories Faker, item E2E_PLAYWRIGHT, commande KDS W8675 375 h) = bruit DB dev.
- **Écriture attendue** : le scan crée un `PurchaseDocument` draft + la validation applique au
  stock (autorisé par le prompt). Additif, hors NF525.

**Conclusion** : les 2 nouveaux écrans Phase 3 sont production-grade (visuel + technique,
desktop + Pixel 7) ; non-régression POS/KDS/catalog-hub verte. 1 finding code P2 (authz
defense-in-depth, fix 1 mot), impact V1 faible.

# TEST-E2E MAX LOGIQUE — chaque produit borne + caisse — CONVERGÉ

**Date** : 2026-07-08 · **Env** : local `:8766` (même code que le déploiement en cours, HEAD `6b0a0ac1e`).
**Verdict** : ✅ **P0+P1 = 0**, 2 cycles consécutifs identiques (déterministe). Audit clos.

## Périmètre
Les **42 produits actifs** du menu Le Cayenne (10 catégories : Sandwichs, Galette, Tacos,
Burgers, Bols, Frites, Menu enfant, Desserts, Boissons) testés sur **les 2 surfaces** :
- **Borne** — endpoint réel `POST /api/frontend/order/quote` (token kiosk + apiKey).
- **Caisse** — endpoint réel `POST /api/admin/pos/quote` (token admin).

## Méthode
Pour chaque produit, **composition minimale valide auto-construite** depuis la structure
réelle (KioskMenuService) : `min_select` variations par attribut requis (Viande 1/2, Sauce,
Pain, Sauce bol). Vérifié par produit : **(a) orderabilité** (HTTP 200, devis scellé émis),
**(b) prix** (`total_ttc` == prix attendu = base + variations). Puis passe **adversaire**
sur le pricing des OPTIONS.

## Résultats

| Surface | Produits OK | Détail |
|---|---|---|
| **Borne** | **42 / 42** | orderable + prix exact |
| **Caisse** | **42 / 42** | orderable + prix exact |
| **Cohérence borne↔caisse** | **42 / 42** | prix **identiques** (PricingService SSOT) |

Exemples (borne = caisse) : Cayenne 7,40 · Suprême 7,00 · Méga 8,00 (2 viandes+sauce+pain) ·
Terminator 9,00 · Tacos M 6,90 · Tacos L 7,90 (2 viandes) · Galettes 6,50/7,00 · 6 burgers
4,90→9,00 · Bols 7,90 · 6 frites 2,50→6,00 · 2 menus enfant 4,90 (Nuggets + Chicken Burger) ·
3 desserts 3,50 · 15 boissons 1,00→1,90.

### Passe adversaire — pricing des OPTIONS (5/5)
- Cayenne base = **7,40** ✓
- Cayenne + Cheddar (0,90) = **8,30** ✓ (supplément payant ajouté exactement)
- Cayenne + 2 crudités gratuites = **7,40** ✓ (crudités = 0, prix inchangé)
- Tacos M + Viande supplémentaire (2,50) = **9,40** ✓
- Coca-Cola × 3 = **5,70** ✓ (quantité multipliée)

## Visuel (cette session, mêmes bundles)
- **Borne** : idle → catégories → wizard tacos (Viande → Sauce → **Crudités** → Suppléments →
  Menu → Récap) → upsell (images entières) → panier → paiement → cash-instruction. Rendu OK.
- **Caisse** : grille → wizard Cayenne (Pain/Crudités/Sauce/Formule/Suppléments) → bouton
  **Modifier** (rouvre le wizard) → paiement (Espèces / **Carte TPE simulation** / **Multi-paiement**)
  → file **à encaisser** avec **🖨️ Cuisine / 🖨️ Client**. Rendu OK.
- **Console** : seulement les 401 pré-autologin borne (kiosk-event/menu/login) — bénins,
  transitoires (l'app retente avec le token et charge). Aucune erreur applicative.

## Convergence
- **Cycle 1** : Borne 42/42, Caisse 42/42, options 5/5 → 0 P0/P1.
- **Cycle 2** : identique (42/42 + 42/42, 0 échec). Tests déterministes.
- 1 faux positif attrapé = **bug de MON test** (parsing `data.total_ttc`), pas un défaut app.

**Aucun produit non commandable, aucun prix faux, aucune page blanche en local, aucune
divergence borne↔caisse.** Le point blocage/page-blanche signalé restait un **bundle stale
côté machine** (réglé par le déploiement + cache Chrome propre, cf. mission cowork).

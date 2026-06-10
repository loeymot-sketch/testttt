# Validation visuelle profonde — release/v1 intégré (2026-06-10)

> Captures live :8768/foodking_e2e (Chrome système, analysées via Read, mandat §6). Arbre intégré (spine+printer+cms+fix).

## W-V1 — CMS GESTION (surfaces neuves cms) — ✅ PROPRE
- **Catalogue/Pilotage** (`/admin/items`) : « PILOTAGE CATALOGUE — Produits, catégories, offres et disponibilités · POS/borne », **45 produits / 12 catégories / 45 actifs / 0 indisponibles**, liste articles (images+prix+statut Actif+actions Voir/Modifier/.../Supprimer), panneau « Ajouter un article » complet (NOM/PRIX/CATÉGORIE/TAXE/IMAGE/TYPE/MIS EN AVANT/SUGGESTION CAISSE/STATUT/ATTENTION/**CANAUX DE DIFFUSION Borne/Caisse/Site Web**/DESCRIPTION). FR, palette Cayenne, 0 label brut, 0 console err. → `cms/v1-catalog-studio.jpg`
- **Hiérarchie catégories** (create) : modale « Catégories » avec **« CATÉGORIE PARENTE »** (sélecteur — feature cms) + aide « Le wizard d'une catégorie parente ne s'applique pas automatiquement à ses sous-catégories », IMAGE/STATUT/DESCRIPTION/Paramètres avancés ; liste 12 catégories (Desserts, Boissons, Menu enfant…). FR, 0 label brut. → `cms/v2-category-create-parent-selector.jpg`

## W-V3 — PARCOURS RÉEL (entrée storefront intégré) — ✅ PROPRE
- **Kiosk idle** : « Bienvenue ! Commandez en quelques touches », logo Le Cayenne, CTA tactile, carte « À emporter — Je récupère ma commande », « CHOISISSEZ UNE OPTION POUR COMMENCER ». Palette Cayenne orange, FR, **pas de « Sur place »** (V1 dine-in off), 0 console err. → `journey/j1-kiosk-idle.jpg`
- Parcours complet kiosk→KDS→OSS déjà capturé+prouvé par `zz-sync-fresh-order-kds-oss` (2 cycles sur :8768, orders 4490/4491) ; central sweep 8/8 (dashboard/stock/encaissement/oss/kds/historique) sur :8768.

## W-V2 — IMPRESSION REÇU
- Backend ESC/POS prouvé par 62 PHPUnit (renderer structure + champs fiscaux + duplicata + netting TVA + claim anti-double + listeners) — verts sur l'arbre intégré. Rendu visuel du reçu = via le flux POS (couvert techniquement ; capture UI = post-DATA-1 quand le catalogue/seed permet une commande POS complète).

## Verdict visuel
Les surfaces NEUVES intégrées (CMS gestion) + l'entrée parcours réel rendent proprement sur l'arbre unifié : FR, palette Cayenne, 0 label brut, 0 console err, données réelles (45/12). Combiné aux suites (3125/2111) + e2e 2 cycles + adversaire release EXHAUSTED → l'arbre intégré est validé technique + UI/UX.

## Adversaire visuel (axe-core + surfaces non-capturées) : EXHAUSTED — 0 P0/P1
Surfaces additionnelles rendues+attaquées : composer builder (loaded+vide), catégorie EDIT (parent-selector exclut self ✓), CatalogStudio, stock-rupture (hiérarchie « ↳Tacos Signature »), **+ axe-core que le pilote n'avait pas lancé**. SSOT re-confirmé : 45/12, Sandwich Cayenne 7,00 €, produits Le Cayenne authentiques (pas le 63 étranger). **No price-on-step = contrat NF525 respecté.**
5 findings P2/P3 (aucun P0/P1) :
- **VADV-1 (P2) HEALÉ** : accents FR manquants dans le composer SHIPPED fr.json → Portée/Édition/Aperçu/Rafraîchi après/Publié/Dépublier. Live-vérifié (portee_new=true, edition_new=true).
- **VADV-2 (P2) HEALÉ** : token brut `item_attribute` fuitait sur le chip d'étape → `sourceLabel()` humanise (label « Attribut produit »). Live-vérifié (raw absent, label présent).
- **VADV-3 (P2) HEALÉ** : axe button-name CRITICAL (edit-icon ×10 + close-btn drawer) + label (input #image) → aria-label miroir delete + `for="image"`. **axe button-name + label = 0** sur surface CMS (drawer ouvert).
- **VADV-4 (P3) DIVULGUÉ — gate owner** : orange marque #F4501E sur blanc = 3,49:1 (<4,5 AA). C'est la couleur de marque Cayenne mandatée (CLAUDE.md §3bis) → tension marque-vs-AA, décision owner (pas un bug de câblage).
- **VADV-5 (P3) DIVULGUÉ** : copy 404 « Commande introuvable » sur composer d'item soft-deleted (404 correct, aucun lien live n'y mène) + `aria-required-children` sur profile-menu admin-shell (dette globale hors-CMS).

## CONVERGENCE validation profonde visuelle release/v1
0 P0/P1 · adversaire visuel EXHAUSTED · 3 P2 healés + re-vérifiés live (axe button-name/label = 0) · frozen 0 · i18n parity 74/74 · captures analysées (catalogue/hiérarchie/kiosk/composer/heals). UI/UX de l'arbre intégré = solide. Restes = gates owner (orange marque, DATA-1, PUSH-1) + dette a11y admin-shell globale (sweep séparé).

# Plan — test-e2e goal4-predeploy-2026-07-17 (pré-deploy VPS)

Contexte : validation complète après le GOAL 4 corrections (commits c3425ee28→9b7e4fd5a) avant `tools/deploy-lecayenne.sh`. Serveur dev 127.0.0.1:8000, DB foodking_e2e.

## Vagues

### Wave A — BORNE (viewport 1080×1920)
États : A01 idle · A02 categories (tuiles cat = vignettes webp) · A03 grille menu-enfant (image poulet item 106) · A04 wizard Nuggets étape sauce (12 tuiles) · A05 sauce sélectionnée + SUIVANT → récap (prix 4,90) · A06 wizard kids-burger crudités · A07 étape suppléments (0,90) · A08 grille bols · A09 wizard Bol Frites jusqu'à QUEL SUPPLÉMENT (CHOISIR viande → sauce → assert Option Gratiné 2,00) · A10 panier avec 1 nuggets (total 4,90).
Intégrité : 4,90 constant grille/wizard/panier ; gratiné 2,00.

### Wave B — CAISSE (viewport 1440×900)
États : B01 login POS OK · B02 grille home (vignettes cat) · B03 grille menu-enfant · B04 popup Nuggets (chips 12 sauces, « 1ère gratuite ») · B05 sauce choisie → Ajouter au panier → ligne ticket 4,90 · B06 popup kids-burger (crudités cochées) · B07 accordéon « + Suppléments » DÉPLIÉ (liste 0,90 visible) · B08 modal Bol Frites (gratiné 2,00 dans les extras).
Intégrité : 4,90 ticket = grille = API ; 0,90 suppléments ; 2,00 gratiné.

### Wave C — CŒUR smoke (1440×900)
États : C01 /login · C02 /admin/pos chargé · C03 /kds (login chef) · C04 /admin/order-status-screen · C05 /admin/items · C06 /admin/stock/rupture.
Checks : page rend, 0 raw label (regex kiosk\.|Label\.|\{\{|undefined), console clean hors vendor, network sans 4xx/5xx silencieux.

## Savoir sélecteurs (transmis aux agents — évite les pièges déjà payés)
- Borne : `loginAsKiosk` (helpers/login.js) → `kiosk-idle-root` → `kiosk-order-type-takeaway` → `/kiosk/categories?cat=<id>` ; cartes `kiosk-product-card-<id>`, ajout `kiosk-product-add-<id>` (ids : nuggets 40, kids-burger 106, bol-frites 41, cat enfant 11, bols 6).
- Fermer wizard borne : bouton « ABANDONNER L'ARTICLE » (regex hasText /abandonner l/i — apostrophe typographique !) PUIS confirmer `.kiosk-wizard-abandon-yes` (overlay bloque tout sinon). JAMAIS matcher « Abandonner » nu (= « Abandonner ma commande » → idle).
- Étapes bornes à min-select : cliquer `CHOISIR` (viande) ou une tuile sauce (ex. texte Mayonnaise) sinon SUIVANT n'avance pas ; scoper les lectures texte à `.kiosk-wizard-overlay` (body.innerText lit la page dessous).
- Caisse : `loginAsPosOperator` → /admin/pos ; ouvrir popup = clic sur l'IMG de la carte produit ; fermer = bouton Annuler SCOPÉ `#item-variation-modal` + waitFor hidden.
- Ne jamais finaliser un paiement (pas d'ordre fiscal pollué) ; ajouter au panier OK.

## Boucle
Round N : 3 GStack capture ∥ → 3 adversarial ∥ → aggregate P0+P1 → fix clusters → re-run. Convergence = 2 rounds consécutifs P0+P1=0 findings identiques → deploy.

# Ultra-audit web — passe VISUELLE (orchestrateur) · 2026-07-19

Site déployé `https://site-lecayenne.vercel.app` (HEAD web `7149d70`, ?v=f) → backend VPS.
Surfaces vues : home (hero/stats/signature/envies-du-moment/mobile-promo/hours/footer), menu+catégories+Boissons,
détail produit (Cayenne, Coca), wizard Tacos L complet, panier, upsell, checkout, paiement, OTP, confirmation+QR,
suivi client, historique commandes, fidélité (trophées/QR/paliers), FAQ, mentions légales.

## Rendu global : PROPRE
Pas d'écran blanc, pas de label brut, layout intact, images produits chargent (Méga/Cayenne/Coca/Tacos),
copie honnête (100% à emporter, règlement comptoir, pas de livraison), contact exact (03 65 67 82 91,
437 Rue Élie Gruyelle 62110, 18h-00h), horaires dynamiques ok, trophées honnêtes (0 débloqué sur compte neuf),
créneaux = « dès que prêt » seul. Menu = 38 produits, 9 catégories.

## Findings (passe visuelle) — tous OWNER-GATED (données légales / décision produit)

### V-P2-1 · Pages légales : 21 × `[À COMPLÉTER]` visibles publiquement — OWNER
`legal/mentions.html` (5), `legal/privacy.html` (7), `legal/cgv.html` (4), `legal/cookies.html` (4),
`legal/allergens.html` (1). Ex. mentions : Forme juridique, Capital social, RCS, Code APE, Directeur de
publication = placeholders. Ce sont des **données d'entité légale** — je ne peux PAS les inventer (fabrication
interdite). **Action OWNER** : fournir les valeurs → je les pose. C'est un mentions-légales incomplet public
(obligatoire en droit FR) mais non bloquant tant que 0 client réel. Note : `SIREN 104170501`, `SIRET 104170...019`,
`TVA FR19104170501`, adresse = DÉJÀ remplis (corrects).

### V-P3-2 · Promo « app mobile » pour une app non publiée — décision OWNER
- Home `screens.jsx:293-295` : label « Bientôt sur iOS & Android » = **non-interactif** (`cursor:default`,
  opacity 0.7) → PAS un dead-CTA, juste un label « bientôt ».
- Footer `components.jsx:222-223` : boutons `📱 iOS` / `🤖 Android` stylés en badges app-store, cliquables →
  toast « L'app iOS/Android arrive bientôt — démo V1 ». Honnête (toast dit « bientôt/démo »), mais ressemble à
  des CTA de téléchargement pour une app non livrée (l'app RN existe en dev, non publiée store).
- **Décision OWNER** : garder (anticipation app) OU retirer/atténuer (honnêteté « pas de fausse promesse »).
  Je peux retirer les 2 en 1 edit si tu veux.

## À croiser avec les 4 agents code (funnel / catalog-wizards / account-loyalty / static-trust-a11y)
Le static-trust-a11y agent doit confirmer V-P2-1 et V-P3-2 côté code + trouver le reste (a11y, console/réseau).

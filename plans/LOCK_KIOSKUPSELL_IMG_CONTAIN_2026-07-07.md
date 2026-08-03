# LOCK_KIOSKUPSELL_IMG_CONTAIN — upsell borne : images entières (fin du crop)

> Override frozen §7 (`KioskUpsellComponent.vue`). Status **APPROVED** — l'owner
> a explicitement signalé (2026-07-07) « lors de proposé les upsell leur image
> sont coupé et pas visible » et demandé le correctif + test-e2e → gate §10
> satisfaite par instruction owner directe.

## §1 Identification
- LOCK ID : `LOCK_KIOSKUPSELL_IMG_CONTAIN`
- Créé/approuvé : 2026-07-07 (instruction owner directe)
- Fichier frozen : `resources/js/components/frontend/kiosk/KioskUpsellComponent.vue` (CSS `<style scoped>` uniquement)

## §2 Changement (chirurgical, 1 propriété CSS)
`.kiosk-upsell-img { object-fit: cover }` → `object-fit: contain`.

`cover` remplit le cadre 176 px de haut en RECADRANT : les visuels de canettes
(Fanta/Coca/Oasis/Sprite) et de produits verticaux étaient tronqués haut+bas
(preuve capture e2e `upsell-images-check.png` — on ne voyait qu'une tranche
horizontale). `contain` affiche le produit ENTIER, mis à l'échelle dans le cadre
(letterbox sur le fond `--kiosk-product-media-bg` déjà présent). Aucun autre style
touché : dimensions du cadre, hover scale, fallback emoji inchangés.

## §3 Acceptance (binaire)
- [ ] `object-fit: contain` présent, `cover` retiré sur `.kiosk-upsell-img`.
- [ ] E2E borne : écran upsell → chaque carte affiche le produit entier (canette
      complète visible, plus de tranche coupée). Capture Read + analysée.
- [ ] Aucune autre règle CSS/markup modifiée (diff = 1 ligne).
- [ ] Baseline SHA-256 frozen mise à jour dans le MÊME commit (sentinelle verte).

## §4 Rollback
`git revert <sha>` (commit frozen isolé) → retour `object-fit: cover`. Branche
filet `backup/pre-convergence-golive-2026-07-07`.

## §5 Sub-agent
Édition directe orchestrateur (1 ligne CSS). Vérif post-patch : e2e visuel + SHA baseline.

## §6 Sign-off
Owner : APPROVED par instruction directe 2026-07-07 (« leur image sont coupé …
test-e2e pour validé »). View-layer pur, aucun impact prix/logique/fiscal.

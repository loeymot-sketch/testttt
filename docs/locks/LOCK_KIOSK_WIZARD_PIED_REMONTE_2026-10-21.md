# LOCK — Édition frozen KioskWizardComponent.vue (pied du wizard remonté sur la borne)

**Date :** 2026-10-21
**Fichier frozen touché (CLAUDE.md §7) :** `resources/js/components/frontend/kiosk/KioskWizardComponent.vue`
**SHA-256 avant :** `f8ecb1118ffeae715a60bccbc58a025d157dbfcd43260ab3e4c41171ebe06465`
**SHA-256 après :** `aeb0095fc11da9d19bdf927e73492397fd31b22097b0a193a1d6443de096a909`
**Baseline mise à jour :** `tests/Feature/Sentinels/frozen-zone-sha256-baseline.json` (même commit que le correctif)

## Pourquoi

Demande explicite du propriétaire (commande `/goal`, 2026-10-21) : « dans la personnalisation les gens sont perdus,
ils doivent baisser la tête, ils cherchent [les boutons]… on les mettra un peu plus haut comme les autres bornes… le bouton
en bas, c'est trop bas, c'est difficile à atteindre. J'ai constaté ça devant moi, les clients sont perdus. »

Sur la borne (1080×1920), la barre Abandonner / Précédent / Suivant / Total était collée au bord bas de l'écran, et ses
boutons ne faisaient que 52 px de haut.

## Le changement (CSS seul — bloc `<style>`, aucune ligne de logique, de gabarit ni de script)

1. `.kiosk-nav` : le padding bas devient `calc(12px + var(--kiosk-nav-lift, 0px))` — une **réserve** sous les boutons, qui
   les remonte sans déplacer la barre (fond et ombre continuent jusqu'au bord).
2. `@media (min-height: 1400px) { .kiosk-wizard { --kiosk-nav-lift: 110px } }` — la réserve n'existe que sur un écran de
   borne portrait ; sur un écran bas (poste de développement, tablette paysage) elle vaut 0 et rien ne bouge.
3. Boutons plus grands, à portée du doigt : `min-height` 52→64 px (Abandonner/Précédent/Suivant, puce Total) et 58→72 px
   pour le « Ajouter au panier » final ; police du « Ajouter au panier » 14→16 px.

Aucun changement du chemin d'ajout au panier, du calcul de prix, de la sérialisation, du stepper, des validations
(`canAdvance`). Les LOCKs antérieurs (`LOCK_KIOSK_WIZARD_MODIFIER_DEPUIS_RECAP`, `…MULTI_VIANDE`, `…FORMULE_SPLIT`) sont intacts.

## Vérification

- **Playwright à 1080×1920** (`tests/Playwright/kiosk-pain-reco-pied-2026-10-21.spec.js`) : l'écart entre le bas des boutons
  et le bord de l'écran passe à **122 px** (assertion ≥ 110) ; captures lues, layout intact sur les étapes pain, viande,
  sauce et suppléments ; 0 erreur JS.
- **Vitest borne : 937 verts / 119 fichiers** avant ajout du spec dédié (6 tests de plus, verts).
- Sentinelle `FrozenZone` verte après mise à jour de la baseline.

## Autorisation

Demande explicite du propriétaire, ci-dessus.

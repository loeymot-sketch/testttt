/**
 * [QA 2026-09-28 — RAPPORT_DEV_CAISSE_2026-09-24 P1-30 / triage A5]
 *
 * Un produit peut être `status = ACTIVE (5)` au catalogue GLOBAL et pourtant
 * indisponible sur la branche courante (`item_branch_availability`, auto-86,
 * rupture de stock). Le backend replie déjà les deux notions une seule fois
 * (`ItemService::applyBranchAvailabilityOverlay`) et expose le champ canonique
 * `is_available` (`SimpleItemResource`).
 *
 * Décision du dépôt, MISSION FIX D4 2026-05-21 : la pastille de statut des
 * écrans catalogue DOIT refléter la disponibilité par branche, pas seulement
 * `items.status`. `ItemListComponent` l'appliquait ; `CatalogStudioComponent`
 * — pourtant la page atteinte par l'entrée de menu « Catalogue » — ne lisait
 * que `item.status` et annonçait donc « Actif » sur un produit en rupture.
 *
 * Ce helper est la SEULE définition du prédicat, pour que les deux écrans
 * cessent de porter deux copies de la même table de vérité.
 *
 * Test volontairement STRICT : seuls `false`, `0` et `'0'` déclarent une
 * rupture. Une clé absente ou `null` ne doit JAMAIS peindre tout le catalogue
 * en rupture — un payload sans overlay vaut « pas d'information », pas
 * « indisponible ».
 *
 * @param {{ is_available?: boolean|number|string|null }|null|undefined} item
 * @returns {boolean}
 */
export function itemIsRuptured(item) {
  if (!item) return false;
  return item.is_available === false
    || item.is_available === 0
    || item.is_available === '0';
}

export default itemIsRuptured;

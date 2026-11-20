/**
 * [AUDIT 2026-04-17 C6] Filtre des addons « boisson » pour l'étape menu borne.
 *
 * PRIORITÉ 1 : group_label explicite (`boisson`, `drink`) — source catalogue.
 * PRIORITÉ 2 : heuristique regex sur le nom (fallback pour données legacy).
 *
 * Les accompagnements solides (frites, menu, nuggets, wrap, etc.) sont
 * exclus explicitement : ils sont gérés par KioskStepMenu (choix formule)
 * ou les upgrades frites, pas comme boisson.
 */

const FOOD_LIKE_REGEX = /frite|menu|patate|nugget|tender|onion|oignon|mozzarella|accompagn|snack|dessert|glace|wrap|cornet|potato|boulette|stick|ring|douille|corbeille|panier|barquette|salade/i;
const DRINK_LIKE_REGEX = /\b(coca|cola|pepsi|fanta|sprite|schweppes|eau|thé|tea|ice\s?tea|jus|boisson|soda|drink|limonade|orangina|oasis|tropico|café|coffee|red\s?bull|vittel|evian|perrier|badoit|heineken|1664|kronenbourg|desperados|kas|san\s?pellegrino|lipton|nestea)/i;
const GENERIC_DRINK_OPTION_REGEX = /^\s*(?:\+?\s*)?(boisson|drink)(?:\s+(seule?|only))?\s*$/i;

export function kioskIsFoodLikeAddonName(name) {
  return FOOD_LIKE_REGEX.test(String(name || '').toLowerCase());
}

export function kioskIsGenericDrinkOptionName(name) {
  return GENERIC_DRINK_OPTION_REGEX.test(String(name || '').toLowerCase());
}

/**
 * Résout le group_label effectif d'un addon.
 * Essaie plusieurs chemins pour compatibilité avec différentes versions
 * de l'API (addon direct, addon.item, addon.addonItem).
 */
function addonGroupLabel(addon) {
  const candidates = [
    addon?.group_label,
    addon?.addonItem?.group_label,
    addon?.addon_item?.group_label,
    addon?.item?.group_label,
  ];
  for (const c of candidates) {
    if (c != null && c !== '') return String(c).toLowerCase();
  }
  return '';
}

export function kioskIsDrinkAddon(addon) {
  const name = String(addon?.addon_item_name || addon?.name || '').toLowerCase();
  if (kioskIsGenericDrinkOptionName(name)) return false;

  const gl = addonGroupLabel(addon);
  if (gl !== '') {
    if (gl === 'boisson' || gl === 'drink' || gl === 'drinks' || gl === 'beverage') return true;
    if (gl.includes('frite') || gl.includes('menu_') || gl === 'menu' || gl.includes('food')) return false;
  }
  return kioskIsDrinkAddonName(name);
}

export function kioskIsDrinkAddonName(name) {
  const n = String(name || '').toLowerCase();
  if (kioskIsGenericDrinkOptionName(n)) return false;
  if (kioskIsFoodLikeAddonName(n)) return false;
  if (n.includes('frite') || n.includes('menu')) return false;
  return DRINK_LIKE_REGEX.test(n);
}

/**
 * [QA 2026-09-28 — RAPPORT_DEV_CAISSE_2026-09-24 P0-16 / triage A5]
 *
 * Une boisson en RUPTURE ne doit pas être proposée dans l'étape menu de la
 * borne. Le backend calcule déjà cette disponibilité par branche et l'envoie
 * sur chaque addon (`KioskMenuService::menuPayload` → `addons[].is_available`,
 * via `ChoiceAvailabilityResolver::availabilityForAddonItem` qui replie le 86
 * de `item_branch_availability`) — ce filtre ne fait que la CONSOMMER.
 *
 * Ce n'est pas une règle nouvelle : les deux jumeaux du dépôt la respectent
 * déjà, seul le chemin addon de la borne l'avait oubliée —
 *   - `public/js/pos-wizard.js:609` (zone gelée §7, laissée intacte) ;
 *   - `KioskStepMenuComponent.isDrinkCatalogItem` (repli catalogue du MÊME
 *     composant, qui filtre `row.is_available === false`).
 *
 * Contrôle volontairement STRICT : seuls `false` et `0` (booléen sérialisé en
 * entier par l'API) masquent la ligne. Une clé absente ou `null` laisse la
 * boisson visible — sinon un payload legacy viderait toute la carte.
 *
 * @param {{ addons?: any[] }|null|undefined} item
 * @returns {any[]}
 */
export function kioskDrinkAddonRowsFromItem(item) {
  if (!item?.addons?.length) return [];
  return item.addons.filter((a) => kioskIsDrinkAddon(a) && !kioskAddonIsOutOfStock(a));
}

/**
 * Vrai uniquement si l'addon est explicitement déclaré indisponible.
 * `undefined`/`null` → false (on ne masque pas sur une absence d'information).
 */
export function kioskAddonIsOutOfStock(addon) {
  const flag = addon?.is_available;
  if (flag === undefined || flag === null) return false;
  return flag === false || flag === 0 || flag === '0';
}

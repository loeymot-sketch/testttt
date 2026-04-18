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

export function kioskIsFoodLikeAddonName(name) {
  return FOOD_LIKE_REGEX.test(String(name || '').toLowerCase());
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
  const gl = addonGroupLabel(addon);
  if (gl !== '') {
    if (gl === 'boisson' || gl === 'drink' || gl === 'drinks' || gl === 'beverage') return true;
    if (gl.includes('frite') || gl.includes('menu_') || gl === 'menu' || gl.includes('food')) return false;
  }
  const name = String(addon?.addon_item_name || addon?.name || '').toLowerCase();
  return kioskIsDrinkAddonName(name);
}

export function kioskIsDrinkAddonName(name) {
  const n = String(name || '').toLowerCase();
  if (kioskIsFoodLikeAddonName(n)) return false;
  if (n.includes('frite') || n.includes('menu')) return false;
  return DRINK_LIKE_REGEX.test(n);
}

/**
 * @param {{ addons?: any[] }|null|undefined} item
 * @returns {any[]}
 */
export function kioskDrinkAddonRowsFromItem(item) {
  if (!item?.addons?.length) return [];
  return item.addons.filter((a) => kioskIsDrinkAddon(a));
}

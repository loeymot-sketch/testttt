/**
 * Filtre des addons « boisson » pour l’étape menu borne — partagé wizard + step
 * (même heuristique que l’historique KioskStepMenu pour éviter divergences).
 */

export function kioskIsFoodLikeAddonName(name) {
  const n = String(name || '').toLowerCase();
  return /frite|menu|patate|nugget|tender|onion|oignon|mozzarella|accompagn|snack|dessert|glace|wrap|cornet|potato|boulette|stick|ring|douille|corbeille|panier|barquette|salade/i.test(
    n
  );
}

export function kioskIsDrinkAddonName(name) {
  const n = String(name || '').toLowerCase();
  if (kioskIsFoodLikeAddonName(n)) return false;
  if (n.includes('frite') || n.includes('menu')) return false;
  return (
    n.includes('coca') ||
    n.includes('cola') ||
    n.includes('pepsi') ||
    n.includes('fanta') ||
    n.includes('sprite') ||
    n.includes('schweppes') ||
    n.includes('eau') ||
    n.includes('thé') ||
    n.includes('tea') ||
    n.includes('ice tea') ||
    n.includes('jus') ||
    n.includes('boisson') ||
    n.includes('soda') ||
    n.includes('drink') ||
    n.includes('limonade') ||
    n.includes('orangina') ||
    n.includes('oasis') ||
    n.includes('tropico') ||
    n.includes('café') ||
    n.includes('coffee') ||
    n.includes('red bull')
  );
}

/**
 * @param {{ addons?: array }|null|undefined} item
 * @returns {array}
 */
export function kioskDrinkAddonRowsFromItem(item) {
  if (!item?.addons?.length) return [];
  return item.addons.filter((a) => kioskIsDrinkAddonName(a.addon_item_name || a.name));
}

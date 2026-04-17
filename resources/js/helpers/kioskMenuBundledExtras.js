/**
 * Extras « upgrade frites / menu » montrés sur l’étape **Menu** (formule) — exclus de l’étape
 * **Suppléments** pour éviter doublon + respecter l’ordre UX (upgrade → boisson → sauce frites).
 *
 * Heuristique : noms / libellés typiques base FoodKing (ajuster via données si besoin).
 */
export function kioskIsBundledFritesMenuUpgradeExtra(extra, item) {
  if (!item?.has_menu) return false;
  const name = String(extra?.name || '').toLowerCase();
  const gl = String(extra?.group_label || '').toLowerCase();
  const price = parseFloat(extra?.convert_price || extra?.price || 0);
  if (price <= 0) return false;

  const isSauce = gl !== '' ? gl === 'sauce' : name.includes('sauce');
  if (isSauce) return false;

  if (gl.includes('menu') && (gl.includes('frite') || gl.includes('upgrade'))) return true;
  if (/frite.*cheddar|cheddar.*frite|frite.*crisp|crisp.*frite|frite.*fromage|suppl.*frite|upgrade.*frite/i.test(name)) {
    return true;
  }
  if ((name.includes('frite') || name.includes('fry')) && (name.includes('cheddar') || name.includes('crisp') || name.includes('fromage'))) {
    return true;
  }
  return false;
}

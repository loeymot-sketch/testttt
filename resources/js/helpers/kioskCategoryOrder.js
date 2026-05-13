/**
 * Ordre d'affichage des catégories borne : plats principaux / sandwichs en haut,
 * desserts & boissons en bas. Complète le champ admin `sort` (tie-break).
 */

function norm(s) {
  return String(s || '')
    .toLowerCase()
    .normalize('NFD')
    .replace(/[\u0300-\u036f]/g, '');
}

/**
 * 0 = carte principale (sandwichs, burgers, plats…)
 * 1 = neutre / accompagnements
 * 2 = desserts & boissons (en fin de sidebar)
 */
export function kioskCategoryDisplayTier(cat) {
  const s = norm(`${cat.name || ''} ${cat.slug || ''}`);

  if (
    /dessert|patisserie|patiss|gateau|gâteau|glace|sorbet|yaourt|cookie|brownie|milkshake|smoothie|sucrerie/.test(
      s
    )
  ) {
    return 2;
  }
  if (
    /boisson|a boire|soft|soda|jus |coca|fanta|sprite|eau |the |thé |ice tea|café|coffee|energy|red bull|orangina|oasis|tropico|vin |biere|bière|champagne|drink/.test(
      s
    )
  ) {
    return 2;
  }

  if (
    /sandwich|burger|tacos|kebab|wrap|pizza|panini|grec|hot dog|croque|bagel|burrito|quesadilla|assiette|plat |menu |specialite|spécialité|snacking|nugget|tender|bucket|box |galette|bols?/.test(
      s
    )
  ) {
    return 0;
  }
  if (/salade|entree|entrée|formule|midi|dejeuner|déjeuner/.test(s)) {
    return 0;
  }
  if (/frite|accompagn|side|sauce|extra/.test(s)) {
    return 1;
  }

  return 1;
}

/**
 * @param {Array<object>} list
 * @returns {Array<object>}
 */
export function sortCategoriesForKioskDisplay(list) {
  if (!Array.isArray(list) || list.length === 0) return Array.isArray(list) ? [...list] : [];

  return [...list].sort((a, b) => {
    const ta = kioskCategoryDisplayTier(a);
    const tb = kioskCategoryDisplayTier(b);
    if (ta !== tb) return ta - tb;

    const sa = parseInt(a.sort, 10);
    const sb = parseInt(b.sort, 10);
    const aOk = !Number.isNaN(sa);
    const bOk = !Number.isNaN(sb);
    if (aOk && bOk && sa !== sb) return sa - sb;
    if (aOk && !bOk) return -1;
    if (!aOk && bOk) return 1;

    return norm(a.name).localeCompare(norm(b.name), 'fr', { sensitivity: 'base' });
  });
}

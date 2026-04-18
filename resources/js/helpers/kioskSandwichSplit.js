/**
 * Borne uniquement : deux entrées sidebar pour « Nos Sandwichs » (signatures vs froid)
 * sans second item_category en base (POS = une seule catégorie).
 */

function slugNorm(s) {
  return String(s || '')
    .toLowerCase()
    .trim();
}

/**
 * @param {Array<object>} categories — déjà triées (ex. sortCategoriesForKioskDisplay)
 * @param {{ parentSlug?: string, coldSlugs?: string[], coldLabel?: string }} options
 * @returns {Array<object>} catégories avec kioskRowKey + kioskSandwichSub optionnel ; ligne « froid » en fin
 */
export function expandKioskSidebarCategories(categories, options = {}) {
  const parentSlug = slugNorm(options.parentSlug || 'nos-sandwichs');
  const coldSlugs = (options.coldSlugs || []).map(slugNorm).filter(Boolean);
  const coldLabel = options.coldLabel || 'Sandwich froid';

  if (!Array.isArray(categories) || categories.length === 0) {
    return Array.isArray(categories) ? [...categories] : [];
  }

  if (!coldSlugs.length) {
    return categories.map((c) => ({ ...c, kioskRowKey: String(c.id) }));
  }

  const idx = categories.findIndex((c) => slugNorm(c.slug) === parentSlug);
  if (idx === -1) {
    return categories.map((c) => ({ ...c, kioskRowKey: String(c.id) }));
  }

  const parent = categories[idx];
  const out = [];

  categories.forEach((c, i) => {
    if (i === idx) {
      out.push({
        ...c,
        kioskRowKey: `${c.id}-sig`,
        kioskSandwichSub: null,
      });
    } else {
      out.push({ ...c, kioskRowKey: String(c.id) });
    }
  });

  out.push({
    ...parent,
    name: coldLabel,
    kioskRowKey: `${parent.id}-sf`,
    kioskSandwichSub: 'cold',
    isVirtualSandwichFroidRow: true,
  });

  return out;
}

/**
 * @param {object[]} items
 * @param {string|null|undefined} sandwichSubcolumn — null = signatures (exclut froid), 'cold' = froid seulement
 * @param {string[]} coldSlugs — slugs normalisés minuscules
 */
export function filterSandwichItemsForKioskColumn(items, sandwichSubcolumn, coldSlugs) {
  if (!Array.isArray(items) || !coldSlugs?.length) return items;
  const set = new Set(coldSlugs.map(slugNorm));
  return items.filter((i) => {
    const s = slugNorm(i.slug);
    const isCold = set.has(s);
    if (sandwichSubcolumn === 'cold') return isCold;
    return !isCold;
  });
}

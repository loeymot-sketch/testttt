/**
 * Kiosk item detail payloads may expose images under several keys (thumb, cover,
 * preview, legacy names). Central resolution keeps wizard steps consistent.
 */

/**
 * @param {...(string|object|null|undefined)} sources - Strings or API-shaped objects
 * @returns {string|null}
 */
export function kioskResolveImageSrc(...sources) {
  for (const src of sources) {
    if (src == null) continue;
    if (typeof src === 'string') {
      const t = src.trim();
      if (t) return t;
      continue;
    }
    if (typeof src === 'object') {
      const u =
        src.thumb ||
        src.image_full_path ||
        src.image ||
        src.cover ||
        src.preview ||
        src.addon_item_thumb ||
        src.media_url;
      if (u != null && String(u).trim()) return String(u).trim();
    }
  }
  return null;
}

/**
 * NormalItemResource groups variations by item_attribute_id; JSON keys are often strings.
 *
 * @param {object} item - resolved kiosk item
 * @param {number|string} attrId - attribute id from itemAttributes
 * @returns {array|null}
 */
export function kioskVariationsForAttribute(item, attrId) {
  if (!item?.variations || attrId == null) return null;
  const raw = item.variations[String(attrId)] ?? item.variations[attrId];
  if (raw == null) return null;
  return Array.isArray(raw) ? raw : [raw];
}

/**
 * Photo à afficher pour un produit de la borne (carte du catalogue, étape pain/galette).
 *
 * Une photo téléversée (galettes…) a une conversion `preview` de 600 px, nette en plein cadre ; la vignette
 * `thumb` n'en fait que 168. Pour un produit dont l'image vient de la configuration (`public/images/menu`),
 * `preview` est l'image générique du serveur : on ne l'utilise jamais, on prend `thumb`.
 *
 * @param {object|null|undefined} product
 * @returns {string|null}
 */
export function kioskPhotoSrc(product) {
  const preview = product?.preview;
  if (typeof preview === 'string' && /\/storage\/.+\/conversions\//.test(preview)) return preview;
  return product?.thumb || product?.image || null;
}

/**
 * La galette qui correspond à un produit : « Galette Cayenne » pour le Cayenne, « Galette Classique » sinon
 * (même règle que le site). Retourne null si le catalogue n'a pas la galette.
 *
 * @param {object|null|undefined} item - produit en cours
 * @param {Array} allItems - catalogue de la borne
 * @returns {object|null}
 */
export function kioskGaletteTwin(item, allItems) {
  const list = Array.isArray(allItems) ? allItems : [];
  const wanted = /cayenne/i.test(item?.name || '') ? /galette\s+cayenne/i : /galette\s+classique/i;
  return list.find((i) => i && wanted.test(i.name || '')) || list.find((i) => i && /^galette\b/i.test(i.name || '')) || null;
}

/**
 * [PAIN-PHOTO 2026-10-29 · test-e2e R8-01/R8-02] Variations d'un article du catalogue, À PLAT. Selon la source c'est une liste
 * plate (forme réelle de la borne), des groupes par attribut (tableau) ou un objet indexé par id : on descend jusqu'aux
 * objets qui portent `id` + `name`, quelle que soit la forme.
 */
export function kioskVariationPool(catalogItem) {
  const pool = [];
  const descendre = (n) => {
    if (!n || typeof n !== 'object') return;
    if (n.id != null && n.name != null) { pool.push(n); return; }
    Object.values(n).forEach(descendre);
  };
  descendre(catalogItem && catalogItem.variations);
  return pool;
}

/** Vrai si l'une des variations choisies (ids) est une galette. */
export function kioskChoseGalette(catalogItem, variationIds) {
  const ids = (variationIds || []).map((id) => Number(id));
  return kioskVariationPool(catalogItem).some((v) => v && ids.includes(Number(v.id)) && /galette/i.test(v.name || ''));
}

/**
 * Photo à montrer pour une ligne : celle de la GALETTE jumelle si le pain choisi est une galette, sinon `fallback`.
 * Jamais d'exception : en cas de doute, la photo d'origine.
 */
export function kioskLinePhoto(catalogItem, variationIds, allItems, fallback = null) {
  try {
    if (kioskChoseGalette(catalogItem, variationIds)) {
      const src = kioskPhotoSrc(kioskGaletteTwin({ name: catalogItem && catalogItem.name }, allItems));
      if (src) return src;
    }
  } catch (e) { /* repli */ }
  return fallback;
}

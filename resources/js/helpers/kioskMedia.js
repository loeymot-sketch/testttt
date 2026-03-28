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

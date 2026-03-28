/**
 * Kiosk upsell routing — Phase A (category flags from menu API).
 * When every cart line belongs to a category with kiosk_upsell_skip_after_cart,
 * skip the upsell screen and go straight to payment (after marking upsell "shown").
 *
 * @param {{ item_category_id?: number|string|null }[]} cartItems
 * @param {{ id: number|string, kiosk_upsell_skip_after_cart?: boolean }[]} categories
 * @returns {boolean}
 */
export function shouldSkipKioskUpsellScreen(cartItems, categories) {
  if (!cartItems?.length) return false;
  const map = new Map();
  (categories || []).forEach((c) => {
    const id = parseInt(c.id, 10);
    if (!Number.isNaN(id)) map.set(id, c);
  });
  for (const line of cartItems) {
    const cid = parseInt(line.item_category_id, 10);
    if (Number.isNaN(cid)) return false;
    const cat = map.get(cid);
    if (!cat || !cat.kiosk_upsell_skip_after_cart) return false;
  }
  return true;
}

const DEFAULT_MENU_PRICING = {
  fullRatio: 1,
  friesRatio: 0.6,
  drinkRatio: 0.4,
};

function normalizeMenuPricingConfig(rawConfig = {}) {
  const fullRatio = Number(rawConfig.fullRatio ?? rawConfig.full_ratio ?? DEFAULT_MENU_PRICING.fullRatio);
  const friesRatio = Number(rawConfig.friesRatio ?? rawConfig.fries_ratio ?? DEFAULT_MENU_PRICING.friesRatio);
  const drinkRatio = Number(rawConfig.drinkRatio ?? rawConfig.drink_ratio ?? DEFAULT_MENU_PRICING.drinkRatio);

  return {
    fullRatio: Number.isFinite(fullRatio) && fullRatio > 0 ? fullRatio : DEFAULT_MENU_PRICING.fullRatio,
    friesRatio: Number.isFinite(friesRatio) && friesRatio >= 0 ? friesRatio : DEFAULT_MENU_PRICING.friesRatio,
    drinkRatio: Number.isFinite(drinkRatio) && drinkRatio >= 0 ? drinkRatio : DEFAULT_MENU_PRICING.drinkRatio,
  };
}

export function getKioskMenuPricingConfig() {
  if (typeof window === 'undefined') {
    return { ...DEFAULT_MENU_PRICING };
  }

  return normalizeMenuPricingConfig(window.foodkingConfig?.kioskMenuPricing || {});
}

export function getKioskExtraSauceUnitPrice(item) {
  const sauceAttr = item?.itemAttributes?.find((a) => (a.name || '').toLowerCase().includes('sauce'));
  const vars = sauceAttr
    ? (item?.variations?.[String(sauceAttr.id)] || item?.variations?.[sauceAttr.id])
    : null;

  let unit = 0.50;
  if (Array.isArray(vars)) {
    const priced = vars.find((v) => parseFloat(v.convert_price || v.price || 0) > 0);
    if (priced) {
      unit = parseFloat(priced.convert_price || priced.price || 0.50);
    }
  }

  return unit;
}

export function getKioskMenuAddonPrice(item, menuChoice) {
  if (!item?.addons || !menuChoice || menuChoice === 'none') {
    return 0;
  }

  const menuAddon = item.addons.find((a) =>
    (a.addon_item_name || '').toLowerCase().includes('menu')
  );

  if (!menuAddon) {
    return 0;
  }

  const fullPrice = parseFloat(menuAddon.addon_item_convert_price || menuAddon.price || 0);
  const pricing = getKioskMenuPricingConfig();

  let result = 0;
  if (menuChoice === 'full') result = fullPrice * pricing.fullRatio;
  else if (menuChoice === 'frites') result = fullPrice * pricing.friesRatio;
  else if (menuChoice === 'boisson') result = fullPrice * pricing.drinkRatio;

  return Math.round(result * 100) / 100;
}

export function calculateKioskRunningTotal(item, selections = {}) {
  if (!item) {
    return 0;
  }

  let total = parseFloat(item.convert_price) || 0;
  const extraSauceUnitPrice = getKioskExtraSauceUnitPrice(item);

  const sauceOrder = selections.sauceOrder || [];
  if (sauceOrder.length > 1) {
    total += (sauceOrder.length - 1) * extraSauceUnitPrice;
  }

  const frySauces = (selections.fritesSauceOrder || []).filter((key) => key && key !== 'sans');
  if ((selections.menuChoice === 'full' || selections.menuChoice === 'frites') && frySauces.length > 1) {
    total += (frySauces.length - 1) * extraSauceUnitPrice;
  }

  if (Array.isArray(item.extras)) {
    item.extras.forEach((extra) => {
      if (!selections.supplements?.[extra.id]) return;

      const price = parseFloat(extra.convert_price || extra.price || 0);
      const groupLabel = (extra.group_label || '').toLowerCase();
      const name = (extra.name || '').toLowerCase();
      const isSauce = (groupLabel !== '' ? groupLabel === 'sauce' : name.includes('sauce'));

      if (price > 0 && !isSauce) {
        total += price;
      }
    });
  }

  total += getKioskMenuAddonPrice(item, selections.menuChoice);

  const rawTotal = total * Math.max(1, parseInt(selections.quantity, 10) || 1);
  return Math.round(rawTotal * 100) / 100;
}

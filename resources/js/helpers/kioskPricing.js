import { kioskSumPaidViandesSurcharge } from './kioskViandeCatalog';
import { matchAttributeByRole } from './kioskPainCatalog';

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
  const attrs = Array.isArray(item?.itemAttributes)
    ? item.itemAttributes
    : Object.values(item?.itemAttributes || {});
  const sauceAttr = attrs.find((attr) => matchAttributeByRole(attr, 'sauce'));
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

function getSauceVariations(item) {
  const attrs = Array.isArray(item?.itemAttributes)
    ? item.itemAttributes
    : Object.values(item?.itemAttributes || {});
  const sauceAttr = attrs.find((attr) => matchAttributeByRole(attr, 'sauce'));
  const vars = sauceAttr
    ? (item?.variations?.[String(sauceAttr.id)] || item?.variations?.[sauceAttr.id])
    : null;

  if (Array.isArray(vars)) return vars;
  if (vars && typeof vars === 'object') return Object.values(vars);
  return [];
}

function normalizeSauceSelectionKey(key) {
  const raw = String(key ?? '');
  return raw.startsWith('sauce-var-') ? raw.slice('sauce-var-'.length) : raw;
}

function getSelectedSauceLinePrice(item, key) {
  const normalizedKey = normalizeSauceSelectionKey(key);
  const variation = getSauceVariations(item).find((row) => {
    if (row == null) return false;
    if (String(row.id ?? '') === normalizedKey) return true;
    return String(row.name ?? '') === normalizedKey;
  });

  return parseFloat(variation?.convert_price || variation?.price || 0) || 0;
}

function getExtraSauceSelectionsTotal(item, sauceOrder = []) {
  if (!Array.isArray(sauceOrder) || sauceOrder.length <= 1) {
    return 0;
  }

  return sauceOrder
    .slice(1)
    .reduce((sum, key) => sum + getSelectedSauceLinePrice(item, key), 0);
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

  const sauceOrder = selections.sauceOrder || [];
  total += getExtraSauceSelectionsTotal(item, sauceOrder);

  const frySauces = (selections.fritesSauceOrder || []).filter((key) => key && key !== 'sans');
  if (selections.menuChoice === 'full' || selections.menuChoice === 'frites') {
    total += getExtraSauceSelectionsTotal(item, frySauces);
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

  // [PHASE9 W-P0-1 FIX] Surplus viandes marquées `source='extra'` — produites
  // par le helper kioskViandeCatalog et remontées par KioskStepViande dans
  // selections._viandeMeta (underscore = contrat officiel wizard). Précédemment
  // lu sans underscore => toujours falsy => running total sous-évalué pour les
  // items à viande payante (ex. « Double Steak +2€ »), créant une divergence
  // silencieuse avec le total du panier (perte revenu + rupture de confiance).
  // Les viandes payantes sont volontairement exclues de la boucle extras
  // ci-dessus (voir helper kioskExtrasPartition).
  const viandeMetaList = Array.isArray(selections._viandeMeta)
    ? selections._viandeMeta
    : (Array.isArray(selections.viandeMeta) ? selections.viandeMeta : null);
  if (viandeMetaList) {
    total += kioskSumPaidViandesSurcharge(viandeMetaList);
  }

  const rawTotal = total * Math.max(1, parseInt(selections.quantity, 10) || 1);
  return Math.round(rawTotal * 100) / 100;
}

import { kioskSumPaidViandesSurcharge } from './kioskViandeCatalog';

const DEFAULT_MENU_PRICING = {
  fullRatio: 1,
  friesRatio: 0.76, // [G-PRIX 2026-07-22] frites seules 1,90 (2,50×0.76)
  drinkRatio: 0.76, // [G-PRIX 2026-07-22] boisson seule 1,90
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
  // [COMPOSITION-SAUCE BORNE 2026-07-15 · commentaire corrigé 2026-07-16 KIOSKPRICING-STALE-COMMENT]
  // La sauce EN PLUS est facturée via l'ItemExtra 'Sauce supplémentaire' (group_label='sauce') —
  // MÊME source que le montant SCELLÉ par le backend dans buildLineItem (normalizedExtras). La
  // facturation est DATA-DRIVEN : tout item qui possède cet extra (bols/sandwich/tacos/galette/
  // burger selon la config catalogue) → son prix (0,50 €) ; tout item SANS l'extra (frites) → 0.
  // Ainsi l'affichage running-total == le prix réellement scellé (fini le display≠sealed qui
  // montrait +0,50 € sans jamais le facturer). NB : ne PAS présumer par famille — c'est la présence
  // de l'ItemExtra qui décide (l'ancien commentaire « bols aucun mécanisme backend » était faux).
  const ss = Array.isArray(item?.extras)
    ? item.extras.find((e) => e
        && String(e.group_label || '').toLowerCase() === 'sauce'
        && /suppl/i.test(String(e.name || '')))
    : null;

  if (ss) {
    return parseFloat(ss.convert_price || ss.price || 0) || 0;
  }

  return 0;
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

// [P2-g / F3 borne 2026-07-18] Standalone drink addon (ex. bol « Boisson Seule » +2,00 €).
// Un bol (has_menu=false) n'a PAS de formule /menu/i : sa boisson est un addon payant
// autonome (id 99, 2,00 €). Le mapping FROZEN `ADDON_ROLE_TO_TYPE['drink']='menu'` route
// ce step vers KioskStepMenu ; celui-ci émet désormais `_boissonMeta.addonId` + menuChoice
// 'boisson' → le wizard frozen pousse l'addon (role 'drink' → prix plein côté backend,
// menuRoleAdjustedAddonPrice ne s'applique qu'aux roles `menu_*`). On reflète ici le MÊME
// montant pour garder display == sealed (le running-total local == total scellé serveur).
// Garde stricte `!item.has_menu` : les formules (has_menu=true) restent gérées par
// getKioskMenuAddonPrice — cette fonction ne touche QUE le cas addon-boisson autonome.
export function getKioskStandaloneDrinkAddonPrice(item, selections = {}) {
  if (!item || item.has_menu) {
    return 0;
  }

  // Miroir exact de la garde du push wizard (KioskWizardComponent buildLineItem) :
  // l'addon boisson n'est poussé que lorsque menuChoice ∈ ('full','boisson').
  const menuChoice = selections?.menuChoice;
  if (menuChoice !== 'full' && menuChoice !== 'boisson') {
    return 0;
  }

  const meta = selections?._boissonMeta || selections?.boissonMeta || null;
  const addonId = meta ? Number(meta.addonId) : NaN;
  if (!Number.isFinite(addonId) || addonId <= 0) {
    return 0;
  }

  const addons = Array.isArray(item?.addons) ? item.addons : [];
  const addon = addons.find((a) => Number(a?.id) === addonId);
  if (!addon) {
    return 0;
  }

  return parseFloat(
    addon.addon_item_convert_price ?? addon.convert_price ?? addon.price ?? addon.addon_item_price ?? 0
  ) || 0;
}

export function normalizeKioskSelectionCount(value) {
  if (value === true) return 1;

  const count = parseInt(value, 10);
  if (!Number.isFinite(count) || count <= 0) return 0;

  return count;
}

export function calculateKioskRunningTotal(item, selections = {}) {
  if (!item) {
    return 0;
  }

  let total = parseFloat(item.convert_price) || 0;
  const extraSauceUnitPrice = getKioskExtraSauceUnitPrice(item);

  // [COMPOSITION-SAUCE BORNE 2026-07-15] Sauce en plus : (N-1) × prix de l'ItemExtra 'Sauce
  // supplémentaire' (0,50 € famille sandwich/tacos/galette/burger ; 0 € si l'item n'a pas cet
  // extra). extraSauceUnitPrice vaut déjà 0 dans ce dernier cas → affichage == prix scellé.
  const sauceOrder = selections.sauceOrder || [];
  if (sauceOrder.length > 1) {
    total += (sauceOrder.length - 1) * extraSauceUnitPrice;
  }

  // [PLAINTE OWNER 2026-07-29] Sauce FRITES en plus : (N-1) × prix de l'ItemExtra « Sauce
  // supplémentaire » du produit PARENT — exactement comme la sauce du sandwich ci-dessus.
  // La prémisse de l'ancien commentaire (« aucun mécanisme de facturation backend, pas
  // d'ItemExtra dédié sur les frites ») était FAUSSE : la ligne facturée est le produit parent,
  // qui porte bien cet extra (24 produits du catalogue). L'étape AFFICHAIT « +0,50 € »
  // (KioskStepMenuComponent.fritesSaucePriceLabel) alors que ni ce total ni le payload ne le
  // reprenaient → « je l'ajoute, le prix ne bouge pas ». Le SITE facture et scelle déjà ce
  // surcoût (menu.js priceFor + api.js item_extras) : la borne était la seule à diverger.
  // extraSauceUnitPrice vaut 0 si l'item n'a pas l'extra → sauce frites gratuite, display == sealed.
  const fritesSauceOrder = selections.fritesSauceOrder || [];
  if (fritesSauceOrder.length > 1) {
    total += (fritesSauceOrder.length - 1) * extraSauceUnitPrice;
  }

  if (Array.isArray(item.extras)) {
    item.extras.forEach((extra) => {
      const count = normalizeKioskSelectionCount(selections.supplements?.[extra.id]);
      if (count <= 0) return;

      const price = parseFloat(extra.convert_price || extra.price || 0);
      const groupLabel = (extra.group_label || '').toLowerCase();
      const name = (extra.name || '').toLowerCase();
      const isSauce = (groupLabel !== '' ? groupLabel === 'sauce' : name.includes('sauce'));

      if (price > 0 && !isSauce) {
        total += price * count;
      }
    });
  }

  total += getKioskMenuAddonPrice(item, selections.menuChoice);

  // [P2-g / F3 borne 2026-07-18] Boisson addon autonome (bol « Boisson Seule » +2,00 €).
  // Aligné sur le montant scellé backend (addon role 'drink' = prix plein). Garde interne
  // `!item.has_menu` → aucun effet sur les formules (has_menu=true).
  total += getKioskStandaloneDrinkAddonPrice(item, selections);

  // V3.6.1 (2026-05-10) Owner gate — frites_style upgrade :
  // Si user a sélectionné Cheddar/Cheddar+Oignons (group_label='frites_style'),
  // ajouter le prix de l'extra au running total. Les frites_style extras sont
  // exclus de la boucle .supplements ci-dessus car leur group_label les fait
  // partir dans la partition fritesUpgrades (cf. kioskExtrasPartition).
  const fritesStyleId = selections.fritesStyleExtraId;
  if (fritesStyleId != null && Array.isArray(item.extras)) {
    const fritesExtra = item.extras.find(
      (e) => e && Number(e.id) === Number(fritesStyleId) && e.group_label === 'frites_style'
    );
    if (fritesExtra) {
      total += parseFloat(fritesExtra.convert_price || fritesExtra.price || 0) || 0;
    }
  }

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

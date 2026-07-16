import { kioskSumPaidViandesSurcharge } from './kioskViandeCatalog';

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

  // Sauce FRITES en plus : AUCUN mécanisme de facturation backend (pas d'ItemExtra dédié sur les
  // frites) → GRATUITE, alignée sur le web. Le surcoût display-only précédent est SUPPRIMÉ pour
  // éliminer le display≠sealed (owner : la parité prime, follow-up si facturation frites voulue).

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

/**
 * [AUDIT 2026-04-17 C4] Source unique du partitionnement des `item.extras`
 * pour toutes les étapes du wizard borne.
 *
 * Objectif : éliminer les duplicats `.filter(...)` dispersés dans
 * KioskStepGarnitures, KioskStepSupplements, KioskStepMenu (upgrades frites),
 * calculateKioskRunningTotal, KioskWizard.shouldShowStep, etc. — qui
 * divergeaient subtilement et pouvaient provoquer du double-comptage
 * (un extra pouvait être considéré à la fois comme upgrade frites ET
 * supplément payant).
 *
 * Contrat :
 *   partitionKioskExtras(item) retourne 4 listes MUTUELLEMENT EXCLUSIVES :
 *     - garnitures    : extras gratuits (prix = 0) non-sauces
 *     - supplements   : extras payants non-sauces, non-upgrade-frites, non-viandes
 *     - fritesUpgrades: extras payants marqués "upgrade frites/menu" (cheddar, crispy, …)
 *     - viandesPaid   : extras payants qualifiés "viande" (choix exclusif, pas supplément)
 *
 * Les sauces ne sont JAMAIS dans ces listes (prises en charge par l'étape Sauce
 * via variations d'attribut, jamais via extras).
 *
 * Tout code qui lit `item.extras` doit passer par ce helper pour rester cohérent.
 */
import { kioskIsBundledFritesMenuUpgradeExtra } from './kioskMenuBundledExtras';

/**
 * Un extra est une sauce si :
 *   - son group_label vaut 'sauce' (priorité, source catalogue)
 *   - sinon si son nom contient 'sauce' (fallback best-effort)
 */
export function kioskIsSauceExtra(extra) {
  const gl = String(extra?.group_label || '').toLowerCase();
  if (gl !== '') return gl === 'sauce';
  const name = String(extra?.name || '').toLowerCase();
  return name.includes('sauce');
}

/**
 * Un extra est une "viande payante" (choix exclusif, pas accompagnement) si :
 *   - son group_label contient 'viande' (priorité, source catalogue)
 *   - OU son nom contient 'viande' et prix > 0
 *
 * Heuristique volontairement stricte : on n'inclut pas les accompagnements
 * carnés type "nuggets supplémentaires" qui restent des suppléments.
 */
export function kioskIsViandePaidExtra(extra) {
  const price = parseFloat(extra?.convert_price || extra?.price || 0);
  if (!(price > 0)) return false;
  const gl = String(extra?.group_label || '').toLowerCase();
  if (gl.includes('viande') || gl.includes('meat') || gl.includes('protein')) {
    return true;
  }
  const name = String(extra?.name || '').toLowerCase();
  return name.includes('viande');
}

/**
 * Partitionne les extras d'un item en 4 listes mutuellement exclusives.
 * Retourne des objets { id, name, price, raw } pour simplifier la consommation.
 *
 * @param {{ extras?: any[], has_menu?: boolean }|null|undefined} item
 * @returns {{
 *   garnitures: Array<{ id:any, name:string, price:number, raw:any }>,
 *   supplements: Array<{ id:any, name:string, price:number, raw:any }>,
 *   fritesUpgrades: Array<{ id:any, name:string, price:number, raw:any }>,
 *   viandesPaid: Array<{ id:any, name:string, price:number, raw:any }>,
 * }}
 */
export function partitionKioskExtras(item) {
  const out = { garnitures: [], supplements: [], fritesUpgrades: [], viandesPaid: [] };
  const list = Array.isArray(item?.extras) ? item.extras : [];

  for (const e of list) {
    if (e == null) continue;
    const price = parseFloat(e.convert_price || e.price || 0) || 0;
    // [HEAL-A 2026-05-08] Propagate is_available / unavailable_reason so step
    // components can render the "Épuisé" marker without needing to crack open
    // raw.* (defensive: e.is_available may be undefined → treat as available).
    const row = {
      id: e.id,
      name: e.name || '',
      price,
      raw: e,
      is_available: e?.is_available !== false,
      unavailable_reason: e?.unavailable_reason || null,
    };

    if (kioskIsSauceExtra(e)) continue;

    if (price === 0) {
      out.garnitures.push(row);
      continue;
    }

    if (kioskIsBundledFritesMenuUpgradeExtra(e, item)) {
      out.fritesUpgrades.push(row);
      continue;
    }

    if (kioskIsViandePaidExtra(e)) {
      out.viandesPaid.push(row);
      continue;
    }

    out.supplements.push(row);
  }

  return out;
}

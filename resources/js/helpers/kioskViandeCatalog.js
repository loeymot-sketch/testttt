/**
 * [AUDIT 2026-04-17 C3] Catalogue viandes borne — fusion variations + extras payants.
 *
 * PROBLÈME :
 *   KioskStepViandeComponent ne lisait QUE les variations de l'attribut "Viande"
 *   (rotation simple sans prix). Les viandes exposées comme EXTRAS avec
 *   prix > 0 (ex. "Double Steak +2€", "Premium Chicken +1.50€") étaient
 *   silencieusement perdues : elles apparaissaient dans l'étape Suppléments
 *   au lieu de faire partie du choix exclusif viande.
 *
 * CONTRAT :
 *   kioskViandeCatalogForItem(item) retourne une liste uniforme
 *     [{ id, key, name, price, source: 'variation'|'extra', thumb, emoji, attrId? }]
 *   où :
 *     - source='variation' : prix = 0 (offert dans le produit de base)
 *     - source='extra'     : prix > 0 (surplus ajouté au total)
 *   Les `id` restent les IDs natifs du catalogue (numériques) pour permettre
 *   le mapping en base (item_variations pour les variations, item_extras
 *   pour les extras) lors du submit panier.
 *
 * CONTRAT D'UTILISATION (KioskStepViandeComponent) :
 *   - Le client peut panacher variations et extras dans le compteur ±.
 *   - Le total affiché inclut le prix des viandes-extras sélectionnées.
 *   - `_viandeMeta` contient { id, key, name, count, price, source } pour
 *     remonter jusqu'au panier et au récap.
 */
import { kioskResolveImageSrc, kioskVariationsForAttribute } from './kioskMedia';
import { kioskIsViandePaidExtra } from './kioskExtrasPartition';

function normalizeAttributes(attrs) {
  if (attrs == null) return [];
  if (Array.isArray(attrs)) return attrs;
  return Object.values(attrs);
}

function pickEmojiForViande(name) {
  const lower = String(name || '').toLowerCase();
  if (lower.includes('poulet') || lower.includes('nugget') || lower.includes('chicken')) return '🍗';
  if (lower.includes('boeuf') || lower.includes('steak') || lower.includes('beef')) return '🥩';
  if (lower.includes('merguez') || lower.includes('saucisse')) return '🌭';
  if (lower.includes('poisson') || lower.includes('fish')) return '🐟';
  if (lower.includes('crevette') || lower.includes('shrimp')) return '🦐';
  if (lower.includes('agneau') || lower.includes('lamb')) return '🐑';
  if (lower.includes('kebab') || lower.includes('gyros')) return '🥙';
  return '🥩';
}

function slugifyName(name) {
  return String(name || '')
    .toLowerCase()
    .replace(/\s+/g, '_')
    .replace(/[^a-z0-9_-]/g, '');
}

/**
 * @param {object|null|undefined} item
 * @returns {Array<{
 *   id: number|string|null,
 *   key: string,
 *   name: string,
 *   price: number,
 *   source: 'variation'|'extra',
 *   thumb: string|null,
 *   emoji: string,
 *   attrId: number|null,
 * }>}
 */
export function kioskViandeCatalogForItem(item) {
  if (!item) return [];
  const rows = [];
  const seenKeys = new Set();

  const attrs = normalizeAttributes(item.itemAttributes);
  const viandeAttr = attrs.find((a) => String(a?.name || '').toLowerCase().includes('viande'));
  const variations = viandeAttr ? kioskVariationsForAttribute(item, viandeAttr.id) : null;

  if (Array.isArray(variations) && variations.length > 0) {
    for (const v of variations) {
      if (v == null || Number(v.status) === 10) continue;
      const name = String(v.name || '').trim();
      if (!name) continue;
      const key = String(v.id ?? slugifyName(name));
      if (seenKeys.has(key)) continue;
      seenKeys.add(key);
      rows.push({
        id: typeof v.id === 'number' ? v.id : null,
        key,
        name,
        price: 0,
        source: 'variation',
        thumb: kioskResolveImageSrc(v),
        emoji: pickEmojiForViande(name),
        attrId: viandeAttr?.id ?? null,
      });
    }
  }

  const extras = Array.isArray(item.extras) ? item.extras : [];
  for (const e of extras) {
    if (!kioskIsViandePaidExtra(e)) continue;
    const name = String(e.name || '').trim();
    if (!name) continue;
    const key = `extra-${e.id ?? slugifyName(name)}`;
    if (seenKeys.has(key)) continue;
    seenKeys.add(key);
    rows.push({
      id: typeof e.id === 'number' ? e.id : null,
      key,
      name,
      price: parseFloat(e.convert_price || e.price || 0) || 0,
      source: 'extra',
      thumb: kioskResolveImageSrc(e),
      emoji: pickEmojiForViande(name),
      attrId: null,
    });
  }

  return rows;
}

/**
 * Somme des surplus viandes (extras payants) à partir des meta remontés.
 * @param {Array<{ price?: number, source?: string, count?: number }>} viandeMeta
 */
export function kioskSumPaidViandesSurcharge(viandeMeta) {
  if (!Array.isArray(viandeMeta)) return 0;
  return viandeMeta.reduce((sum, row) => {
    if (!row) return sum;
    if (row.source !== 'extra') return sum;
    const price = parseFloat(row.price || 0) || 0;
    const count = parseInt(row.count || 0, 10) || 0;
    return sum + price * count;
  }, 0);
}

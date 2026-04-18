import { kioskResolveImageSrc, kioskVariationsForAttribute } from './kioskMedia';

const FALLBACK_NAME_MATCHERS = {
  bread: ['pain', 'galette'],
  meat: ['viande'],
  sauce: ['sauce'],
};

function normalizeAttributes(attrs) {
  if (attrs == null) return [];
  if (Array.isArray(attrs)) return attrs;
  return Object.values(attrs);
}

export function matchAttributeByRole(attr, role) {
  if (!attr || !role) return false;

  if (attr.role != null) {
    return String(attr.role).toLowerCase() === String(role).toLowerCase();
  }

  const lowerName = String(attr.name || '').toLowerCase();
  return (FALLBACK_NAME_MATCHERS[role] || []).some((candidate) => lowerName.includes(candidate));
}

function pickEmojiForPain(name) {
  const lower = String(name || '').toLowerCase();
  if (lower.includes('galette')) return '🥙';
  if (lower.includes('pain')) return '🥖';
  return '🍞';
}

export function kioskPainCatalogForItem(item) {
  if (!item) return [];

  const attrs = normalizeAttributes(item.itemAttributes);
  const painAttr = attrs.find((attr) => matchAttributeByRole(attr, 'bread'));
  if (!painAttr?.id) return [];

  const list = kioskVariationsForAttribute(item, painAttr.id);
  if (!Array.isArray(list) || list.length === 0) return [];

  return list
    .filter((variation) => variation != null && Number(variation.status) !== 10)
    .map((variation) => ({
      id: variation.id,
      name: variation.name,
      emoji: pickEmojiForPain(variation.name),
      attrId: painAttr.id,
      displayThumb: kioskResolveImageSrc(variation),
    }));
}

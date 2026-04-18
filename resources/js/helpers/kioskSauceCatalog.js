/**
 * Liste des variations « sauce » pour un item (même logique que KioskStepSauceComponent).
 * Sert pour les sauces **frites** sur l’étape menu (alignement catalogue complet, pas 5 lignes figées).
 */
import { kioskResolveImageSrc } from './kioskMedia';
import { matchAttributeByRole } from './kioskPainCatalog';

function normalizeAttributes(attrs) {
  if (attrs == null) return [];
  if (Array.isArray(attrs)) return attrs;
  return Object.values(attrs);
}

function variationsRowsForAttribute(variations, attrId) {
  const raw = variations?.[String(attrId)] ?? variations?.[attrId];
  if (raw == null) return [];
  if (Array.isArray(raw)) return raw;
  if (typeof raw === 'object') return Object.values(raw);
  return [];
}

function pickEmojiForSauceName(name) {
  const lower = (name || '').toLowerCase();
  if (lower.includes('algérienne') || lower.includes('samouraï') || lower.includes('harissa')) return '🌶️';
  if (lower.includes('blanche') || lower.includes('fromagère') || lower.includes('cheddar')) return '🧀';
  if (lower.includes('ketchup') || lower.includes('tomate') || lower.includes('bbq')) return '🍅';
  if (lower.includes('mayonnaise') || lower.includes('mayo')) return '🥚';
  if (lower.includes('biggy') || lower.includes('burger') || lower.includes('moutarde')) return '🍔';
  if (lower.includes('tartare') || lower.includes('poivre')) return '🧂';
  return '🥄';
}

/**
 * @param {{ itemAttributes?: unknown, variations?: unknown }|null|undefined} item
 * @returns {{ rowKey: string, id: string|number|null, name: string, emoji: string, thumb: string|null }[]}
 */
export function kioskSauceVariationRowsForItem(item) {
  const attrs = normalizeAttributes(item?.itemAttributes);
  const sauceAttr = attrs.find((attr) => matchAttributeByRole(attr, 'sauce'));
  if (!sauceAttr) return [];

  const sauceVariations = variationsRowsForAttribute(item?.variations, sauceAttr.id);
  return sauceVariations
    .filter((v) => v != null && Number(v.status) !== 10)
    .map((v) => ({
      rowKey: v.id != null && v.id !== '' ? `sauce-var-${v.id}` : `sauce-${v.name || 'x'}`,
      id: v.id,
      name: v.name,
      emoji: pickEmojiForSauceName(v.name),
      thumb: kioskResolveImageSrc(v),
    }));
}

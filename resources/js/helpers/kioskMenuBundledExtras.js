/**
 * [AUDIT 2026-04-17 C6] Extras « upgrade frites / menu » montrés sur l'étape
 * Menu (formule) — exclus de l'étape Suppléments pour éviter doublon et
 * respecter l'ordre UX (upgrade → boisson → sauce frites).
 *
 * PRIORITÉ 1 : group_label explicite catalogue.
 * PRIORITÉ 2 : heuristique regex sur le nom.
 *
 * Un extra n'est jamais un upgrade frites si :
 *   - l'item n'a pas de menu (has_menu = false)
 *   - c'est une sauce (group_label=sauce ou nom contient 'sauce')
 *   - son prix est nul (c'est une garniture, pas un upgrade)
 */

const FRITES_UPGRADE_NAME_REGEX = /frite.*cheddar|cheddar.*frite|frite.*crisp|crisp.*frite|frite.*fromage|fromage.*frite|loaded\s*fries|cheese\s*fries|suppl.*frite|upgrade.*frite|frite.*grat|frite.*bacon|bacon.*frite/i;

function groupLabelOf(extra) {
  const gl = String(extra?.group_label || '').toLowerCase();
  return gl;
}

function isSauce(extra) {
  const gl = groupLabelOf(extra);
  if (gl !== '') return gl === 'sauce';
  return String(extra?.name || '').toLowerCase().includes('sauce');
}

export function kioskIsBundledFritesMenuUpgradeExtra(extra, item) {
  // V3.6 (2026-05-10) Owner gate : group_label='frites_style' (Cheddar fondu /
  // Cheddar+Oignons) doit ÊTRE détecté comme upgrade frites MEME si l'item n'a
  // pas has_menu (les items Frites Moyenne/Grande/Seules/Menu eux-mêmes
  // exposent ce upgrade directement). Cela exclut ces extras du step
  // Supplements (sinon double affichage avec KioskStepFritesStyle).
  const glFast = String(extra?.group_label || '').toLowerCase();
  if (glFast === 'frites_style') {
    return true;
  }
  if (!item?.has_menu) return false;
  const price = parseFloat(extra?.convert_price || extra?.price || 0);
  if (!(price > 0)) return false;
  if (isSauce(extra)) return false;

  const gl = groupLabelOf(extra);
  if (gl !== '') {
    if (
      gl === 'menu_frites' ||
      gl === 'frites_upgrade' ||
      gl === 'menu_upgrade' ||
      gl === 'frites_menu' ||
      (gl.includes('menu') && (gl.includes('frite') || gl.includes('upgrade'))) ||
      (gl.includes('frite') && gl.includes('upgrade'))
    ) {
      return true;
    }
    if (gl === 'supplement' || gl === 'extra' || gl === 'viande' || gl === 'garniture') {
      return false;
    }
  }

  const name = String(extra?.name || '').toLowerCase();
  if (FRITES_UPGRADE_NAME_REGEX.test(name)) return true;
  if ((name.includes('frite') || name.includes('fry')) && (name.includes('cheddar') || name.includes('crisp') || name.includes('fromage'))) {
    return true;
  }
  return false;
}

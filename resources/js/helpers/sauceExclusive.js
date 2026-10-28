/**
 * [QA 2026-09-28 — RAPPORT_DEV_CAISSE_2026-09-24 P1-11 / P1-65 / triage A19]
 *
 * Reconnaît l'option « Sans sauce », qui doit être EXCLUSIVE : elle ne peut pas
 * coexister avec de vraies sauces.
 *
 * Pourquoi c'est un défaut d'ARGENT et pas de confort : le wizard borne facture
 * les sauces au DÉCOMPTE (`extraSauceN = sauceOrder.length - 1`, puis autant
 * d'extras « Sauce supplémentaire » à 0,50 € scellés par PricingService). « Sans
 * sauce » vaut pourtant 0,00 €. Cochée EN PLUS de deux sauces, elle comptait
 * comme troisième sauce payante : le client payait 0,50 € pour ne PAS avoir de
 * sauce (rapport : Menu Enfant Nuggets à 5,90 € au lieu de 4,90 €).
 *
 * SSOT : `config/pos_sauces.php` (entrée `key = 'sans_sauce'` + ses `aliases`),
 * servie à la page par `SauceCatalog::frontPayload()` dans
 * `window.POS_WIZARD_CONFIG.sauceStyles` (master.blade.php / admin-pos-v4.blade.php).
 * On lit cette SSOT en priorité, de sorte qu'ajouter un alias au SEUL fichier de
 * config suffise — exactement l'intention déclarée de ce payload.
 *
 * Le miroir ci-dessous n'est qu'un REPLI pour les contextes sans page servie
 * (tests unitaires, rendu hors layout). Il reprend mot pour mot les alias du
 * fichier de config ; c'est le pattern « miroir codé en dur » explicitement
 * accepté par CLAUDE.md §3bis, la config restant la source de vérité.
 */

/** Repli — miroir de config/pos_sauces.php, entrée `sans_sauce`. */
const ALIAS_SANS_SAUCE_PAR_DEFAUT = ['sans sauce', 'aucune sauce', 'pas de sauce'];

/**
 * Normalisation alignée sur `App\Support\Menu\SauceCatalog::normalize()` :
 * minuscules, accents dépliés, tout non-alphanumérique réduit à une espace,
 * espaces compactés. Indispensable pour que « Zéro sauce » corresponde à
 * l'alias « zero sauce » servi par la config.
 */
export function normalizeSauceName(name) {
  return String(name == null ? '' : name)
    .toLowerCase()
    .normalize('NFD')
    .replace(/[̀-ͯ]/g, '')
    .replace(/œ/g, 'oe')
    .replace(/æ/g, 'ae')
    .replace(/[^a-z0-9]+/g, ' ')
    .trim()
    .replace(/\s+/g, ' ');
}

/** Alias de l'option « sans sauce », depuis la SSOT servie si disponible. */
function aliasSansSauce() {
  try {
    const styles = (typeof window !== 'undefined' && window && window.POS_WIZARD_CONFIG)
      ? window.POS_WIZARD_CONFIG.sauceStyles
      : null;
    if (Array.isArray(styles)) {
      const entry = styles.find((s) => s && String(s.key) === 'sans_sauce');
      if (entry) {
        const fromSsot = []
          .concat(Array.isArray(entry.aliases) ? entry.aliases : [])
          .concat(entry.name ? [entry.name] : [])
          .map(normalizeSauceName)
          .filter(Boolean);
        if (fromSsot.length > 0) return fromSsot;
      }
    }
  } catch (_) {
    // Page absente ou payload illisible : on retombe sur le miroir.
  }
  return ALIAS_SANS_SAUCE_PAR_DEFAUT;
}

/**
 * Vrai si ce nom de sauce désigne l'option « pas de sauce ».
 *
 * @param {string|null|undefined} name
 * @returns {boolean}
 */
export function sauceIsNoSauceOption(name) {
  const n = normalizeSauceName(name);
  if (n === '') return false;
  return aliasSansSauce().indexOf(n) !== -1;
}

/**
 * Vrai si cette entrée de sauce du wizard (`{ id, name, raw }`) est l'option
 * « sans sauce ».
 */
export function sauceEntryIsNoSauceOption(sauce) {
  if (!sauce) return false;
  return sauceIsNoSauceOption(sauce.name ?? sauce?.raw?.name ?? '');
}

export default sauceIsNoSauceOption;

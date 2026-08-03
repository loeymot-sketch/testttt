/**
 * [P-MEGA-01] Détection centralisée de la taille tacos / kebab-like.
 *
 * PROBLÈME ORIGINEL :
 *   Trois fonctions dans KioskWizardComponent.vue (`detectViandeCount`,
 *   `shouldAskTacosTaille`, `inferTacosPresetMeta`) implémentaient chacune
 *   leur propre regex pour deviner la taille d'un produit. Les regex
 *   étaient incohérentes :
 *     - `detectViandeCount` : `\b\d+\s*viandes?\b | \bxxl\b | \bxl\b | \bl\b`
 *     - `shouldAskTacosTaille` : `'tacos m' | 'tacos l' | 'xl' | 'xxl' | '1..4 viande'`
 *     - `inferTacosPresetMeta` : `xxl | xl | tacos l | tacos m`
 *
 *   Conséquence rapportée par l'utilisateur :
 *     « Tacos 2/3/4 viandes » → on ne peut sélectionner qu'1 viande.
 *
 *   Cause racine : pour des libellés admin courants tels que `Tacos M`,
 *   `Tacos Méga`, `Tacos Famille`, `Tacos XXL Spécial`, l'une des trois
 *   fonctions matche (et skip donc l'étape Taille) pendant qu'une autre
 *   ne matche pas (et retombe à 1 viande). Le step viande affiche alors
 *   `0 / 1` et le bouton « + » se désactive après une sélection.
 *
 * CONTRAT :
 *   Une seule SIZE_TO_VIANDE_COUNT, partagée par les trois fonctions.
 *   Les regex digit (`2 viandes`, `3 viandes`, ...) restent prioritaires
 *   sur la taille lettre pour respecter un libellé explicite.
 *
 * NON-OBJECTIF :
 *   Ce helper ne remplace PAS la SSOT future `item.viande_count` exposée
 *   côté serveur (cf. P-MEGA-23 — drift admin/kiosk). Il rend uniquement
 *   l'heuristique nom cohérente et observable, en attendant que l'admin
 *   ajoute le champ structuré.
 */

const SIZE_TO_VIANDE_COUNT = Object.freeze({
  M: 1,
  L: 2,
  XL: 3,
  XXL: 4,
  MEGA: 4,
  FAMILLE: 4,
});

const SIZE_LABEL_REGEX = [
  // Ordre important : matcher les libellés les plus longs d'abord pour
  // éviter qu'« XL » ne capture une partie de « XXL ».
  { size: 'FAMILLE', re: /\bfamille\b/i },
  { size: 'MEGA', re: /\bm[eé]ga\b/i },
  { size: 'XXL', re: /\bxxl\b/i },
  { size: 'XL', re: /\bxl\b/i },
  { size: 'L', re: /\b(?:tacos\s+)?l\b/i },
  { size: 'M', re: /\b(?:tacos\s+)?m\b/i },
];

/**
 * Détecte une taille canonique depuis le nom d'un produit.
 * Retourne null si aucune taille reconnue (et pas de chiffre `N viandes`).
 *
 * @param {string} rawName
 * @returns {'M'|'L'|'XL'|'XXL'|'MEGA'|'FAMILLE'|null}
 */
export function detectTacosSize(rawName) {
  const name = String(rawName || '');
  if (!name) return null;
  for (const { size, re } of SIZE_LABEL_REGEX) {
    if (re.test(name)) return size;
  }
  return null;
}

/**
 * Détermine combien de viandes le client doit choisir, à partir du seul
 * nom du produit.
 *
 * Priorité :
 *   1. Une mention digit explicite (« 4 viandes ») gagne sur la taille
 *      lettre (un Tacos L libellé « Tacos L 3 viandes » → 3).
 *   2. Sinon, mapping SIZE_TO_VIANDE_COUNT.
 *   3. Sinon, retourne null (PAS 1) — l'appelant doit décider quoi faire :
 *      forcer l'étape Taille, afficher un guard, ou demander au serveur.
 *
 * @param {string} rawName
 * @returns {number|null}
 */
export function viandeCountFromName(rawName) {
  const name = String(rawName || '');
  if (!name) return null;
  const digitMatch = name.match(/\b([1-9]\d*)\s*viandes?\b/i);
  if (digitMatch) {
    const n = parseInt(digitMatch[1], 10);
    if (Number.isInteger(n) && n >= 1 && n <= 12) return n;
  }
  const size = detectTacosSize(name);
  if (size && Object.prototype.hasOwnProperty.call(SIZE_TO_VIANDE_COUNT, size)) {
    return SIZE_TO_VIANDE_COUNT[size];
  }
  return null;
}

/**
 * Indique si une taille est déjà "présent" dans le nom — utilisé par le
 * wizard pour décider d'afficher (ou non) l'étape Taille.
 *
 * @param {string} rawName
 * @returns {boolean}
 */
export function hasPresetSizeInName(rawName) {
  return viandeCountFromName(rawName) !== null;
}

/**
 * Construit le label humain de la taille pour le récap.
 *
 * @param {string} rawName
 * @returns {string|null}
 */
export function tacosSizeLabel(rawName) {
  const size = detectTacosSize(rawName);
  if (size) return size === 'MEGA' ? 'Méga' : size === 'FAMILLE' ? 'Famille' : size;
  const count = viandeCountFromName(rawName);
  if (count != null) return `${count} viande${count > 1 ? 's' : ''}`;
  return null;
}

export const __KIOSK_TACOS_SIZE_INTERNALS__ = Object.freeze({
  SIZE_TO_VIANDE_COUNT,
  SIZE_LABEL_REGEX,
});

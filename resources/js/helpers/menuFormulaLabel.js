/**
 * [LIBELLÉ-FORMULE 2026-11-02] Ce que le client a RÉELLEMENT pris dans une formule.
 *
 * Miroir de `App\Support\Order\MenuFormulaLabel` (PHP) : toute évolution se fait dans les deux.
 * Une formule est UN supplément scellé « Menu (Frites + Boisson) » au nom identique pour les trois
 * choix ; seul son `role` (menu_full / menu_frites / menu_boisson) dit ce que le client a pris.
 * Ne change que le TEXTE — le prix reste le `line_total` scellé (NF525).
 */
export const MENU_FULL = 'Menu Frites + Boisson';
export const MENU_FRITES = 'Frites seules';
export const MENU_BOISSON = 'Boisson seule';

/** Libellé d'un supplément de formule, ou '' si le rôle n'est pas une formule (garder le nom brut). */
export function menuFormulaLabel(role) {
    const r = String(role == null ? '' : role).trim().toLowerCase();
    if (r === '' || !r.startsWith('menu_')) return '';
    if (r === 'menu_frites') return MENU_FRITES;
    if (r === 'menu_boisson') return MENU_BOISSON;
    return MENU_FULL;
}

/**
 * Libellé à AFFICHER pour un supplément : le choix réel si son nom scellé est le nom GÉNÉRIQUE de la
 * formule (« Menu (Frites + Boisson) »), sinon le nom tel quel — « Coca-Cola » (rôle menu_boisson) garde
 * la boisson choisie au lieu d'être écrasé par « Boisson seule ».
 */
export function menuFormulaAddonName(role, name) {
    const label = menuFormulaLabel(role);
    const clean = String(name == null ? '' : name).trim();
    if (label === '') return String(name == null ? '' : name);
    return clean === '' || /^menu\b/i.test(clean) ? label : String(name);
}

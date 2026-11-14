/**
 * [GOAL REMARQUES 2026-10-03 · R-017 · revue vague 3 P2-3] Temps de préparation annoncé au client,
 * choisi par le caissier en acceptant une commande du site.
 *
 * Bornes = celles du SERVEUR (`App\Http\Requests\OrderStatusRequest` : integer, min:5, max:120). Un
 * champ qui acceptait 1-180 faisait échouer « Accepter » (422) et la commande restait en attente.
 * La caisse et le Suivi passent tous deux par ici : une seule règle.
 */
export const TEMPS_PREPARATION_MIN = 5;
export const TEMPS_PREPARATION_MAX = 120;
export const TEMPS_PREPARATION_DEFAUT = 15;

/** Valeur saisie → minutes entières ramenées dans les bornes du serveur (défaut si illisible). */
export function bornerTempsPreparation(valeur) {
    const n = parseInt(valeur, 10);
    if (!Number.isFinite(n)) return TEMPS_PREPARATION_DEFAUT;
    return Math.min(TEMPS_PREPARATION_MAX, Math.max(TEMPS_PREPARATION_MIN, n));
}

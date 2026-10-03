/**
 * [GOAL REMARQUES 2026-10-03 · R-009] Aperçu TECHNIQUE d'une commande pour la file « À encaisser » de la
 * caisse — propriétaire : « faudrait pas juste voir le total […] mettre même les noms de produits que il y
 * a avec les mots techniques ». La file ne montrait que le N° et le prix : le caissier ne reconnaissait
 * pas une commande téléphone sans le nom du client.
 *
 * Mots techniques = ceux de la cuisine : chaque ligne passe par le moteur de l'écran cuisine
 * (`renderItemSymbolic`), après le même repli des formules (`collapseBundledAddonItems`) — la file dit
 * exactement ce que la cuisine lit (« 2× CAY | P | POU | STO | ALG + MENU : MAY »).
 *
 * Budget en caractères ; la coupe tombe sur un séparateur et le reste est ANNONCÉ (« +N »), jamais une
 * ellipse muette (même règle que compositionCommande.compoAffichee).
 */
import { renderItemSymbolic } from './kdsSymbolic';
import { collapseBundledAddonItems } from './kdsBundledAddons';

export const BUDGET_APERCU = 70;
const SEPARATEUR = ' · ';

function ligneTechnique(orderItem) {
    const lignes = renderItemSymbolic(orderItem).lines;
    const principale = lignes.find((l) => l.type === 'symbolic-main');
    const menu = lignes.find((l) => l.type === 'symbolic-menu');
    const qte = Number(orderItem && orderItem.quantity) > 1 ? `${orderItem.quantity}× ` : '';
    const texte = (principale && principale.label) || String((orderItem && orderItem.item_name) || '').trim();
    if (!texte) return '';
    return `${qte}${texte}${menu ? ` + ${menu.label}` : ''}`;
}

/**
 * @param {{order_items?: object[]}} commande
 * @param {number} budget
 * @returns {{texte: string, restants: number}}
 */
export function apercuTechnique(commande, budget = BUDGET_APERCU) {
    const items = commande && Array.isArray(commande.order_items) ? commande.order_items : [];
    const morceaux = collapseBundledAddonItems(items).map(ligneTechnique).filter(Boolean);
    if (morceaux.length === 0) return { texte: '', restants: 0 };

    const gardes = [];
    let longueur = 0;
    for (const m of morceaux) {
        const cout = gardes.length ? longueur + SEPARATEUR.length + m.length : m.length;
        if (gardes.length && cout > budget) break;
        if (!gardes.length && m.length > budget) {
            // Première ligne trop longue : coupée sur un « | » plutôt qu'au milieu d'un symbole.
            const parts = m.split(' | ');
            let t = parts[0];
            for (const p of parts.slice(1)) {
                if ((t + ' | ' + p).length > budget) break;
                t += ' | ' + p;
            }
            gardes.push(t);
            longueur = t.length;
            break;
        }
        gardes.push(m);
        longueur = cout;
    }

    return { texte: gardes.join(SEPARATEUR), restants: morceaux.length - gardes.length };
}

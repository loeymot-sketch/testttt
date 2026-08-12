/**
 * [GOAL-OPS-SWAP W1 2026-08-12] Rend son corps JSON à une erreur reçue en Blob.
 *
 * LE DÉFAUT (prouvé en navigateur, session admin réelle, /admin/sales-report) :
 * les exports admin partent en `responseType: 'blob'` — c'est correct, la
 * réponse NOMINALE est un fichier. Mais sur une réponse d'ERREUR, axios ne
 * désérialise pas : `err.response.data` reste un Blob. Les 20 écrans qui
 * affichent `err.response.data.message` montrent donc `undefined`.
 *
 * Mesuré : le serveur répondait « Trop de lignes pour un export PDF (3191
 * lignes). Affinez la période avec un filtre de date. » ; l'écran affichait
 * « undefined ». L'exploitant en conclut que le rapport est cassé — alors que
 * la réponse contenait la marche à suivre.
 *
 * Écrans concernés (même motif `responseType:'blob'` + `.data.message`) :
 *   salesReport · itemsReport · onlineOrder · creditBalanceReport · transaction
 *   posOrder · item · itemCategory · customer · subscriber · coupon · offer
 *   waiter · chef · employee · administrator · deliveryBoy · diningTable
 *   tableOrder · pushNotification
 *
 * POURQUOI UN INTERCEPTEUR ET PAS 20 CORRECTIFS : une cause unique se corrige
 * en un point. Vingt corrections, c'est vingt occasions d'en oublier une —
 * et le prochain écran d'export naîtrait déjà cassé.
 *
 * CE QUE CE MODULE NE FAIT PAS, VOLONTAIREMENT :
 *   - il ne change AUCUN en-tête ni aucun contrat /api ;
 *   - il ne touche PAS au chemin de succès (un vrai fichier reste un Blob) ;
 *   - il ne RÉSOUT jamais une erreur : le rejet est toujours propagé, sinon
 *     l'écran fabriquerait un PDF à partir d'un corps d'erreur ;
 *   - il n'invente aucun message : un Blob non-JSON est laissé tel quel.
 */

/**
 * Lit le contenu textuel d'un Blob, avec repli `FileReader` si `Blob.text()`
 * n'existe pas (environnements anciens). Retourne `null` en cas d'échec —
 * jamais d'exception : un normalisateur ne doit pas masquer l'erreur d'origine.
 *
 * @param {Blob} blob
 * @returns {Promise<string|null>}
 */
async function lireTexte(blob) {
    try {
        if (typeof blob.text === 'function') {
            return await blob.text();
        }
        if (typeof FileReader !== 'undefined') {
            return await new Promise((resolve) => {
                const reader = new FileReader();
                reader.onload = () => resolve(String(reader.result || ''));
                reader.onerror = () => resolve(null);
                reader.readAsText(blob);
            });
        }
    } catch (_) {
        // ignoré volontairement — voir contrat ci-dessus
    }
    return null;
}

/**
 * Installe l'intercepteur de réponse sur une instance axios.
 *
 * @param {object} axios instance axios (doit exposer interceptors.response.use)
 * @returns {number|undefined} identifiant de l'intercepteur (pour `eject`)
 */
export function installBlobErrorNormalizer(axios) {
    if (!axios || !axios.interceptors || !axios.interceptors.response) {
        return undefined;
    }

    return axios.interceptors.response.use(
        (response) => response,
        async (error) => {
            const data = error && error.response ? error.response.data : null;

            if (typeof Blob === 'undefined' || !(data instanceof Blob)) {
                throw error;
            }

            const texte = await lireTexte(data);
            if (texte === null || texte === '') {
                throw error;
            }

            try {
                const parsed = JSON.parse(texte);
                // Un JSON scalaire (`"texte"`, `42`) n'a pas de `.message` :
                // le remplacer casserait autant que le Blob. On ne substitue
                // que si l'on rend un objet réellement exploitable.
                if (parsed && typeof parsed === 'object') {
                    error.response.data = parsed;
                }
            } catch (_) {
                // Corps non-JSON (vrai PDF, HTML d'erreur serveur) : on laisse
                // le Blob intact plutôt que d'inventer un message.
            }

            throw error;
        },
    );
}

export default installBlobErrorNormalizer;

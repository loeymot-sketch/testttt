/**
 * [GOAL-OPS-SWAP W1 · constat EXPORT-BLOB-MUET 2026-08-12]
 *
 * DÉFAUT PROUVÉ EN NAVIGATEUR (session admin réelle, /admin/sales-report) :
 * les exports partent en `responseType: 'blob'`. Sur une réponse d'ERREUR
 * (422), axios ne désérialise pas le corps : `err.response.data` est un Blob.
 * Les 20 écrans concernés affichent `err.response.data.message`, qui vaut
 * donc littéralement `undefined` — alors que le serveur avait répondu, en
 * français : « Trop de lignes pour un export PDF (3191 lignes). Affinez la
 * période avec un filtre de date. »
 *
 * L'exploitant voit « undefined » et conclut que le rapport est cassé.
 *
 * Ce banc éprouve le normalisateur qui rend son corps JSON à l'erreur.
 * Il DOIT échouer tant que `resources/js/shared/blob-error.js` n'existe pas.
 */
import { describe, expect, it } from 'vitest';
import { installBlobErrorNormalizer } from '../../resources/js/shared/blob-error';

/** Faux axios : capture le gestionnaire d'erreur installé par l'intercepteur. */
function fakeAxios() {
    let onRejected = null;
    return {
        interceptors: {
            response: {
                use: (_onFulfilled, rejected) => {
                    onRejected = rejected;
                },
            },
        },
        reject: (err) => onRejected(err),
        installed: () => typeof onRejected === 'function',
    };
}

const MESSAGE_SERVEUR =
    'Trop de lignes pour un export PDF (3191 lignes). Affinez la période avec un filtre de date.';

function erreurExportReelle() {
    return {
        response: {
            status: 422,
            data: new Blob([JSON.stringify({ status: false, message: MESSAGE_SERVEUR })], {
                type: 'application/json',
            }),
        },
    };
}

describe('normalisateur d’erreur Blob (exports admin)', () => {
    it('installe bien un gestionnaire de rejet', () => {
        const axios = fakeAxios();
        installBlobErrorNormalizer(axios);
        expect(axios.installed()).toBe(true);
    });

    it('rend lisible le message serveur qu’un export en Blob rendait `undefined`', async () => {
        const axios = fakeAxios();
        installBlobErrorNormalizer(axios);

        await expect(axios.reject(erreurExportReelle())).rejects.toMatchObject({
            response: { status: 422 },
        });

        // Le contrat vécu par les 20 écrans : `err.response.data.message`.
        const err = await axios.reject(erreurExportReelle()).catch((e) => e);
        expect(err.response.data.message).toBe(MESSAGE_SERVEUR);
        expect(err.response.data.status).toBe(false);
    });

    it('REJETTE toujours — ne transforme jamais une erreur en succès', async () => {
        const axios = fakeAxios();
        installBlobErrorNormalizer(axios);

        let resolu = false;
        await axios.reject(erreurExportReelle()).then(
            () => {
                resolu = true;
            },
            () => {},
        );
        // Si l'intercepteur résolvait, l'écran construirait un PDF depuis un
        // corps d'erreur et téléchargerait un fichier illisible.
        expect(resolu).toBe(false);
    });

    it('laisse intacte une erreur dont le corps n’est PAS un Blob', async () => {
        const axios = fakeAxios();
        installBlobErrorNormalizer(axios);

        const dejaJson = { response: { status: 422, data: { message: 'déjà lisible' } } };
        const err = await axios.reject(dejaJson).catch((e) => e);
        expect(err.response.data.message).toBe('déjà lisible');
    });

    it('laisse intact un Blob qui n’est pas du JSON (vrai PDF en erreur)', async () => {
        const axios = fakeAxios();
        installBlobErrorNormalizer(axios);

        const binaire = {
            response: {
                status: 500,
                data: new Blob(['%PDF-1.4 binaire non-JSON'], { type: 'application/pdf' }),
            },
        };
        const err = await axios.reject(binaire).catch((e) => e);
        // Ne doit ni jeter, ni fabriquer un message inventé.
        expect(err.response.data).toBeInstanceOf(Blob);
    });

    it('n’explose pas sur une erreur réseau sans réponse', async () => {
        const axios = fakeAxios();
        installBlobErrorNormalizer(axios);

        const reseau = { message: 'Network Error' };
        const err = await axios.reject(reseau).catch((e) => e);
        expect(err.message).toBe('Network Error');
    });
});

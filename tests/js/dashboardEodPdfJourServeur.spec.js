import { describe, it, expect, vi, beforeEach } from 'vitest';
import DashboardComponent from '../../resources/js/components/admin/dashboard/DashboardComponent.vue';

// Le composant n'importe PAS axios : il appelle l'instance globale posée au démarrage
// (`window.axios`). Se moquer du module ne l'atteindrait donc pas — c'est la variable
// globale qu'il faut remplacer, sinon le banc mesure autre chose que ce que le code fait.
const axios = { post: vi.fn(), get: vi.fn() };
globalThis.axios = axios;
window.axios = axios;

/**
 * [GOAL DASHBOARD-CONTRÔLE 2026-09-02 · Sub 3.1 · T-3.1.3]
 *
 * Le PDF de clôture demandait le jour calculé par le NAVIGATEUR
 * (`new Date()` + fuseau du poste). Une tablette de caisse réglée sur un autre fuseau, ou
 * dont l'horloge a dérivé, faisait donc clôturer un autre jour que celui du restaurant —
 * sur une pièce de nature fiscale. Le serveur, lui, connaît l'heure de Paris.
 *
 * On vérifie le COMPORTEMENT de la méthode (l'URL réellement appelée et le nom du fichier
 * enregistré), pas la présence d'une chaîne dans le source.
 */
describe('PDF de clôture — le jour est choisi par le serveur', () => {
    let telecharge;

    function methode() {
        // On appelle la méthode sur un contexte minimal : monter tout le tableau de bord
        // (huit cartes, graphiques, i18n) n'apporterait rien à ce qu'on mesure ici.
        const ctx = {
            eodDownloading: false,
            $t: (k) => k,
        };

        return DashboardComponent.methods.downloadEodPdf.bind(ctx);
    }

    beforeEach(() => {
        axios.post.mockReset();
        telecharge = null;

        const vraiCreate = document.createElement.bind(document);
        vi.spyOn(document, 'createElement').mockImplementation((tag) => {
            const el = vraiCreate(tag);
            if (tag === 'a') {
                el.click = () => { telecharge = el.download; };
            }
            return el;
        });
        window.URL.createObjectURL = vi.fn(() => 'blob:faux');
        window.URL.revokeObjectURL = vi.fn();
    });

    it("n'envoie plus le jour calculé par le navigateur", async () => {
        axios.post.mockResolvedValue({
            data: new Blob(['%PDF-1.4']),
            headers: { 'content-disposition': 'attachment; filename="cloture_jour_2026-09-02.pdf"' },
        });

        await methode()();

        expect(axios.post).toHaveBeenCalledTimes(1);
        const url = axios.post.mock.calls[0][0];
        expect(url).toBe('admin/dashboard/eod-pdf');
        expect(url).not.toMatch(/date=/);
    });

    it('enregistre le fichier sous le nom que le serveur a donné', async () => {
        axios.post.mockResolvedValue({
            data: new Blob(['%PDF-1.4']),
            headers: { 'content-disposition': 'attachment; filename="cloture_jour_2026-09-01.pdf"' },
        });

        await methode()();

        expect(telecharge).toBe('cloture_jour_2026-09-01.pdf');
    });

    it("retombe sur un nom lisible si l'en-tête manque", async () => {
        axios.post.mockResolvedValue({ data: new Blob(['%PDF-1.4']), headers: {} });

        await methode()();

        expect(telecharge).toMatch(/^cloture_jour\.pdf$/);
    });
});

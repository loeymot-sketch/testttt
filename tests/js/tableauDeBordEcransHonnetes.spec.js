import { describe, it, expect, vi, afterEach } from 'vitest';
import { mount, flushPromises } from '@vue/test-utils';
import { createI18n } from 'vue-i18n';
import SlaAlertsComponent from '../../resources/js/components/admin/dashboard/SlaAlertsComponent.vue';
import LastZReportWidget from '../../resources/js/components/admin/dashboard/LastZReportWidget.vue';
import frMessages from '../../resources/js/languages/fr.json';

const i18n = createI18n({ legacy: false, locale: 'fr', messages: { fr: frMessages }, silentFallbackWarn: true });

/**
 * [2026-09-03] Deux écrans affirmaient plus que ce qu'ils mesuraient.
 *
 * · « Aucune préparation hors délai · Dernier contrôle terminé avec succès » s'affichait
 *   devant 344 commandes figées en préparation, la plus ancienne depuis le 10 juin. Le 0
 *   était juste dans son périmètre — les dernières 24 h — mais ce périmètre n'était écrit
 *   NULLE PART. Une affirmation absolue sur une mesure bornée.
 * · Le dernier rapport Z avait 47 jours, avec 81 commandes encaissées depuis, et s'affichait
 *   exactement comme un Z d'aujourd'hui. Son statut sortait en anglais brut (« Closed »).
 */

describe('alertes SLA — le périmètre est écrit à l’écran', () => {
    afterEach(() => { vi.restoreAllMocks(); });

    function monter(reponse) {
        return mount(SlaAlertsComponent, {
            global: {
                plugins: [i18n],
                mocks: {
                    $store: { dispatch: vi.fn(() => Promise.resolve(reponse)), getters: { authPermission: [] } },
                },
                stubs: { LoadingComponent: true, 'router-link': true },
            },
        });
    }

    it('annonce la fenêtre publiée par le serveur quand rien n’est hors délai', async () => {
        const w = monter({ data: { data: [], fenetre_heures: 24 } });
        await flushPromises();

        const portee = w.find('[data-testid="sla-empty-scope"]');
        expect(portee.exists(), 'le message vert doit dire sur quoi il porte').toBe(true);
        expect(portee.text()).toContain('24');
        // Garde anti-test-vide : le message absolu d'origine ne doit plus être seul.
        expect(w.text()).toContain('Aucune préparation hors délai');
        w.unmount();
    });

    it('suit la fenêtre du serveur plutôt qu’une valeur écrite en dur', async () => {
        const w = monter({ data: { data: [], fenetre_heures: 6 } });
        await flushPromises();
        expect(w.find('[data-testid="sla-empty-scope"]').text()).toContain('6');
        w.unmount();
    });

    it('retombe sur 24 h si un serveur plus ancien ne publie pas la clé', async () => {
        const w = monter({ data: { data: [] } });
        await flushPromises();
        expect(w.find('[data-testid="sla-empty-scope"]').text()).toContain('24');
        w.unmount();
    });
});

describe('dernier rapport Z — âge et statut lisibles', () => {
    function widget() {
        return mount(LastZReportWidget, {
            global: {
                plugins: [i18n],
                mocks: { $store: { getters: { authPermission: [] } } },
                stubs: { LoadingComponent: true, 'router-link': true },
            },
        });
    }

    it('traduit le statut plutôt que de le rendre brut', () => {
        const vm = widget().vm;
        vm.resolvedReport = { sequence_no: 27, status: 'closed', closed_at: new Date().toISOString() };
        expect(vm.statutLisible).toBe('Clôturé');
    });

    it('garde lisible un statut inconnu au lieu de le faire disparaître', () => {
        const vm = widget().vm;
        vm.resolvedReport = { sequence_no: 1, status: 'archived', closed_at: null };
        expect(vm.statutLisible).toBe('Archived');
    });

    it('dit l’âge du rapport, et le signale au-delà de deux jours', () => {
        const vm = widget().vm;
        const ilYA47Jours = new Date(Date.now() - 47 * 86400000).toISOString();
        vm.resolvedReport = { sequence_no: 27, status: 'closed', closed_at: ilYA47Jours };
        expect(vm.ageJours).toBe(47);
        expect(vm.ageTexte).toBe('il y a 47 jours');
        expect(vm.ageInquietant, 'un Z de 47 jours doit se voir sans le chercher').toBe(true);
    });

    it('ne crie pas au loup sur un rapport du jour', () => {
        const vm = widget().vm;
        vm.resolvedReport = { sequence_no: 28, status: 'closed', closed_at: new Date().toISOString() };
        expect(vm.ageTexte).toBe("aujourd'hui");
        expect(vm.ageInquietant).toBe(false);
    });
});

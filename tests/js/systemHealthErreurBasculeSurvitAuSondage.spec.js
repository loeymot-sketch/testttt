import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import { mount } from '@vue/test-utils';
import axios from 'axios';
import SystemHealthComponent from '../../resources/js/components/admin/observability/SystemHealthComponent.vue';

/**
 * [GOAL G3 2026-09-03 · complément superviseur]
 *
 * L'écran de contrôle porte UNE seule chaîne `erreur`, partagée entre la lecture de santé
 * (rejouée en boucle par un minuteur) et la bascule d'interrupteur (déclenchée par un clic).
 *
 * `charger()` remet `erreur` à `null` en entrée. Donc : l'exploitant bascule un interrupteur,
 * la bascule ÉCHOUE, le message s'affiche — et au tic suivant du sondage il disparaît. Le
 * bouton, lui, n'a pas bougé (l'écran refuse à juste titre d'inverser sur échec). L'exploitant
 * voit donc un interrupteur inchangé et plus aucune explication : il conclut que son clic n'a
 * pas été pris, et recommence.
 *
 * C'est le pendant côté écran du défaut que G3 corrige côté serveur : là-bas le journal
 * affirmait une bascule qui n'avait pas eu lieu ; ici l'écran efface l'aveu qu'elle a raté.
 *
 * Second point, plus court : ce message n'est annoncé à aucune aide technique — il apparaît
 * dans un `<p>` sans `role="alert"`.
 */
describe('Contrôle système — un échec de bascule ne doit pas être effacé par le sondage', () => {
    let get;
    let put;

    const SANTE = {
        checks: { db: { status: 'ok' } },
        timestamp: new Date().toISOString(),
    };

    const INTERRUPTEURS = {
        data: [
            { nom: 'wheel', libelle: 'Roue de la fortune', actif: false, consequence: '' },
        ],
    };

    beforeEach(() => {
        vi.useFakeTimers();
        get = vi.spyOn(axios, 'get').mockImplementation((url) => {
            if (String(url).includes('interrupteurs')) return Promise.resolve({ data: INTERRUPTEURS });
            return Promise.resolve({ data: SANTE });
        });
        put = vi.spyOn(axios, 'put');
    });

    afterEach(() => {
        vi.restoreAllMocks();
        vi.useRealTimers();
    });

    async function monter() {
        const w = mount(SystemHealthComponent, {
            global: { stubs: { RouterLink: true } },
        });
        await vi.runOnlyPendingTimersAsync();
        return w;
    }

    it("garde le message d'échec quand la lecture de santé se rejoue derrière", async () => {
        vi.spyOn(window, 'confirm').mockReturnValue(true);
        put.mockRejectedValue(new Error('500'));

        const w = await monter();

        await w.find('[data-testid="system-health-erreur"]').exists();
        const bouton = w.findAll('button').find((b) => /Activé|Désactivé/.test(b.text()));
        expect(bouton, "le bouton de bascule doit exister").toBeTruthy();

        await bouton.trigger('click');
        await vi.runOnlyPendingTimersAsync();
        await w.vm.$nextTick();

        expect(
            w.find('[data-testid="system-health-erreur"]').text(),
            "l'échec de bascule doit être affiché"
        ).toContain('bascule');

        // Le sondage se rejoue — c'est son travail, et il réussit.
        await w.vm.charger();
        await w.vm.$nextTick();

        expect(
            w.find('[data-testid="system-health-erreur"]').exists(),
            "une lecture de santé réussie ne doit PAS effacer l'aveu qu'une bascule a échoué : "
            + "l'exploitant croirait que son clic n'a pas été pris et recommencerait"
        ).toBe(true);

        expect(w.find('[data-testid="system-health-erreur"]').text()).toContain('bascule');
    });

    it("annonce l'erreur aux aides techniques", async () => {
        vi.spyOn(window, 'confirm').mockReturnValue(true);
        put.mockRejectedValue(new Error('500'));

        const w = await monter();
        const bouton = w.findAll('button').find((b) => /Activé|Désactivé/.test(b.text()));
        await bouton.trigger('click');
        await vi.runOnlyPendingTimersAsync();
        await w.vm.$nextTick();

        const zone = w.find('[data-testid="system-health-erreur"]');
        expect(zone.exists()).toBe(true);
        expect(
            zone.attributes('role'),
            "un message d'erreur qui apparaît après une action doit être annoncé : sans "
            + "`role=\"alert\"`, un lecteur d'écran ne dit jamais que la bascule a échoué"
        ).toBe('alert');
    });

    it("efface bien l'erreur de LECTURE quand la lecture redevient bonne", async () => {
        const w = await monter();

        get.mockRejectedValueOnce(new Error('réseau'));
        await w.vm.charger();
        await w.vm.$nextTick();
        expect(w.find('[data-testid="system-health-erreur"]').text()).toContain('état du système');

        await w.vm.charger();
        await w.vm.$nextTick();
        expect(
            w.find('[data-testid="system-health-erreur"]').exists(),
            "une erreur de lecture, elle, doit disparaître dès que la lecture repasse : "
            + "sinon on garde un rouge périmé, ce qui est l'autre moitié du même défaut"
        ).toBe(false);
    });
});

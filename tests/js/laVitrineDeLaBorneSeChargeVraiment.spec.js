import { describe, it, expect, vi, beforeEach } from 'vitest';
import { mount, flushPromises } from '@vue/test-utils';
import { createStore } from 'vuex';
import axios from 'axios';
import KioskIdleScreenComponent from '../../resources/js/components/frontend/kiosk/KioskIdleScreenComponent.vue';

vi.mock('axios', () => ({
    default: { get: vi.fn(), defaults: { baseURL: '' } },
}));

/**
 * [ONB-12 2026-08-28] La vitrine de la borne se charge VRAIMENT.
 *
 * ═══ POURQUOI CE BANC EXISTE ═══
 *
 * Un audit adverse a relevé que mes huit premiers bancs sur cet écran lisaient le
 * fichier source avec `fs.readFileSync` et n'assertaient que des chaînes. Ils
 * tournaient en 5 ms — et ils ne voyaient pas la mutation la plus évidente :
 *
 *   > supprimer `this.chargerLaVitrine()` de `mounted()`.
 *
 * C'est le **seul** site d'appel. Le carrousel resterait vide à vie, et les huit
 * tests resteraient verts, puisqu'ils vérifiaient la présence de la chaîne
 * `axios.get('frontend/item/featured-items')` — jamais qu'elle soit appelée.
 *
 * Un banc qui lit du texte prouve qu'une ligne existe. Il ne prouve pas qu'elle
 * s'exécute. Celui-ci monte le composant et regarde ce qui s'affiche.
 */
describe('la vitrine de la borne se charge vraiment', () => {
    const magasin = (reglages = {}) => createStore({
        modules: {
            frontendSetting: {
                namespaced: true,
                actions: { lists: () => Promise.resolve({ data: { data: reglages } }) },
            },
        },
    });

    const monter = (reglages = {}) => mount(KioskIdleScreenComponent, {
        global: {
            plugins: [magasin(reglages)],
            mocks: { $t: (cle) => cle, $te: () => false },
            stubs: { LoadingComponent: true, 'router-link': true },
        },
    });

    beforeEach(() => {
        vi.clearAllMocks();
    });

    it('appelle la carte du commerçant au montage — pas seulement dans le code', async () => {
        axios.get.mockResolvedValue({ data: { data: [] } });

        monter();
        await flushPromises();

        expect(
            axios.get,
            "`chargerLaVitrine()` n'est jamais appelée au montage. Le carrousel\n"
            + 'resterait vide à vie, et les bancs qui lisent le fichier source ne le\n'
            + 'verraient pas : ils prouvent que la ligne existe, pas qu\'elle s\'exécute.',
        ).toHaveBeenCalledWith('frontend/item/featured-items');
    });

    it('affiche les produits du commerçant, avec leur image pleine taille', async () => {
        axios.get.mockResolvedValue({
            data: {
                data: [
                    { name: 'Tacos maison', cover: '/img/tacos-cover.webp', thumb: '/img/tacos-thumb.webp' },
                    { name: 'Bol du jour', cover: '', thumb: '/img/bol-thumb.webp' },
                ],
            },
        });

        const ecran = monter();
        await flushPromises();

        expect(ecran.vm.products).toHaveLength(2);

        // `cover` d'abord : le cadre fait 900 px de large, une vignette 320 px y
        // serait étirée de trois fois.
        expect(ecran.vm.products[0].img).toBe('/img/tacos-cover.webp');

        // …et `thumb` en repli quand il n'y a pas de pleine taille.
        expect(ecran.vm.products[1].img).toBe('/img/bol-thumb.webp');

        expect(ecran.html()).toContain('Tacos maison');
    });

    it("écarte les images de substitution au lieu de les afficher en grand", async () => {
        // `Item::getThumbAttribute()` renvoie TOUJOURS une chaîne et retombe sur
        // `item-default.svg` : filtrer sur la présence de `thumb` n'écarte rien.
        // Un carré gris 200×200 s'affichait donc plein cadre (900×884), agrandi
        // et animé, sur l'écran client.
        axios.get.mockResolvedValue({
            data: {
                data: [
                    { name: 'Sans photo', cover: '/images/item/cover.png', thumb: '/images/menu/item-default.svg' },
                    { name: 'Avec photo', cover: '/img/vrai.webp', thumb: '/img/vrai-t.webp' },
                ],
            },
        });

        const ecran = monter();
        await flushPromises();

        expect(
            ecran.vm.products.map((p) => p.name),
            "Un produit sans vraie photo entre en vitrine : le client verrait un\n"
            + 'rectangle gris tourner à la place des plats.',
        ).toEqual(['Avec photo']);
    });

    it("sur une carte vide, l'écran reste sobre au lieu d'afficher un cadre creux", async () => {
        axios.get.mockResolvedValue({ data: { data: [] } });

        const ecran = monter();
        await flushPromises();

        expect(ecran.vm.products).toHaveLength(0);
        expect(ecran.find('.cay-hero').exists()).toBe(false);

        // Et la légende qui promet des produits doit partir avec eux : le gabarit
        // est en positionnement absolu, la laisser seule ouvrait ~1020 px de vide
        // sous une promesse.
        expect(
            ecran.find('.cay-eyebrow').exists(),
            'La légende « Nos incontournables » reste affichée au-dessus du vide.',
        ).toBe(false);
    });

    it("une panne réseau ne fait pas réapparaître la carte d'un autre établissement", async () => {
        axios.get.mockRejectedValue(new Error('réseau coupé'));

        const ecran = monter();
        await flushPromises();

        expect(
            ecran.vm.products,
            "En cas d'échec, la vitrine doit rester vide. Toute reprise sur une liste\n"
            + "livrée ferait réapparaître les produits de Le Cayenne chez quelqu'un d'autre.",
        ).toEqual([]);
    });

    it("le bandeau de marque ne montre aucun logo tant que le commerçant n'en a pas", async () => {
        axios.get.mockResolvedValue({ data: { data: [] } });

        const ecran = monter();
        await flushPromises();

        expect(
            ecran.vm.brandLogo,
            "Un logo s'affiche par défaut. C'était la marque déposée « LE CAYENNE ® »,\n"
            + "l'élément le plus grand de l'écran client, imposée à tout installateur.",
        ).toBeFalsy();
    });

    it('le logo suit trois crans de repli, du plus spécifique au plus général', async () => {
        axios.get.mockResolvedValue({ data: { data: [] } });

        // 1. le logo DÉDIÉ à l'accueil borne l'emporte
        const dedie = monter({
            kiosk_attract_logo: '/img/accueil.webp',
            logo_full_path: '/img/general.webp',
        });
        await flushPromises();
        expect(dedie.vm.brandLogo).toBe('/img/accueil.webp');

        // 2. à défaut, le logo général de l'établissement
        const general = monter({ logo_full_path: '/img/general.webp' });
        await flushPromises();
        expect(general.vm.brandLogo).toBe('/img/general.webp');

        // 3. à défaut, RIEN — et le nom s'affiche en toutes lettres. Ce repli
        //    n'était jamais atteignable avant : `brandLogo` valant toujours le
        //    logo livré, le `v-else` du gabarit était du code mort.
        const rien = monter({ company_name: 'Chez Sami' });
        await flushPromises();
        expect(rien.vm.brandLogo).toBeFalsy();
        expect(rien.html()).toContain('Chez Sami');
    });
});

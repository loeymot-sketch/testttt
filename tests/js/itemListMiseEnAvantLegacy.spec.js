/**
 * [F-ITEM-SAVE-MUET 2026-09-03]
 *
 * Sentinelle — une valeur héritée ne doit pas rendre une fiche produit
 * impossible à enregistrer.
 *
 * `is_featured` n'accepte que Ask::YES (5) ou Ask::NO (10), et la validation
 * serveur refuse explicitement 0. Or, mesuré sur la production le 2026-09-03,
 * 41 des 70 fiches du menu portaient 0 — « jamais renseigné », héritage des
 * imports. Le formulaire renvoyait ce 0 tel quel : refus 422, aucune case cochée
 * à l'écran, et un commerçant qui conclut que « ça ne s'enregistre jamais ».
 * 42 fiches étaient concernées, dont 34 ACTIVES au menu.
 *
 * 0 n'a jamais signifié « mis en avant » (la mise en avant filtre sur YES=5) :
 * replier sur NO ne change donc aucun comportement, cela rend seulement la fiche
 * modifiable. Ce banc échoue si l'on retire la coercition.
 */

import { describe, it, expect, vi, beforeEach } from 'vitest';

const appServiceMock = vi.hoisted(() => ({
    sideDrawerShow: vi.fn(),
    sideDrawerHide: vi.fn(),
    destroyConfirmation: vi.fn(),
}));

vi.mock('../../resources/js/services/appService', () => ({ default: appServiceMock }));
vi.mock('../../resources/js/services/alertService', () => ({
    default: { error: vi.fn(), success: vi.fn(), successFlip: vi.fn() },
}));

import ItemListComponent from '../../resources/js/components/admin/items/ItemListComponent.vue';
import askEnum from '../../resources/js/enums/modules/askEnum';

const ficheServeur = (remplace = {}) => ({
    id: 33,
    name: 'Petite Frites',
    flat_price: '2.50',
    description: '',
    caution: '',
    is_featured: 0,
    is_upsell: null,
    item_type: 10,
    tax_id: 3,
    item_category_id: 4,
    status: 5,
    channels: null,
    allergen_flags: [],
    kds_station: 'hot',
    order: 3,
    ...remplace,
});

/**
 * `edit()` n'a besoin ni du DOM ni des six modules Vuex de l'écran : on l'appelle
 * sur un contexte minimal. On teste ainsi la VRAIE méthode, pas une copie ni le
 * texte du fichier source.
 */
const appelerEdit = (item) => {
    const contexte = {
        props: { form: {}, errors: {}, search: { page: 1 } },
        loading: { isActive: false },
        $store: { dispatch: vi.fn() },
    };
    ItemListComponent.methods.edit.call(contexte, item);
    return contexte;
};

describe('Liste des produits — la mise en avant héritée ne bloque plus la modification', () => {
    beforeEach(() => {
        vi.clearAllMocks();
    });

    it('replie une mise en avant à 0 sur « Non » (valeur refusée par le serveur)', () => {
        const { props } = appelerEdit(ficheServeur({ is_featured: 0 }));
        expect(Number(props.form.is_featured)).toBe(askEnum.NO);
    });

    it('replie aussi les valeurs héritées hors référentiel, comme 1', () => {
        const { props } = appelerEdit(ficheServeur({ is_featured: 1 }));
        expect(Number(props.form.is_featured)).toBe(askEnum.NO);
    });

    it('ne touche pas à une fiche réellement mise en avant', () => {
        const { props } = appelerEdit(ficheServeur({ is_featured: askEnum.YES }));
        expect(Number(props.form.is_featured)).toBe(askEnum.YES);
    });

    it('ne touche pas à une fiche explicitement non mise en avant', () => {
        const { props } = appelerEdit(ficheServeur({ is_featured: askEnum.NO }));
        expect(Number(props.form.is_featured)).toBe(askEnum.NO);
    });

    it('conserve le reste de la fiche intact', () => {
        const { props } = appelerEdit(ficheServeur({ is_featured: 0 }));
        expect(props.form.name).toBe('Petite Frites');
        expect(props.form.order).toBe(3);
        expect(props.form.kds_station).toBe('hot');
        expect(props.form.tax_id).toBe(3);
    });
});

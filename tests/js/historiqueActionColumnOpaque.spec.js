import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import { mount } from '@vue/test-utils';
import { readFileSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';

/**
 * [C-002 · supervisor-caisse-2026-08-24/round-1/wave-C-findings.json]
 *
 * LA COLONNE DATE EST ILLISIBLE SUR 10 LIGNES SUR 10, DE DEUX FAÇONS OPPOSÉES.
 *
 * `HistoriqueListComponent.vue:675-679` déclarait
 *   `.hist-action-col { position: sticky; right: 0; z-index: 2; background: inherit; }`
 * et la zébrure du design system (`resources/css/app.css:464`, compilée en
 * `.db-table.stripe .db-table-body-tr:nth-child(odd) { background-color: rgb(249,250,251) !important }`)
 * ne peint QUE les rangs impairs.
 *
 * `inherit` recopie donc le fond du `<tr>` PARENT :
 *   • rang IMPAIR  → #f9fafb opaque : la cellule collante REPEINT la date, qui
 *     disparaît (mesure superviseur x=1170 : (249,250,251) sur y=385/499/613) ;
 *   • rang PAIR    → le `<tr>` n'a AUCUN fond à hériter, la cellule est donc
 *     TRANSPARENTE : la date s'imprime À TRAVERS les boutons (mesure
 *     (26,26,26) sur y=442/556/670, « 02:18, 08 » barré par l'icône œil) ;
 *   • `<thead>`    → aucun fond non plus : DATE et ACTION s'impriment l'un sur
 *     l'autre, le mot rendu est « DACTIEON » (confirmé glyphe par glyphe ×6).
 *
 * INVARIANT VERROUILLÉ ICI : une cellule collante qui recouvre du contenu doit
 * être OPAQUE dans TOUS ses états (en-tête, rang pair, rang impair, survol).
 * `inherit` / `transparent` / fond absent sont des régressions.
 *
 * MÉTHODE : on injecte dans le document la règle de zébrure RÉELLE (copiée
 * verbatim de `public/css/app.css:5608`) et le bloc `<style>` RÉEL du composant
 * (lu sur disque), puis on interroge `getComputedStyle` sur le DOM réellement
 * monté. Ce n'est pas une assertion de texte : la cascade est évaluée.
 */

vi.mock('../../resources/js/services/appService', () => ({
    default: {
        permissionChecker: vi.fn(() => true), // la colonne ACTION doit exister
        orderStatusClass: vi.fn(() => ''),
        handleSlide: vi.fn(),
    },
}));
vi.mock('../../resources/js/services/alertService', () => ({ default: { error: vi.fn(), success: vi.fn() } }));

import HistoriqueListComponent from '../../resources/js/components/admin/orderHistory/HistoriqueListComponent.vue';

const HERE = dirname(fileURLToPath(import.meta.url));
const SFC = join(HERE, '../../resources/js/components/admin/orderHistory/HistoriqueListComponent.vue');

/** Règle de zébrure du design system, verbatim de `public/css/app.css:5608`. */
const DESIGN_SYSTEM_STRIPE = `
.db-table.stripe .db-table-body-tr:nth-child(odd) {
  background-color: rgb(249, 250, 251) !important;
}
`;

/** Le bloc <style> réel du composant, dé-scopé (les data-v n'existent pas ici). */
function componentStyle() {
    const src = readFileSync(SFC, 'utf8');
    const m = src.match(/<style[^>]*>([\s\S]*?)<\/style>/);
    if (!m) throw new Error('bloc <style> introuvable dans HistoriqueListComponent.vue');
    return m[1];
}

const ORDERS = [1, 2, 3, 4].map((i) => ({
    id: i,
    order_serial_no: `100${i}`,
    queue_number: `A0${i}`,
    customer_name: 'Client',
    total: 14.6,
    payment_status: 5,
    status: 25,
    order_datetime: '02:18, 25-08-2026',
    fiscal_sequence_no: 40 + i,
    source_surface: 'kiosk',
}));

let wrapper;
let styleEl;

const makeStore = () => ({
    getters: new Proxy({}, {
        get(_t, key) {
            // `orders` est un computed lisant ce getter (composant:325).
            if (key === 'orderHistory/lists') return ORDERS;
            if (typeof key === 'string' && key.endsWith('/lists')) return [];
            if (key === 'frontendLanguage/show') return { display_mode: 0 };
            return {};
        },
    }),
    dispatch: vi.fn(() => Promise.resolve({ data: {} })),
    commit: vi.fn(),
});

beforeEach(() => {
    styleEl = document.createElement('style');
    styleEl.textContent = DESIGN_SYSTEM_STRIPE + componentStyle();
    document.head.appendChild(styleEl);

    const Test = { ...HistoriqueListComponent, mounted() {} };
    wrapper = mount(Test, {
        attachTo: document.body,
        data() {
            return { loading: { isActive: false }, reprintOrder: {}, reprintBusyId: null };
        },
        global: {
            stubs: {
                'router-link': true, Datepicker: true, ReceiptComponent: true, CaisseSecondaryNav: true,
                LoadingComponent: true, PaginationBox: true, PaginationSMBox: true,
                PaginationTextComponent: true, TableLimitComponent: true, FilterComponent: true,
                'vue-select': true, transition: false,
            },
            mocks: {
                $store: makeStore(), $t: (k) => k,
                $route: { query: {}, params: {} }, $router: { push: vi.fn(), replace: vi.fn() },
            },
        },
    });
});

afterEach(() => {
    wrapper?.unmount();
    styleEl?.remove();
});

/** Une couleur est OPAQUE si c'est un rgb() plein — pas rgba(...,0), pas `inherit`, pas vide. */
function opacityVerdict(el) {
    const raw = String(getComputedStyle(el).backgroundColor || '').trim();
    if (raw === '' || raw === 'inherit' || raw === 'transparent' || raw === 'initial' || raw === 'unset') {
        return { opaque: false, raw };
    }
    const rgba = raw.match(/^rgba\(\s*[\d.]+\s*,\s*[\d.]+\s*,\s*[\d.]+\s*,\s*([\d.]+)\s*\)$/);
    if (rgba) return { opaque: Number(rgba[1]) >= 1, raw };
    return { opaque: /^rgb\(/.test(raw) || /^#[0-9a-f]{3,8}$/i.test(raw), raw };
}

describe('C-002 — la colonne ACTION collante ne doit RIEN laisser transparaître', () => {
    it('la cellule collante est bien celle qui recouvre les colonnes DATE/STATUT', () => {
        // Garde-fou : si la colonne cesse d'être collante, ce spec n'a plus d'objet.
        const th = wrapper.find('th.hist-action-col').element;
        expect(getComputedStyle(th).position).toBe('sticky');
        expect(wrapper.findAll('td.hist-action-col').length).toBe(ORDERS.length);
    });

    it('EN-TÊTE : fond opaque — sinon DATE et ACTION s\'impriment l\'un sur l\'autre (« DACTIEON »)', () => {
        const v = opacityVerdict(wrapper.find('th.hist-action-col').element);
        expect(v.opaque, `en-tête : background = "${v.raw}" — la ligne DATE transparaît dessous`).toBe(true);
    });

    // [AUDIT-SUPERVISEUR 2026-08-25 · C-020] LE TROU DE CE VERROU, ET IL ÉTAIT AU PIRE ENDROIT.
    // La version précédente ne comparait les COULEURS que sur les rangs impairs. L'en-tête et
    // le survol étaient affirmés « opaques » — mais jamais confrontés à la couleur de la ligne
    // qu'ils recouvrent. Or ce sont EXACTEMENT les deux états que le superviseur a trouvés faux :
    // en-tête peint en blanc sur un thead beige, et survol qui ne teinte QUE la cellule ACTION.
    // Opaque ne suffit pas : une cellule opaque de la MAUVAISE couleur laisse une couture, un
    // rectangle d'une autre teinte au milieu du tableau. On compare donc les quatre états.
    it('EN-TÊTE : la cellule collante reprend LA MÊME source que le thead', () => {
        // Le harnais ne charge pas `app.css` : comparer des couleurs CALCULÉES ici
        // renverrait du vide et prouverait n'importe quoi. On compare donc les
        // DÉCLARATIONS, que le harnais lit vraiment — c'est ce qu'il peut établir.
        const css = componentStyle();

        const theadRule = css.match(/:deep\(\.db-table-head\)\s*\{[^}]*background[^:]*:\s*([^;]+)/);
        // [2026-08-26] La regex exigeait que « { » suive IMMÉDIATEMENT le sélecteur. Quand la
        // règle est devenue une LISTE — la colonne STATUT ayant été épinglée elle aussi et
        // partageant le même fond — elle a cessé de matcher, et ce test a rougi sur un code
        // parfaitement correct. On tolère donc les sélecteurs voisins jusqu'à l'accolade.
        const celluleRule = css.match(/\.db-table-head\s+\.hist-action-col[^{]*\{[^}]*background[^:]*:\s*([^;]+)/);

        expect(theadRule, 'le thead doit déclarer un fond').not.toBeNull();
        expect(celluleRule, 'la cellule d\'en-tête collante doit déclarer un fond').not.toBeNull();

        // On compare la SOURCE de la teinte — le nom de la variable de thème — et non
        // l'expression littérale : `var(--x, #fff)` et `var(--x)` désignent la même
        // couleur, et exiger l'égalité textuelle ferait rougir sur un repli, qui est
        // une amélioration. Ce qu'on interdit, c'est DEUX SOURCES DIFFÉRENTES.
        const source = (v) => {
            const t = v.trim().replace(/\s+/g, '').replace(/!important$/, '');
            const m = t.match(/^var\((--[a-z0-9-]+)/i);
            return m ? m[1] : t;
        };
        expect(
            source(celluleRule[1]),
            `couture d'en-tête : la cellule peint "${celluleRule[1].trim()}" sur un thead `
            + `"${theadRule[1].trim()}". Opaque ne suffit pas — il faut la MÊME teinte, `
            + 'sinon un rectangle d\'une autre couleur au bout du bandeau.'
        ).toBe(source(theadRule[1]));
    });

    it('SURVOL : la cellule collante prend la teinte de survol de sa ligne', () => {
        // Le défaut mesuré : `app.css` pose le survol sur le `<tr>` avec `!important`,
        // la règle du round précédent visait le `<td>` — au survol d'un rang impair,
        // SEULE la cellule ACTION virait au pêche, en plein milieu d'une ligne grise.
        const css = componentStyle();

        const survolCellule = css.match(
            /\.db-table-body-tr:hover\s+\.hist-action-col[^{]*\{[^}]*background[^:]*:\s*([^;]+)/
        );
        expect(survolCellule, 'aucune règle de survol sur la colonne collante').not.toBeNull();

        const survolLigne = css.match(
            /:deep\(\.db-table-body-tr:hover\)\s*\{[^}]*background[^:]*:\s*([^;]+)/
        );

        if (survolLigne) {
            const source = (v) => {
                const t = v.trim().replace(/\s+/g, '').replace(/!important$/, '');
                const m = t.match(/^var\((--[a-z0-9-]+)/i);
                return m ? m[1] : t;
            };
            expect(
                source(survolCellule[1]),
                'la cellule collante doit prendre la MÊME teinte de survol que sa ligne'
            ).toBe(source(survolLigne[1]));
        } else {
            // Pas de règle de ligne lisible ici : on exige au moins que la cellule
            // déclare une teinte explicite, jamais un héritage.
            expect(survolCellule[1]).not.toMatch(/inherit|transparent/);
        }
    });

    it('RANG PAIR : fond opaque — sinon la date s\'imprime À TRAVERS les boutons', () => {
        // nth-child(even) côté CSS = index 1 et 3 dans la liste (0-based).
        const cells = wrapper.findAll('td.hist-action-col');
        [1, 3].forEach((i) => {
            const v = opacityVerdict(cells[i].element);
            expect(v.opaque, `rang pair #${i + 1} : background = "${v.raw}" — cellule transparente`).toBe(true);
        });
    });

    it('RANG IMPAIR : fond opaque ET identique à la zébrure de sa ligne (aucune couture visible)', () => {
        const cells = wrapper.findAll('td.hist-action-col');
        [0, 2].forEach((i) => {
            const cell = cells[i].element;
            const v = opacityVerdict(cell);
            expect(v.opaque, `rang impair #${i + 1} : background = "${v.raw}"`).toBe(true);
            const rowBg = getComputedStyle(cell.closest('tr')).backgroundColor;
            expect(
                v.raw.replace(/\s/g, ''),
                'la cellule collante doit porter EXACTEMENT la couleur de sa ligne zébrée'
            ).toBe(String(rowBg).replace(/\s/g, ''));
        });
    });

    it('aucune déclaration `background: inherit` ne subsiste sur la colonne collante', () => {
        const css = componentStyle();
        const block = css.match(/\.hist-action-col\s*\{([^}]*)\}/);
        expect(block, '.hist-action-col doit rester déclaré').not.toBeNull();
        expect(
            /background(-color)?\s*:\s*inherit/.test(block[1]),
            '`inherit` recopie un fond qui n\'existe pas une ligne sur deux : c\'est la racine du défaut'
        ).toBe(false);
    });
});

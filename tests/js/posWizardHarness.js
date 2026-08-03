/**
 * posWizardHarness.js — harnais fonctionnel du wizard caisse FROZEN (public/js/pos-wizard.js).
 *
 * [LOCK_POSWIZARD_KIOSKWIZARD_OWNER8 2026-07-06] Contrairement aux sentinels source-level
 * (posWizardComposerAware.spec.js), ce harnais EXÉCUTE l'IIFE complète dans happy-dom et
 * pilote le vrai flux : modal #item-variation-modal + data-wizard-item-data (chemin
 * edit-from-cart d'openWizard) + data-pos-drinks-catalog → classList 'active' →
 * MutationObserver → openWizard → renderSinglePage + bindSinglePageEvents.
 *
 * Contraintes :
 *  - l'IIFE n'est évaluée qu'UNE fois par fichier de spec (elle patche XHR/fetch et pose
 *    un body-observer permanent) ; chaque mount() remplace le nœud modal — le body-observer
 *    du wizard détecte le nouveau nœud, reset lastItemData et se ré-attache (flux SPA réel).
 *  - tout est asynchrone (MutationObserver + setTimeout(0..50)) → helpers await.
 */
import { readFileSync } from 'fs';
import { resolve } from 'path';

const RAW_SOURCE = readFileSync(resolve(__dirname, '../../public/js/pos-wizard.js'), 'utf8');

// Instrumentation TEST-ONLY (le fichier frozen n'est PAS modifié) : expose
// openWizard/closeWizard sur window.__wizTest pour un pilotage DÉTERMINISTE.
// La scheduling MutationObserver de happy-dom n'offre aucune garantie sous
// charge CPU (flush starvation) → piloter par observers = flaky.
const SOURCE = RAW_SOURCE.replace(
    /\}\)\(\);\s*$/,
    'window.__wizTest = { open: openWizard, close: closeWizard };\n})();\n'
);
if (!SOURCE.includes('__wizTest')) {
    throw new Error('[posWizardHarness] instrumentation __wizTest impossible (fin IIFE introuvable)');
}

let booted = false;

export function tick(ms = 30) {
    return new Promise((r) => setTimeout(r, ms));
}

/**
 * Monte le wizard sur un item donné.
 * @param {object} opts
 * @param {object} opts.itemData          payload item (shape Vue ItemComponent)
 * @param {Array}  [opts.drinksCatalog]   catalogue boissons (data-pos-drinks-catalog)
 * @param {string} [opts.originalBodyHtml] contenu du .modal-body Vue (checkboxes extras…)
 * @returns {Promise<{modal: HTMLElement, wizard: HTMLElement|null}>}
 */
export async function mountPosWizard({ itemData, drinksCatalog = [], originalBodyHtml = '' }) {
    // UN SEUL nœud modal réutilisé (comme la prod Vue). Ouverture/fermeture pilotées
    // DIRECTEMENT via window.__wizTest (déterministe) — les MutationObservers du
    // wizard restent attachés mais leurs callbacks sont no-op grâce aux gardes
    // internes (`active && !wizardEl` / `!active && wizardEl`).
    let modal = document.getElementById('item-variation-modal');
    if (modal) {
        // Fermeture SYNCHRONE : reset complet de l'état interne (steps, selections…).
        if (window.__wizTest) window.__wizTest.close();
        modal.classList.remove('active');
        await tick(10); // laisse d'éventuels callbacks observer voir l'état stable fermé
    } else {
        modal = document.createElement('div');
        modal.id = 'item-variation-modal';
        modal.className = 'modal';
        modal.innerHTML =
            '<div class="modal-dialog">' +
            '<div class="modal-header"></div>' +
            '<div class="modal-body"></div>' +
            '</div>';
        document.body.appendChild(modal);
    }
    modal.setAttribute('data-pos-drinks-catalog', JSON.stringify(drinksCatalog));
    modal.setAttribute('data-wizard-item-data', JSON.stringify(itemData));
    modal.querySelector('.modal-body').innerHTML = originalBodyHtml;

    if (!booted) {
        // eslint-disable-next-line no-new-func
        new Function(SOURCE)();
        booted = true;
        // readyState 'loading' éventuel : déclenche l'init manuellement
        document.dispatchEvent(new Event('DOMContentLoaded'));
        await tick(10);
    }

    modal.classList.add('active');
    await tick(10);
    // Ouverture directe si l'observer n'a pas déjà ouvert (les deux chemins sont
    // équivalents ; openWizard lit data-wizard-item-data et rend le single-page).
    if (!document.getElementById('pos-wizard-root')) {
        window.__wizTest.open(modal);
        await tick(10);
    }

    const wizard = document.getElementById('pos-wizard-root');
    if (wizard && itemData && itemData.name) {
        // Garde anti-stale : le wizard rendu doit porter le produit de CE mount.
        const h2 = wizard.querySelector('.wizard-item-header h2');
        if (h2 && h2.textContent.trim() !== String(itemData.name)) {
            throw new Error('[posWizardHarness] wizard STALE : ' + h2.textContent.trim() + ' ≠ ' + itemData.name);
        }
    }

    return { modal, wizard };
}

/**
 * Fixture « sandwich Cayenne 1 viande » minimale mais fidèle au payload POS réel :
 * 3 addons formule génériques (le vrai jeu de données V1 — cause du filtre boisson vide),
 * crudités gratuites (Salade/Tomates/Oignon [+ Oignons cuits]), viandes avec/without thumb.
 */
export function cayenneLikeItem(overrides = {}) {
    return {
        id: 22,
        name: 'Cayenne (1 viande)',
        description: '',
        category_name: 'Sandwichs',
        convert_price: 7.4,
        currency_price: '€7.40',
        thumb: '',
        itemAttributes: [
            { id: 301, name: 'Viande 1', max_select: 1 },
            { id: 311, name: 'Sauce (1ère Gratuite)' },
            { id: 320, name: 'Type de Pain' },
        ],
        variations: {
            301: [
                { id: 9001, name: 'Poulet mariné', thumb: 'http://127.0.0.1/images/menu/viande-poulet.png?v=1' },
                { id: 9002, name: 'Mexicanos', thumb: '' },
            ],
            311: [
                { id: 9101, name: 'Algérienne', thumb: null },
                { id: 9102, name: 'Samouraï', thumb: null },
            ],
            320: [{ id: 9201, name: 'Pain', thumb: null }],
        },
        extras: [
            { id: 51, name: 'Oignon', convert_price: 0, currency_price: '€0.00', thumb: null },
            { id: 52, name: 'Salade', convert_price: 0, currency_price: '€0.00', thumb: null },
            { id: 53, name: 'Tomates', convert_price: 0, currency_price: '€0.00', thumb: null },
            { id: 60, name: 'Oignons cuits', convert_price: 0, currency_price: '€0.00', thumb: null },
            { id: 61, name: 'Cheddar', convert_price: 1, currency_price: '€1.00', thumb: null },
        ],
        addons: [
            { id: 1, addon_item_id: 200, addon_item_name: 'Menu (Frites + Boisson)', addon_item_convert_price: 2.5, addon_item_currency_price: '€2.50' },
            { id: 2, addon_item_id: 201, addon_item_name: 'Frites Seules', addon_item_convert_price: 2.0, addon_item_currency_price: '€2.00' },
            { id: 3, addon_item_id: 202, addon_item_name: 'Boisson Seule', addon_item_convert_price: 2.0, addon_item_currency_price: '€2.00' },
        ],
        ...overrides,
    };
}

/** Catalogue boissons réel (extraits) — noms VOLONTAIREMENT hors DRINK_LIKE_REGEX. */
export function drinksCatalogFixture() {
    return [
        { id: 124, name: 'Hawaï 33cl', thumb: '', category_id: 10, is_available: true },
        { id: 121, name: 'Capri-Sun', thumb: 'http://127.0.0.1/images/menu/capri-sun.png', category_id: 10, is_available: true },
        { id: 52, name: 'Coca-Cola 33cl', thumb: '', category_id: 10, is_available: true },
        { id: 999, name: 'Rupture 33cl', thumb: '', category_id: 10, is_available: false },
    ];
}

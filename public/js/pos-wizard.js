/**
 * POS Wizard — Parcours intelligent multi-étapes style McDo
 * Intercepts the item variation modal and transforms it into a guided wizard.
 *
 * Steps (up to 7):
 *   1. Viande    — Tacos only: choose N meats based on size (M=1, L=2, XL=3, XXL=4)
 *   2. Sauce     — Multi-select, 1st FREE, each extra +€0.50
 *   3. Garnitures — Free extras, pre-checked (Salade, Tomate, Oignon)
 *   4. Suppléments — Paid extras (Œuf, Fromage, Raclette, Boursin, etc.)
 *   5. Menu combo — Full menu or individual (Frites, Boisson)
 *   6. Sauce Frites — If frites/menu selected: 1st FREE, extra +€0.50
 *   7. Récap     — Summary + quantity + instructions + add to cart
 */
(function () {
    'use strict';

    /* ==============================
       ONLY RUN ON POS PAGE
       ============================== */
    if (!window.location.pathname.includes('/admin/pos')) return;

    /* ==============================
       STATE
       ============================== */
    var lastItemData = null;
    var wizardEl = null;
    var originalBody = null;
    var currentStep = 0;
    var steps = [];
    var selections = {};
    var itemQuantity = 1;
    var instructionText = '';

    /* ==============================
       CONFIG — VIANDES DISPONIBLES
       ============================== */
    var VIANDES = [
        { key: 'merguez', name: 'Merguez', emoji: '🌶️' },
        { key: 'kefta', name: 'Kefta', emoji: '🥩' },
        { key: 'poulet', name: 'Poulet', emoji: '🍗' },
        { key: 'cordon_bleu', name: 'Cordon Bleu', emoji: '🔵' },
        { key: 'viande_hachee', name: 'Viande Hachée', emoji: '🥩' },
        { key: 'nuggets', name: 'Nuggets', emoji: '🟡' },
        { key: 'escalope', name: 'Escalope Poulet', emoji: '🍗' },
        { key: 'cayenne', name: 'Cayenne', emoji: '🌶️' },
        { key: 'tenders', name: 'Tenders', emoji: '🍗' },
        { key: 'fricadelle', name: 'Fricadelle', emoji: '🌭' }
    ];

    /* ==============================
       CONFIG — SAUCES COMPLETES
       ============================== */
    var ALL_SAUCES = [
        { key: 'ketchup', name: 'Ketchup', emoji: '🍅' },
        { key: 'mayonnaise', name: 'Mayonnaise', emoji: '🥚' },
        { key: 'algerienne', name: 'Algérienne', emoji: '🌶️' },
        { key: 'curry', name: 'Curry', emoji: '🍛' },
        { key: 'andalouse', name: 'Andalouse', emoji: '🌶️' },
        { key: 'burger', name: 'Burger', emoji: '🍔' },
        { key: 'samourai', name: 'Samouraï', emoji: '⚔️' },
        { key: 'barbecue', name: 'Barbecue', emoji: '🔥' },
        { key: 'cocktail', name: 'Cocktail', emoji: '🍹' },
        { key: 'americaine', name: 'Américaine', emoji: '🇺🇸' },
        { key: 'hannibal', name: 'Hannibal', emoji: '🦁' },
        { key: 'harissa', name: 'Harissa', emoji: '🔥' },
        { key: 'blanche', name: 'Blanche', emoji: '🥛' },
        { key: 'poivre', name: 'Poivre', emoji: '🫓' },
        { key: 'biggy', name: 'Biggy', emoji: '🧄' },
        { key: 'bbq', name: 'BBQ', emoji: '🔥' },
        { key: 'sans_sauce', name: 'Sans sauce', emoji: '🚫' }
    ];

    var SAUCE_EXTRA_PRICE = 0.50;

    /* ==============================
       CONFIG — SUPPLEMENTS
       ============================== */
    var SUPPLEMENT_EMOJIS = {
        'oeuf': '🥚', 'œuf': '🥚', 'fromage': '🧀', 'raclette': '🫕',
        'boursin': '🧀', 'jambon': '🥓', 'jambon de dinde': '🥓',
        'mozzarella': '🧀', 'galette': '🥔', 'steak': '🥩', 'poulet': '🍗',
        'cheddar': '🧀', 'double viande': '🥩', 'double steak': '🥩',
        'bacon': '🥓', 'fromage a raclette': '🫕', 'galette pommes de terre': '🥔',
        'default': '➕'
    };

    var GARNITURE_EMOJIS = {
        'salade': '🥬', 'tomate': '🍅', 'oignon': '🧅', 'oignons': '🧅',
        'riz': '🍚', 'frites': '🍟', 'default': '🥗'
    };

    var ADDON_EMOJIS = {
        'frites': '🍟', 'coca-cola': '🥤', 'coca': '🥤',
        'orangina': '🍊', 'eau': '💧', 'jus': '🧃',
        'boisson': '🥤', 'default': '🍽️'
    };

    function getEmoji(map, name) {
        var n = (name || '').toLowerCase().trim();
        for (var k in map) {
            if (k !== 'default' && n.includes(k)) return map[k];
        }
        return map['default'] || '';
    }

    /* ==============================
       INTERCEPT XHR TO CAPTURE ITEM DATA
       ============================== */
    var _xhrOpen = XMLHttpRequest.prototype.open;
    var _xhrSend = XMLHttpRequest.prototype.send;

    XMLHttpRequest.prototype.open = function (method, url) {
        this.__wizUrl = url;
        return _xhrOpen.apply(this, arguments);
    };

    XMLHttpRequest.prototype.send = function () {
        if (this.__wizUrl && (this.__wizUrl.includes('/admin/item/') || this.__wizUrl.includes('/admin/setting/item/'))) {
            this.addEventListener('load', function () {
                try {
                    var d = JSON.parse(this.responseText);
                    if (d && d.data && (d.data.itemAttributes || d.data.variations || d.data.extras)) {
                        lastItemData = d.data;
                    }
                } catch (e) { /* ignore */ }
            });
        }
        return _xhrSend.apply(this, arguments);
    };

    /* ==============================
       HELPERS
       ============================== */
    function fmtPrice(val) {
        var num = parseFloat(val) || 0;
        return '€' + num.toFixed(2);
    }

    /**
     * Detect viande count from item name
     * "Tacos M (1 viande)" → 1, "Tacos L (2 viandes)" → 2, etc.
     */
    function detectViandeCount(name) {
        if (!name) return 0;
        var n = name.toLowerCase();
        if (!n.includes('tacos')) return 0;

        // Try to extract number from parentheses: "(3 viande)"
        var match = n.match(/\((\d+)\s*viande/);
        if (match) return parseInt(match[1]);

        // Try XXL first (before XL)
        if (n.includes('xxl')) return 4;
        if (n.includes('xl') && !n.includes('xxl')) return 3;
        if (n.includes(' l ') || n.match(/\bl\b/) || n.includes(' l(') || n.includes('tacos l')) return 2;
        if (n.includes(' m ') || n.match(/\bm\b/) || n.includes(' m(') || n.includes('tacos m')) return 1;

        return 0;
    }

    /**
     * Detect product category from API data.
     * Returns: 'tacos', 'sandwich', 'burger', 'assiette', 'salade',
     *          'omelette', 'ojja', 'snacking', 'menu_enfant',
     *          'dessert', 'boisson', 'unknown'
     *
     * RULES per category:
     *   tacos     → Viande + Sauce + Garnitures + Suppléments + Menu + SauceFrites + Récap
     *   sandwich  → Sauce + Garnitures + Suppléments + Menu + SauceFrites + Récap
     *   burger    → Sauce + Garnitures + Suppléments + Menu + SauceFrites + Récap
     *   assiette  → Sauce + Accompagnement(from extras: Riz/Frites/Salade) + Suppléments + Récap
     *   salade    → Sauce + Suppléments + Récap (no garnitures question — salad IS the garniture)
     *   omelette  → Sauce + Récap (fixed recipe, no extras)
     *   ojja      → Récap only (complete dish, no customization)
     *   snacking  → Récap only (wings, nuggets, frites, potatoes…)
     *   menu_enfant, dessert, boisson → No wizard at all
     */
    function detectCategory(data) {
        var cat = (data.category_name || '').toLowerCase();
        var name = (data.name || '').toLowerCase();

        // Try DOM first if category_name is empty (POS API doesn't return it)
        if (!cat) {
            var activeTab = document.querySelector('.db-product-filter.active, .nav-link.active .tab-title');
            if (activeTab) cat = (activeTab.innerText || activeTab.textContent || '').toLowerCase();
        }

        console.log('[POS-WIZARD] detectCategory:', { domCat: cat, name: data.name });

        if (cat.includes('tacos') || name.includes('tacos')) return 'tacos';
        if (cat.includes('sandwich') || name.includes('sandwich')) return 'sandwich';
        if (cat.includes('burger') || name.includes('burger')) return 'burger';
        if (cat.includes('assiette') || name.includes('assiette')) return 'assiette';
        if (cat.includes('salade') || name.includes('salade')) return 'salade';
        if (cat.includes('omelette') || name.includes('omelette')) return 'omelette';
        if (cat.includes('ojja') || name.includes('ojja')) return 'ojja';
        if (cat.includes('snacking') || name.includes('nuggets') || name.includes('wings') || name.includes('tenders') || name.includes('frites')) return 'snacking';
        if (cat.includes('menu enfant') || name.includes('enfant')) return 'menu_enfant';
        if (cat.includes('dessert') || name.includes('dessert') || name.includes('tiramisu') || name.includes('tarte')) return 'dessert';
        if (cat.includes('boisson') || name.includes('boisson') || name.includes('coca') || name.includes('fanta')) return 'boisson';

        return 'unknown';
    }

    /**
     * Based on category, decide which wizard steps are allowed.
     */
    function getAllowedSteps(category) {
        switch (category) {
            case 'tacos':
                return ['viande', 'sauce', 'garnitures', 'supplements', 'menu', 'sauce_frites', 'recap'];
            case 'sandwich':
            case 'burger':
                return ['sauce', 'garnitures', 'supplements', 'menu', 'sauce_frites', 'recap'];
            case 'assiette':
                return ['sauce', 'accompagnement', 'supplements', 'recap'];
            case 'salade':
                return ['sauce', 'supplements', 'recap'];
            case 'omelette':
                return ['sauce_single', 'recap'];
            case 'ojja':
                return ['recap'];
            case 'snacking':
                return ['sauce_single', 'recap'];
            default:
                return ['viande', 'sauce', 'garnitures', 'supplements', 'menu', 'sauce_frites', 'recap'];
        }
    }

    /**
     * Check if the item has frites selected (in menu or individual)
     */
    function hasFritesSelected() {
        if (selections.menuChoice === 'full') return true;
        if (selections.individualAddons) {
            var menuStep = steps.find(function (s) { return s.type === 'menu'; });
            if (menuStep) {
                for (var i = 0; i < menuStep.items.length; i++) {
                    var addon = menuStep.items[i];
                    if (addon.name.toLowerCase().includes('frite') && selections.individualAddons[addon.id]) {
                        return true;
                    }
                }
            }
        }
        return false;
    }

    /* ==============================
       BUILD STEPS FROM ITEM DATA
       ============================== */
    function buildSteps(data) {
        var s = [];
        selections = {};

        // Detect category and allowed steps
        var category = detectCategory(data);
        var allowed = getAllowedSteps(category);

        // For categories that skip wizard entirely, return only recap
        if (category === 'menu_enfant' || category === 'dessert' || category === 'boisson') {
            return [{ type: 'recap', label: 'Récap', subtitle: 'Vérifiez votre commande' }];
        }

        // === Step: Viande (Tacos only) ===
        var viandeCount = detectViandeCount(data.name);
        if (viandeCount > 0 && allowed.indexOf('viande') !== -1) {
            s.push({
                type: 'viande',
                label: 'Choix de viande' + (viandeCount > 1 ? 's' : ''),
                subtitle: 'Choisissez ' + viandeCount + ' viande' + (viandeCount > 1 ? 's' : ''),
                maxViandes: viandeCount,
                items: VIANDES
            });
            selections.viandes = {};
            VIANDES.forEach(function (v) { selections.viandes[v.key] = 0; });
            selections.maxViandes = viandeCount;
            selections.totalViandes = 0;
        }

        // === Step: Sauce (multi-select, 1st free) ===
        // Build sauce list from DB variations OR fallback to config
        var sauceList = [];
        var dbSauces = {};

        if (data.itemAttributes && data.itemAttributes.length > 0) {
            data.itemAttributes.forEach(function (attr) {
                var attrId = attr.id.toString();
                var vars = data.variations && data.variations[attrId] ? data.variations[attrId] : [];
                vars.forEach(function (v) {
                    var name = v.name;
                    dbSauces[name.toLowerCase()] = {
                        id: v.id,
                        name: name,
                        attributeId: attr.id,
                        dbPrice: parseFloat(v.convert_price) || 0
                    };
                });
            });
        }

        // Use DB sauces enriched with emojis from config
        if (Object.keys(dbSauces).length > 0) {
            for (var sKey in dbSauces) {
                var dbS = dbSauces[sKey];
                var emoji = '🥄';
                ALL_SAUCES.forEach(function (cs) {
                    if (sKey.includes(cs.key) || cs.name.toLowerCase() === sKey) {
                        emoji = cs.emoji;
                    }
                });
                sauceList.push({
                    id: dbS.id,
                    name: dbS.name,
                    emoji: emoji,
                    attributeId: dbS.attributeId
                });
            }
        }

        if (sauceList.length > 0 && allowed.indexOf('sauce') !== -1) {
            s.push({
                type: 'sauce',
                label: 'Sauce',
                subtitle: '1ère sauce gratuite, chaque supplémentaire +€0.50',
                items: sauceList
            });
            selections.sauces = {};
            selections.sauceOrder = [];
            selections.sauces[sauceList[0].id] = true;
            selections.sauceOrder = [sauceList[0].id];
            selections.sauceAttrId = sauceList[0].attributeId;
        }

        // === Step: Sauce Single (radio — omelettes, snacking) ===
        if (sauceList.length > 0 && allowed.indexOf('sauce_single') !== -1) {
            s.push({
                type: 'sauce_single',
                label: 'Sauce',
                subtitle: 'Choisissez votre sauce',
                items: sauceList
            });
            selections.sauceSingle = sauceList[0].id;
            selections.sauceAttrId = sauceList[0].attributeId;
        }

        // === Step: Accompagnement (radio — assiettes: Riz/Frites/Salade) ===
        if (data.extras && data.extras.length > 0 && allowed.indexOf('accompagnement') !== -1) {
            var accompItems = [];
            data.extras.forEach(function (ex) {
                var price = parseFloat(ex.convert_price) || 0;
                if (price <= 0) {
                    accompItems.push({ id: ex.id, name: ex.name, price: 0, currencyPrice: 'Inclus' });
                }
            });
            if (accompItems.length > 0) {
                s.push({
                    type: 'accompagnement',
                    label: 'Accompagnement',
                    subtitle: 'Choisissez votre accompagnement',
                    items: accompItems
                });
                selections.accompagnement = accompItems[0].id; // pre-select first
            }
        }

        // === Step: Garnitures (free extras, pre-checked) ===
        // For assiettes: rename to "Accompagnement" since it's Riz/Frites/Salade
        if (data.extras && data.extras.length > 0) {
            var freeExtras = [];
            var paidExtras = [];
            data.extras.forEach(function (ex) {
                var price = parseFloat(ex.convert_price) || 0;
                var obj = {
                    id: ex.id,
                    name: ex.name,
                    price: price,
                    currencyPrice: ex.currency_price || fmtPrice(price)
                };
                if (price <= 0) {
                    freeExtras.push(obj);
                } else {
                    paidExtras.push(obj);
                }
            });

            if (freeExtras.length > 0 && allowed.indexOf('garnitures') !== -1) {
                s.push({
                    type: 'garnitures',
                    label: 'Garnitures',
                    subtitle: 'Décochez ce que vous ne voulez pas',
                    items: freeExtras
                });
                selections.garnitures = {};
                freeExtras.forEach(function (g) { selections.garnitures[g.id] = true; });
            }

            if (paidExtras.length > 0 && allowed.indexOf('supplements') !== -1) {
                s.push({
                    type: 'supplements',
                    label: 'Suppléments',
                    subtitle: 'Ajoutez des suppléments',
                    items: paidExtras
                });
                selections.supplements = {};
            }
        }

        // === Step: Menu combo (addons) — only if allowed ===
        if (data.addons && data.addons.length > 0 && allowed.indexOf('menu') !== -1) {
            var addonItems = data.addons.map(function (ad) {
                return {
                    id: ad.id,
                    itemId: ad.addon_item_id || ad.item_addon_id,
                    name: ad.addon_item_name,
                    price: parseFloat(ad.total_convert_price) || 0,
                    currencyPrice: ad.total_currency_price || fmtPrice(ad.total_convert_price),
                    thumb: (ad.addonItem && ad.addonItem.thumb) ? ad.addonItem.thumb : (ad.thumb || ad.cover || '')
                };
            });
            s.push({
                type: 'menu',
                label: 'Menu',
                subtitle: 'Voulez-vous le menu ?',
                items: addonItems
            });
            selections.menuChoice = null;
            selections.individualAddons = {};
        }

        // === Step: Sauce Frites (conditional) ===
        if (allowed.indexOf('sauce_frites') !== -1 && sauceList.length > 0) {
            s.push({
                type: 'sauce_frites',
                label: 'Sauce Frites',
                subtitle: '1ère sauce gratuite, chaque supplémentaire +€0.50',
                items: sauceList.filter(function (item) { return item.name.toLowerCase() !== 'sans sauce'; })
            });
            selections.sauceFrites = {};
            selections.sauceFritesOrder = [];
        }

        // === Step: Recap ===
        s.push({ type: 'recap', label: 'Récap', subtitle: 'Vérifiez votre commande' });

        return s;
    }

    /**
     * Get active steps (filters out sauce_frites if no frites selected)
     */
    function getActiveSteps() {
        return steps.filter(function (step) {
            if (step.type === 'sauce_frites') {
                return hasFritesSelected();
            }
            return true;
        });
    }

    function getActiveStepIndex() {
        var active = getActiveSteps();
        var realStep = steps[currentStep];
        for (var i = 0; i < active.length; i++) {
            if (active[i] === realStep) return i;
        }
        return 0;
    }

    /* ==============================
       RENDER WIZARD HTML
       ============================== */
    function renderWizard() {
        var html = '<div class="pos-wizard">';

        var basePrice = 0;
        if (lastItemData) {
            basePrice = lastItemData.offer && lastItemData.offer.length > 0
                ? parseFloat(lastItemData.offer[0].convert_price) || 0
                : parseFloat(lastItemData.convert_price) || 0;
        }

        // Main item header with image
        if (lastItemData) {
            html += '<div class="wizard-item-header">';
            if (lastItemData.thumb) {
                html += '<img src="' + lastItemData.thumb + '" alt="item" class="wizard-item-img">';
            }
            html += '<div class="wizard-item-info">';
            html += '<h2>' + lastItemData.name + '</h2>';
            html += '<p class="wizard-item-price">' + fmtPrice(basePrice) + '</p>';
            html += '</div>';
            html += '</div>';
        }

        // Progress bar with labels
        var activeSteps = getActiveSteps();
        var activeIdx = getActiveStepIndex();
        var STEP_ICONS = {
            'viande': '🥩', 'sauce': '🥄', 'sauce_single': '🥄',
            'garnitures': '🥬', 'supplements': '➕', 'accompagnement': '🍟',
            'menu': '🍔', 'sauce_frites': '🍟', 'recap': '📋'
        };

        html += '<div class="wizard-progress-bar">';
        for (var i = 0; i < activeSteps.length; i++) {
            var cls = i < activeIdx ? 'done' : (i === activeIdx ? 'active' : '');
            var icon = STEP_ICONS[activeSteps[i].type] || '•';
            html += '<div class="step-item ' + cls + '">';
            html += '<div class="step-icon">' + icon + '</div>';
            html += '<div class="step-label">' + activeSteps[i].label + '</div>';
            if (i < activeSteps.length - 1) {
                html += '<div class="step-line ' + (i < activeIdx ? 'done' : '') + '"></div>';
            }
            html += '</div>';
        }
        html += '</div>';

        // Current step content
        var step = steps[currentStep];
        html += '<div class="wizard-step active" data-step="' + currentStep + '">';
        html += '<div class="wizard-step-header">';
        html += '<h3>' + step.label + '</h3>';
        html += '<p>' + (step.subtitle || '') + '</p>';
        html += '</div>';

        if (step.type === 'viande') html += renderViandeStep(step);
        else if (step.type === 'sauce') html += renderSauceStep(step);
        else if (step.type === 'sauce_single') html += renderSauceSingleStep(step);
        else if (step.type === 'accompagnement') html += renderAccompagnementStep(step);
        else if (step.type === 'garnitures') html += renderGarnituresStep(step);
        else if (step.type === 'supplements') html += renderSupplementsStep(step);
        else if (step.type === 'menu') html += renderMenuStep(step);
        else if (step.type === 'sauce_frites') html += renderSauceFritesStep(step);
        else if (step.type === 'recap') html += renderRecapStep();

        html += '</div>';

        // Running total (except on recap)
        if (step.type !== 'recap') {
            var runTotal = calculateRunningTotal();
            html += '<div class="wizard-running-total">';
            html += '<span>Total provisoire</span><span class="run-total-value">' + fmtPrice(runTotal) + '</span>';
            html += '</div>';
        }

        // Navigation
        html += renderNav();

        html += '</div>';
        return html;
    }

    /* ==============================
       STEP RENDERERS
       ============================== */

    // ---- VIANDE ----
    function renderViandeStep(step) {
        var h = '';
        var total = selections.totalViandes || 0;
        var max = step.maxViandes;

        h += '<div class="wizard-viande-counter-header">';
        h += '<span class="viande-total ' + (total === max ? 'complete' : '') + '">' + total + ' / ' + max + '</span>';
        if (total === max) {
            h += '<span class="viande-complete-badge">✅ Complet</span>';
        }
        h += '</div>';

        h += '<div class="wizard-viande-list">';
        step.items.forEach(function (viande) {
            var count = selections.viandes[viande.key] || 0;
            var canAdd = total < max;
            h += '<div class="wizard-viande-row' + (count > 0 ? ' active' : '') + '">';
            h += '<div class="viande-info">';
            h += '<span class="viande-emoji">' + viande.emoji + '</span>';
            h += '<span class="viande-name">' + viande.name + '</span>';
            h += '</div>';
            h += '<div class="viande-controls">';
            h += '<button type="button" class="viande-btn minus' + (count <= 0 ? ' disabled' : '') + '" data-viande="' + viande.key + '" data-action="minus">−</button>';
            h += '<span class="viande-count">' + count + '</span>';
            h += '<button type="button" class="viande-btn plus' + (!canAdd ? ' disabled' : '') + '" data-viande="' + viande.key + '" data-action="plus">+</button>';
            h += '</div>';
            h += '</div>';
        });
        h += '</div>';
        return h;
    }

    // ---- SAUCE (multi-select, 1st free) ----
    function renderSauceStep(step) {
        var h = '';
        var count = selections.sauceOrder ? selections.sauceOrder.length : 0;

        h += '<div class="wizard-sauce-info">';
        if (count === 0) {
            h += '<span class="sauce-badge free">Sélectionnez votre 1ère sauce (gratuite)</span>';
        } else if (count === 1) {
            h += '<span class="sauce-badge free">✅ 1 sauce sélectionnée (gratuite)</span>';
        } else {
            var extraCost = (count - 1) * SAUCE_EXTRA_PRICE;
            h += '<span class="sauce-badge paid">' + count + ' sauces — ' + (count - 1) + ' supplém. = +' + fmtPrice(extraCost) + '</span>';
        }
        h += '</div>';

        h += '<div class="wizard-options sauce-grid">';
        step.items.forEach(function (sauce) {
            var sel = selections.sauces && selections.sauces[sauce.id] ? ' selected' : '';
            var idx = selections.sauceOrder ? selections.sauceOrder.indexOf(sauce.id) : -1;
            var priceLabel = '';
            if (idx === 0) {
                priceLabel = '<span class="option-price free">Gratuit</span>';
            } else if (idx > 0) {
                priceLabel = '<span class="option-price paid">+€0.50</span>';
            } else {
                priceLabel = '<span class="option-price">' + (count === 0 ? 'Gratuit' : '+€0.50') + '</span>';
            }

            h += '<div class="wizard-option sauce-opt' + sel + '" data-type="sauce" data-id="' + sauce.id + '">';
            h += '<span class="check-mark"><i class="fa-solid fa-check"></i></span>';
            h += '<span class="option-icon">' + sauce.emoji + '</span>';
            h += '<span class="option-name">' + sauce.name + '</span>';
            h += priceLabel;
            h += '</div>';
        });
        h += '</div>';
        return h;
    }

    // ---- CALCULATE RUNNING TOTAL ----
    function calculateRunningTotal() {
        var basePrice = 0;
        if (lastItemData) {
            basePrice = lastItemData.offer && lastItemData.offer.length > 0
                ? parseFloat(lastItemData.offer[0].convert_price) || 0
                : parseFloat(lastItemData.convert_price) || 0;
        }
        var extra = 0;
        // Extra sauces (+€0.50 each after 1st)
        if (selections.sauceOrder && selections.sauceOrder.length > 1) {
            extra += (selections.sauceOrder.length - 1) * SAUCE_EXTRA_PRICE;
        }
        // Supplements
        if (selections.supplements) {
            var suppStep = steps.find(function (s) { return s.type === 'supplements'; });
            if (suppStep) {
                suppStep.items.forEach(function (s) {
                    if (selections.supplements[s.id]) extra += s.price;
                });
            }
        }
        // Sauce frites extra
        if (selections.sauceFritesOrder && selections.sauceFritesOrder.length > 1) {
            extra += (selections.sauceFritesOrder.length - 1) * SAUCE_EXTRA_PRICE;
        }
        // Addons
        var addonTotal = 0;
        var menuStep = steps.find(function (s) { return s.type === 'menu'; });
        if (menuStep) {
            if (selections.menuChoice === 'full') {
                menuStep.items.forEach(function (a) { addonTotal += a.price; });
            } else if (selections.individualAddons) {
                menuStep.items.forEach(function (a) {
                    if (selections.individualAddons[a.id]) addonTotal += a.price;
                });
            }
        }
        return (basePrice + extra) * itemQuantity + addonTotal;
    }

    // ---- SAUCE SINGLE (radio — omelettes, snacking) ----
    function renderSauceSingleStep(step) {
        var h = '<div class="wizard-sauce-info">';
        h += '<span class="sauce-badge free">Choisissez votre sauce (gratuite)</span>';
        h += '</div>';
        h += '<div class="wizard-options sauce-grid">';
        step.items.forEach(function (sauce) {
            var sel = selections.sauceSingle === sauce.id ? ' selected' : '';
            h += '<div class="wizard-option sauce-opt' + sel + '" data-type="sauce_single" data-id="' + sauce.id + '">';
            h += '<span class="check-mark"><i class="fa-solid fa-check"></i></span>';
            h += '<span class="option-icon">' + sauce.emoji + '</span>';
            h += '<span class="option-name">' + sauce.name + '</span>';
            h += '<span class="option-price free">Inclus</span>';
            h += '</div>';
        });
        h += '</div>';
        return h;
    }

    // ---- ACCOMPAGNEMENT (radio — assiettes: Riz/Frites/Salade) ----
    function renderAccompagnementStep(step) {
        var h = '<div class="wizard-options">';
        step.items.forEach(function (item) {
            var sel = selections.accompagnement === item.id ? ' selected' : '';
            h += '<div class="wizard-option accomp' + sel + '" data-type="accompagnement" data-id="' + item.id + '">';
            h += '<span class="check-mark"><i class="fa-solid fa-check"></i></span>';
            h += '<span class="option-icon">' + getEmoji(GARNITURE_EMOJIS, item.name) + '</span>';
            h += '<span class="option-name">' + item.name + '</span>';
            h += '<span class="option-price free">Inclus</span>';
            h += '</div>';
        });
        h += '</div>';
        return h;
    }

    // ---- GARNITURES ----
    function renderGarnituresStep(step) {
        var h = '<div class="wizard-options">';
        step.items.forEach(function (item) {
            var sel = selections.garnitures && selections.garnitures[item.id] ? ' selected' : '';
            h += '<div class="wizard-option garniture' + sel + '" data-type="garniture" data-id="' + item.id + '">';
            h += '<span class="check-mark"><i class="fa-solid fa-check"></i></span>';
            h += '<span class="option-icon">' + getEmoji(GARNITURE_EMOJIS, item.name) + '</span>';
            h += '<span class="option-name">' + item.name + '</span>';
            h += '<span class="option-price">Inclus</span>';
            h += '</div>';
        });
        h += '</div>';
        return h;
    }

    // ---- SUPPLEMENTS ----
    function renderSupplementsStep(step) {
        var h = '<div class="wizard-options">';
        step.items.forEach(function (item) {
            var sel = selections.supplements && selections.supplements[item.id] ? ' selected' : '';
            h += '<div class="wizard-option' + sel + '" data-type="supplement" data-id="' + item.id + '">';
            h += '<span class="check-mark"><i class="fa-solid fa-check"></i></span>';
            h += '<span class="option-icon">' + getEmoji(SUPPLEMENT_EMOJIS, item.name) + '</span>';
            h += '<span class="option-name">' + item.name + '</span>';
            h += '<span class="option-price paid">+' + item.currencyPrice + '</span>';
            h += '</div>';
        });
        h += '</div>';
        return h;
    }

    // ---- MENU ----
    function renderMenuStep(step) {
        var h = '<div class="wizard-menu-question">';
        h += '<h4>🍔 Voulez-vous le menu ?</h4>';

        var comboPrice = 0;
        step.items.forEach(function (a) { comboPrice += a.price; });

        h += '<div class="wizard-menu-buttons">';
        var selYes = selections.menuChoice === 'full' ? ' selected' : '';
        var selNo = selections.menuChoice === 'none' || selections.menuChoice === 'individual' ? ' selected' : '';

        h += '<div class="wizard-menu-btn yes' + selYes + '" data-menu="full">';
        h += '<div class="menu-icon">✅</div>';
        h += '<div class="menu-label">Oui, Menu complet</div>';
        h += '<div class="menu-desc">' + fmtPrice(comboPrice) + '</div>';
        h += '</div>';

        h += '<div class="wizard-menu-btn no' + selNo + '" data-menu="none">';
        h += '<div class="menu-icon">❌</div>';
        h += '<div class="menu-label">Non merci</div>';
        h += '<div class="menu-desc">Choisir individuellement</div>';
        h += '</div>';

        h += '</div>'; // .wizard-menu-buttons

        // Individual addons (collapsed by default)
        var isOpen = (selections.menuChoice === 'none' || selections.menuChoice === 'individual') ? ' open' : '';
        h += '<div class="wizard-individual-addons' + isOpen + '">';
        h += '<div class="wizard-options" style="grid-template-columns: repeat(2, 1fr);">';
        step.items.forEach(function (item) {
            var sel = selections.individualAddons && selections.individualAddons[item.id] ? ' selected' : '';
            h += '<div class="wizard-option addons' + sel + '" data-type="addon" data-id="' + item.id + '">';
            h += '<span class="check-mark"><i class="fa-solid fa-check"></i></span>';
            if (item.thumb) {
                h += '<span class="option-icon has-img"><img src="' + item.thumb + '" alt="' + item.name + '"></span>';
            } else {
                h += '<span class="option-icon">' + getEmoji(ADDON_EMOJIS, item.name) + '</span>';
            }
            h += '<span class="option-name">' + item.name + '</span>';
            h += '<span class="option-price paid">+' + item.currencyPrice + '</span>';
            h += '</div>';
        });
        h += '</div>';
        h += '</div>'; // .wizard-individual-addons

        h += '</div>'; // .wizard-menu-question
        return h;
    }

    // ---- SAUCE FRITES ----
    function renderSauceFritesStep(step) {
        var h = '';
        var count = selections.sauceFritesOrder ? selections.sauceFritesOrder.length : 0;

        h += '<div class="wizard-sauce-info">';
        h += '<div class="sauce-frites-label">🍟 Sauce pour vos frites</div>';
        if (count === 0) {
            h += '<span class="sauce-badge free">Sélectionnez votre sauce frites (gratuite)</span>';
        } else if (count === 1) {
            h += '<span class="sauce-badge free">✅ 1 sauce sélectionnée (gratuite)</span>';
        } else {
            var extraCost = (count - 1) * SAUCE_EXTRA_PRICE;
            h += '<span class="sauce-badge paid">' + count + ' sauces — ' + (count - 1) + ' supplém. = +' + fmtPrice(extraCost) + '</span>';
        }
        h += '</div>';

        h += '<div class="wizard-options sauce-grid">';
        step.items.forEach(function (sauce) {
            var sel = selections.sauceFrites && selections.sauceFrites[sauce.id] ? ' selected' : '';
            var idx = selections.sauceFritesOrder ? selections.sauceFritesOrder.indexOf(sauce.id) : -1;
            var priceLabel = '';
            if (idx === 0) {
                priceLabel = '<span class="option-price free">Gratuit</span>';
            } else if (idx > 0) {
                priceLabel = '<span class="option-price paid">+€0.50</span>';
            } else {
                priceLabel = '<span class="option-price">' + (count === 0 ? 'Gratuit' : '+€0.50') + '</span>';
            }

            h += '<div class="wizard-option sauce-opt' + sel + '" data-type="sauce_frite" data-id="' + sauce.id + '">';
            h += '<span class="check-mark"><i class="fa-solid fa-check"></i></span>';
            h += '<span class="option-icon">' + sauce.emoji + '</span>';
            h += '<span class="option-name">' + sauce.name + '</span>';
            h += priceLabel;
            h += '</div>';
        });
        h += '</div>';
        return h;
    }

    // ---- RECAP ----
    function renderRecapStep() {
        var h = '';
        var totalExtra = 0;
        var basePrice = 0;

        if (lastItemData) {
            basePrice = lastItemData.offer && lastItemData.offer.length > 0
                ? parseFloat(lastItemData.offer[0].convert_price) || 0
                : parseFloat(lastItemData.convert_price) || 0;
        }

        // Quantity selector
        h += '<div class="wizard-quantity">';
        h += '<button type="button" class="wizard-qty-btn" data-qty="minus">−</button>';
        h += '<span class="wizard-qty-value">' + itemQuantity + '</span>';
        h += '<button type="button" class="wizard-qty-btn" data-qty="plus">+</button>';
        h += '</div>';

        h += '<div class="wizard-recap">';

        // Viandes
        var viandeStep = steps.find(function (s) { return s.type === 'viande'; });
        if (viandeStep && selections.viandes) {
            var viandeNames = [];
            viandeStep.items.forEach(function (v) {
                var count = selections.viandes[v.key] || 0;
                if (count > 0) {
                    viandeNames.push(count > 1 ? count + '× ' + v.name : v.name);
                }
            });
            if (viandeNames.length > 0) {
                h += '<div class="wizard-recap-row"><span class="label">🥩 Viandes</span><span class="value">' +
                    viandeNames.join(', ') + '</span></div>';
            }
        }

        // Sauces
        var sauceStep = steps.find(function (s) { return s.type === 'sauce'; });
        if (sauceStep && selections.sauceOrder && selections.sauceOrder.length > 0) {
            var sauceNames = [];
            selections.sauceOrder.forEach(function (id, idx) {
                var sauce = sauceStep.items.find(function (s) { return s.id === id; });
                if (sauce) {
                    if (idx === 0) {
                        sauceNames.push(sauce.name + ' (gratuit)');
                    } else {
                        sauceNames.push(sauce.name + ' (+€0.50)');
                        totalExtra += SAUCE_EXTRA_PRICE;
                    }
                }
            });
            h += '<div class="wizard-recap-row"><span class="label">🥄 Sauce</span><span class="value">' +
                sauceNames.join(', ') + '</span></div>';
        }

        // Sauce Single (omelettes, snacking)
        if (selections.sauceSingle) {
            var singleStep = steps.find(function (s) { return s.type === 'sauce_single'; });
            if (singleStep) {
                var sName = '';
                singleStep.items.forEach(function (s) {
                    if (s.id === selections.sauceSingle) sName = s.name;
                });
                if (sName) {
                    h += '<div class="wizard-recap-row"><span class="label">🥄 Sauce</span><span class="value">' +
                        sName + ' (inclus)</span></div>';
                }
            }
        }

        // Accompagnement (assiettes)
        if (selections.accompagnement) {
            var accStep = steps.find(function (s) { return s.type === 'accompagnement'; });
            if (accStep) {
                var accName = '';
                accStep.items.forEach(function (a) {
                    if (a.id === selections.accompagnement) accName = a.name;
                });
                if (accName) {
                    h += '<div class="wizard-recap-row"><span class="label">🍟 Accompagnement</span><span class="value">' +
                        accName + ' (inclus)</span></div>';
                }
            }
        }

        // Garnitures
        var garnStep = steps.find(function (s) { return s.type === 'garnitures'; });
        if (garnStep) {
            var garnNames = [];
            garnStep.items.forEach(function (g) {
                if (selections.garnitures && selections.garnitures[g.id]) {
                    garnNames.push(g.name);
                }
            });
            h += '<div class="wizard-recap-row"><span class="label">🥬 Garnitures</span><span class="value">' +
                (garnNames.length > 0 ? garnNames.join(', ') : 'Aucune') + '</span></div>';
        }

        // Supplements
        var suppStep = steps.find(function (s) { return s.type === 'supplements'; });
        if (suppStep) {
            var suppNames = [];
            suppStep.items.forEach(function (s) {
                if (selections.supplements && selections.supplements[s.id]) {
                    suppNames.push(s.name);
                    totalExtra += s.price;
                }
            });
            if (suppNames.length > 0) {
                h += '<div class="wizard-recap-row"><span class="label">➕ Suppléments</span><span class="value">' +
                    suppNames.join(', ') + '</span></div>';
            }
        }

        // Menu / Addons
        var menuStep = steps.find(function (s) { return s.type === 'menu'; });
        var addonTotal = 0;
        if (menuStep) {
            if (selections.menuChoice === 'full') {
                var addonNames = [];
                menuStep.items.forEach(function (a) {
                    addonNames.push(a.name);
                    addonTotal += a.price;
                });
                h += '<div class="wizard-recap-row"><span class="label">🍟 Menu</span><span class="value">' +
                    addonNames.join(' + ') + '</span></div>';
            } else if (selections.menuChoice === 'none' || selections.menuChoice === 'individual') {
                var indNames = [];
                menuStep.items.forEach(function (a) {
                    if (selections.individualAddons && selections.individualAddons[a.id]) {
                        indNames.push(a.name);
                        addonTotal += a.price;
                    }
                });
                if (indNames.length > 0) {
                    h += '<div class="wizard-recap-row"><span class="label">🍟 À la carte</span><span class="value">' +
                        indNames.join(', ') + '</span></div>';
                }
            }
        }

        // Sauce Frites
        if (hasFritesSelected() && selections.sauceFritesOrder && selections.sauceFritesOrder.length > 0) {
            var sfStep = steps.find(function (s) { return s.type === 'sauce_frites'; });
            if (sfStep) {
                var sfNames = [];
                selections.sauceFritesOrder.forEach(function (id, idx) {
                    var sauce = sfStep.items.find(function (s) { return s.id === id; });
                    if (sauce) {
                        if (idx === 0) {
                            sfNames.push(sauce.name + ' (gratuit)');
                        } else {
                            sfNames.push(sauce.name + ' (+€0.50)');
                            totalExtra += SAUCE_EXTRA_PRICE;
                        }
                    }
                });
                h += '<div class="wizard-recap-row"><span class="label">🍟 Sauce frites</span><span class="value">' +
                    sfNames.join(', ') + '</span></div>';
            }
        }

        // Total
        var unitPrice = basePrice + totalExtra;
        var total = (unitPrice * itemQuantity) + addonTotal;
        h += '<div class="wizard-recap-row total"><span class="label">Total</span><span class="value">' +
            fmtPrice(total) + '</span></div>';

        h += '</div>'; // .wizard-recap

        // Instruction
        h += '<div class="wizard-instruction">';
        h += '<textarea placeholder="Instructions spéciales (ex: sans oignon, bien cuit...)">' +
            (instructionText || '') + '</textarea>';
        h += '</div>';

        return h;
    }

    // ---- NAVIGATION ----
    function renderNav() {
        var activeSteps = getActiveSteps();
        var activeIdx = getActiveStepIndex();
        var h = '<div class="wizard-nav">';

        if (activeIdx > 0) {
            h += '<button type="button" class="wizard-btn wizard-btn-back" data-nav="back">';
            h += '<i class="fa-solid fa-arrow-left"></i> Retour</button>';
        } else {
            h += '<div></div>';
        }

        if (activeIdx < activeSteps.length - 1) {
            h += '<div>';
            h += '<button type="button" class="wizard-btn wizard-btn-skip" data-nav="skip">Passer</button>';
            h += '<button type="button" class="wizard-btn wizard-btn-next" data-nav="next">';
            h += 'Suivant <i class="fa-solid fa-arrow-right"></i></button>';
            h += '</div>';
        } else {
            h += '<button type="button" class="wizard-btn wizard-btn-cart" data-nav="cart">';
            h += '<i class="fa-solid fa-cart-shopping"></i> Ajouter au panier</button>';
        }

        h += '</div>';
        return h;
    }

    /* ==============================
       SYNC SELECTIONS BACK TO VUE MODAL
       ============================== */
    function syncAndSubmit() {
        if (!originalBody) return;

        // 1. Set quantity
        var qtyInput = originalBody.querySelector('.indec-value');
        if (qtyInput) {
            var nativeInputValueSetter = Object.getOwnPropertyDescriptor(window.HTMLInputElement.prototype, 'value').set;
            nativeInputValueSetter.call(qtyInput, itemQuantity);
            qtyInput.dispatchEvent(new Event('input', { bubbles: true }));
            qtyInput.dispatchEvent(new Event('keyup', { bubbles: true }));
        }

        // 2. Click the correct sauce/variation radio (use 1st selected sauce or sauceSingle)
        var sauceIdToSync = null;
        if (selections.sauceOrder && selections.sauceOrder.length > 0) {
            sauceIdToSync = selections.sauceOrder[0];
        } else if (selections.sauceSingle) {
            sauceIdToSync = selections.sauceSingle;
        }
        if (sauceIdToSync) {
            var radios = originalBody.querySelectorAll('.custom-radio-field');
            radios.forEach(function (radio) {
                if (parseInt(radio.value) === sauceIdToSync) {
                    radio.click();
                }
            });
            var selects = originalBody.querySelectorAll('select');
            selects.forEach(function (sel) {
                var opts = sel.querySelectorAll('option');
                opts.forEach(function (opt) {
                    if (parseInt(opt.value) === sauceIdToSync) {
                        sel.value = opt.value;
                        sel.dispatchEvent(new Event('change', { bubbles: true }));
                    }
                });
            });
        }

        // 3. Check/uncheck extras
        var extraCheckboxes = originalBody.querySelectorAll('.extra .custom-checkbox-field');
        var allSelectedExtras = {};

        if (selections.garnitures) {
            Object.keys(selections.garnitures).forEach(function (id) {
                if (selections.garnitures[id]) allSelectedExtras[id] = true;
            });
        }
        if (selections.supplements) {
            Object.keys(selections.supplements).forEach(function (id) {
                if (selections.supplements[id]) allSelectedExtras[id] = true;
            });
        }
        if (selections.accompagnement) {
            allSelectedExtras[selections.accompagnement] = true;
        }

        extraCheckboxes.forEach(function (cb) {
            var cbId = parseInt(cb.value);
            var shouldBeChecked = !!allSelectedExtras[cbId];
            if (cb.checked !== shouldBeChecked) {
                cb.click();
            }
        });

        // 4. Click addon cards
        var addonCards = originalBody.querySelectorAll('.addon');
        var menuStep = steps.find(function (s) { return s.type === 'menu'; });
        if (menuStep && addonCards.length > 0) {
            addonCards.forEach(function (card, index) {
                var addon = menuStep.items[index];
                if (!addon) return;

                var shouldBeSelected = false;
                if (selections.menuChoice === 'full') {
                    shouldBeSelected = true;
                } else if (selections.individualAddons && selections.individualAddons[addon.id]) {
                    shouldBeSelected = true;
                }

                var isSelected = card.closest('.selected, [class*="primary"]') !== null;
                if (shouldBeSelected && !isSelected) {
                    card.click();
                }
            });
        }

        // 5. Set instruction — build comprehensive instruction with viandes, extra sauces, sauce frites
        var fullInstruction = '';

        // Viande info
        if (selections.viandes) {
            var viandeNames = [];
            VIANDES.forEach(function (v) {
                var count = selections.viandes[v.key] || 0;
                if (count > 0) {
                    viandeNames.push(count > 1 ? count + '× ' + v.name : v.name);
                }
            });
            if (viandeNames.length > 0) {
                fullInstruction += 'VIANDES: ' + viandeNames.join(', ') + '. ';
            }
        }

        // Extra sauces (2nd, 3rd, etc.)
        if (selections.sauceOrder && selections.sauceOrder.length > 1) {
            var sauceStep = steps.find(function (s) { return s.type === 'sauce'; });
            var extraSauceNames = [];
            for (var si = 1; si < selections.sauceOrder.length; si++) {
                var sauce = sauceStep.items.find(function (ss) { return ss.id === selections.sauceOrder[si]; });
                if (sauce) extraSauceNames.push(sauce.name);
            }
            if (extraSauceNames.length > 0) {
                fullInstruction += 'SAUCES SUPPL: ' + extraSauceNames.join(', ') + '. ';
            }
        }

        // Sauce frites
        if (selections.sauceFritesOrder && selections.sauceFritesOrder.length > 0) {
            var sfStep = steps.find(function (s) { return s.type === 'sauce_frites'; });
            var sfNames = [];
            selections.sauceFritesOrder.forEach(function (id) {
                var sauce = sfStep.items.find(function (ss) { return ss.id === id; });
                if (sauce) sfNames.push(sauce.name);
            });
            if (sfNames.length > 0) {
                fullInstruction += 'SAUCE FRITES: ' + sfNames.join(', ') + '. ';
            }
        }

        // User instruction
        if (instructionText) {
            fullInstruction += instructionText;
        }

        var textarea = originalBody.querySelector('textarea');
        if (textarea && fullInstruction) {
            var nativeTextSetter = Object.getOwnPropertyDescriptor(window.HTMLTextAreaElement.prototype, 'value').set;
            nativeTextSetter.call(textarea, fullInstruction);
            textarea.dispatchEvent(new Event('input', { bubbles: true }));
        }

        // 6. Small delay then click Add to Cart
        setTimeout(function () {
            var addBtn = originalBody.querySelector('button[class*="bg-primary"]');
            if (!addBtn) {
                var buttons = originalBody.querySelectorAll('button');
                addBtn = buttons[buttons.length - 1];
            }
            if (addBtn) {
                addBtn.click();
            }
            closeWizard();
        }, 200);
    }

    /* ==============================
       WIZARD OPEN / CLOSE
       ============================== */
    function openWizard(modal) {
        if (!lastItemData) return;

        var modalDialog = modal.querySelector('.modal-dialog');
        originalBody = modal.querySelector('.modal-body');
        if (!originalBody || !modalDialog) return;

        steps = buildSteps(lastItemData);

        // If only recap step, skip wizard
        var activeSteps = getActiveSteps();
        if (activeSteps.length <= 1) return;

        currentStep = 0;
        itemQuantity = 1;
        instructionText = '';

        originalBody.style.display = 'none';

        wizardEl = document.createElement('div');
        wizardEl.id = 'pos-wizard-root';
        wizardEl.innerHTML = renderWizard();
        modalDialog.appendChild(wizardEl);

        bindEvents();
    }

    function closeWizard() {
        if (wizardEl) {
            wizardEl.remove();
            wizardEl = null;
        }
        if (originalBody) {
            originalBody.style.display = '';
            originalBody = null;
        }
        lastItemData = null;
        currentStep = 0;
        steps = [];
        selections = {};
        itemQuantity = 1;
        instructionText = '';
    }

    function updateWizardUI() {
        if (!wizardEl) return;

        // 1. Update running total
        var runTotalEl = wizardEl.querySelector('.run-total-value');
        if (runTotalEl) {
            runTotalEl.innerText = fmtPrice(calculateRunningTotal());
        }

        // 2. Viande counters
        if (selections.viandes) {
            var total = selections.totalViandes || 0;
            var max = selections.maxViandes;
            var headerEl = wizardEl.querySelector('.wizard-viande-counter-header');
            if (headerEl) {
                var totalEl = headerEl.querySelector('.viande-total');
                if (totalEl) {
                    totalEl.innerText = total + ' / ' + max;
                    if (total === max) totalEl.classList.add('complete');
                    else totalEl.classList.remove('complete');
                }
                var badgeEl = headerEl.querySelector('.viande-complete-badge');
                if (total === max && !badgeEl) {
                    headerEl.insertAdjacentHTML('beforeend', '<span class="viande-complete-badge">✅ Complet</span>');
                } else if (total < max && badgeEl) {
                    badgeEl.remove();
                }
            }

            wizardEl.querySelectorAll('.wizard-viande-row').forEach(function (row) {
                var btnMinus = row.querySelector('.viande-btn.minus');
                var btnPlus = row.querySelector('.viande-btn.plus');
                var countEl = row.querySelector('.viande-count');
                if (!btnMinus || !btnPlus) return;

                var key = btnMinus.getAttribute('data-viande');
                var count = selections.viandes[key] || 0;

                if (countEl) countEl.innerText = count;
                if (count > 0) row.classList.add('active');
                else row.classList.remove('active');

                if (count <= 0) btnMinus.classList.add('disabled');
                else btnMinus.classList.remove('disabled');

                if (total >= max) btnPlus.classList.add('disabled');
                else btnPlus.classList.remove('disabled');
            });
        }

        // 3. Update all wizard options
        wizardEl.querySelectorAll('.wizard-option').forEach(function (opt) {
            var type = opt.getAttribute('data-type');
            var id = parseInt(opt.getAttribute('data-id'));
            var isSelected = false;

            if (type === 'sauce') {
                isSelected = selections.sauces && selections.sauces[id];
                var idx = selections.sauceOrder ? selections.sauceOrder.indexOf(id) : -1;
                var count = selections.sauceOrder ? selections.sauceOrder.length : 0;
                var priceEl = opt.querySelector('.option-price');
                if (priceEl) {
                    if (idx === 0) {
                        priceEl.className = 'option-price free';
                        priceEl.innerText = 'Gratuit';
                    } else if (idx > 0) {
                        priceEl.className = 'option-price paid';
                        priceEl.innerText = '+€0.50';
                    } else {
                        priceEl.className = 'option-price';
                        priceEl.innerText = (count === 0 ? 'Gratuit' : '+€0.50');
                    }
                }
            } else if (type === 'sauce_single') {
                isSelected = (selections.sauceSingle === id);
            } else if (type === 'accompagnement') {
                isSelected = (selections.accompagnement === id);
            } else if (type === 'garniture') {
                isSelected = selections.garnitures && selections.garnitures[id];
            } else if (type === 'supplement') {
                isSelected = selections.supplements && selections.supplements[id];
            } else if (type === 'addon') {
                isSelected = selections.individualAddons && selections.individualAddons[id];
            } else if (type === 'sauce_frite') {
                isSelected = selections.sauceFrites && selections.sauceFrites[id];
                var idx = selections.sauceFritesOrder ? selections.sauceFritesOrder.indexOf(id) : -1;
                var count = selections.sauceFritesOrder ? selections.sauceFritesOrder.length : 0;
                var priceEl = opt.querySelector('.option-price');
                if (priceEl) {
                    if (idx === 0) {
                        priceEl.className = 'option-price free';
                        priceEl.innerText = 'Gratuit';
                    } else if (idx > 0) {
                        priceEl.className = 'option-price paid';
                        priceEl.innerText = '+€0.50';
                    } else {
                        priceEl.className = 'option-price';
                        priceEl.innerText = (count === 0 ? 'Gratuit' : '+€0.50');
                    }
                }
            }

            if (isSelected) opt.classList.add('selected');
            else opt.classList.remove('selected');
        });

        // 4. Update Sauce Info Headers
        var sauceInfoEl = wizardEl.querySelector('.wizard-sauce-info');
        if (sauceInfoEl && selections.sauceOrder) {
            var count = selections.sauceOrder.length;
            var newHtml = '';
            if (count === 0) newHtml = '<span class="sauce-badge free">Sélectionnez votre 1ère sauce (gratuite)</span>';
            else if (count === 1) newHtml = '<span class="sauce-badge free">✅ 1 sauce sélectionnée (gratuite)</span>';
            else {
                var extraCost = (count - 1) * SAUCE_EXTRA_PRICE;
                newHtml = '<span class="sauce-badge paid">' + count + ' sauces — ' + (count - 1) + ' supplém. = +' + fmtPrice(extraCost) + '</span>';
            }
            sauceInfoEl.innerHTML = newHtml;
        }

        var sfInfoEl = wizardEl.querySelector('.wizard-sauce-frite-info');
        if (sfInfoEl && selections.sauceFritesOrder) {
            var count = selections.sauceFritesOrder.length;
            var newHtml = '';
            if (count === 0) newHtml = '<span class="sauce-badge free">Sauce frites (1ère gratuite)</span>';
            else if (count === 1) newHtml = '<span class="sauce-badge free">✅ 1 sauce frites sélectionnée (gratuite)</span>';
            else {
                var extraCost = (count - 1) * SAUCE_EXTRA_PRICE;
                newHtml = '<span class="sauce-badge paid">' + count + ' sauces — ' + (count - 1) + ' supplém. = +' + fmtPrice(extraCost) + '</span>';
            }
            sfInfoEl.innerHTML = newHtml;
        }

        // 5. Update Menu Full choice
        var menuFullYes = wizardEl.querySelector('.wizard-menu-btn.yes');
        var menuFullNo = wizardEl.querySelector('.wizard-menu-btn.no');
        if (menuFullYes && menuFullNo) {
            if (selections.menuChoice === 'full') {
                menuFullYes.classList.add('selected');
                menuFullNo.classList.remove('selected');
            } else if (selections.menuChoice === 'none') {
                menuFullNo.classList.add('selected');
                menuFullYes.classList.remove('selected');
            } else {
                menuFullYes.classList.remove('selected');
                menuFullNo.classList.remove('selected');
            }
        }
    }

    function refreshWizard() {
        if (!wizardEl) return;
        wizardEl.innerHTML = renderWizard();
        bindEvents();
    }

    /* ==============================
       NAVIGATION HELPERS
       ============================== */
    function goToNextActiveStep() {
        var activeSteps = getActiveSteps();
        var activeIdx = getActiveStepIndex();
        if (activeIdx < activeSteps.length - 1) {
            var nextActive = activeSteps[activeIdx + 1];
            // Find this step in the full steps array
            for (var i = 0; i < steps.length; i++) {
                if (steps[i] === nextActive) {
                    currentStep = i;
                    break;
                }
            }
            refreshWizard();
        }
    }

    function goToPrevActiveStep() {
        var activeSteps = getActiveSteps();
        var activeIdx = getActiveStepIndex();
        if (activeIdx > 0) {
            var prevActive = activeSteps[activeIdx - 1];
            for (var i = 0; i < steps.length; i++) {
                if (steps[i] === prevActive) {
                    currentStep = i;
                    break;
                }
            }
            refreshWizard();
        }
    }

    /* ==============================
       EVENT BINDING
       ============================== */
    function bindEvents() {
        if (!wizardEl) return;

        // Viande +/- buttons
        wizardEl.querySelectorAll('.viande-btn').forEach(function (btn) {
            btn.addEventListener('click', function () {
                var key = this.getAttribute('data-viande');
                var action = this.getAttribute('data-action');
                if (!selections.viandes) return;

                if (action === 'plus') {
                    if (selections.totalViandes < selections.maxViandes) {
                        selections.viandes[key] = (selections.viandes[key] || 0) + 1;
                        selections.totalViandes++;
                    }
                } else if (action === 'minus') {
                    if (selections.viandes[key] > 0) {
                        selections.viandes[key]--;
                        selections.totalViandes--;
                    }
                }
                updateWizardUI();
            });
        });

        // Sauce multi-select
        wizardEl.querySelectorAll('.wizard-option[data-type="sauce"]').forEach(function (opt) {
            opt.addEventListener('click', function () {
                var id = parseInt(this.getAttribute('data-id'));
                if (!selections.sauces) selections.sauces = {};
                if (!selections.sauceOrder) selections.sauceOrder = [];

                if (selections.sauces[id]) {
                    // Deselect
                    selections.sauces[id] = false;
                    selections.sauceOrder = selections.sauceOrder.filter(function (sid) { return sid !== id; });
                } else {
                    // Select
                    selections.sauces[id] = true;
                    selections.sauceOrder.push(id);
                }
                updateWizardUI();
            });
        });

        // Sauce frites multi-select
        wizardEl.querySelectorAll('.wizard-option[data-type="sauce_frite"]').forEach(function (opt) {
            opt.addEventListener('click', function () {
                var id = parseInt(this.getAttribute('data-id'));
                if (!selections.sauceFrites) selections.sauceFrites = {};
                if (!selections.sauceFritesOrder) selections.sauceFritesOrder = [];

                if (selections.sauceFrites[id]) {
                    selections.sauceFrites[id] = false;
                    selections.sauceFritesOrder = selections.sauceFritesOrder.filter(function (sid) { return sid !== id; });
                } else {
                    selections.sauceFrites[id] = true;
                    selections.sauceFritesOrder.push(id);
                }
                updateWizardUI();
            });
        });

        // Sauce single (radio) — omelettes, snacking
        wizardEl.querySelectorAll('.wizard-option[data-type="sauce_single"]').forEach(function (opt) {
            opt.addEventListener('click', function () {
                var id = parseInt(this.getAttribute('data-id'));
                selections.sauceSingle = id;
                updateWizardUI();
            });
        });

        // Accompagnement (radio) — assiettes
        wizardEl.querySelectorAll('.wizard-option[data-type="accompagnement"]').forEach(function (opt) {
            opt.addEventListener('click', function () {
                var id = parseInt(this.getAttribute('data-id'));
                selections.accompagnement = id;
                updateWizardUI();
            });
        });

        // Garniture toggle
        wizardEl.querySelectorAll('.wizard-option[data-type="garniture"]').forEach(function (opt) {
            opt.addEventListener('click', function () {
                var id = parseInt(this.getAttribute('data-id'));
                if (!selections.garnitures) selections.garnitures = {};
                selections.garnitures[id] = !selections.garnitures[id];
                updateWizardUI();
            });
        });

        // Supplement toggle
        wizardEl.querySelectorAll('.wizard-option[data-type="supplement"]').forEach(function (opt) {
            opt.addEventListener('click', function () {
                var id = parseInt(this.getAttribute('data-id'));
                if (!selections.supplements) selections.supplements = {};
                selections.supplements[id] = !selections.supplements[id];
                updateWizardUI();
            });
        });

        // Addon toggle
        wizardEl.querySelectorAll('.wizard-option[data-type="addon"]').forEach(function (opt) {
            opt.addEventListener('click', function () {
                var id = parseInt(this.getAttribute('data-id'));
                if (!selections.individualAddons) selections.individualAddons = {};
                selections.individualAddons[id] = !selections.individualAddons[id];
                selections.menuChoice = 'individual';
                updateWizardUI();
            });
        });

        // Menu buttons (Yes/No)
        wizardEl.querySelectorAll('.wizard-menu-btn').forEach(function (btn) {
            btn.addEventListener('click', function () {
                var choice = this.getAttribute('data-menu');
                if (choice === 'full') {
                    selections.menuChoice = 'full';
                    selections.individualAddons = {};
                } else {
                    selections.menuChoice = 'none';
                }
                updateWizardUI();
            });
        });

        // Navigation buttons
        wizardEl.querySelectorAll('[data-nav]').forEach(function (btn) {
            btn.addEventListener('click', function () {
                var action = this.getAttribute('data-nav');
                if (action === 'next' || action === 'skip') {
                    goToNextActiveStep();
                } else if (action === 'back') {
                    goToPrevActiveStep();
                } else if (action === 'cart') {
                    syncAndSubmit();
                }
            });
        });

        // Quantity buttons
        wizardEl.querySelectorAll('[data-qty]').forEach(function (btn) {
            btn.addEventListener('click', function () {
                var action = this.getAttribute('data-qty');
                if (action === 'plus') {
                    itemQuantity++;
                } else if (action === 'minus' && itemQuantity > 1) {
                    itemQuantity--;
                }
                refreshWizard();
            });
        });

        // Instruction textarea
        var ta = wizardEl.querySelector('.wizard-instruction textarea');
        if (ta) {
            ta.addEventListener('input', function () {
                instructionText = this.value;
            });
        }
    }

    /* ==============================
       OBSERVE MODAL OPENING
       ============================== */
    function init() {
        var pollInterval = setInterval(function () {
            var modal = document.getElementById('item-variation-modal');
            if (modal) {
                clearInterval(pollInterval);

                var observer = new MutationObserver(function () {
                    if (modal.classList.contains('active') && !wizardEl) {
                        setTimeout(function () {
                            openWizard(modal);
                        }, 400);
                    } else if (!modal.classList.contains('active') && wizardEl) {
                        closeWizard();
                    }
                });

                observer.observe(modal, { attributes: true, attributeFilter: ['class'] });
            }
        }, 500);
    }

    /* ==============================
       START
       ============================== */
    if (document.readyState === 'complete' || document.readyState === 'interactive') {
        init();
    } else {
        document.addEventListener('DOMContentLoaded', init);
    }

})();

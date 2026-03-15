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
       UTILS — Normalisation
       ============================== */
    function normalizeStr(str) {
        return (str || '')
            .toLowerCase()
            .normalize('NFD')
            .replace(/[\u0300-\u036f]/g, '');
    }

    /* ==============================
       ONLY RUN ON POS PAGE
       ============================== */
    if (!window.location.pathname.includes('/admin/pos')) return;

    /* ==============================
       STATE
       ============================== */
    let lastItemData = null;
    let wizardItemData = null; // [BUG-W1 FIX] Store item data for buildWizardInstruction()
    let wizardEl = null;
    let originalBody = null;
    let currentStep = 0;
    let steps = [];
    let selections = {};
    let itemQuantity = 1;
    let instructionText = '';

    /* ==============================
       CONFIG — VIANDES DISPONIBLES
       ============================== */
    const VIANDES = [
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
    const ALL_SAUCES = [
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

    const SAUCE_EXTRA_PRICE = 0.50;

    /* ==============================
       CONFIG — SUPPLEMENTS
       ============================== */
    const SUPPLEMENT_EMOJIS = {
        'oeuf': '🥚', 'œuf': '🥚', 'fromage': '🧀', 'raclette': '🫕',
        'boursin': '🧀', 'jambon': '🥓', 'jambon de dinde': '🥓',
        'mozzarella': '🧀', 'galette': '🥔', 'steak': '🥩', 'poulet': '🍗',
        'cheddar': '🧀', 'double viande': '🥩', 'double steak': '🥩',
        'bacon': '🥓', 'fromage a raclette': '🫕', 'galette pommes de terre': '🥔',
        'default': '➕'
    };

    const GARNITURE_EMOJIS = {
        'salade': '🥬', 'tomate': '🍅', 'oignon': '🧅', 'oignons': '🧅',
        'riz': '🍚', 'frites': '🍟', 'default': '🥗'
    };

    const ADDON_EMOJIS = {
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

    /** Affiche image si thumb disponible, sinon emoji. Mode micro pour sauces/garnitures compactes */
    function renderOptionIcon(thumb, emoji, isMicro) {
        if (isMicro) {
            if (thumb && typeof thumb === 'string' && thumb.length > 0) {
                return '<img src="' + thumb + '" alt="" class="option-img-micro" />';
            }
            return '<span class="option-icon-micro">' + (emoji || '🥄') + '</span>';
        }
        if (thumb && typeof thumb === 'string' && thumb.length > 0) {
            return '<img src="' + thumb + '" alt="" class="option-img" />';
        }
        return '<span class="option-icon">' + (emoji || '') + '</span>';
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
        let n = normalizeStr(name);
        if (!n.includes('tacos')) return 0;

        // Try to extract number from parentheses: "(3 viande)"
        let match = n.match(/\((\d+)\s*viande/);
        if (match) return parseInt(match[1]);

        // Try XXL first (before XL)
        if (n.includes('xxl')) return 4;
        if (n.includes('xl') && !n.includes('xxl')) return 3;
        if (n.includes(' l ') || n.match(/\bl\b/) || n.includes(' l(') || n.includes('tacos l')) return 2;
        if (n.includes(' m ') || n.match(/\bm\b/) || n.includes(' m(') || n.includes('tacos m')) return 1;

        return 0;
    }

    /**
     * [REFACTORED SPRINT 4] Detect product category from API data.
     * NEW: Robust detection with priority on API data over DOM fallback.
     * Returns: 'tacos', 'sandwich', 'burger', 'assiette', 'salade',
     *          'omelette', 'ojja', 'snacking', 'menu_enfant',
     *          'dessert', 'boisson', 'unknown'
     */
    function detectCategory(data) {
        // [PLAN_12 ARCH-02] Priority 1: wizard_template depuis l'API (DB-driven)
        if (data.wizard_template && data.wizard_template !== 'simple' && data.wizard_template !== 'unknown') {
            console.log('[POS-WIZARD] wizard_template from API:', data.wizard_template);
            return data.wizard_template;
        }
        
        // [PLAN_12 ARCH-02] Fallback legacy: string matching sur category_name
        let cat = normalizeStr(data.category_name || data.item_category_name || '');
        let name = normalizeStr(data.name || '');

        // DOM fallback only if API data is empty
        if (!cat) {
            let activeTab = document.querySelector(
                '.db-product-filter.active, .nav-link.active .tab-title, [class*="tab"].active'
            );
            if (activeTab) cat = normalizeStr(activeTab.innerText || activeTab.textContent || '');
        }

        console.log('[POS-WIZARD] detectCategory (fallback):', { apiCat: data.category_name || data.item_category_name, domCat: cat, name: data.name });

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

        return 'simple';
    }

    /**
     * [REFACTORED SPRINT 4] Based on category, decide which wizard steps are allowed.
     * NEW: Combined steps for faster POS workflow (max 4 steps vs 7 before)
     */
    function getAllowedSteps(category) {
        switch (category) {
            case 'tacos':
                // NEW: 4 steps instead of 7 (viande+sauce combined, perso combined, menu inline)
                return ['viande_sauce', 'perso', 'menu', 'recap'];
            case 'sandwich':
                // [UI/UX Sprint 4] Pain/Galette step first, then others
                var viandeCount = detectViandeCount(lastItemData ? lastItemData.name : '');
                if (viandeCount > 0) {
                    // Sandwich with meats: pain + viande + perso + menu (5 steps)
                    return ['pain', 'viande_sauce', 'perso', 'menu', 'recap'];
                }
                // Sandwich no meat: pain + sauce_garnitures + supplements + recap (4 steps)
                return ['pain', 'sauce_garnitures', 'supplements_menu', 'recap'];
            case 'burger':
                // [BUG-WIZ-003 FIX] Check if item has meat slots (e.g., Terminator with 2 viandes)
                var viandeCount = detectViandeCount(lastItemData ? lastItemData.name : '');
                if (viandeCount > 0) {
                    // Burger with meats: show meat step (4 steps)
                    return ['viande_sauce', 'perso', 'menu', 'recap'];
                }
                // NEW: 3 steps instead of 6 (no meat)
                return ['sauce_garnitures', 'supplements_menu', 'recap'];
            case 'assiette':
                // NEW: 3 steps instead of 4 (sauce+accompagnement combined)
                return ['sauce_accompagnement', 'supplements', 'recap'];
            case 'salade':
                // NEW: 2 steps instead of 3 (sauce+supplements combined)
                return ['sauce_supplements', 'recap'];
            case 'omelette':
                return ['sauce_single', 'recap']; // Already optimal
            case 'ojja':
                return ['recap']; // Already optimal
            case 'snacking':
                return ['sauce_single', 'recap']; // Already optimal
            default:
                return ['sauce_garnitures', 'supplements_menu', 'recap'];
        }
    }

    /**
     * Check if the item has frites selected (in menu, frites only, or individual)
     */
    function hasFritesSelected() {
        if (selections.menuChoice === 'full') return true;  // Menu complet
        if (selections.menuChoice === 'frites') return true;  // Frites seules [P1]
        if (selections.individualAddons) {
            // [W-7 FIX] Include supplements_menu step for Sandwich/Burger flow
            var menuStep = steps.find(function (s) {
                return s.type === 'menu' || s.type === 'supplements_menu';
            });
            if (menuStep) {
                // [W-7 FIX] Support both menuStep.items (menu flow) and menuStep.menuItems (supplements_menu flow)
                var items = menuStep.items || menuStep.menuItems || [];
                for (var i = 0; i < items.length; i++) {
                    var addon = items[i];
                    if (addon.name.toLowerCase().includes('frite') && selections.individualAddons[addon.id]) {
                        return true;
                    }
                }
            }
        }
        return false;
    }

    /**
     * [P1] Check if the item needs boisson selection (menu full or boisson seule)
     */
    function hasBoissonSelected() {
        if (selections.menuChoice === 'full') return true;   // Menu complet → besoin boisson
        if (selections.menuChoice === 'boisson') return true;  // Boisson seule [P1]
        return false;
    }

    /* ==============================
       BUILD STEPS FROM ITEM DATA
       ============================== */
    /**
     * [REFACTORED SPRINT 4] Build wizard steps based on item data.
     * NEW: Combined steps for faster POS workflow.
     */
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

        // === PREPARE SHARED DATA ===
        var viandeCount = detectViandeCount(data.name);
        var sauceList = [];
        var dbSauces = {};

        // Build sauce list from DB variations OR fallback to config
        if (data.itemAttributes && data.itemAttributes.length > 0) {
            data.itemAttributes.forEach(function (attr) {
                // [FIX BUG-POS-001] : Ne prendre QUE les attributs de type SAUCE
                var attrName = (attr.name || '').toLowerCase();
                var isSauceAttr = attrName.includes('sauce') || attrName.includes('assaisonnement');
                if (!isSauceAttr) return;   // ← FILTRE: ignorer viandes et autres attributs

                var attrId = attr.id.toString();
                var vars = data.variations && data.variations[attrId] ? data.variations[attrId] : [];
                vars.forEach(function (v) {
                    var name = v.name;
                    dbSauces[name.toLowerCase()] = {
                        id: v.id,
                        name: name,
                        attributeId: attr.id,
                        dbPrice: parseFloat(v.convert_price) || 0,
                        thumb: v.thumb || null
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
                    thumb: dbS.thumb || null,
                    attributeId: dbS.attributeId
                });
            }
        }

        // Prepare extras (garnitures + suppléments)
        var freeExtras = [];
        var paidExtras = [];
        if (data.extras && data.extras.length > 0) {
            data.extras.forEach(function (ex) {
                // [FIX BUG-POS-005] : Exclure les "Sauce supplémentaire" des extras (gérées par étape sauce)
                var exName = (ex.name || '').toLowerCase();
                if (exName.includes('sauce suppl') || exName.startsWith('sauce suppl')) return;

                var price = parseFloat(ex.convert_price) || 0;
                var obj = {
                    id: ex.id,
                    name: ex.name,
                    price: price,
                    currencyPrice: ex.currency_price || fmtPrice(price),
                    thumb: ex.thumb || null
                };
                if (price <= 0) {
                    freeExtras.push(obj);
                } else {
                    paidExtras.push(obj);
                }
            });
        }

        // Prepare menu addons
        var addonItems = [];
        if (data.addons && data.addons.length > 0) {
            addonItems = data.addons.map(function (ad) {
                // [FIX BUG-POS-002] : Utiliser prix UNITAIRE (addon_item_convert_price) pas total (qui peut être ×2)
                var unitPrice = parseFloat(ad.addon_item_convert_price)
                    || parseFloat(ad.addonItem && ad.addonItem.convert_price || 0)
                    || parseFloat(ad.total_convert_price) || 0;
                return {
                    id: ad.id,
                    itemId: ad.addon_item_id || ad.item_addon_id,
                    name: ad.addon_item_name,
                    price: unitPrice,
                    currencyPrice: '€' + unitPrice.toFixed(2),
                    thumb: (ad.addonItem && ad.addonItem.thumb) ? ad.addonItem.thumb : (ad.thumb || ad.cover || '')
                };
            });
        }

        // === NEW COMBINED STEPS ===

        // === Step: Type de Pain (Sandwichs ONLY - Sprint 4) ===
        if (allowed.indexOf('pain') !== -1) {
            // Extract pain/galette variations from attributes
            var painVariations = [];
            if (data.itemAttributes && data.itemAttributes.length > 0) {
                data.itemAttributes.forEach(function (attr) {
                    var attrName = (attr.name || '').toLowerCase();
                    if (attrName.includes('pain') || attrName.includes('galette')) {
                        var attrId = attr.id.toString();
                        var vars = data.variations && data.variations[attrId] ? data.variations[attrId] : [];
                        vars.forEach(function (v) {
                            painVariations.push({
                                id: v.id,
                                name: v.name,
                                emoji: v.name.toLowerCase().includes('galette') ? '🥙' : '🥖',
                                thumb: v.thumb || null,
                                attributeId: attr.id
                            });
                        });
                    }
                });
            }
            // Fallback if no variations found
            if (painVariations.length === 0) {
                painVariations = [
                    { id: 'pain', name: 'Pain', emoji: '🥖', thumb: null },
                    { id: 'galette', name: 'Galette', emoji: '🥙', thumb: null }
                ];
            }
            s.push({
                type: 'pain',
                label: 'Type de Pain',
                subtitle: 'Pain traditionnel ou Galette',
                painItems: painVariations
            });
            selections.pain = painVariations.length > 0 ? painVariations[0].id : null;
        }

        // === Step: Viande + Sauce (Tacos ONLY - split screen) ===
        if (allowed.indexOf('viande_sauce') !== -1 && viandeCount > 0) {
            s.push({
                type: 'viande_sauce',
                label: 'Viande & Sauce',
                subtitle: 'Choisissez ' + viandeCount + ' viande' + (viandeCount > 1 ? 's' : '') + ' + 1ère sauce gratuite',
                maxViandes: viandeCount,
                viandeItems: VIANDES,
                sauceItems: sauceList
            });
            // Init selections
            selections.viandes = {};
            VIANDES.forEach(function (v) { selections.viandes[v.key] = 0; });
            selections.maxViandes = viandeCount;
            selections.totalViandes = 0;
            selections.sauces = {};
            selections.sauceOrder = [];
            if (sauceList.length > 0) {
                selections.sauces[sauceList[0].id] = true;
                selections.sauceOrder = [sauceList[0].id];
                selections.sauceAttrId = sauceList[0].attributeId;
            }
        }

        // === Step: Personnalisation (Garnitures + Suppléments combined) ===
        if (allowed.indexOf('perso') !== -1) {
            s.push({
                type: 'perso',
                label: 'Personnalisation',
                subtitle: 'Garnitures incluses & Suppléments optionnels',
                freeItems: freeExtras,
                paidItems: paidExtras
            });
            selections.garnitures = {};
            freeExtras.forEach(function (g) { selections.garnitures[g.id] = true; });
            selections.supplements = {};
        }

        // === Step: Sauce + Garnitures (Sandwich/Burger - combined) ===
        if (allowed.indexOf('sauce_garnitures') !== -1) {
            s.push({
                type: 'sauce_garnitures',
                label: 'Sauce & Garnitures',
                subtitle: '1ère sauce gratuite + Garnitures incluses',
                sauceItems: sauceList,
                garnitureItems: freeExtras
            });
            selections.sauces = {};
            selections.sauceOrder = [];
            if (sauceList.length > 0) {
                selections.sauces[sauceList[0].id] = true;
                selections.sauceOrder = [sauceList[0].id];
                selections.sauceAttrId = sauceList[0].attributeId;
            }
            selections.garnitures = {};
            freeExtras.forEach(function (g) { selections.garnitures[g.id] = true; });
        }

        // === Step: Suppléments + Menu (Sandwich/Burger - combined) ===
        if (allowed.indexOf('supplements_menu') !== -1) {
            // [BUG-W4 FIX] Find menu addon to get correct price (don't hardcode €3.00)
            var menuAddon = addonItems.find(function (a) {
                return a.name.toLowerCase().includes('menu');
            });
            var fritesAddon = addonItems.find(function (a) {
                return a.name.toLowerCase().includes('frites');
            });
            var boissonAddon = addonItems.find(function (a) {
                return a.name.toLowerCase().includes('boisson') ||
                       a.name.toLowerCase().includes('coca') ||
                       a.name.toLowerCase().includes('fanta') ||
                       a.name.toLowerCase().includes('sprite');
            });

            s.push({
                type: 'supplements_menu',
                label: 'Suppléments & Menu',
                subtitle: 'Ajoutez des extras et choisissez votre formule',
                paidItems: paidExtras,
                menuItems: addonItems,
                sauceItems: sauceList.filter(function (item) { return item.name.toLowerCase() !== 'sans sauce'; }),
                // [BUG-W4 FIX] Include menu prices from DB
                // [BUG-W4b FIX] Use ?? instead of || to allow price = 0 without fallback override
                menuComplet: menuAddon ? { label: 'Menu Complet', price: menuAddon.price ?? 3.00 } : { label: 'Menu Complet', price: 3.00 },
                fritesSeules: fritesAddon ? { label: 'Frites Seules', price: fritesAddon.price ?? 2.50 } : null,
                boissonSeule: boissonAddon ? { label: 'Boisson Seule', price: boissonAddon.price ?? 1.50 } : null
            });
            selections.supplements = {};
            selections.menuChoice = null;
            selections.individualAddons = {};
            selections.sauceFrites = {};
            selections.sauceFritesOrder = [];
        }

        // === Step: Sauce + Accompagnement (Assiette - combined) ===
        if (allowed.indexOf('sauce_accompagnement') !== -1) {
            var accompItems = freeExtras.filter(function (ex) {
                return ex.name.toLowerCase().includes('riz') ||
                    ex.name.toLowerCase().includes('frites') ||
                    ex.name.toLowerCase().includes('salade') ||
                    ex.name.toLowerCase().includes('bourgoul');
            });
            s.push({
                type: 'sauce_accompagnement',
                label: 'Sauce & Accompagnement',
                subtitle: 'Choisissez votre sauce et accompagnement',
                sauceItems: sauceList,
                accompItems: accompItems
            });
            selections.sauces = {};
            selections.sauceOrder = [];
            if (sauceList.length > 0) {
                selections.sauces[sauceList[0].id] = true;
                selections.sauceOrder = [sauceList[0].id];
                selections.sauceAttrId = sauceList[0].attributeId;
            }
            if (accompItems.length > 0) {
                selections.accompagnement = accompItems[0].id;
            }
        }

        // === Step: Suppléments (Assiette - standalone if needed) ===
        if (allowed.indexOf('supplements') !== -1 && paidExtras.length > 0) {
            s.push({
                type: 'supplements',
                label: 'Suppléments',
                subtitle: 'Ajoutez des suppléments',
                items: paidExtras
            });
            selections.supplements = {};
        }

        // === Step: Sauce + Suppléments (Salade - combined) ===
        if (allowed.indexOf('sauce_supplements') !== -1) {
            s.push({
                type: 'sauce_supplements',
                label: 'Sauce & Extras',
                subtitle: '1ère sauce gratuite + Suppléments optionnels',
                sauceItems: sauceList,
                paidItems: paidExtras
            });
            selections.sauces = {};
            selections.sauceOrder = [];
            if (sauceList.length > 0) {
                selections.sauces[sauceList[0].id] = true;
                selections.sauceOrder = [sauceList[0].id];
                selections.sauceAttrId = sauceList[0].attributeId;
            }
            selections.supplements = {};
        }

        // === Step: Sauce Single (Omelette/Snacking - radio) ===
        if (allowed.indexOf('sauce_single') !== -1 && sauceList.length > 0) {
            s.push({
                type: 'sauce_single',
                label: 'Sauce',
                subtitle: 'Choisissez votre sauce',
                items: sauceList
            });
            selections.sauceSingle = sauceList[0].id;
            selections.sauceAttrId = sauceList[0].attributeId;
        }

        // === NEW FLOW: Menu Choice (3 clear options) ===
        if (allowed.indexOf('menu') !== -1 && addonItems.length > 0) {
            // Séparer les types d'addons
            var menuComplet = addonItems.find(function (a) {
                var name = a.name.toLowerCase();
                return name.includes('menu') || (name.includes('frite') && name.includes('boisson'));
            });
            var fritesSeules = addonItems.find(function (a) {
                var name = a.name.toLowerCase();
                return name.includes('frite') && !name.includes('boisson') && !name.includes('menu');
            });
            var boissonSeule = addonItems.find(function (a) {
                var name = a.name.toLowerCase();
                return (name.includes('boisson') || name.includes('coca') || name.includes('jus')) && !name.includes('frite');
            });

            // [P1] Step: menu_choice — 3 options (Menu Complet / Frites / Rien)
            s.push({
                type: 'menu_choice',
                label: 'Formule',
                subtitle: 'Voulez-vous accompagner votre repas ?',
                menuComplet: menuComplet || { name: 'Menu Complet (Frites+Boisson)', price: 3.00, thumb: '' },
                fritesSeules: fritesSeules || { name: 'Frites Seules', price: 1.50, thumb: '' },
                boissonSeule: boissonSeule || { name: 'Boisson Seule', price: 1.50, thumb: '' },
                sauceItems: sauceList.filter(function (item) { return item.name.toLowerCase() !== 'sans sauce'; })
            });

            // [P1] Step: frites_options — Taille + Cheddar (visible si frites choisies)
            s.push({
                type: 'frites_options',
                label: 'Options Frites',
                subtitle: 'Personnalisez vos frites',
                showCondition: 'hasFrites',
                upgradePrice: 1.00,
                cheddarPrice: 1.00
            });

            // [P1] Step: sauce_frites — Sauce pour frites (visible si frites choisies)
            s.push({
                type: 'sauce_frites',
                label: 'Sauce Frites',
                subtitle: '1ère sauce gratuite pour vos frites',
                items: sauceList,
                showCondition: 'hasFrites'
            });

            // [P1] Step: boisson_choice — Choix boisson (visible si menu complet ou boisson seule)
            // [BUG-W5 FIX] Only add boisson_choice step if there are actual boisson items
            var boissonItems = addonItems.filter(function (a) {
                var name = a.name.toLowerCase();
                return (name.includes('boisson') || name.includes('coca') || name.includes('fanta') ||
                    name.includes('sprite') || name.includes('jus') || name.includes('eau')) &&
                    !name.includes('frite');
            });
            if (boissonItems.length > 0) {
                s.push({
                    type: 'boisson_choice',
                    label: 'Boisson',
                    subtitle: 'Choisissez votre boisson',
                    showCondition: 'hasBoisson',
                    boissonItems: boissonItems
                });
            }

            // Init selections avec valeurs par défaut
            selections.menuChoice = 'none';  // Par défaut: pas de formule (user doit choisir)
            selections.fritesGrande = false;  // Portion normale par défaut
            selections.fritesCheddar = false;  // Sans cheddar par défaut
            selections.sauceFrites = {};
            selections.sauceFritesOrder = [];
            selections.boissonChoice = null;
        }

        // === Step: Recap (always last) ===
        s.push({
            type: 'recap',
            label: 'Récap',
            subtitle: 'Vérifiez votre commande',
            editable: true // [NEW] Enable edit buttons
        });

        return s;
    }

    /**
     * Get active steps (filters out conditional steps based on selections)
     */
    function getActiveSteps() {
        return steps.filter(function (step) {
            // [P1] Conditional steps based on user selections
            if (step.showCondition === 'hasFrites') {
                return hasFritesSelected();
            }
            if (step.showCondition === 'hasBoisson') {
                return hasBoissonSelected();
            }
            // Legacy condition
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
            
            // [PLAN_07 UX-03] Badge étape X/Y — affiché sauf sur le récap
            var step = steps[currentStep];
            var isRecap = step.type === 'recap';
            if (!isRecap) {
                var activeStepsCount = getActiveSteps().length;
                var currentStepNum = getActiveStepIndex() + 1;
                // Exclure le récap du total visible (dernière étape = récap)
                var totalStepsWithoutRecap = activeStepsCount - 1;
                html += '<div class="wizard-step-badge">';
                html += '<span class="step-badge-current">' + currentStepNum + '</span>';
                html += '<span class="step-badge-sep">/</span>';
                html += '<span class="step-badge-total">' + totalStepsWithoutRecap + '</span>';
                html += '</div>';
            }
            
            html += '</div>';
        }

        // Progress bar with labels
        var activeSteps = getActiveSteps();
        var activeIdx = getActiveStepIndex();
        var STEP_ICONS = {
            'viande': '🥩', 'sauce': '🥄', 'sauce_single': '🥄',
            'garnitures': '🥬', 'supplements': '➕', 'accompagnement': '🍟',
            'menu': '🍔', 'sauce_frites': '🍟', 'recap': '📋',
            // [NEW SPRINT 4] Combined steps
            'viande_sauce': '🥩🥄', 'perso': '🥬➕', 'sauce_garnitures': '🥄🥬',
            'supplements_menu': '➕🍔', 'sauce_accompagnement': '🥄🍟',
            'sauce_supplements': '🥄➕'
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

        // [REFACTORED SPRINT 4] New combined steps + legacy steps
        if (step.type === 'viande') html += renderViandeStep(step);
        else if (step.type === 'sauce') html += renderSauceStep(step);
        else if (step.type === 'sauce_single') html += renderSauceSingleStep(step);
        else if (step.type === 'accompagnement') html += renderAccompagnementStep(step);
        else if (step.type === 'garnitures') html += renderGarnituresStep(step);
        else if (step.type === 'supplements') html += renderSupplementsStep(step);
        // [P1] NEW MENU FLOW
        else if (step.type === 'menu_choice') html += renderMenuChoiceStep(step);
        else if (step.type === 'frites_options') html += renderFritesOptionsStep(step);
        else if (step.type === 'sauce_frites') html += renderSauceFritesStep(step);
        else if (step.type === 'boisson_choice') html += renderBoissonChoiceStep(step);
        // Legacy menu (fallback)
        else if (step.type === 'menu') html += renderMenuStep(step);
        // === NEW COMBINED STEPS (Sprint 4) ===
        else if (step.type === 'pain') html += renderPainStep(step);
        else if (step.type === 'viande_sauce') html += renderViandeSauceStep(step);
        else if (step.type === 'perso') html += renderPersoStep(step);
        else if (step.type === 'sauce_garnitures') html += renderSauceGarnituresStep(step);
        else if (step.type === 'supplements_menu') html += renderSupplementsMenuStep(step);
        else if (step.type === 'sauce_accompagnement') html += renderSauceAccompagnementStep(step);
        else if (step.type === 'sauce_supplements') html += renderSauceSupplementsStep(step);
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

        // [UI/UX Sprint 4] Limit to 6 sauces visible by default, with "Voir Plus" button
        var limit = 6;
        var totalSauces = step.items.length;
        var hasMoreSauces = totalSauces > limit;

        h += '<div class="wizard-options sauce-grid' + (hasMoreSauces ? ' has-hidden' : '') + '">';
        step.items.forEach(function (sauce, index) {
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

            var hiddenClass = (hasMoreSauces && index >= limit) ? ' hidden-opt' : '';
            h += '<div class="wizard-option sauce-opt micro-opt' + sel + hiddenClass + '" data-type="sauce" data-id="' + sauce.id + '">';
            h += '<span class="check-mark"><i class="fa-solid fa-check"></i></span>';
            h += renderOptionIcon(sauce.thumb, sauce.emoji, true);
            h += '<span class="option-name">' + sauce.name + '</span>';
            h += priceLabel;
            h += '</div>';
        });
        h += '</div>';

        // [UI/UX Sprint 4] Add "Voir Plus" button if more than 6 sauces
        if (hasMoreSauces) {
            var hiddenCount = totalSauces - limit;
            h += '<button type="button" class="btn-voir-plus" onclick="var grid=this.previousElementSibling; grid.classList.toggle(\'expanded\'); this.innerHTML=grid.classList.contains(\'expanded\') ? \'▲ Masquer\' : \'▼ Voir tous (+' + hiddenCount + ')\';">▼ Voir tous (+' + hiddenCount + ')</button>';
        }
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
        // Supplements — [FIX BUG-POS-004] : Recherche dans toutes les étapes possibles (perso, supplements_menu, etc.)
        if (selections.supplements) {
            // Chercher dans toutes les étapes qui ont des paidItems ou items
            for (var si = 0; si < steps.length; si++) {
                var step = steps[si];
                var allPaidItems = (step.paidItems || []).concat(step.items || []);
                for (var pi = 0; pi < allPaidItems.length; pi++) {
                    var item = allPaidItems[pi];
                    if (selections.supplements[item.id]) {
                        extra += (item.price || 0);
                    }
                }
            }
        }
        // Sauce frites extra
        if (selections.sauceFritesOrder && selections.sauceFritesOrder.length > 1) {
            extra += (selections.sauceFritesOrder.length - 1) * SAUCE_EXTRA_PRICE;
        }
        // Addons — [FIX] Support both legacy 'menu' AND new 'menu_choice' step types
        // [W-6 FIX] Also support 'supplements_menu' step type for Sandwich/Burger flow
        var addonTotal = 0;
        var menuStep = steps.find(function (s) {
            return s.type === 'menu' || s.type === 'menu_choice' || s.type === 'supplements_menu';
        });
        if (menuStep) {
            // New menu_choice format (Sprint 4+)
            if (selections.menuChoice === 'full' && menuStep.menuComplet) {
                addonTotal += menuStep.menuComplet.price;
            } else if (selections.menuChoice === 'frites' && menuStep.fritesSeules) {
                addonTotal += menuStep.fritesSeules.price;
            } else if (selections.menuChoice === 'boisson' && menuStep.boissonSeule) {
                addonTotal += menuStep.boissonSeule.price;
            } else if (selections.menuChoice === 'full' && menuStep.items) {
                // Legacy fallback
                menuStep.items.forEach(function (a) { addonTotal += a.price; });
            } else if (selections.individualAddons && (menuStep.items || menuStep.menuItems)) {
                var addonItems = menuStep.items || menuStep.menuItems;
                addonItems.forEach(function (a) {
                    if (selections.individualAddons[a.id]) addonTotal += a.price;
                });
            }
            // Frites upgrade options
            if (selections.fritesGrande) addonTotal += 1.00;
            if (selections.fritesCheddar) addonTotal += 1.00;
        }
        // [S21-2 FIX] addonTotal must be multiplied by itemQuantity — formule price applies per item
        return (basePrice + extra + addonTotal) * itemQuantity;
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
            h += renderOptionIcon(sauce.thumb, sauce.emoji);
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
            h += renderOptionIcon(item.thumb, getEmoji(GARNITURE_EMOJIS, item.name));
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
            h += '<div class="wizard-option garniture micro-opt' + sel + '" data-type="garniture" data-id="' + item.id + '">';
            h += '<span class="check-mark"><i class="fa-solid fa-check"></i></span>';
            h += renderOptionIcon(item.thumb, getEmoji(GARNITURE_EMOJIS, item.name), true);
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
            h += '<div class="wizard-option micro-opt' + sel + '" data-type="supplement" data-id="' + item.id + '">';
            h += '<span class="check-mark"><i class="fa-solid fa-check"></i></span>';
            h += renderOptionIcon(item.thumb, getEmoji(SUPPLEMENT_EMOJIS, item.name), true);
            h += '<span class="option-name">' + item.name + '</span>';
            h += '<span class="option-price paid">+' + item.currencyPrice + '</span>';
            h += '</div>';
        });
        h += '</div>';
        return h;
    }

    // [P1] ---- MENU CHOICE (3 clear options: Menu Complet / Frites / Rien) ----
    function renderMenuChoiceStep(step) {
        var h = '<div class="wizard-menu-choice-container">';
        h += '<h4 class="menu-choice-title">🍟🥤 Choisissez votre formule</h4>';

        h += '<div class="wizard-menu-choice-grid">';

        // Option 1: Menu Complet
        var selFull = selections.menuChoice === 'full' ? ' selected' : '';
        h += '<div class="menu-choice-card' + selFull + '" data-action="menu-choice" data-value="full">';
        h += '<div class="menu-card-icon">' + renderOptionIcon(step.menuComplet.thumb, '🍟🥤') + '</div>';
        h += '<div class="menu-card-name">' + step.menuComplet.name + '</div>';
        h += '<div class="menu-card-price">+' + fmtPrice(step.menuComplet.price) + '</div>';
        h += '<div class="menu-card-desc">Frites + Boisson incluse</div>';
        h += '</div>';

        // Option 2: Frites Seules
        var selFrites = selections.menuChoice === 'frites' ? ' selected' : '';
        h += '<div class="menu-choice-card' + selFrites + '" data-action="menu-choice" data-value="frites">';
        h += '<div class="menu-card-icon">' + renderOptionIcon(step.fritesSeules.thumb, '🍟') + '</div>';
        h += '<div class="menu-card-name">' + step.fritesSeules.name + '</div>';
        h += '<div class="menu-card-price">+' + fmtPrice(step.fritesSeules.price) + '</div>';
        h += '<div class="menu-card-desc">Juste les frites</div>';
        h += '</div>';

        // [S21-6 FIX] Option 3: Boisson Seule (was built in step but never rendered)
        if (step.boissonSeule) {
            var selBoisson = selections.menuChoice === 'boisson' ? ' selected' : '';
            h += '<div class="menu-choice-card' + selBoisson + '" data-action="menu-choice" data-value="boisson">';
            h += '<div class="menu-card-icon">' + renderOptionIcon(step.boissonSeule.thumb, '🥤') + '</div>';
            h += '<div class="menu-card-name">' + step.boissonSeule.name + '</div>';
            h += '<div class="menu-card-price">+' + fmtPrice(step.boissonSeule.price) + '</div>';
            h += '<div class="menu-card-desc">Juste la boisson</div>';
            h += '</div>';
        }

        // Option 4: Non merci
        var selNone = selections.menuChoice === 'none' ? ' selected' : '';
        h += '<div class="menu-choice-card' + selNone + '" data-action="menu-choice" data-value="none">';
        h += '<div class="menu-card-icon">🚫</div>';
        h += '<div class="menu-card-name">Non merci</div>';
        h += '<div class="menu-card-price">—</div>';
        h += '<div class="menu-card-desc">Sans accompagnement</div>';
        h += '</div>';

        h += '</div>'; // .wizard-menu-choice-grid
        h += '</div>'; // .wizard-menu-choice-container
        return h;
    }

    // [P1] ---- FRITES OPTIONS (Taille + Cheddar) ----
    function renderFritesOptionsStep(step) {
        var h = '<div class="wizard-frites-options-container">';

        // Section: Taille des frites
        h += '<div class="frites-section">';
        h += '<h4 class="frites-title">🍟 Taille des frites</h4>';
        h += '<div class="frites-size-row">';

        var selNormal = !selections.fritesGrande ? ' selected' : '';
        h += '<div class="frites-option' + selNormal + '" data-action="frites-size" data-value="normal">';
        h += '<span class="frites-opt-label">🍟 Portion Normale</span>';
        h += '<span class="frites-opt-price">Incluse</span>';
        h += '</div>';

        var selGrande = selections.fritesGrande ? ' selected' : '';
        h += '<div class="frites-option' + selGrande + '" data-action="frites-size" data-value="grande">';
        h += '<span class="frites-opt-label">🍟🍟 Grande Portion</span>';
        h += '<span class="frites-opt-price upgrade">+' + fmtPrice(step.upgradePrice) + '</span>';
        h += '</div>';

        h += '</div>'; // .frites-size-row
        h += '</div>'; // .frites-section

        // Section: Supplément Cheddar
        h += '<div class="frites-section">';
        h += '<h4 class="frites-title">🧀 Supplément Cheddar</h4>';
        h += '<div class="frites-size-row">';

        var selNoCheddar = !selections.fritesCheddar ? ' selected' : '';
        h += '<div class="frites-option' + selNoCheddar + '" data-action="frites-cheddar" data-value="no">';
        h += '<span class="frites-opt-label">Sans Cheddar</span>';
        h += '<span class="frites-opt-price">Inclus</span>';
        h += '</div>';

        var selCheddar = selections.fritesCheddar ? ' selected' : '';
        h += '<div class="frites-option' + selCheddar + '" data-action="frites-cheddar" data-value="yes">';
        h += '<span class="frites-opt-label">🧀 Avec Cheddar Fondu</span>';
        h += '<span class="frites-opt-price upgrade">+' + fmtPrice(step.cheddarPrice) + '</span>';
        h += '</div>';

        h += '</div>'; // .frites-size-row
        h += '</div>'; // .frites-section

        h += '</div>'; // .wizard-frites-options-container
        return h;
    }

    // [P1] ---- BOISSON CHOICE ----
    function renderBoissonChoiceStep(step) {
        var h = '<div class="wizard-boisson-container">';
        h += '<h4 class="boisson-title">🥤 Choisissez votre boisson</h4>';

        h += '<div class="wizard-options boisson-grid">';

        // Option "Sans boisson"
        var selNone = selections.boissonChoice === 'none' ? ' selected' : '';
        h += '<div class="wizard-option boisson-opt' + selNone + '" data-action="boisson-choice" data-value="none">';
        h += '<span class="check-mark"><i class="fa-solid fa-check"></i></span>';
        h += '<span class="option-icon">🚫</span>';
        h += '<span class="option-name">Sans boisson</span>';
        h += '<span class="option-price free">—</span>';
        h += '</div>';

        // Liste des boissons disponibles
        if (step.boissonItems && step.boissonItems.length > 0) {
            step.boissonItems.forEach(function (boisson) {
                var sel = selections.boissonChoice === boisson.id ? ' selected' : '';
                h += '<div class="wizard-option boisson-opt' + sel + '" data-action="boisson-choice" data-id="' + boisson.id + '">';
                h += '<span class="check-mark"><i class="fa-solid fa-check"></i></span>';
                if (boisson.thumb) {
                    h += '<span class="option-icon has-img"><img src="' + boisson.thumb + '" alt="' + boisson.name + '"></span>';
                } else {
                    h += '<span class="option-icon">' + getEmoji(ADDON_EMOJIS, boisson.name) + '</span>';
                }
                h += '<span class="option-name">' + boisson.name + '</span>';
                h += '<span class="option-price free">Incluse</span>';
                h += '</div>';
            });
        }

        h += '</div>'; // .wizard-options
        h += '</div>'; // .wizard-boisson-container
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
            h += renderOptionIcon(sauce.thumb, sauce.emoji);
            h += '<span class="option-name">' + sauce.name + '</span>';
            h += priceLabel;
            h += '</div>';
        });
        h += '</div>';
        return h;
    }

    // ============================================================================
    // [NEW SPRINT 4] COMBINED STEP RENDERERS
    // ============================================================================

    // ---- TYPE DE PAIN (Sandwichs - Sprint 4) ----
    function renderPainStep(step) {
        var h = '';
        h += '<div class="wizard-pain-question">';
        h += '<h4>🥖 Quel type de pain ?</h4>';
        h += '</div>';
        h += '<div class="wizard-options pain-grid">';
        step.painItems.forEach(function (pain) {
            var sel = selections.pain === pain.id ? ' selected' : '';
            h += '<div class="wizard-option pain-opt' + sel + '" data-type="pain" data-id="' + pain.id + '">';
            h += '<span class="check-mark"><i class="fa-solid fa-check"></i></span>';
            h += '<span class="option-icon" style="font-size: 36px; margin-bottom: 8px;">' + pain.emoji + '</span>';
            h += '<span class="option-name">' + pain.name + '</span>';
            h += '<span class="option-price free">Inclus</span>';
            h += '</div>';
        });
        h += '</div>';
        return h;
    }

    // ---- VIANDE + SAUCE (Split-screen for Tacos) ----
    function renderViandeSauceStep(step) {
        var h = '';
        h += '<div class="wizard-split">';

        // LEFT: Viandes
        h += '<div class="wizard-col">';
        h += '<h4>🥩 ' + step.label + '</h4>';
        var total = selections.totalViandes || 0;
        var max = step.maxViandes;
        h += '<div class="wizard-viande-counter-header">';
        h += '<span class="viande-total ' + (total === max ? 'complete' : '') + '">' + total + ' / ' + max + '</span>';
        if (total === max) h += '<span class="viande-complete-badge">✅ Complet</span>';
        h += '</div>';

        // [UI/UX Sprint 4] Limit to 4 meats visible by default, with "Voir Plus" button
        var viandeLimit = 4;
        var totalViandes = step.viandeItems.length;
        var hasMoreViandes = totalViandes > viandeLimit;

        h += '<div class="wizard-viande-list' + (hasMoreViandes ? ' has-hidden' : '') + '">';
        step.viandeItems.forEach(function (viande, index) {
            var count = selections.viandes[viande.key] || 0;
            var canAdd = total < max;
            var hiddenClass = (hasMoreViandes && index >= viandeLimit) ? ' hidden-opt' : '';
            h += '<div class="wizard-viande-row' + (count > 0 ? ' active' : '') + hiddenClass + '">';
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

        // [UI/UX Sprint 4] Add "Voir Plus" button for viandes if more than 4
        if (hasMoreViandes) {
            var hiddenViandeCount = totalViandes - viandeLimit;
            h += '<button type="button" class="btn-voir-plus viande-voir-plus" onclick="var list=this.previousElementSibling; list.classList.toggle(\'expanded\'); this.innerHTML=list.classList.contains(\'expanded\') ? \'▲ Masquer\' : \'▼ Voir tous (+' + hiddenViandeCount + ')\';">▼ Voir tous (+' + hiddenViandeCount + ')</button>';
        }
        h += '</div>'; // .wizard-col

        // RIGHT: Sauces
        h += '<div class="wizard-col">';
        h += '<h4>🥄 Sauce (1ère gratuite)</h4>';
        var sauceCount = selections.sauceOrder ? selections.sauceOrder.length : 0;
        h += '<div class="wizard-sauce-info">';
        if (sauceCount === 0) {
            h += '<span class="sauce-badge free">Sélectionnez une sauce</span>';
        } else if (sauceCount === 1) {
            h += '<span class="sauce-badge free">✅ 1 sauce (gratuite)</span>';
        } else {
            var extraCost = (sauceCount - 1) * SAUCE_EXTRA_PRICE;
            h += '<span class="sauce-badge paid">' + sauceCount + ' sauces = +' + fmtPrice(extraCost) + '</span>';
        }
        h += '</div>';
        h += '<div class="wizard-options sauce-grid compact">';
        step.sauceItems.forEach(function (sauce) {
            var sel = selections.sauces && selections.sauces[sauce.id] ? ' selected' : '';
            var idx = selections.sauceOrder ? selections.sauceOrder.indexOf(sauce.id) : -1;
            var priceLabel = '';
            if (idx === 0) priceLabel = '<span class="option-price free">Gratuit</span>';
            else if (idx > 0) priceLabel = '<span class="option-price paid">+€0.50</span>';
            else priceLabel = '<span class="option-price">' + (sauceCount === 0 ? 'Gratuit' : '+€0.50') + '</span>';

            h += '<div class="wizard-option sauce-opt' + sel + '" data-type="sauce" data-id="' + sauce.id + '">';
            h += '<span class="check-mark"><i class="fa-solid fa-check"></i></span>';
            h += renderOptionIcon(sauce.thumb, sauce.emoji);
            h += '<span class="option-name">' + sauce.name + '</span>';
            h += priceLabel;
            h += '</div>';
        });
        h += '</div>';
        h += '</div>'; // .wizard-col

        h += '</div>'; // .wizard-split
        return h;
    }

    // ---- PERSONNALISATION (Garnitures + Suppléments combined) ----
    function renderPersoStep(step) {
        var h = '';

        // Section 1: Garnitures (toggle style)
        if (step.freeItems && step.freeItems.length > 0) {
            h += '<div class="wizard-section">';
            h += '<h4>🥬 Garnitures (tout inclus)</h4>';
            h += '<p class="wizard-hint">Décochez ce que vous ne voulez pas</p>';
            h += '<div class="garniture-toggle">';
            step.freeItems.forEach(function (g) {
                var sel = selections.garnitures && selections.garnitures[g.id] ? ' active' : '';
                var emoji = getEmoji(GARNITURE_EMOJIS, g.name);
                h += '<button type="button" class="garniture-toggle-btn' + sel + '" data-type="garniture" data-id="' + g.id + '">';
                h += emoji + ' ' + g.name;
                h += '</button>';
            });
            h += '</div>';
            h += '</div>';
        }

        // Section 2: Suppléments
        if (step.paidItems && step.paidItems.length > 0) {
            h += '<div class="wizard-section">';
            h += '<h4>➕ Suppléments (payants)</h4>';
            h += '<div class="wizard-options supplement-grid">';
            step.paidItems.forEach(function (s) {
                var sel = selections.supplements && selections.supplements[s.id] ? ' selected' : '';
                var emoji = getEmoji(SUPPLEMENT_EMOJIS, s.name);
                h += '<div class="wizard-option supplement-opt' + sel + '" data-type="supplement" data-id="' + s.id + '">';
                h += '<span class="check-mark"><i class="fa-solid fa-check"></i></span>';
                h += renderOptionIcon(s.thumb, emoji);
                h += '<span class="option-name">' + s.name + '</span>';
                h += '<span class="option-price">' + s.currencyPrice + '</span>';
                h += '</div>';
            });
            h += '</div>';
            h += '</div>';
        }

        return h;
    }

    // ---- SAUCE + GARNITURES (Sandwich/Burger combined) ----
    function renderSauceGarnituresStep(step) {
        var h = '';
        h += '<div class="wizard-split">';

        // LEFT: Sauce
        h += '<div class="wizard-col">';
        h += '<h4>🥄 Sauce (1ère gratuite)</h4>';
        var sauceCount = selections.sauceOrder ? selections.sauceOrder.length : 0;
        h += '<div class="wizard-sauce-info">';
        if (sauceCount === 0) h += '<span class="sauce-badge free">Sélectionnez une sauce</span>';
        else if (sauceCount === 1) h += '<span class="sauce-badge free">✅ 1 sauce (gratuite)</span>';
        else {
            var extraCost = (sauceCount - 1) * SAUCE_EXTRA_PRICE;
            h += '<span class="sauce-badge paid">' + sauceCount + ' sauces = +' + fmtPrice(extraCost) + '</span>';
        }
        h += '</div>';
        h += '<div class="wizard-options sauce-grid compact">';
        step.sauceItems.forEach(function (sauce) {
            var sel = selections.sauces && selections.sauces[sauce.id] ? ' selected' : '';
            var idx = selections.sauceOrder ? selections.sauceOrder.indexOf(sauce.id) : -1;
            var priceLabel = '';
            if (idx === 0) priceLabel = '<span class="option-price free">Gratuit</span>';
            else if (idx > 0) priceLabel = '<span class="option-price paid">+€0.50</span>';
            else priceLabel = '<span class="option-price">' + (sauceCount === 0 ? 'Gratuit' : '+€0.50') + '</span>';

            h += '<div class="wizard-option sauce-opt' + sel + '" data-type="sauce" data-id="' + sauce.id + '">';
            h += '<span class="check-mark"><i class="fa-solid fa-check"></i></span>';
            h += renderOptionIcon(sauce.thumb, sauce.emoji);
            h += '<span class="option-name">' + sauce.name + '</span>';
            h += priceLabel;
            h += '</div>';
        });
        h += '</div>';
        h += '</div>';

        // RIGHT: Garnitures
        h += '<div class="wizard-col">';
        h += '<h4>🥬 Garnitures</h4>';
        h += '<p class="wizard-hint">Décochez ce que vous ne voulez pas</p>';
        h += '<div class="garniture-toggle">';
        step.garnitureItems.forEach(function (g) {
            var sel = selections.garnitures && selections.garnitures[g.id] ? ' active' : '';
            var emoji = getEmoji(GARNITURE_EMOJIS, g.name);
            h += '<button type="button" class="garniture-toggle-btn' + sel + '" data-type="garniture" data-id="' + g.id + '">';
            h += emoji + ' ' + g.name;
            h += '</button>';
        });
        h += '</div>';
        h += '</div>';

        h += '</div>';
        return h;
    }

    // ---- SUPPLÉMENTS + MENU (combined with inline sauce frites) ----
    function renderSupplementsMenuStep(step) {
        var h = '';

        // Section 1: Suppléments
        if (step.paidItems && step.paidItems.length > 0) {
            h += '<div class="wizard-section">';
            h += '<h4>➕ Suppléments</h4>';
            h += '<div class="wizard-options supplement-grid">';
            step.paidItems.forEach(function (s) {
                var sel = selections.supplements && selections.supplements[s.id] ? ' selected' : '';
                var emoji = getEmoji(SUPPLEMENT_EMOJIS, s.name);
                h += '<div class="wizard-option supplement-opt' + sel + '" data-type="supplement" data-id="' + s.id + '">';
                h += '<span class="check-mark"><i class="fa-solid fa-check"></i></span>';
                h += renderOptionIcon(s.thumb, emoji);
                h += '<span class="option-name">' + s.name + '</span>';
                h += '<span class="option-price">' + s.currencyPrice + '</span>';
                h += '</div>';
            });
            h += '</div>';
            h += '</div>';
        }

        // Section 2: Menu
        if (step.menuItems && step.menuItems.length > 0) {
            h += '<div class="wizard-section">';
            h += '<h4>🍔 Formule ?</h4>';
            h += '<div class="wizard-menu-options">';

            // Full menu option
            // [BUG-W4 FIX] Use menuComplet.price from DB instead of hardcoded €3.00
            var menuPrice = (step.menuComplet && step.menuComplet.price) ? step.menuComplet.price.toFixed(2) : '3.00';
            h += '<div class="wizard-menu-card' + (selections.menuChoice === 'full' ? ' selected' : '') + '" data-menu="full">';
            h += '<div class="menu-icon">🍟🥤</div>';
            h += '<div class="menu-name">Menu Complet</div>';
            h += '<div class="menu-price">+€' + menuPrice + '</div>';
            h += '<div class="menu-desc">Frites + Boisson</div>';
            h += '</div>';

            // Individual options
            step.menuItems.forEach(function (addon) {
                h += '<div class="wizard-menu-card' + (selections.individualAddons && selections.individualAddons[addon.id] ? ' selected' : '') + '" data-addon-id="' + addon.id + '">';
                h += '<div class="menu-icon">' + (addon.name.toLowerCase().includes('frites') ? '🍟' : '🥤') + '</div>';
                h += '<div class="menu-name">' + addon.name + '</div>';
                h += '<div class="menu-price">' + addon.currencyPrice + '</div>';
                h += '</div>';
            });

            // None option
            h += '<div class="wizard-menu-card' + (selections.menuChoice === 'none' ? ' selected' : '') + '" data-menu="none">';
            h += '<div class="menu-icon">🚫</div>';
            h += '<div class="menu-name">Aucun</div>';
            h += '<div class="menu-price">-</div>';
            h += '</div>';

            h += '</div>';
            h += '</div>';

            // INLINE: Frites Options (visible only if frites selected) — [S21-3 FIX] Cheddar & Grande for Sandwich flow
            var fritesSelected = selections.menuChoice === 'full' ||
                (selections.individualAddons && step.menuItems.some(function (a) {
                    return selections.individualAddons[a.id] && a.name.toLowerCase().includes('frite');
                }));

            h += '<div class="frites-options-inline' + (fritesSelected ? ' visible' : '') + '">';
            h += '<h5>🍟 Options pour vos frites</h5>';
            h += '<div class="wizard-frites-options compact">';
            // Grande portion toggle
            h += '<div class="wizard-option frites-opt' + (selections.fritesGrande ? ' selected' : '') + '" data-action="frites-size" data-value="grande">';
            h += '<span class="check-mark"><i class="fa-solid fa-check"></i></span>';
            h += '<span class="option-name">Grande Portion (+€1.00)</span>';
            h += '</div>';
            h += '<div class="wizard-option frites-opt' + (!selections.fritesGrande ? ' selected' : '') + '" data-action="frites-size" data-value="normal">';
            h += '<span class="check-mark"><i class="fa-solid fa-check"></i></span>';
            h += '<span class="option-name">Portion Normale (incluse)</span>';
            h += '</div>';
            // Cheddar toggle
            h += '<div class="wizard-option frites-opt' + (selections.fritesCheddar ? ' selected' : '') + '" data-action="frites-cheddar" data-value="yes">';
            h += '<span class="check-mark"><i class="fa-solid fa-check"></i></span>';
            h += '<span class="option-name">Avec Cheddar Fondu (+€1.00)</span>';
            h += '</div>';
            h += '<div class="wizard-option frites-opt' + (!selections.fritesCheddar ? ' selected' : '') + '" data-action="frites-cheddar" data-value="no">';
            h += '<span class="check-mark"><i class="fa-solid fa-check"></i></span>';
            h += '<span class="option-name">Sans Cheddar (inclus)</span>';
            h += '</div>';
            h += '</div>';
            h += '</div>';

            // INLINE: Sauce Frites (visible only if frites selected)
            h += '<div class="sauce-frites-inline' + (fritesSelected ? ' visible' : '') + '">';
            h += '<h5>🍟 Sauce pour vos frites</h5>';
            var sauceFCount = selections.sauceFritesOrder ? selections.sauceFritesOrder.length : 0;
            h += '<div class="wizard-sauce-info">';
            if (sauceFCount === 0) h += '<span class="sauce-badge free">Sélectionnez une sauce</span>';
            else if (sauceFCount === 1) h += '<span class="sauce-badge free">✅ 1 sauce (gratuite)</span>';
            else {
                var extraCost = (sauceFCount - 1) * SAUCE_EXTRA_PRICE;
                h += '<span class="sauce-badge paid">' + sauceFCount + ' sauces = +' + fmtPrice(extraCost) + '</span>';
            }
            h += '</div>';
            h += '<div class="wizard-options sauce-grid compact">';
            step.sauceItems.forEach(function (sauce) {
                var sel = selections.sauceFrites && selections.sauceFrites[sauce.id] ? ' selected' : '';
                var idx = selections.sauceFritesOrder ? selections.sauceFritesOrder.indexOf(sauce.id) : -1;
                var priceLabel = '';
                if (idx === 0) priceLabel = '<span class="option-price free">Gratuit</span>';
                else if (idx > 0) priceLabel = '<span class="option-price paid">+€0.50</span>';
                else priceLabel = '<span class="option-price">' + (sauceFCount === 0 ? 'Gratuit' : '+€0.50') + '</span>';

                h += '<div class="wizard-option sauce-frite-opt' + sel + '" data-type="sauce_frite" data-id="' + sauce.id + '">';
                h += '<span class="check-mark"><i class="fa-solid fa-check"></i></span>';
                h += renderOptionIcon(sauce.thumb, sauce.emoji);
                h += '<span class="option-name">' + sauce.name + '</span>';
                h += priceLabel;
                h += '</div>';
            });
            h += '</div>';
            h += '</div>';
        }

        return h;
    }

    // ---- SAUCE + ACCOMPAGNEMENT (Assiettes combined) ----
    function renderSauceAccompagnementStep(step) {
        var h = '';
        h += '<div class="wizard-split">';

        // LEFT: Sauce
        h += '<div class="wizard-col">';
        h += '<h4>🥄 Sauce (1ère gratuite)</h4>';
        var sauceCount = selections.sauceOrder ? selections.sauceOrder.length : 0;
        h += '<div class="wizard-sauce-info">';
        if (sauceCount === 0) h += '<span class="sauce-badge free">Sélectionnez une sauce</span>';
        else if (sauceCount === 1) h += '<span class="sauce-badge free">✅ 1 sauce (gratuite)</span>';
        else {
            var extraCost = (sauceCount - 1) * SAUCE_EXTRA_PRICE;
            h += '<span class="sauce-badge paid">' + sauceCount + ' sauces = +' + fmtPrice(extraCost) + '</span>';
        }
        h += '</div>';
        h += '<div class="wizard-options sauce-grid">';
        step.sauceItems.forEach(function (sauce) {
            var sel = selections.sauces && selections.sauces[sauce.id] ? ' selected' : '';
            var idx = selections.sauceOrder ? selections.sauceOrder.indexOf(sauce.id) : -1;
            var priceLabel = '';
            if (idx === 0) priceLabel = '<span class="option-price free">Gratuit</span>';
            else if (idx > 0) priceLabel = '<span class="option-price paid">+€0.50</span>';
            else priceLabel = '<span class="option-price">' + (sauceCount === 0 ? 'Gratuit' : '+€0.50') + '</span>';

            h += '<div class="wizard-option sauce-opt' + sel + '" data-type="sauce" data-id="' + sauce.id + '">';
            h += '<span class="check-mark"><i class="fa-solid fa-check"></i></span>';
            h += renderOptionIcon(sauce.thumb, sauce.emoji);
            h += '<span class="option-name">' + sauce.name + '</span>';
            h += priceLabel;
            h += '</div>';
        });
        h += '</div>';
        h += '</div>';

        // RIGHT: Accompagnement (radio)
        h += '<div class="wizard-col">';
        h += '<h4>🍟 Accompagnement</h4>';
        if (step.accompItems && step.accompItems.length > 0) {
            step.accompItems.forEach(function (acc) {
                var sel = selections.accompagnement === acc.id ? ' selected' : '';
                var emoji = acc.name.toLowerCase().includes('riz') ? '🍚' : (acc.name.toLowerCase().includes('frite') ? '🍟' : '🥗');
                h += '<div class="wizard-option radio-opt' + sel + '" data-type="accompagnement" data-id="' + acc.id + '">';
                h += '<span class="radio-mark"><i class="fa-solid fa-circle-dot"></i></span>';
                h += renderOptionIcon(acc.thumb, emoji);
                h += '<span class="option-name">' + acc.name + '</span>';
                h += '<span class="option-price">Inclus</span>';
                h += '</div>';
            });
        }
        h += '</div>';

        h += '</div>';
        return h;
    }

    // ---- SAUCE + SUPPLÉMENTS (Salades combined) ----
    function renderSauceSupplementsStep(step) {
        var h = '';

        // Section 1: Sauce
        h += '<div class="wizard-section">';
        h += '<h4>🥄 Sauce (1ère gratuite)</h4>';
        var sauceCount = selections.sauceOrder ? selections.sauceOrder.length : 0;
        h += '<div class="wizard-sauce-info">';
        if (sauceCount === 0) h += '<span class="sauce-badge free">Sélectionnez une sauce</span>';
        else if (sauceCount === 1) h += '<span class="sauce-badge free">✅ 1 sauce (gratuite)</span>';
        else {
            var extraCost = (sauceCount - 1) * SAUCE_EXTRA_PRICE;
            h += '<span class="sauce-badge paid">' + sauceCount + ' sauces = +' + fmtPrice(extraCost) + '</span>';
        }
        h += '</div>';
        h += '<div class="wizard-options sauce-grid">';
        step.sauceItems.forEach(function (sauce) {
            var sel = selections.sauces && selections.sauces[sauce.id] ? ' selected' : '';
            var idx = selections.sauceOrder ? selections.sauceOrder.indexOf(sauce.id) : -1;
            var priceLabel = '';
            if (idx === 0) priceLabel = '<span class="option-price free">Gratuit</span>';
            else if (idx > 0) priceLabel = '<span class="option-price paid">+€0.50</span>';
            else priceLabel = '<span class="option-price">' + (sauceCount === 0 ? 'Gratuit' : '+€0.50') + '</span>';

            h += '<div class="wizard-option sauce-opt' + sel + '" data-type="sauce" data-id="' + sauce.id + '">';
            h += '<span class="check-mark"><i class="fa-solid fa-check"></i></span>';
            h += renderOptionIcon(sauce.thumb, sauce.emoji);
            h += '<span class="option-name">' + sauce.name + '</span>';
            h += priceLabel;
            h += '</div>';
        });
        h += '</div>';
        h += '</div>';

        // Section 2: Suppléments (if any)
        if (step.paidItems && step.paidItems.length > 0) {
            h += '<div class="wizard-section">';
            h += '<h4>➕ Suppléments optionnels</h4>';
            h += '<div class="wizard-options supplement-grid">';
            step.paidItems.forEach(function (s) {
                var sel = selections.supplements && selections.supplements[s.id] ? ' selected' : '';
                var emoji = getEmoji(SUPPLEMENT_EMOJIS, s.name);
                h += '<div class="wizard-option supplement-opt' + sel + '" data-type="supplement" data-id="' + s.id + '">';
                h += '<span class="check-mark"><i class="fa-solid fa-check"></i></span>';
                h += renderOptionIcon(s.thumb, emoji);
                h += '<span class="option-name">' + s.name + '</span>';
                h += '<span class="option-price">' + s.currencyPrice + '</span>';
                h += '</div>';
            });
            h += '</div>';
            h += '</div>';
        }

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

        // [REFACTORED SPRINT 4] Helper for edit button
        function editBtn(stepType) {
            return '<button type="button" class="edit-step-btn" data-goto="' + stepType + '">✏️</button>';
        }

        // Pain (Sandwichs - Sprint 4)
        if (selections.pain) {
            var painStep = steps.find(function (s) { return s.type === 'pain'; });
            if (painStep) {
                var painItem = painStep.painItems.find(function (p) { return p.id === selections.pain; });
                if (painItem) {
                    h += '<div class="wizard-recap-row"><span class="label">' + painItem.emoji + ' Pain' + editBtn('pain') + '</span><span class="value">' + painItem.name + '</span></div>';
                }
            }
        }

        // Viandes (from viande or viande_sauce step)
        var viandeStep = steps.find(function (s) { return s.type === 'viande' || s.type === 'viande_sauce'; });
        if (selections.viandes && selections.totalViandes > 0) {
            var viandeNames = [];
            var viandeItems = viandeStep ? (viandeStep.items || viandeStep.viandeItems) : VIANDES;
            viandeItems.forEach(function (v) {
                var count = selections.viandes[v.key] || 0;
                if (count > 0) {
                    viandeNames.push(count > 1 ? count + '× ' + v.name : v.name);
                }
            });
            if (viandeNames.length > 0) {
                var viandeGoto = steps.find(function (s) { return s.type === 'viande_sauce'; }) ? 'viande_sauce' : 'viande';
                h += '<div class="wizard-recap-row"><span class="label">🥩 Viandes' + editBtn(viandeGoto) + '</span><span class="value">' +
                    viandeNames.join(', ') + '</span></div>';
            }
        }

        // Sauces (from sauce, viande_sauce, sauce_garnitures, or sauce_supplements step)
        var hasSauceStep = steps.some(function (s) {
            return ['sauce', 'viande_sauce', 'sauce_garnitures', 'sauce_accompagnement', 'sauce_supplements'].indexOf(s.type) !== -1;
        });
        if (hasSauceStep && selections.sauceOrder && selections.sauceOrder.length > 0) {
            var sauceStep = steps.find(function (s) {
                return s.type === 'sauce' || s.type === 'viande_sauce' || s.type === 'sauce_garnitures' ||
                    s.type === 'sauce_accompagnement' || s.type === 'sauce_supplements';
            });
            var sauceItems = sauceStep ? (sauceStep.items || sauceStep.sauceItems) : [];
            var sauceNames = [];
            selections.sauceOrder.forEach(function (id, idx) {
                var sauce = sauceItems.find(function (s) { return s.id === id; });
                if (sauce) {
                    if (idx === 0) {
                        sauceNames.push(sauce.name + ' (gratuit)');
                    } else {
                        sauceNames.push(sauce.name + ' (+€0.50)');
                        totalExtra += SAUCE_EXTRA_PRICE;
                    }
                }
            });
            if (sauceNames.length > 0) {
                var sauceGoto = 'sauce';
                if (steps.find(function (s) { return s.type === 'viande_sauce'; })) sauceGoto = 'viande_sauce';
                else if (steps.find(function (s) { return s.type === 'sauce_garnitures'; })) sauceGoto = 'sauce_garnitures';
                else if (steps.find(function (s) { return s.type === 'sauce_accompagnement'; })) sauceGoto = 'sauce_accompagnement';
                else if (steps.find(function (s) { return s.type === 'sauce_supplements'; })) sauceGoto = 'sauce_supplements';
                h += '<div class="wizard-recap-row"><span class="label">🥄 Sauce' + editBtn(sauceGoto) + '</span><span class="value">' +
                    sauceNames.join(', ') + '</span></div>';
            }
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
                    h += '<div class="wizard-recap-row"><span class="label">🥄 Sauce' + editBtn('sauce_single') + '</span><span class="value">' +
                        sName + ' (inclus)</span></div>';
                }
            }
        }

        // Accompagnement (assiettes - from accompagnement or sauce_accompagnement step)
        if (selections.accompagnement) {
            var accStep = steps.find(function (s) { return s.type === 'accompagnement' || s.type === 'sauce_accompagnement'; });
            if (accStep) {
                var accItems = accStep.items || accStep.accompItems || [];
                var accName = '';
                accItems.forEach(function (a) {
                    if (a.id === selections.accompagnement) accName = a.name;
                });
                if (accName) {
                    var accGoto = steps.find(function (s) { return s.type === 'sauce_accompagnement'; }) ? 'sauce_accompagnement' : 'accompagnement';
                    h += '<div class="wizard-recap-row"><span class="label">🍟 Accompagnement' + editBtn(accGoto) + '</span><span class="value">' +
                        accName + ' (inclus)</span></div>';
                }
            }
        }

        // Garnitures (from garnitures, perso, or sauce_garnitures step)
        var hasGarnStep = steps.some(function (s) {
            return ['garnitures', 'perso', 'sauce_garnitures'].indexOf(s.type) !== -1;
        });
        if (hasGarnStep && selections.garnitures) {
            var garnStep = steps.find(function (s) {
                return s.type === 'garnitures' || s.type === 'perso' || s.type === 'sauce_garnitures';
            });
            var garnItems = garnStep ? (garnStep.items || garnStep.freeItems || garnStep.garnitureItems) : [];
            var garnNames = [];
            garnItems.forEach(function (g) {
                if (selections.garnitures && selections.garnitures[g.id]) {
                    garnNames.push(g.name);
                }
            });
            var garnGoto = 'garnitures';
            if (steps.find(function (s) { return s.type === 'perso'; })) garnGoto = 'perso';
            else if (steps.find(function (s) { return s.type === 'sauce_garnitures'; })) garnGoto = 'sauce_garnitures';
            h += '<div class="wizard-recap-row"><span class="label">🥬 Garnitures' + editBtn(garnGoto) + '</span><span class="value">' +
                (garnNames.length > 0 ? garnNames.join(', ') : 'Aucune') + '</span></div>';
        }

        // Supplements (from supplements, perso, supplements_menu, or sauce_supplements step)
        var hasSuppStep = steps.some(function (s) {
            return ['supplements', 'perso', 'supplements_menu', 'sauce_supplements'].indexOf(s.type) !== -1;
        });
        if (hasSuppStep && selections.supplements) {
            var suppStep = steps.find(function (s) {
                return s.type === 'supplements' || s.type === 'perso' || s.type === 'supplements_menu' || s.type === 'sauce_supplements';
            });
            var suppItems = suppStep ? (suppStep.items || suppStep.paidItems) : [];
            var suppNames = [];
            suppItems.forEach(function (s) {
                if (selections.supplements && selections.supplements[s.id]) {
                    suppNames.push(s.name);
                    totalExtra += s.price;
                }
            });
            if (suppNames.length > 0) {
                var suppGoto = 'supplements';
                if (steps.find(function (s) { return s.type === 'perso'; })) suppGoto = 'perso';
                else if (steps.find(function (s) { return s.type === 'supplements_menu'; })) suppGoto = 'supplements_menu';
                else if (steps.find(function (s) { return s.type === 'sauce_supplements'; })) suppGoto = 'sauce_supplements';
                h += '<div class="wizard-recap-row"><span class="label">➕ Suppléments' + editBtn(suppGoto) + '</span><span class="value">' +
                    suppNames.join(', ') + '</span></div>';
            }
        }

        // Menu / Addons — [FIX BUG-WIZARD-RECAP-TOTAL] Support legacy 'menu'/'supplements_menu' AND new 'menu_choice' step type
        var menuStep = steps.find(function (s) { return s.type === 'menu' || s.type === 'supplements_menu' || s.type === 'menu_choice'; });
        var addonTotal = 0;
        if (menuStep && selections.menuChoice) {
            // --- New menu_choice format (Sprint 4+) ---
            if (menuStep.type === 'menu_choice') {
                var formuleLabel = '';
                var formulePrice = 0;
                if (selections.menuChoice === 'full' && menuStep.menuComplet) {
                    formuleLabel = menuStep.menuComplet.label || 'Menu Complet (Frites + Boisson)';
                    formulePrice = menuStep.menuComplet.price || 0;
                } else if (selections.menuChoice === 'frites' && menuStep.fritesSeules) {
                    formuleLabel = menuStep.fritesSeules.label || 'Frites Seules';
                    formulePrice = menuStep.fritesSeules.price || 0;
                } else if (selections.menuChoice === 'boisson' && menuStep.boissonSeule) {
                    formuleLabel = menuStep.boissonSeule.label || 'Boisson Seule';
                    formulePrice = menuStep.boissonSeule.price || 0;
                }
                if (formuleLabel) {
                    addonTotal += formulePrice;
                    h += '<div class="wizard-recap-row"><span class="label">🍟 Formule' + editBtn('menu_choice') + '</span><span class="value">' +
                        formuleLabel + ' <span style="color:#E93C3C;font-weight:700">+€' + formulePrice.toFixed(2) + '</span></span></div>';
                }
                // Frites upgrades
                if (selections.fritesGrande) {
                    addonTotal += 1.00;
                    h += '<div class="wizard-recap-row"><span class="label">🍟 Option frites' + editBtn('frites_options') + '</span><span class="value">Grande Portion <span style="color:#E93C3C;font-weight:700">+€1.00</span></span></div>';
                }
                if (selections.fritesCheddar) {
                    addonTotal += 1.00;
                    h += '<div class="wizard-recap-row"><span class="label"> </span><span class="value">Cheddar Fondu <span style="color:#E93C3C;font-weight:700">+€1.00</span></span></div>';
                }
            } else {
                // --- Legacy format (menu / supplements_menu) ---
                var menuItems = menuStep.items || menuStep.menuItems || [];
                if (selections.menuChoice === 'full') {
                    var addonNames = [];
                    menuItems.forEach(function (a) {
                        addonNames.push(a.name);
                        addonTotal += (a.price || 0);
                    });
                    var menuGoto = steps.find(function (s) { return s.type === 'supplements_menu'; }) ? 'supplements_menu' : 'menu';
                    h += '<div class="wizard-recap-row"><span class="label">🍟 Menu' + editBtn(menuGoto) + '</span><span class="value">' +
                        addonNames.join(' + ') + '</span></div>';
                } else if (selections.menuChoice === 'individual') {
                    var indNames = [];
                    menuItems.forEach(function (a) {
                        if (selections.individualAddons && selections.individualAddons[a.id]) {
                            indNames.push(a.name);
                            addonTotal += (a.price || 0);
                        }
                    });
                    if (indNames.length > 0) {
                        var indGoto = steps.find(function (s) { return s.type === 'supplements_menu'; }) ? 'supplements_menu' : 'menu';
                        h += '<div class="wizard-recap-row"><span class="label">🍟 À la carte' + editBtn(indGoto) + '</span><span class="value">' +
                            indNames.join(', ') + '</span></div>';
                    }
                }
            }
        }

        // Sauce Frites
        if (hasFritesSelected() && selections.sauceFritesOrder && selections.sauceFritesOrder.length > 0) {
            var sfNames = [];
            var sfItems = [];
            // Get sauce items from any step that has them
            var sfStep = steps.find(function (s) {
                return s.type === 'sauce_frites' || s.type === 'menu' || s.type === 'supplements_menu';
            });
            if (sfStep) {
                sfItems = sfStep.items || sfStep.sauceItems || [];
            }
            selections.sauceFritesOrder.forEach(function (id, idx) {
                var sauce = sfItems.find(function (s) { return s.id === id; });
                if (sauce) {
                    if (idx === 0) {
                        sfNames.push(sauce.name + ' (gratuit)');
                    } else {
                        sfNames.push(sauce.name + ' (+€0.50)');
                        totalExtra += SAUCE_EXTRA_PRICE;
                    }
                }
            });
            if (sfNames.length > 0) {
                var sfGoto = steps.find(function (s) { return s.type === 'supplements_menu'; }) ? 'supplements_menu' : 'menu';
                h += '<div class="wizard-recap-row"><span class="label">🍟 Sauce frites' + editBtn(sfGoto) + '</span><span class="value">' +
                    sfNames.join(', ') + '</span></div>';
            }
        }

        // Total
        var unitPrice = basePrice + totalExtra;
        var total = (unitPrice + addonTotal) * itemQuantity;
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
       [D-010] BUILD WIZARD INSTRUCTION FOR KDS
       ============================== */
    /**
     * Génère une chaîne lisible pour le KDS à partir des choix du wizard.
     * Format: "VIANDES: Merguez, Poulet. SAUCE: Harissa. FORMULE: Menu Complet."
     * @returns {string|null} L'instruction formatée ou null si vide
     */
    function buildWizardInstruction() {
        var parts = [];

        // Viandes
        if (selections.viandes) {
            var viandeNames = [];
            VIANDES.forEach(function (v) {
                var count = selections.viandes[v.key] || 0;
                if (count > 0) {
                    viandeNames.push(count > 1 ? count + '× ' + v.name : v.name);
                }
            });
            if (viandeNames.length > 0) {
                parts.push('VIANDES: ' + viandeNames.join(', '));
            }
        }

        // [BUG-1 FIX] Pain selection (sandwich only) - Include bread type in KDS instruction
        if (selections.pain) {
            var painVariation = null;
            // Find pain variation from wizardItemData.variations (grouped by attribute) [BUG-W1 FIX]
            if (wizardItemData && wizardItemData.variations) {
                Object.values(wizardItemData.variations).forEach(function (group) {
                    if (Array.isArray(group)) {
                        group.forEach(function (v) {
                            if (v.id === selections.pain) {
                                painVariation = v;
                            }
                        });
                    }
                });
            }
            if (painVariation) {
                parts.push('PAIN: ' + painVariation.name);
            }
        }

        // Sauce principale (première sauce dans sauceOrder)
        if (selections.sauceOrder && selections.sauceOrder.length > 0) {
            var allSauceItems = [];
            steps.forEach(function (s) {
                if (s.sauceItems) allSauceItems = allSauceItems.concat(s.sauceItems);
                if (s.items && s.type === 'sauce') allSauceItems = allSauceItems.concat(s.items);
            });
            var sauce = allSauceItems.find(function (ss) { return ss.id === selections.sauceOrder[0]; });
            if (sauce) {
                parts.push('SAUCE: ' + sauce.name);
            }
        }

        // Extra sauces (2nd, 3rd, etc.)
        if (selections.sauceOrder && selections.sauceOrder.length > 1) {
            allSauceItems = []; // reuse — already declared above
            steps.forEach(function (s) {
                if (s.sauceItems) allSauceItems = allSauceItems.concat(s.sauceItems);
                if (s.items && s.type === 'sauce') allSauceItems = allSauceItems.concat(s.items);
            });
            var extraSauceNames = [];
            for (var si = 1; si < selections.sauceOrder.length; si++) {
                var sauce = allSauceItems.find(function (ss) { return ss.id === selections.sauceOrder[si]; });
                if (sauce) extraSauceNames.push(sauce.name);
            }
            if (extraSauceNames.length > 0) {
                parts.push('SAUCES SUPPL: ' + extraSauceNames.join(', '));
            }
        }

        // [G1 FIX] sauceSingle for omelette/snacking
        if (selections.sauceSingle) {
            var sauceSingleStep = steps.find(function (s) { return s.type === 'sauce_single'; });
            if (sauceSingleStep && sauceSingleStep.items) {
                var sauceSingleItem = sauceSingleStep.items.find(function (ss) {
                    return ss.id === selections.sauceSingle;
                });
                if (sauceSingleItem) {
                    parts.push('SAUCE: ' + sauceSingleItem.name);
                }
            }
        }

        // Garnitures
        // [BUG-GARN-1+2 FIX] Garnitures: emit both SANS: (excluded) and GARNITURES: (included)
        // Also search freeItems (perso step for Tacos) AND garnitureItems AND items
        if (selections.garnitures) {
            var allGarnItems = [];
            steps.forEach(function (s) {
                // [BUG-GARN-2 FIX] Search freeItems (perso step) AND garnitureItems AND items
                var items = s.garnitureItems || s.freeItems || (s.type === 'garnitures' ? s.items : null) || [];
                items.forEach(function (g) {
                    if (!allGarnItems.find(function (x) { return x.id === g.id; })) {
                        allGarnItems.push(g);
                    }
                });
            });
            var garnIncluded = [];
            var garnExcluded = [];
            allGarnItems.forEach(function (g) {
                if (selections.garnitures[g.id] === true) garnIncluded.push(g.name);
                else if (selections.garnitures[g.id] === false) garnExcluded.push(g.name);
            });
            // [BUG-GARN-1 FIX] Emit SANS: for excluded garnitures
            if (garnExcluded.length > 0) {
                parts.push('SANS: ' + garnExcluded.join(', '));
            }
            if (garnIncluded.length > 0) {
                parts.push('GARNITURES: ' + garnIncluded.join(', '));
            }
        }

        // [G2 FIX] Accompagnement for assiettes (riz/frites/salade)
        if (selections.accompagnement) {
            var accompStep = steps.find(function (s) { return s.type === 'sauce_accompagnement'; });
            if (accompStep && accompStep.accompItems) {
                var accompItem = accompStep.accompItems.find(function (a) {
                    return a.id === selections.accompagnement;
                });
                if (accompItem) {
                    parts.push('ACCOMPAGNEMENT: ' + accompItem.name);
                }
            }
        }

        // Suppléments
        if (selections.supplements) {
            var supplNames = [];
            Object.keys(selections.supplements).forEach(function (id) {
                if (!selections.supplements[id]) return;
                steps.forEach(function (s) {
                    (s.paidItems || []).concat(s.items || []).forEach(function (p) {
                        if (String(p.id) === String(id)) supplNames.push(p.name);
                    });
                });
            });
            if (supplNames.length > 0) {
                parts.push('SUPPLÉMENTS: ' + supplNames.join(', '));
            }
        }

        // Formule/Menu
        if (selections.menuChoice) {
            if (selections.menuChoice === 'full') {
                parts.push('FORMULE: Menu Complet (Frites + Boisson)');
            } else if (selections.menuChoice === 'frites') {
                parts.push('FORMULE: Frites Seules');
            } else if (selections.menuChoice === 'boisson') {
                parts.push('FORMULE: Boisson Seule');
            } else if (selections.menuChoice === 'individual' && selections.individualAddons) {
                // [S21-1 FIX] Handle individual addons (e.g., Frites Seules, Boisson Seule selected individually)
                var indNames = [];
                var menuStep = steps.find(function (s) { return s.type === 'supplements_menu' || s.type === 'menu'; });
                var addonItems = menuStep ? (menuStep.menuItems || menuStep.items) : null;
                if (addonItems) {
                    addonItems.forEach(function (a) {
                        if (selections.individualAddons[a.id]) {
                            indNames.push(a.name);
                        }
                    });
                }
                if (indNames.length > 0) {
                    parts.push('FORMULE: ' + indNames.join(', '));
                }
            }

            // [BUG-2 FIX] Include specific boisson name in KDS instruction
            // [W-4 FIX] Look for boissonItems on boisson_choice step, not menu_choice
            if (selections.boissonChoice && selections.boissonChoice !== 'none') {
                var boissonStep = steps.find(function (s) { return s.type === 'boisson_choice'; });
                if (boissonStep && boissonStep.boissonItems) {
                    var boissonItem = boissonStep.boissonItems.find(function (b) { return b.id === selections.boissonChoice; });
                    if (boissonItem) {
                        parts.push('BOISSON: ' + boissonItem.name);
                    }
                }
            }
        }

        // Options frites
        var fritesOptions = [];
        if (selections.fritesGrande) fritesOptions.push('Grande portion (+€1.00)');
        if (selections.fritesCheddar) fritesOptions.push('Cheddar (+€1.00)');
        if (fritesOptions.length > 0) {
            parts.push('FRITES: ' + fritesOptions.join(', '));
        }

        // Sauce frites
        if (selections.sauceFritesOrder && selections.sauceFritesOrder.length > 0) {
            var sfStep = steps.find(function (s) { return s.type === 'sauce_frites'; });
            // [W-8 FIX] Fallback: look for sauceItems in supplements_menu step
            var sfItems = (sfStep && sfStep.items) ? sfStep.items : null;
            if (!sfItems) {
                var suppStep = steps.find(function (s) { return s.type === 'supplements_menu'; });
                if (suppStep && suppStep.sauceItems) sfItems = suppStep.sauceItems;
            }
            var sfNames = [];
            if (sfItems) {
                selections.sauceFritesOrder.forEach(function (id) {
                    var sauce = sfItems.find(function (ss) { return ss.id === id; });
                    if (sauce) sfNames.push(sauce.name);
                });
            }
            if (sfNames.length > 0) {
                parts.push('SAUCE FRITES: ' + sfNames.join(', '));
            }
        }

        // User instruction
        if (instructionText && instructionText.trim()) {
            parts.push('NOTE: ' + instructionText.trim());
        }

        return parts.length > 0 ? parts.join('. ') + '.' : null;
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

        // [BUG-1 FIX] Sync pain variation (sandwich) - Click the pain/galette radio
        // [W-10 FIX] Handle both integer IDs (DB variations) and string IDs (fallback 'pain'/'galette')
        if (selections.pain) {
            var painRadios = originalBody.querySelectorAll('.custom-radio-field');
            painRadios.forEach(function (radio) {
                var parsedVal = parseInt(radio.value);
                var isMatch = isNaN(parsedVal) ? radio.value === selections.pain : parsedVal === selections.pain;
                if (isMatch) {
                    radio.click();
                }
            });
            var painSelects = originalBody.querySelectorAll('select');
            painSelects.forEach(function (sel) {
                var opts = sel.querySelectorAll('option');
                opts.forEach(function (opt) {
                    if (parseInt(opt.value) === selections.pain) {
                        sel.value = opt.value;
                        sel.dispatchEvent(new Event('change', { bubbles: true }));
                    }
                });
            });
        }

        // 2b. Sync extra sauces (index 1+) as extras checkboxes
        // Extra sauces are stored as ItemExtra named "Sauce supplémentaire: X" in DB
        if (selections.sauceOrder && selections.sauceOrder.length > 1) {
            var extraSauceIds = selections.sauceOrder.slice(1); // all sauces after the 1st

            // Find the sauce names for these IDs
            var allSauceItems = [];
            steps.forEach(function (step) {
                if (step.sauceItems) allSauceItems = allSauceItems.concat(step.sauceItems);
                if (step.items && (step.type === 'sauce' || step.type === 'sauce_frites')) {
                    allSauceItems = allSauceItems.concat(step.items);
                }
            });

            extraSauceIds.forEach(function (sauceId) {
                var sauce = allSauceItems.find(function (s) { return s.id === sauceId; });
                if (!sauce) return;

                // Find the extra checkbox for "Sauce supplémentaire: {sauce.name}"
                var extraCheckboxes = originalBody.querySelectorAll('.extra .custom-checkbox-field');
                extraCheckboxes.forEach(function (cb) {
                    // The extra checkbox value is the extra ID
                    // We need to find the extra whose name matches "Sauce supplémentaire: {sauce.name}"
                    var label = cb.closest('.extra')
                        ? cb.closest('.extra').querySelector('label, span, .option-name')
                        : null;
                    if (label) {
                        var labelText = (label.textContent || '').toLowerCase();
                        var sauceName = (sauce.name || '').toLowerCase();
                        if (labelText.includes('sauce suppl') && labelText.includes(sauceName)) {
                            if (!cb.checked) cb.click();
                        }
                    }
                });
            });
        }

        // 3. Check/uncheck extras
        var extraCheckboxes = originalBody.querySelectorAll('.extra .custom-checkbox-field');
        var allSelectedExtras = {};

        // [SYNC-BUG-003] Décocher TOUTES les garnitures d'abord, puis cocher seulement la sélectionnée
        // pour éviter les contradictions (ex: "Complet" + "Sans Oignon")
        var garnitureIds = [];
        var selectedGarnitureIds = [];

        if (selections.garnitures) {
            Object.keys(selections.garnitures).forEach(function (id) {
                garnitureIds.push(parseInt(id));
                if (selections.garnitures[id]) selectedGarnitureIds.push(parseInt(id));
            });
        }

        // Décocher toutes les garnitures d'abord
        extraCheckboxes.forEach(function (cb) {
            var cbId = parseInt(cb.value);
            if (garnitureIds.indexOf(cbId) !== -1 && cb.checked) {
                cb.click();  // Décocher
            }
        });

        // Puis cocher les garnitures sélectionnées
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
        // [FIX] Support both legacy 'menu' AND new 'menu_choice' step types
        // [W-6 FIX] Also support 'supplements_menu' step type for Sandwich/Burger flow
        var addonCards = originalBody.querySelectorAll('.addon');
        var menuStep = steps.find(function (s) {
            return s.type === 'menu' || s.type === 'menu_choice' || s.type === 'supplements_menu';
        });
        if (menuStep && addonCards.length > 0) {
            var menuAddonItems = menuStep.items || menuStep.menuItems || [];
            if (selections.menuChoice === 'full') {
                // Menu complet: click ALL addon cards
                addonCards.forEach(function (card) {
                    var isSelected = card.closest('.selected, [class*="primary"]') !== null;
                    if (!isSelected) card.click();
                });
            } else if (selections.menuChoice && selections.menuChoice !== 'none') {
                // Frites seules / Boisson seule / individual: robust matching by name + id fallback
                addonCards.forEach(function (card, index) {
                    var addon = menuAddonItems[index] || null;
                    var shouldBeSelected = false;
                    var addonName = (addon && addon.name ? addon.name : (card.getAttribute('data-addon-name') || '')).toLowerCase();
                    if (selections.menuChoice === 'frites' && addonName.includes('frite')) {
                        shouldBeSelected = true;
                    }
                    if (selections.menuChoice === 'boisson' && (addonName.includes('boisson') || addonName.includes('coca'))) {
                        shouldBeSelected = true;
                    }
                    if (addon && selections.individualAddons && selections.individualAddons[addon.id]) {
                        shouldBeSelected = true;
                    }
                    // Fallback: name-based match for individualAddons when DOM order differs from menuAddonItems
                    if (!shouldBeSelected && selections.individualAddons && menuAddonItems.length > 0) {
                        var selectedNames = menuAddonItems
                            .filter(function (a) { return selections.individualAddons[a.id]; })
                            .map(function (a) { return (a.name || '').toLowerCase(); });
                        shouldBeSelected = selectedNames.some(function (name) {
                            return name && addonName && addonName.includes(name.split(' ')[0]);
                        });
                    }
                    var isSelected = card.closest('.selected, [class*="primary"]') !== null;
                    if (shouldBeSelected && !isSelected) card.click();
                });
            }
        }

        // [W-5 FIX] Sync boissonChoice to Vue modal addon cards
        if (selections.boissonChoice && selections.boissonChoice !== 'none') {
            var boissonStepSync = steps.find(function (s) { return s.type === 'boisson_choice'; });
            if (boissonStepSync && boissonStepSync.boissonItems) {
                var boissonItemSync = boissonStepSync.boissonItems.find(function (b) { return b.id === selections.boissonChoice; });
                if (boissonItemSync) {
                    var boissonAddonCards = originalBody.querySelectorAll('.addon[data-addon-id]');
                    boissonAddonCards.forEach(function (card) {
                        var addonName = (card.getAttribute('data-addon-name') || '').toLowerCase();
                        if (addonName.includes(boissonItemSync.name.toLowerCase().split(' ')[0])) {
                            var isSelected = card.closest('.selected, [class*="primary"]') !== null;
                            if (!isSelected) card.click();
                        }
                    });
                }
            }
        }

        // 5. Set instruction — [D-010] Use buildWizardInstruction() for KDS formatted string
        var fullInstruction = buildWizardInstruction();

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

        // [BUG-W1 FIX] Store item data for buildWizardInstruction()
        wizardItemData = lastItemData;

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

        // [NEW SPRINT 4] Inject CSS styles if not already present
        if (!document.getElementById('pos-wizard-styles')) {
            var styleEl = document.createElement('style');
            styleEl.id = 'pos-wizard-styles';
            styleEl.textContent = `
                /* [Sprint 4] Split-screen layout */
                .wizard-split { display: flex; gap: 16px; }
                .wizard-split > .wizard-col { flex: 1; min-width: 0; }
                .wizard-split > .wizard-col h4 { font-size: 0.95rem; font-weight: 600; margin-bottom: 12px; color: #374151; }

                /* [Sprint 4] Section styling */
                .wizard-section { margin-bottom: 20px; }
                .wizard-section h4 { font-size: 0.95rem; font-weight: 600; margin-bottom: 8px; color: #374151; }
                .wizard-hint { font-size: 0.8rem; color: #6b7280; margin-bottom: 10px; }

                /* [Sprint 4] Garniture toggle buttons */
                .garniture-toggle { display: flex; gap: 8px; flex-wrap: wrap; }
                .garniture-toggle-btn { padding: 10px 16px; border-radius: 20px; border: 2px solid #e5e7eb; background: white; cursor: pointer; font-size: 0.9rem; transition: all 0.2s; }
                .garniture-toggle-btn:hover { border-color: #d1d5db; }
                .garniture-toggle-btn.active { background: #22c55e; border-color: #22c55e; color: white; }

                /* [Sprint 4] Sauce frites inline */
                .sauce-frites-inline { display: none; margin-top: 16px; padding-top: 16px; border-top: 2px dashed #e5e7eb; }
                .sauce-frites-inline.visible { display: block; animation: slideDown 0.3s ease; }
                @keyframes slideDown { from { opacity: 0; transform: translateY(-10px); } to { opacity: 1; transform: translateY(0); } }

                /* [Sprint 4] Menu cards */
                .wizard-menu-options { display: flex; gap: 12px; flex-wrap: wrap; }
                .wizard-menu-card { flex: 1; min-width: 100px; max-width: 140px; padding: 16px 12px; border: 2px solid #e5e7eb; border-radius: 12px; text-align: center; cursor: pointer; transition: all 0.2s; background: white; }
                .wizard-menu-card:hover { border-color: #d1d5db; transform: translateY(-2px); }
                .wizard-menu-card.selected { border-color: #3b82f6; background: #eff6ff; }
                .wizard-menu-card .menu-icon { font-size: 2rem; margin-bottom: 8px; }
                .wizard-menu-card .menu-name { font-size: 0.85rem; font-weight: 500; color: #374151; margin-bottom: 4px; }
                .wizard-menu-card .menu-price { font-size: 0.9rem; font-weight: 600; color: #3b82f6; }
                .wizard-menu-card .menu-desc { font-size: 0.75rem; color: #6b7280; margin-top: 4px; }

                /* [Sprint 4] Compact grids */
                .sauce-grid.compact .wizard-option { padding: 8px 10px; }
                .sauce-grid.compact .option-icon { font-size: 1.2rem; }
                .sauce-grid.compact .option-name { font-size: 0.8rem; }

                /* [Sprint 4] Edit buttons */
                .edit-step-btn { background: none; border: none; cursor: pointer; font-size: 0.8rem; color: #6b7280; padding: 2px 6px; margin-left: 8px; opacity: 0.6; transition: opacity 0.2s; }
                .edit-step-btn:hover { opacity: 1; color: #2563eb; }
                .wizard-recap-row:hover .edit-step-btn { opacity: 1; }

                /* [Sprint 4] Keyboard navigation hints */
                .pos-wizard { position: relative; }
                .keyboard-hint { position: absolute; bottom: 4px; right: 8px; font-size: 0.7rem; color: #9ca3af; }
            `;
            document.head.appendChild(styleEl);
        }

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

        // 5. Update Menu Full choice (legacy style)
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

        // [NEW SPRINT 4] 6. Update garniture toggle buttons
        wizardEl.querySelectorAll('.garniture-toggle-btn').forEach(function (btn) {
            var id = parseInt(btn.getAttribute('data-id'));
            var isSelected = selections.garnitures && selections.garnitures[id];
            if (isSelected) btn.classList.add('active');
            else btn.classList.remove('active');
        });

        // [NEW SPRINT 4] 7. Update menu cards (new style)
        wizardEl.querySelectorAll('.wizard-menu-card').forEach(function (card) {
            var menu = card.getAttribute('data-menu');
            var addonId = card.getAttribute('data-addon-id');
            var isSelected = false;

            if (menu === 'full') {
                isSelected = (selections.menuChoice === 'full');
            } else if (menu === 'none') {
                isSelected = (selections.menuChoice === 'none');
            } else if (addonId) {
                isSelected = selections.individualAddons && selections.individualAddons[parseInt(addonId)];
            }

            if (isSelected) card.classList.add('selected');
            else card.classList.remove('selected');
        });

        // [P1] Update menu choice cards (3 options: full/frites/none)
        wizardEl.querySelectorAll('.menu-choice-card').forEach(function (card) {
            var value = card.getAttribute('data-value');
            var isSelected = (selections.menuChoice === value);
            if (isSelected) card.classList.add('selected');
            else card.classList.remove('selected');
        });

        // [P1] Update frites options — [S21-3a FIX] Include .frites-opt inline elements
        wizardEl.querySelectorAll('.frites-option, .frites-opt').forEach(function (opt) {
            var action = opt.getAttribute('data-action');
            var value = opt.getAttribute('data-value');
            var isSelected = false;
            if (action === 'frites-size') {
                isSelected = (value === 'grande') ? selections.fritesGrande : !selections.fritesGrande;
            } else if (action === 'frites-cheddar') {
                isSelected = (value === 'yes') ? selections.fritesCheddar : !selections.fritesCheddar;
            }
            if (isSelected) opt.classList.add('selected');
            else opt.classList.remove('selected');
        });

        // [P1] Update boisson choice options
        wizardEl.querySelectorAll('.boisson-opt').forEach(function (opt) {
            var value = opt.getAttribute('data-value');
            var id = parseInt(opt.getAttribute('data-id'));
            var isSelected = false;
            if (value === 'none') {
                isSelected = (selections.boissonChoice === 'none');
            } else if (id) {
                isSelected = (selections.boissonChoice === id);
            }
            if (isSelected) opt.classList.add('selected');
            else opt.classList.remove('selected');
        });

        // [NEW SPRINT 4] 8. Toggle sauce frites inline visibility
        var sauceFritesInline = wizardEl.querySelector('.sauce-frites-inline');
        if (sauceFritesInline) {
            var fritesSelected = (selections.menuChoice === 'full') ||
                (selections.individualAddons && wizardEl.querySelectorAll('.wizard-menu-card[data-addon-id]').length > 0 &&
                    Array.from(wizardEl.querySelectorAll('.wizard-menu-card[data-addon-id]')).some(function (card) {
                        var id = parseInt(card.getAttribute('data-addon-id'));
                        var name = card.querySelector('.menu-name');
                        return selections.individualAddons[id] && name && name.innerText.toLowerCase().includes('frite');
                    }));

            if (fritesSelected) sauceFritesInline.classList.add('visible');
            else sauceFritesInline.classList.remove('visible');
        }

        // [S21-3 FIX] 8b. Toggle frites options inline visibility (for Sandwich/Burger flow)
        var fritesOptionsInline = wizardEl.querySelector('.frites-options-inline');
        if (fritesOptionsInline) {
            var fritesSelectedOpts = (selections.menuChoice === 'full') ||
                (selections.individualAddons && wizardEl.querySelectorAll('.wizard-menu-card[data-addon-id]').length > 0 &&
                    Array.from(wizardEl.querySelectorAll('.wizard-menu-card[data-addon-id]')).some(function (card) {
                        var id = parseInt(card.getAttribute('data-addon-id'));
                        var name = card.querySelector('.menu-name');
                        return selections.individualAddons[id] && name && name.innerText.toLowerCase().includes('frite');
                    }));
            if (fritesSelectedOpts) fritesOptionsInline.classList.add('visible');
            else fritesOptionsInline.classList.remove('visible');
        }

        // [NEW SPRINT 4] 9. Update sauce info headers in combined steps
        wizardEl.querySelectorAll('.wizard-sauce-info').forEach(function (infoEl) {
            var stepType = infoEl.closest('.wizard-step') ? infoEl.closest('.wizard-step').getAttribute('data-step') : null;
            if (!stepType) return;

            var step = steps[parseInt(stepType)];
            if (!step) return;

            if ((step.type === 'viande_sauce' || step.type === 'sauce_garnitures' || step.type === 'sauce_accompagnement' || step.type === 'sauce_supplements') && selections.sauceOrder) {
                var count = selections.sauceOrder.length;
                var newHtml = '';
                if (count === 0) newHtml = '<span class="sauce-badge free">Sélectionnez une sauce</span>';
                else if (count === 1) newHtml = '<span class="sauce-badge free">✅ 1 sauce (gratuite)</span>';
                else {
                    var extraCost = (count - 1) * SAUCE_EXTRA_PRICE;
                    newHtml = '<span class="sauce-badge paid">' + count + ' sauces = +' + fmtPrice(extraCost) + '</span>';
                }
                infoEl.innerHTML = newHtml;
            }
        });
    }

    function refreshWizard(goingBack) {
        if (!wizardEl) return;
        var prevTotal = calculateRunningTotal();
        wizardEl.innerHTML = renderWizard();
        bindEvents();
        // [UI] Directional slide animation
        if (goingBack) {
            var stepEl = wizardEl.querySelector('.wizard-step.active');
            if (stepEl) {
                stepEl.classList.add('slide-back');
                setTimeout(function () { stepEl.classList.remove('slide-back'); }, 300);
            }
        }
        // [UI] Price-bump animation if total changed
        var newTotal = calculateRunningTotal();
        if (Math.abs(newTotal - prevTotal) > 0.001) {
            var totalEl = wizardEl.querySelector('.run-total-value');
            if (totalEl) {
                totalEl.closest('.wizard-running-total').classList.add('price-bump');
                setTimeout(function () {
                    var rt = wizardEl.querySelector('.wizard-running-total');
                    if (rt) rt.classList.remove('price-bump');
                }, 400);
            }
        }
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
            // [UI] Flag the refresh as a backward navigation for CSS animation
            refreshWizard(true);
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

        // Pain selection (radio - single choice)
        wizardEl.querySelectorAll('.wizard-option[data-type="pain"]').forEach(function (opt) {
            opt.addEventListener('click', function () {
                var id = this.getAttribute('data-id');
                // [W-9 FIX] Keep string ID for fallback pain items ('pain', 'galette') instead of NaN
                var parsedId = parseInt(id);
                selections.pain = isNaN(parsedId) ? id : parsedId;
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

        // [NEW SPRINT 4] Garniture toggle buttons (new style)
        wizardEl.querySelectorAll('.garniture-toggle-btn').forEach(function (btn) {
            btn.addEventListener('click', function () {
                var id = parseInt(this.getAttribute('data-id'));
                if (!selections.garnitures) selections.garnitures = {};
                selections.garnitures[id] = !selections.garnitures[id];
                updateWizardUI();
            });
        });

        // [NEW SPRINT 4] Menu cards (new style)
        wizardEl.querySelectorAll('.wizard-menu-card').forEach(function (card) {
            card.addEventListener('click', function () {
                var menu = this.getAttribute('data-menu');
                var addonId = this.getAttribute('data-addon-id');

                if (menu === 'full') {
                    selections.menuChoice = 'full';
                    selections.individualAddons = {};
                } else if (menu === 'none') {
                    selections.menuChoice = 'none';
                    selections.individualAddons = {};
                    selections.sauceFrites = {};
                    selections.sauceFritesOrder = [];
                } else if (addonId) {
                    // Individual addon toggle
                    if (!selections.individualAddons) selections.individualAddons = {};
                    var id = parseInt(addonId);
                    selections.individualAddons[id] = !selections.individualAddons[id];
                    if (selections.individualAddons[id]) {
                        selections.menuChoice = 'individual';
                    } else {
                        // Check if any addons remain selected
                        var anySelected = Object.values(selections.individualAddons).some(function (v) { return v; });
                        if (!anySelected) selections.menuChoice = null;
                    }
                }
                updateWizardUI();
            });
        });

        // [NEW SPRINT 4] Edit buttons in recap
        wizardEl.querySelectorAll('.edit-step-btn').forEach(function (btn) {
            btn.addEventListener('click', function (e) {
                e.stopPropagation();
                var gotoStep = this.getAttribute('data-goto');
                if (gotoStep) {
                    var stepIndex = steps.findIndex(function (s) { return s.type === gotoStep; });
                    if (stepIndex >= 0) {
                        currentStep = stepIndex;
                        refreshWizard(); // [BUG-W3 FIX] Re-render step content, not just update UI
                    }
                }
            });
        });

        // [P1] Menu choice cards (3 options)
        wizardEl.querySelectorAll('[data-action="menu-choice"]').forEach(function (card) {
            card.addEventListener('click', function () {
                var choice = this.getAttribute('data-value');
                selections.menuChoice = choice;  // 'full' | 'frites' | 'none'
                updateWizardUI();
                // Auto-advance after short delay
                setTimeout(function () {
                    goToNextActiveStep();
                }, 300);
            });
        });

        // [P1] Frites size options
        wizardEl.querySelectorAll('[data-action="frites-size"]').forEach(function (opt) {
            opt.addEventListener('click', function () {
                var value = this.getAttribute('data-value');
                selections.fritesGrande = (value === 'grande');
                updateWizardUI();
            });
        });

        // [P1] Frites cheddar option
        wizardEl.querySelectorAll('[data-action="frites-cheddar"]').forEach(function (opt) {
            opt.addEventListener('click', function () {
                var value = this.getAttribute('data-value');
                selections.fritesCheddar = (value === 'yes');
                updateWizardUI();
            });
        });

        // [P1] Boisson choice
        wizardEl.querySelectorAll('[data-action="boisson-choice"]').forEach(function (opt) {
            opt.addEventListener('click', function () {
                var value = this.getAttribute('data-value');
                var id = this.getAttribute('data-id');
                if (value === 'none') {
                    selections.boissonChoice = 'none';
                } else if (id) {
                    selections.boissonChoice = parseInt(id);
                }
                updateWizardUI();
                // Auto-advance after selection
                setTimeout(function () {
                    goToNextActiveStep();
                }, 300);
            });
        });

        // [NEW SPRINT 4] Keyboard shortcuts
        if (!window.wizardKeyboardBound) {
            window.wizardKeyboardBound = true;
            document.addEventListener('keydown', function (e) {
                if (!wizardEl) return;

                // Ignore if typing in textarea
                if (e.target.tagName === 'TEXTAREA') return;

                // Enter or ArrowRight = Next
                if (e.key === 'Enter' || e.key === 'ArrowRight') {
                    e.preventDefault();
                    var nextBtn = wizardEl.querySelector('[data-nav="next"], [data-nav="skip"]');
                    if (nextBtn && !nextBtn.disabled) {
                        goToNextActiveStep();
                    }
                }

                // ArrowLeft or Backspace = Back
                if (e.key === 'ArrowLeft' || e.key === 'Backspace') {
                    e.preventDefault();
                    var backBtn = wizardEl.querySelector('[data-nav="back"]');
                    if (backBtn && !backBtn.disabled) {
                        goToPrevActiveStep();
                    }
                }

                // Number keys 1-9 for quick selection
                if (e.key >= '1' && e.key <= '9') {
                    var idx = parseInt(e.key) - 1;
                    var options = wizardEl.querySelectorAll('.wizard-option:not(.selected), .wizard-viande-row, .garniture-toggle-btn');
                    if (options[idx]) {
                        e.preventDefault();
                        options[idx].click();
                    }
                }
            }, true);
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
                        // [BUG-6 FIX] Retry loop instead of fixed 400ms timeout
                        // Waits for lastItemData to be populated by XHR interceptor
                        var retries = 0;
                        var tryOpen = function () {
                            if (lastItemData) {
                                openWizard(modal);
                            } else if (retries < 10) {
                                retries++;
                                setTimeout(tryOpen, 100);
                            }
                            // else: fallback to original modal if data never arrives
                        };
                        setTimeout(tryOpen, 100);
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

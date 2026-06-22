/**
 * POS Wizard — Single-page order flow for fast POS checkout
 * Version: S25-SinglePage
 * Date: 2026-03-17
 *
 * Intercepts the item variation modal and transforms it into a single-page form.
 * All options displayed at once: Viandes, Pain, Crudités, Sauce, Supplements, Formule, Commentaire
 * No multi-step navigation — scrollable single page with sticky bottom bar.
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
    // Do NOT guard with window.location.pathname here — this script is loaded once for the
    // entire SPA session. If the user lands on /admin/dashboard first and then navigates to
    // /admin/pos via Vue Router, pathname is already evaluated and the wizard would never init.
    // Instead, the body-level MutationObserver in init() only activates when #item-variation-modal
    // actually appears in the DOM (which only happens on the POS page).

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
    let currentCategory = 'unknown'; // module-level so buildTicketInstruction can read it

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

    // [AUDIT-FIX P2-1] Prices injected from server via window.POS_WIZARD_CONFIG (master.blade.php).
    // Fallback values ensure the wizard works even if the config block fails to load.
    var _cfg = window.POS_WIZARD_CONFIG || {};
    var SAUCE_EXTRA_PRICE    = typeof _cfg.sauceExtraPrice   === 'number' ? _cfg.sauceExtraPrice   : 0.50;
    var VIANDE_SUPPL_PRICE   = typeof _cfg.viandeSupplPrice  === 'number' ? _cfg.viandeSupplPrice  : 2.50;
    var FRITES_GRANDE_PRICE  = typeof _cfg.fritesGrandePrice === 'number' ? _cfg.fritesGrandePrice : 1.00;
    var FRITES_CHEDDAR_PRICE = typeof _cfg.fritesCheddarPrice === 'number' ? _cfg.fritesCheddarPrice : 1.00;

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

    const VIANDE_EMOJIS = {
        'merguez': '🌶️', 'kefta': '🥩', 'poulet': '🍗', 'escalope': '🍗',
        'cordon': '🔵', 'bleu': '🔵', 'hachee': '🥩', 'viande hachee': '🥩',
        'nuggets': '🟡', 'tenders': '🍗', 'cayenne': '🌶️', 'fricadelle': '🌭',
        'mexicain': '🌮', 'default': '🥩'
    };

    const SAUCE_EMOJIS = {
        'ketchup': '🍅', 'mayonnaise': '🥚', 'mayo': '🥚', 'algerienne': '🌶️',
        'curry': '🍛', 'andalouse': '🌶️', 'burger': '🍔', 'samourai': '⚔️',
        'barbecue': '🔥', 'bbq': '🔥', 'cocktail': '🍹', 'americaine': '🇺🇸',
        'hannibal': '🦁', 'harissa': '🔥', 'blanche': '🥛', 'poivre': '🫓',
        'biggy': '🧄', 'sans': '🚫', 'default': '🥄'
    };

    function getEmoji(map, name) {
        var n = (name || '').toLowerCase().trim();
        for (var k in map) {
            if (k !== 'default' && n.includes(k)) return map[k];
        }
        return map['default'] || '';
    }

    /** Affiche image si thumb disponible, sinon emoji. Mode micro pour sauces/garnitures compactes */
    /**
     * [S24 FIX] Render option icon/pictogram
     * @param {string} thumb - Thumbnail URL
     * @param {string} emoji - Emoji/pictogram to use
     * @param {boolean} isMicro - Small size
     * @param {boolean} forceEmoji - [S24] Force emoji-only mode (for sauces, garnitures)
     */
    function renderOptionIcon(thumb, emoji, isMicro, forceEmoji) {
        // [LOCK XSS 2026-06-19] thumb (API image URL) + emoji (API field for non-getEmoji callers,
        // e.g. sauce.emoji/viande.emoji) flow into innerHTML here — escape both.
        // [S24 FIX] For sauces and garnitures, always use emoji/pictogram
        if (forceEmoji) {
            if (isMicro) {
                return '<span class="option-icon-micro force-emoji">' + escapeHtml(emoji || '🥄') + '</span>';
            }
            return '<span class="option-icon force-emoji">' + escapeHtml(emoji || '') + '</span>';
        }
        if (isMicro) {
            if (thumb && typeof thumb === 'string' && thumb.length > 0) {
                return '<img src="' + escapeHtml(thumb) + '" alt="" class="option-img-micro" />';
            }
            return '<span class="option-icon-micro">' + escapeHtml(emoji || '🥄') + '</span>';
        }
        if (thumb && typeof thumb === 'string' && thumb.length > 0) {
            return '<img src="' + escapeHtml(thumb) + '" alt="" class="option-img" />';
        }
        return '<span class="option-icon">' + escapeHtml(emoji || '') + '</span>';
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

    function _isItemDetailUrl(url) {
        if (typeof url !== 'string') return false;
        // Match both absolute (/api/admin/item/...) and relative (admin/item/...) URLs
        return url.includes('admin/item/') || url.includes('admin/setting/item/');
    }

    XMLHttpRequest.prototype.send = function () {
        if (this.__wizUrl && _isItemDetailUrl(this.__wizUrl)) {
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
       INTERCEPT FETCH TO CAPTURE ITEM DATA (Vue axios fallback)
       ============================== */
    var _originalFetch = window.fetch;
    window.fetch = function (url, options) {
        return _originalFetch.apply(this, arguments).then(function (response) {
            if (_isItemDetailUrl(typeof url === 'string' ? url : (url && url.url) || '')) {
                response.clone().json().then(function (d) {
                    if (d && d.data && (d.data.itemAttributes || d.data.variations || d.data.extras)) {
                        lastItemData = d.data;
                    }
                }).catch(function () { /* ignore */ });
            }
            return response;
        });
    };

    /* ==============================
       HELPERS
       ============================== */
    function fmtPrice(val) {
        var num = parseFloat(val) || 0;
        // [LOCK G-FROZEN-WIZARD-MONEY 2026-06-18 — owner-approved frozen-zone edit] FR money display: was
        // en-US '€' + num.toFixed(2) = "€0.90" (€-prefix, dot decimal), clashing with the FR "0,90 €" used
        // everywhere else in the app. Now Intl fr-FR (comma decimal + NBSP thousands) + " €" suffix =
        // "0,90 €", matching appService.currencyFormat. DISPLAY-ONLY: callers concatenate this string for
        // rendering and never parse it back (verified: 74 uses, all `+ fmtPrice`, no parseFloat(fmtPrice)).
        // The wizard's pricing math is untouched (it operates on the raw numbers, not this string).
        try {
            return new Intl.NumberFormat('fr-FR', { minimumFractionDigits: 2, maximumFractionDigits: 2 }).format(num) + ' €';
        } catch (e) {
            return num.toFixed(2).replace('.', ',') + ' €';
        }
    }

    // [LOCK XSS 2026-06-19] HTML-escape any user/API-controlled string before it is concatenated
    // into an innerHTML sink. Item/extra/variation/option names come from the items API (set via
    // items_edit) and were interpolated RAW into the `h`/`html`/`newHtml` builders assigned to
    // wizardEl.innerHTML — a name like `<img src=x onerror=...>` = stored XSS in the POS operator's
    // session. Render-only: escaped text still displays identically; pricing/selection/math untouched.
    // NOTE: never apply this to the print/ticket text path (buildTicketInstruction → textarea.value),
    // which is plain-text and must stay literal.
    function escapeHtml(s) {
        if (s === null || s === undefined) return '';
        return String(s)
            .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;').replace(/'/g, '&#39;');
    }

    /**
     * Detect viande count from item name
     * "Tacos M (1 viande)" → 1, "Tacos L (2 viandes)" → 2, etc.
     */
    function detectViandeCount(name) {
        if (!name) return 0;
        var n = normalizeStr(name);
        if (!n.includes('tacos')) return 0;

        // Try to extract number from parentheses: "(3 viande)"
        var match = n.match(/\((\d+)\s*viande/);
        if (match) return parseInt(match[1]);

        // [T2 FIX] Use anchored patterns relative to 'tacos' to avoid false matches
        // e.g. "Tacos Légumes" must not match 'l' as size L
        if (n.includes('xxl')) return 4;
        if (/tacos\s*xl\b/.test(n) && !n.includes('xxl')) return 3;
        if (/tacos\s*l\b/.test(n) || n.includes('tacos l ') || n.includes('tacos l(')) return 2;
        if (/tacos\s*m\b/.test(n) || n.includes('tacos m ') || n.includes('tacos m(')) return 1;

        return 0;
    }

    function hasViandeAttribute(data) {
        if (!data || !Array.isArray(data.itemAttributes)) return false;
        return data.itemAttributes.some(function (attr) {
            var n = normalizeStr(attr.name || '');
            return n.includes('viande') || n.includes('meat') || n.includes('proteine');
        });
    }

    function extractViandeCountFromText(text) {
        var n = normalizeStr(text || '');
        var match = n.match(/(\d+)\s*viande/);
        if (match) return parseInt(match[1]) || 0;
        return 0;
    }

    function detectViandeCountFromData(data) {
        if (!data) return 0;
        var byName = detectViandeCount(data.name || '');
        if (byName > 0) return byName;
        var byDesc = extractViandeCountFromText(data.description || '');
        if (byDesc > 0) return byDesc;
        if (hasViandeAttribute(data)) {
            var viandeAttrs = (data.itemAttributes || []).filter(function (attr) {
                var n = normalizeStr(attr.name || '');
                return n.includes('viande') || n.includes('meat') || n.includes('proteine');
            });
            if (viandeAttrs.length > 1) {
                return viandeAttrs.length;
            }
            if (viandeAttrs.length === 1) {
                var mx = parseInt(viandeAttrs[0].max_select, 10);
                if (isFinite(mx) && mx > 0) return mx;
            }
            return 1;
        }
        return 0;
    }

    function getViandeItemsFromData(data) {
        if (!data || !Array.isArray(data.itemAttributes) || !data.variations) return null;
        var items = [];
        var seenIds = {};
        var seenNames = {};
        data.itemAttributes.forEach(function (attr) {
            var attrName = normalizeStr(attr.name || '');
            var isViandeAttr = attrName.includes('viande') || attrName.includes('meat') || attrName.includes('proteine');
            if (!isViandeAttr) return;
            var vars = data.variations[attr.id.toString()] || [];
            vars.forEach(function (v) {
                var normName = normalizeStr(v.name || '');
                if (seenIds[v.id] || seenNames[normName]) return;
                seenIds[v.id] = true;
                seenNames[normName] = true;
                items.push({
                    key: 'viande_' + v.id,
                    id: v.id,
                    name: v.name,
                    emoji: getEmoji(SUPPLEMENT_EMOJIS, v.name),
                    thumb: v.thumb || null,
                    attributeId: attr.id
                });
            });
        });
        return items.length > 0 ? items : null;
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
    function getAllowedSteps(category, data) {
        switch (category) {
            case 'tacos':
                // NEW: 4 steps instead of 7 (viande+sauce combined, perso combined, menu inline)
                return ['viande_sauce', 'perso', 'menu', 'recap'];
            case 'sandwich':
                // [UI/UX Sprint 4] Pain/Galette step first, then others
                var viandeCount = detectViandeCountFromData(data || lastItemData || {});
                if (viandeCount > 0) {
                    // Sandwich with meats: pain + viande + perso + menu (5 steps)
                    return ['pain', 'viande_sauce', 'perso', 'menu', 'recap'];
                }
                // Sandwich no meat: pain + sauce_garnitures + supplements + recap (4 steps)
                return ['pain', 'sauce_garnitures', 'supplements_menu', 'recap'];
            case 'burger':
                // [BUG-WIZ-003 FIX] Check if item has meat slots (e.g., Terminator with 2 viandes)
                var viandeCount = detectViandeCountFromData(data || lastItemData || {});
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
       COMPOSER-AWARE PATH (T-WC-POS-RUNTIME-01)
       ============================== */
    function isComposerAwareEnabled() {
        return !!(window && window.foodkingConfig
            && window.foodkingConfig.posWizardComposerAware
            && window.foodkingConfig.posWizardComposerAware.enabled);
    }

    function getComposerProfileFromData(data) {
        if (!data) return null;
        var profile = data.composer_profile;
        if (!profile || !Array.isArray(profile.steps) || profile.steps.length === 0) return null;
        return profile;
    }

    var COMPOSER_STEP_KEY_MAP = {
        pain: 'pain', galette: 'pain', bun: 'pain',
        viande: 'viande', meat: 'viande', proteine: 'viande',
        sauce: 'sauce', sauces: 'sauce',
        garnitures: 'garnitures', garniture: 'garnitures', crudites: 'garnitures',
        supplements: 'supplements', supplement: 'supplements', extras: 'supplements',
        menu: 'menu', formule: 'menu', boisson: 'menu', drink: 'menu',
        frites: 'menu', side: 'menu', dessert: 'menu',
        taille: 'taille', size: 'taille'
    };

    var COMPOSER_ADDON_ROLE_MAP = {
        drink: 'menu', side: 'menu', dessert: 'menu', menu_component: 'menu'
    };

    // [V2-WIZARD-RT-REFACTOR-XL Batch B] Small internal extraction only.
    // Keeps behavior identical while making composer filters easier to audit.
    function isComposerStepVisibleOnPos(step) {
        var missingPos = Array.isArray(step.visible_on) ? step.visible_on.indexOf('pos') === -1 : false;
        // empty / missing visible_on => all surfaces
        if (!Array.isArray(step.visible_on) || step.visible_on.length === 0) {
            return true;
        }
        return !missingPos;
    }

    // [V2-WIZARD-RT-REFACTOR-XL Batch C] Defensive adapter seam:
    // normalize one composer step so malformed payloads degrade gracefully.
    function normalizeComposerStep(step) {
        if (!step || typeof step !== 'object') {
            return null;
        }
        return {
            step_key: step.step_key,
            label: step.label,
            min_select: step.min_select,
            max_select: step.max_select,
            choices: Array.isArray(step.choices) ? step.choices : [],
            allow_repeat: !!step.allow_repeat,
            visible_on: Array.isArray(step.visible_on) ? step.visible_on : [],
            addon_role: step.addon_role
        };
    }

    function buildStepsFromComposerProfile(profile, data) {
        var result = [];
        profile.steps.forEach(function (rawStep) {
            var step = normalizeComposerStep(rawStep);
            if (!step) return;
            // Filter visible_on (skip if 'pos' not in array — empty array = all surfaces)
            if (!isComposerStepVisibleOnPos(step)) {
                return;
            }
            // Resolve internal type: addon_role priority, then step_key, then generic_choices fallback
            var internalType = null;
            var addonRole = String(step.addon_role || '').toLowerCase().trim();
            if (addonRole && COMPOSER_ADDON_ROLE_MAP[addonRole]) {
                internalType = COMPOSER_ADDON_ROLE_MAP[addonRole];
            } else {
                var stepKey = String(step.step_key || '').toLowerCase().trim();
                if (stepKey && COMPOSER_STEP_KEY_MAP[stepKey]) {
                    internalType = COMPOSER_STEP_KEY_MAP[stepKey];
                }
            }
            if (!internalType) {
                if (Array.isArray(step.choices) && step.choices.length > 0) {
                    internalType = 'generic_choices';
                } else {
                    if (typeof console !== 'undefined' && console.warn) {
                        console.warn('[pos-wizard.composer] step skipped (unsupported)', {
                            step_key: step.step_key, label: step.label
                        });
                    }
                    return;
                }
            }
            result.push({
                type: internalType,
                key: step.step_key,
                label: step.label || step.step_key,
                min: Number(step.min_select) || 0,
                max: Number(step.max_select) || 1,
                options: Array.isArray(step.choices) ? step.choices : [],
                allow_repeat: !!step.allow_repeat,
                composer_step: step
            });
        });
        // Always append recap step for consistency with legacy buildSteps
        result.push({ type: 'recap', label: 'Récap', subtitle: 'Vérifiez votre commande' });
        return result;
    }

    /* ==============================
       BUILD STEPS FROM ITEM DATA
       ============================== */
    /**
     * [REFACTORED SPRINT 4] Build wizard steps based on item data.
     * NEW: Combined steps for faster POS workflow.
     * [T-WC-POS-RUNTIME-01] When flag pos_wizard_composer_aware.enabled=true and
     * data.composer_profile.steps is present, delegate to buildStepsFromComposerProfile
     * (admin-defined wizard) instead of legacy heuristic. Default OFF preserves legacy.
     */
    function buildSteps(data) {
        // [T-WC-POS-RUNTIME-01] Composer-aware early-return (gated by flag).
        if (isComposerAwareEnabled()) {
            var composerProfile = getComposerProfileFromData(data);
            if (composerProfile) {
                return buildStepsFromComposerProfile(composerProfile, data);
            }
        }

        var s = [];
        selections = {};

        // Detect category and allowed steps
        var category = detectCategory(data);
        var allowed = getAllowedSteps(category, data);

        // For categories that skip wizard entirely, return only recap
        if (category === 'menu_enfant' || category === 'dessert' || category === 'boisson') {
            return [{ type: 'recap', label: 'Récap', subtitle: 'Vérifiez votre commande' }];
        }

        // === PREPARE SHARED DATA ===
        var viandeCount = detectViandeCountFromData(data);
        var viandeItems = getViandeItemsFromData(data) || VIANDES;
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
                // [Sprint 23 Fix P3] Use DB price directly — no frontend hardcoding
                var unitPrice = parseFloat(ad.addon_item_convert_price)
                    || parseFloat(ad.addonItem && ad.addonItem.convert_price || 0)
                    || parseFloat(ad.total_convert_price) || 0;
                // [POS-WIZARD-DRINKS 2026-05-02] Capture group_label pour détection boisson
                // multi-priorité (alignée sur kioskIsDrinkAddon — symétrie POS↔borne).
                var groupLabel = '';
                if (ad.group_label) groupLabel = String(ad.group_label);
                else if (ad.addonItem && ad.addonItem.group_label) groupLabel = String(ad.addonItem.group_label);
                else if (ad.addon_item && ad.addon_item.group_label) groupLabel = String(ad.addon_item.group_label);
                return {
                    id: ad.id,
                    itemId: ad.addon_item_id || ad.item_addon_id,
                    name: ad.addon_item_name,
                    price: unitPrice,
                    currencyPrice: fmtPrice(unitPrice), // [LOCK G-FROZEN-WIZARD-MONEY-MISSED 2026-06-22 owner-countersigned] was '€'+toFixed = "€5.00" en-US → FR "5,00 €" (display-only, bypassed the 2026-06-18 fmtPrice sweep)
                    thumb: (ad.addonItem && ad.addonItem.thumb) ? ad.addonItem.thumb : (ad.thumb || ad.cover || ''),
                    groupLabel: groupLabel.toLowerCase()
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
                viandeItems: viandeItems,
                sauceItems: sauceList
            });
            // [T4 FIX] Init selections with BOTH 'v_<id>' keys (single-page) AND 'viande_<key>' keys (multi-step)
            // so both rendering paths work with the same selections object
            selections.viandes = {};
            viandeItems.forEach(function (v) {
                selections.viandes[v.key] = 0;        // multi-step key: 'viande_<key>'
                selections.viandes['v_' + v.id] = 0;  // single-page key: 'v_<id>'
            });
            selections.maxViandes = viandeCount;
            selections.totalViandes = 0;
            selections.sauces = {};
            selections.sauceOrder = [];
            selections.sauceAttrId = null;
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
            freeExtras.forEach(function (g) { selections.garnitures['c_' + g.id] = true; });
            selections.supplements = {};
            // Viandes supplémentaires : { 'v_123': count } — per-viande tracking
            selections.viandeSupplItems = {};
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
            selections.sauceAttrId = null;
            selections.garnitures = {};
            freeExtras.forEach(function (g) { selections.garnitures['c_' + g.id] = true; });
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

            // [KIOSK-UX-2026-04-17] « Boisson seule » n'a pas de sens comme formule sur sandwich/burger/tacos
            var _allowBoissonSeule = ['sandwich', 'burger', 'tacos'].indexOf(category) === -1;

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
                fritesSeules: fritesAddon ? { label: 'Frites Seules', price: fritesAddon.price ?? 2.00 } : { label: 'Frites Seules', price: 2.00 },
                boissonSeule: _allowBoissonSeule ? (boissonAddon ? { label: 'Boisson Seule', price: boissonAddon.price ?? 2.00 } : { label: 'Boisson Seule', price: 2.00 }) : null
            });
            selections.supplements = {};
            selections.menuChoice = null;
            selections.individualAddons = {};
            selections.sauceFrites = {};
            selections.sauceFritesOrder = [];
        }

        // === Step: Sauce + Accompagnement (Assiette - combined) ===
        if (allowed.indexOf('sauce_accompagnement') !== -1) {
            var accompKeywordsBS = ['riz', 'frites', 'salade', 'bourgoul', 'semoule', 'couscous', 'pates', 'legume'];
            var accompItems = freeExtras.filter(function (ex) {
                var n = normalizeStr(ex.name);
                return accompKeywordsBS.some(function (kw) { return n.includes(kw); });
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
            selections.sauceAttrId = null;
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
            selections.sauceAttrId = null;
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
            selections.sauceSingle = null;
            selections.sauceAttrId = null;
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

            // [KIOSK-UX-2026-04-17] Masquer « Boisson Seule » sur repas type sandwich/burger/tacos
            var _posAllowBoissonSeule = ['sandwich', 'burger', 'tacos'].indexOf(category) === -1;

            // [P1] Step: menu_choice — 3 options (Menu Complet / Frites / Rien)
            s.push({
                type: 'menu_choice',
                label: 'Formule',
                subtitle: 'Voulez-vous accompagner votre repas ?',
                menuComplet: menuComplet || { name: 'Menu Complet (Frites+Boisson)', price: 3.00, thumb: '' },
                fritesSeules: fritesSeules || { name: 'Frites Seules', price: 2.00, thumb: '' },
                boissonSeule: _posAllowBoissonSeule ? (boissonSeule || { name: 'Boisson Seule', price: 2.00, thumb: '' }) : null,
                sauceItems: sauceList.filter(function (item) { return item.name.toLowerCase() !== 'sans sauce'; })
            });

            // [P1] Step: frites_options — Taille + Cheddar (visible si frites choisies)
            // [S24 FIX] Mark as inline step (not shown in progress bar)
            s.push({
                type: 'frites_options',
                label: 'Options Frites',
                subtitle: 'Personnalisez vos frites',
                showCondition: 'hasFrites',
                inline: true,
                upgradePrice: 1.00,
                cheddarPrice: 1.00
            });

            // [P1] Step: sauce_frites — Sauce pour frites (visible si frites choisies)
            // [S24 FIX] Mark as inline step (not shown in progress bar)
            s.push({
                type: 'sauce_frites',
                label: 'Sauce Frites',
                subtitle: '1ère sauce gratuite pour vos frites',
                items: sauceList,
                showCondition: 'hasFrites',
                inline: true
            });

            // [POS-WIZARD-DRINKS 2026-05-02] Détection boisson catalogue-aware multi-priorité.
            // Aligné sur `kioskIsDrinkAddon` (resources/js/helpers/kioskDrinkAddons.js) pour
            // garantir la symétrie POS↔borne (même item, même règles, stock partagé via
            // le même item_id côté backend).
            //
            // Sources d'autorité (ordre décroissant) :
            //   P1. Catalogue Vue : addon dont l'`itemId` est dans la catégorie « boisson »
            //       (data-pos-drinks-catalog attribut DOM, alimenté par PosComponent.drinksCatalog)
            //   P2. Catalogue Vue : addon dont le nom matche un nom du catalogue
            //   P3. group_label explicite ('boisson' | 'drink' | 'drinks' | 'beverage')
            //   P4. Regex legacy (eau, thé, jus, lipton, evian, perrier, etc. — couvre cas
            //       où admin n'a pas encore configuré le catalogue/group_label)
            //
            // Exclusions explicites : addons formule (boisson seule), food-like (frites,
            // nuggets, wraps, etc.), addons groupés en frites ou menu.
            var boissonItems = (function () {
                var modalEl = document.getElementById('item-variation-modal');
                var catalogList = [];
                if (modalEl) {
                    var rawCatalog = modalEl.getAttribute('data-pos-drinks-catalog');
                    if (rawCatalog) {
                        try { catalogList = JSON.parse(rawCatalog) || []; } catch (e) { catalogList = []; }
                    }
                }
                var catalogIds = new Set();
                var catalogNames = new Set();
                for (var ci = 0; ci < catalogList.length; ci++) {
                    var d = catalogList[ci];
                    if (!d) continue;
                    if (d.id != null) catalogIds.add(String(d.id));
                    if (d.name) catalogNames.add(String(d.name).toLowerCase().trim());
                }

                var DRINK_LIKE_REGEX = /\b(coca|cola|pepsi|fanta|sprite|schweppes|eau|th[ée]|tea|ice\s?tea|jus|boisson|soda|drink|limonade|orangina|oasis|tropico|caf[ée]|coffee|red\s?bull|vittel|evian|perrier|badoit|heineken|1664|kronenbourg|desperados|kas|san\s?pellegrino|lipton|nestea|cristalline|volvic|monster|citron|raisin|7up|pomme)/i;
                var FOOD_LIKE_REGEX = /frite|patate|nugget|tender|onion|oignon|mozzarella|accompagn|snack|dessert|glace|wrap|cornet|potato|boulette|stick|ring|douille|corbeille|panier|barquette|salade/i;
                var GENERIC_OPTION_REGEX = /^\s*(?:\+?\s*)?(boisson|drink)(?:\s+(seule?|only))?\s*$/i;

                return addonItems.filter(function (a) {
                    var name = String(a.name || '').toLowerCase().trim();
                    if (!name) return false;
                    if (GENERIC_OPTION_REGEX.test(name)) return false;
                    if (FOOD_LIKE_REGEX.test(name)) return false;
                    if (name.indexOf('menu') !== -1) return false;

                    if (a.itemId != null && catalogIds.has(String(a.itemId))) return true;
                    if (catalogNames.has(name)) return true;

                    var gl = a.groupLabel || '';
                    if (gl === 'boisson' || gl === 'drink' || gl === 'drinks' || gl === 'beverage') return true;
                    if (gl !== '' && (gl.indexOf('frite') !== -1 || gl.indexOf('food') !== -1 || gl === 'menu' || gl.indexOf('menu_') === 0)) return false;

                    return DRINK_LIKE_REGEX.test(name);
                });
            })();
            if (boissonItems.length > 0) {
                s.push({
                    type: 'boisson_choice',
                    label: 'Boisson',
                    subtitle: 'Choisissez votre boisson',
                    showCondition: 'hasBoisson',
                    inline: true,
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
     * [S24 FIX] Added forProgressBar param to exclude inline steps from progress bar
     */
    function getActiveSteps(forProgressBar) {
        return steps.filter(function (step) {
            // [S24 FIX] Exclude inline steps from progress bar display
            if (forProgressBar && step.inline) {
                return false;
            }
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

    /**
     * [S24 FIX] Added forProgressBar param to match getActiveSteps behavior
     */
    function getActiveStepIndex(forProgressBar) {
        var active = getActiveSteps(forProgressBar);
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
                html += '<img src="' + escapeHtml(lastItemData.thumb) + '" alt="item" class="wizard-item-img">';
            }
            html += '<div class="wizard-item-info">';
            html += '<h2>' + escapeHtml(lastItemData.name) + '</h2>';
            html += '<p class="wizard-item-price">' + fmtPrice(basePrice) + '</p>';
            html += '</div>';
            
            // [PLAN_07 UX-03] Badge étape X/Y — affiché sauf sur le récap
            // [S24 FIX] Use forProgressBar=true to exclude inline steps from badge count
            var step = steps[currentStep];
            var isRecap = step.type === 'recap';
            if (!isRecap) {
                var progressSteps = getActiveSteps(true);
                var currentStepNum = 0;
                for (var psi = 0; psi < progressSteps.length; psi++) {
                    if (progressSteps[psi] === step) {
                        currentStepNum = psi + 1;
                        break;
                    }
                }
                // Exclure le récap du total visible (dernière étape = récap)
                var totalStepsWithoutRecap = progressSteps.length - 1;
                if (currentStepNum > 0) {
                    html += '<div class="wizard-step-badge">';
                    html += '<span class="step-badge-current">' + currentStepNum + '</span>';
                    html += '<span class="step-badge-sep">/</span>';
                    html += '<span class="step-badge-total">' + totalStepsWithoutRecap + '</span>';
                    html += '</div>';
                }
            }

            html += '</div>';
        }

        // Progress bar with labels
        // [S24 FIX] Use forProgressBar=true to exclude inline steps from progress bar
        var activeSteps = getActiveSteps(true);
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
        html += '<h3>' + escapeHtml(step.label) + '</h3>';
        html += '<p>' + escapeHtml(step.subtitle || '') + '</p>';
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
            h += '<span class="viande-emoji">' + escapeHtml(viande.emoji) + '</span>';
            h += '<span class="viande-name">' + escapeHtml(viande.name) + '</span>';
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
                priceLabel = '<span class="option-price paid">+' + fmtPrice(SAUCE_EXTRA_PRICE) + '</span>';
            } else {
                priceLabel = '<span class="option-price">' + (count === 0 ? 'Gratuit' : '+' + fmtPrice(SAUCE_EXTRA_PRICE)) + '</span>';
            }

            var hiddenClass = (hasMoreSauces && index >= limit) ? ' hidden-opt' : '';
            h += '<div class="wizard-option sauce-opt micro-opt' + sel + hiddenClass + '" data-type="sauce" data-id="' + sauce.id + '">';
            h += '<span class="check-mark"><i class="fa-solid fa-check"></i></span>';
            h += renderOptionIcon(sauce.thumb, sauce.emoji, true, true); // [S24 FIX] Force emoji for sauces
            h += '<span class="option-name">' + escapeHtml(sauce.name) + '</span>';
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
        // Supplements — [S25] Use 'p_' + extra.id key format from lastItemData.extras
        if (selections.supplements && lastItemData && lastItemData.extras) {
            lastItemData.extras.forEach(function (extra_item) {
                var key = 'p_' + extra_item.id;
                if (selections.supplements[key]) {
                    extra += parseFloat(extra_item.convert_price) || 0;
                }
            });
        }
        // Sauce frites extra
        if (selections.sauceFritesOrder && selections.sauceFritesOrder.length > 1) {
            extra += (selections.sauceFritesOrder.length - 1) * SAUCE_EXTRA_PRICE;
        }
        // Viandes supplémentaires — sum all per-viande extra counts
        if (selections.viandeSupplItems) {
            var supplTotal = 0;
            Object.keys(selections.viandeSupplItems).forEach(function (k) {
                supplTotal += selections.viandeSupplItems[k] || 0;
            });
            extra += supplTotal * VIANDE_SUPPL_PRICE;
        }
        // Formule (addon) — [S25] Extract price from lastItemData.addons using 'addon_123' format
        // [P1-3 FIX] Also handle legacy menuChoice values 'full'/'frites'/'boisson' by name lookup
        var addonTotal = 0;
        if (selections.menuChoice && selections.menuChoice !== 'none' && lastItemData && lastItemData.addons) {
            var addonMatch = selections.menuChoice.match(/^addon_(\d+)$/);
            var selectedAddon = null;
            if (addonMatch) {
                var selectedAddonId = parseInt(addonMatch[1]);
                selectedAddon = lastItemData.addons.find(function (a) { return a.id === selectedAddonId; });
            } else if (selections.menuChoice === 'full') {
                selectedAddon = lastItemData.addons.find(function (a) {
                    var n = (a.addon_item_name || a.name || '').toLowerCase();
                    return n.includes('menu') || (n.includes('frite') && n.includes('boisson'));
                });
            } else if (selections.menuChoice === 'frites') {
                selectedAddon = lastItemData.addons.find(function (a) {
                    var n = (a.addon_item_name || a.name || '').toLowerCase();
                    return n.includes('frite') && !n.includes('boisson') && !n.includes('menu');
                });
            } else if (selections.menuChoice === 'boisson') {
                selectedAddon = lastItemData.addons.find(function (a) {
                    var n = (a.addon_item_name || a.name || '').toLowerCase();
                    return (n.includes('boisson') || n.includes('coca') || n.includes('jus')) && !n.includes('frite');
                });
            }
            if (selectedAddon) {
                var addonPriceStr = (selectedAddon.addon_item_currency_price || '0').replace(/[^0-9.,]/g, '').replace(',', '.');
                addonTotal += parseFloat(addonPriceStr) || 0;
            }
        }
        // Frites upgrade options — prices from POS_WIZARD_CONFIG
        if (selections.fritesGrande) addonTotal += FRITES_GRANDE_PRICE;
        if (selections.fritesCheddar) addonTotal += FRITES_CHEDDAR_PRICE;

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
            h += renderOptionIcon(sauce.thumb, sauce.emoji, false, true); // [S24 FIX] Force emoji for sauces
            h += '<span class="option-name">' + escapeHtml(sauce.name) + '</span>';
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
            h += renderOptionIcon(item.thumb, getEmoji(GARNITURE_EMOJIS, item.name), false, true); // [S24 FIX] Force emoji for garnitures
            h += '<span class="option-name">' + escapeHtml(item.name) + '</span>';
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
            h += renderOptionIcon(item.thumb, getEmoji(GARNITURE_EMOJIS, item.name), true, true); // [S24 FIX] Force emoji for garnitures
            h += '<span class="option-name">' + escapeHtml(item.name) + '</span>';
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
            h += renderOptionIcon(item.thumb, getEmoji(SUPPLEMENT_EMOJIS, item.name), true, true); // [S24 FIX] Force emoji for supplements
            h += '<span class="option-name">' + escapeHtml(item.name) + '</span>';
            h += '<span class="option-price paid">+' + escapeHtml(item.currencyPrice) + '</span>';
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
        h += '<div class="menu-card-name">' + escapeHtml(step.menuComplet.name) + '</div>';
        h += '<div class="menu-card-price">+' + fmtPrice(step.menuComplet.price) + '</div>';
        h += '<div class="menu-card-desc">Frites + Boisson incluse</div>';
        h += '</div>';

        // Option 2: Frites Seules
        var selFrites = selections.menuChoice === 'frites' ? ' selected' : '';
        h += '<div class="menu-choice-card' + selFrites + '" data-action="menu-choice" data-value="frites">';
        h += '<div class="menu-card-icon">' + renderOptionIcon(step.fritesSeules.thumb, '🍟') + '</div>';
        h += '<div class="menu-card-name">' + escapeHtml(step.fritesSeules.name) + '</div>';
        h += '<div class="menu-card-price">+' + fmtPrice(step.fritesSeules.price) + '</div>';
        h += '<div class="menu-card-desc">Juste les frites</div>';
        h += '</div>';

        // [S21-6 FIX] Option 3: Boisson Seule (was built in step but never rendered)
        if (step.boissonSeule) {
            var selBoisson = selections.menuChoice === 'boisson' ? ' selected' : '';
            h += '<div class="menu-choice-card' + selBoisson + '" data-action="menu-choice" data-value="boisson">';
            h += '<div class="menu-card-icon">' + renderOptionIcon(step.boissonSeule.thumb, '🥤') + '</div>';
            h += '<div class="menu-card-name">' + escapeHtml(step.boissonSeule.name) + '</div>';
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
                    h += '<span class="option-icon has-img"><img src="' + escapeHtml(boisson.thumb) + '" alt="' + escapeHtml(boisson.name) + '"></span>';
                } else {
                    h += '<span class="option-icon">' + getEmoji(ADDON_EMOJIS, boisson.name) + '</span>';
                }
                h += '<span class="option-name">' + escapeHtml(boisson.name) + '</span>';
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
                priceLabel = '<span class="option-price paid">+' + fmtPrice(SAUCE_EXTRA_PRICE) + '</span>';
            } else {
                priceLabel = '<span class="option-price">' + (count === 0 ? 'Gratuit' : '+' + fmtPrice(SAUCE_EXTRA_PRICE)) + '</span>';
            }

            h += '<div class="wizard-option sauce-opt' + sel + '" data-type="sauce_frite" data-id="' + sauce.id + '">';
            h += '<span class="check-mark"><i class="fa-solid fa-check"></i></span>';
            h += renderOptionIcon(sauce.thumb, sauce.emoji, false, true); // [S24 FIX] Force emoji for sauces
            h += '<span class="option-name">' + escapeHtml(sauce.name) + '</span>';
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
            h += '<span class="option-icon" style="font-size: 36px; margin-bottom: 8px;">' + escapeHtml(pain.emoji) + '</span>';
            h += '<span class="option-name">' + escapeHtml(pain.name) + '</span>';
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
        h += '<h4>🥩 ' + escapeHtml(step.label) + '</h4>';
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
            h += '<span class="viande-emoji">' + escapeHtml(viande.emoji) + '</span>';
            h += '<span class="viande-name">' + escapeHtml(viande.name) + '</span>';
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
            else if (idx > 0) priceLabel = '<span class="option-price paid">+' + fmtPrice(SAUCE_EXTRA_PRICE) + '</span>';
            else priceLabel = '<span class="option-price">' + (sauceCount === 0 ? 'Gratuit' : '+' + fmtPrice(SAUCE_EXTRA_PRICE)) + '</span>';

            h += '<div class="wizard-option sauce-opt' + sel + '" data-type="sauce" data-id="' + sauce.id + '">';
            h += '<span class="check-mark"><i class="fa-solid fa-check"></i></span>';
            h += renderOptionIcon(sauce.thumb, sauce.emoji, false, true); // [S24 FIX] Force emoji for sauces
            h += '<span class="option-name">' + escapeHtml(sauce.name) + '</span>';
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
            h += '<h4>🥬 Garnitures</h4>';
            h += '<p class="wizard-hint">Cliquez pour retirer une crudité (affichage rouge ✕ Sans ...)</p>';
            h += '<div class="garniture-toggle">';
            step.freeItems.forEach(function (g) {
                // [H2 FIX] Use 'c_<id>' key (unified format) with fallback to numeric key
                var cKey = 'c_' + g.id;
                var isIncluded = !selections.garnitures ||
                    (selections.garnitures[cKey] !== false && selections.garnitures[g.id] !== false);
                var stateClass = isIncluded ? ' included' : ' removed';
                var emoji = getEmoji(GARNITURE_EMOJIS, g.name);
                var label = isIncluded ? ('✓ ' + escapeHtml(g.name)) : ('✕ Sans ' + escapeHtml(g.name));
                h += '<button type="button" class="garniture-toggle-btn' + stateClass + '" data-type="garniture" data-id="' + g.id + '" data-name="' + escapeHtml(g.name) + '" data-emoji="' + escapeHtml(emoji) + '">';
                h += emoji + ' ' + label;
                h += '</button>';
            });
            h += '</div>';
            h += '</div>';
        }

        // Section 2: Viandes supplémentaires — per-viande selector (multi-step path)
        if (step.viandeVariations && step.viandeVariations.length > 0) {
            h += '<div class="wizard-section">';
            h += '<h4>➕ Extra (+' + fmtPrice(VIANDE_SUPPL_PRICE) + '/viande)</h4>';
            h += '<div class="wizard-viande-suppl-section">';
            step.viandeVariations.forEach(function (variation) {
                var key = 'v_' + variation.id;
                var sc = (selections.viandeSupplItems && selections.viandeSupplItems[key]) || 0;
                var emoji = getEmoji(VIANDE_EMOJIS, variation.name);
                h += '<div class="wizard-viande-suppl-row' + (sc > 0 ? ' active' : '') + '" data-suppl-id="' + key + '">';
                h += '<div class="viande-info"><span class="viande-emoji">' + escapeHtml(emoji) + '</span><span class="viande-name">' + escapeHtml(variation.name) + '</span></div>';
                h += '<div class="viande-controls">';
                h += '<button type="button" class="viande-suppl-btn viande-btn minus' + (sc <= 0 ? ' disabled' : '') + '" data-viande-suppl="' + key + '" data-action="minus">−</button>';
                h += '<span class="viande-suppl-count viande-count">' + sc + '</span>';
                h += '<button type="button" class="viande-suppl-btn viande-btn plus" data-viande-suppl="' + key + '" data-action="plus">+</button>';
                h += '</div></div>';
            });
            h += '</div>';
            h += '</div>';
        }

        // Section 3: Suppléments
        if (step.paidItems && step.paidItems.length > 0) {
            h += '<div class="wizard-section">';
            h += '<h4>➕ Suppléments (payants)</h4>';
            h += '<div class="wizard-options supplement-grid">';
            step.paidItems.forEach(function (s) {
                var sel = selections.supplements && selections.supplements[s.id] ? ' selected' : '';
                var emoji = getEmoji(SUPPLEMENT_EMOJIS, s.name);
                h += '<div class="wizard-option supplement-opt' + sel + '" data-type="supplement" data-id="' + s.id + '">';
                h += '<span class="check-mark"><i class="fa-solid fa-check"></i></span>';
                h += renderOptionIcon(s.thumb, emoji, false, true); // [S24 FIX] Force emoji for supplements
                h += '<span class="option-name">' + escapeHtml(s.name) + '</span>';
                h += '<span class="option-price">' + escapeHtml(s.currencyPrice) + '</span>';
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
            else if (idx > 0) priceLabel = '<span class="option-price paid">+' + fmtPrice(SAUCE_EXTRA_PRICE) + '</span>';
            else priceLabel = '<span class="option-price">' + (sauceCount === 0 ? 'Gratuit' : '+' + fmtPrice(SAUCE_EXTRA_PRICE)) + '</span>';

            h += '<div class="wizard-option sauce-opt' + sel + '" data-type="sauce" data-id="' + sauce.id + '">';
            h += '<span class="check-mark"><i class="fa-solid fa-check"></i></span>';
            h += renderOptionIcon(sauce.thumb, sauce.emoji, false, true); // [S24 FIX] Force emoji for sauces
            h += '<span class="option-name">' + escapeHtml(sauce.name) + '</span>';
            h += priceLabel;
            h += '</div>';
        });
        h += '</div>';
        h += '</div>';

        // RIGHT: Garnitures
        h += '<div class="wizard-col">';
        h += '<h4>🥬 Garnitures</h4>';
        h += '<p class="wizard-hint">Tout est inclus par défaut. Cliquez pour retirer (rouge ✕ Sans ...)</p>';
        h += '<div class="garniture-toggle">';
        step.garnitureItems.forEach(function (g) {
            // [H2 FIX] Read using 'c_<id>' key (unified format) with fallback to numeric key
            var cKey = 'c_' + g.id;
            var isIncluded = !selections.garnitures ||
                (selections.garnitures[cKey] !== false && selections.garnitures[g.id] !== false);
            var stateClass = isIncluded ? ' included' : ' removed';
            var emoji = getEmoji(GARNITURE_EMOJIS, g.name);
            var label = isIncluded ? ('✓ ' + escapeHtml(g.name)) : ('✕ Sans ' + escapeHtml(g.name));
            h += '<button type="button" class="garniture-toggle-btn' + stateClass + '" data-type="garniture" data-id="' + g.id + '" data-name="' + escapeHtml(g.name) + '" data-emoji="' + escapeHtml(emoji) + '">';
            h += emoji + ' ' + label;
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
                h += '<span class="option-name">' + escapeHtml(s.name) + '</span>';
                h += '<span class="option-price">' + escapeHtml(s.currencyPrice) + '</span>';
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
            h += '<div class="menu-price">+' + fmtPrice(menuPrice) + '</div>'; // [LOCK G-FROZEN-WIZARD-MONEY-MISSED 2026-06-22] was "+€3.00" en-US → FR "+3,00 €"
            h += '<div class="menu-desc">Frites + Boisson</div>';
            h += '</div>';

            // Individual options
            step.menuItems.forEach(function (addon) {
                h += '<div class="wizard-menu-card' + (selections.individualAddons && selections.individualAddons[addon.id] ? ' selected' : '') + '" data-addon-id="' + addon.id + '">';
                h += '<div class="menu-icon">' + (addon.name.toLowerCase().includes('frites') ? '🍟' : '🥤') + '</div>';
                h += '<div class="menu-name">' + escapeHtml(addon.name) + '</div>';
                h += '<div class="menu-price">' + escapeHtml(addon.currencyPrice) + '</div>';
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
            h += '<span class="option-name">Grande Portion (+' + fmtPrice(FRITES_GRANDE_PRICE) + ')</span>';
            h += '</div>';
            h += '<div class="wizard-option frites-opt' + (!selections.fritesGrande ? ' selected' : '') + '" data-action="frites-size" data-value="normal">';
            h += '<span class="check-mark"><i class="fa-solid fa-check"></i></span>';
            h += '<span class="option-name">Portion Normale (incluse)</span>';
            h += '</div>';
            // Cheddar toggle
            h += '<div class="wizard-option frites-opt' + (selections.fritesCheddar ? ' selected' : '') + '" data-action="frites-cheddar" data-value="yes">';
            h += '<span class="check-mark"><i class="fa-solid fa-check"></i></span>';
            h += '<span class="option-name">Avec Cheddar Fondu (+' + fmtPrice(FRITES_CHEDDAR_PRICE) + ')</span>';
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
                else if (idx > 0) priceLabel = '<span class="option-price paid">+' + fmtPrice(SAUCE_EXTRA_PRICE) + '</span>';
                else priceLabel = '<span class="option-price">' + (sauceFCount === 0 ? 'Gratuit' : '+' + fmtPrice(SAUCE_EXTRA_PRICE)) + '</span>';

                h += '<div class="wizard-option sauce-frite-opt' + sel + '" data-type="sauce_frite" data-id="' + sauce.id + '">';
                h += '<span class="check-mark"><i class="fa-solid fa-check"></i></span>';
                h += renderOptionIcon(sauce.thumb, sauce.emoji, false, true); // [S24 FIX] Force emoji for sauces
                h += '<span class="option-name">' + escapeHtml(sauce.name) + '</span>';
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
            else if (idx > 0) priceLabel = '<span class="option-price paid">+' + fmtPrice(SAUCE_EXTRA_PRICE) + '</span>';
            else priceLabel = '<span class="option-price">' + (sauceCount === 0 ? 'Gratuit' : '+' + fmtPrice(SAUCE_EXTRA_PRICE)) + '</span>';

            h += '<div class="wizard-option sauce-opt' + sel + '" data-type="sauce" data-id="' + sauce.id + '">';
            h += '<span class="check-mark"><i class="fa-solid fa-check"></i></span>';
            h += renderOptionIcon(sauce.thumb, sauce.emoji, false, true); // [S24 FIX] Force emoji for sauces
            h += '<span class="option-name">' + escapeHtml(sauce.name) + '</span>';
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
                h += renderOptionIcon(acc.thumb, emoji, false, true); // [S24 FIX] Force emoji for accompaniments
                h += '<span class="option-name">' + escapeHtml(acc.name) + '</span>';
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
            else if (idx > 0) priceLabel = '<span class="option-price paid">+' + fmtPrice(SAUCE_EXTRA_PRICE) + '</span>';
            else priceLabel = '<span class="option-price">' + (sauceCount === 0 ? 'Gratuit' : '+' + fmtPrice(SAUCE_EXTRA_PRICE)) + '</span>';

            h += '<div class="wizard-option sauce-opt' + sel + '" data-type="sauce" data-id="' + sauce.id + '">';
            h += '<span class="check-mark"><i class="fa-solid fa-check"></i></span>';
            h += renderOptionIcon(sauce.thumb, sauce.emoji, false, true); // [S24 FIX] Force emoji for sauces
            h += '<span class="option-name">' + escapeHtml(sauce.name) + '</span>';
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
                h += renderOptionIcon(s.thumb, emoji, false, true); // [S24 FIX] Force emoji for supplements
                h += '<span class="option-name">' + escapeHtml(s.name) + '</span>';
                h += '<span class="option-price">' + escapeHtml(s.currencyPrice) + '</span>';
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
        h += '<div class="wizard-ticket-head">';
        h += '<div class="wizard-ticket-title">' + ((lastItemData && lastItemData.name) ? escapeHtml(lastItemData.name) : 'Votre commande') + '</div>';
        h += '<div class="wizard-ticket-subtitle">Résumé clair avant validation panier</div>';
        h += '</div>';
        h += '<div class="wizard-recap-section-title">Choix principaux</div>';

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
                    h += '<div class="wizard-recap-row"><span class="label">' + escapeHtml(painItem.emoji) + ' Pain' + editBtn('pain') + '</span><span class="value">' + escapeHtml(painItem.name) + '</span></div>';
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
                    escapeHtml(viandeNames.join(', ')) + '</span></div>';
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
                        sauceNames.push(sauce.name + ' (+' + fmtPrice(SAUCE_EXTRA_PRICE) + ')');
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
                    escapeHtml(sauceNames.join(', ')) + '</span></div>';
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
                        escapeHtml(sName) + ' (inclus)</span></div>';
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
                        escapeHtml(accName) + ' (inclus)</span></div>';
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
            var garnIncluded = [];
            var garnRemoved = [];
            garnItems.forEach(function (g) {
                // [H2 FIX] Check both 'c_<id>' and numeric key
                var cKey = 'c_' + g.id;
                var isIncluded = !selections.garnitures ||
                    (selections.garnitures[cKey] !== false && selections.garnitures[g.id] !== false);
                if (isIncluded) {
                    garnIncluded.push(g.name);
                } else {
                    garnRemoved.push('Sans ' + g.name);
                }
            });
            var garnGoto = 'garnitures';
            if (steps.find(function (s) { return s.type === 'perso'; })) garnGoto = 'perso';
            else if (steps.find(function (s) { return s.type === 'sauce_garnitures'; })) garnGoto = 'sauce_garnitures';
            h += '<div class="wizard-recap-row"><span class="label">🥬 Crudités incluses' + editBtn(garnGoto) + '</span><span class="value">' +
                (garnIncluded.length > 0 ? escapeHtml(garnIncluded.join(', ')) : 'Aucune') + '</span></div>';
            if (garnRemoved.length > 0) {
                h += '<div class="wizard-recap-row"><span class="label">✕ Retirées</span><span class="value">' +
                    escapeHtml(garnRemoved.join(', ')) + '</span></div>';
            }
        }

        // Supplements (from supplements, perso, supplements_menu, or sauce_supplements step)
        var hasSuppStep = steps.some(function (s) {
            return ['supplements', 'perso', 'supplements_menu', 'sauce_supplements'].indexOf(s.type) !== -1;
        });
        if (hasSuppStep) h += '<div class="wizard-recap-section-title">Extras et formules</div>';
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
                    escapeHtml(suppNames.join(', ')) + '</span></div>';
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
                        escapeHtml(formuleLabel) + ' <span style="color:#E93C3C;font-weight:700">+' + fmtPrice(formulePrice) + '</span></span></div>'; // [LOCK G-FROZEN-WIZARD-MONEY-MISSED 2026-06-22] was "+€3.00" en-US → FR "+3,00 €"
                }
                // Frites upgrades — prices from POS_WIZARD_CONFIG
                if (selections.fritesGrande) {
                    addonTotal += FRITES_GRANDE_PRICE;
                    h += '<div class="wizard-recap-row"><span class="label">🍟 Option frites' + editBtn('frites_options') + '</span><span class="value">Grande Portion <span style="color:#E93C3C;font-weight:700">+' + fmtPrice(FRITES_GRANDE_PRICE) + '</span></span></div>';
                }
                if (selections.fritesCheddar) {
                    addonTotal += FRITES_CHEDDAR_PRICE;
                    h += '<div class="wizard-recap-row"><span class="label"> </span><span class="value">Cheddar Fondu <span style="color:#E93C3C;font-weight:700">+' + fmtPrice(FRITES_CHEDDAR_PRICE) + '</span></span></div>';
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
                        escapeHtml(addonNames.join(' + ')) + '</span></div>';
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
                            escapeHtml(indNames.join(', ')) + '</span></div>';
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
                        sfNames.push(sauce.name + ' (+' + fmtPrice(SAUCE_EXTRA_PRICE) + ')');
                        totalExtra += SAUCE_EXTRA_PRICE;
                    }
                }
            });
            if (sfNames.length > 0) {
                var sfGoto = steps.find(function (s) { return s.type === 'supplements_menu'; }) ? 'supplements_menu' : 'menu';
                h += '<div class="wizard-recap-row"><span class="label">🍟 Sauce frites' + editBtn(sfGoto) + '</span><span class="value">' +
                    escapeHtml(sfNames.join(', ')) + '</span></div>';
            }
        }

        // Total
        var unitPrice = basePrice + totalExtra;
        var total = (unitPrice + addonTotal) * itemQuantity;
        h += '<div class="wizard-recap-row total"><span class="label">Total</span><span class="value">' +
            fmtPrice(total) + '</span></div>';

        h += '</div>'; // .wizard-recap

        var summaryInstruction = buildWizardInstruction();
        h += '<div class="wizard-instruction-summary">';
        h += summaryInstruction ? ('Instruction KDS: ' + summaryInstruction) : 'Aucune instruction spéciale.';
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

        // Viandes: "VIANDES : X, Y, +Z" — all merged, no "Viande 1 / Viande 2" labels
        if (selections.viandes) {
            var viandeNames = [];
            var viandeStepForInstruction = steps.find(function (s) { return s.type === 'viande' || s.type === 'viande_sauce'; });
            var viandeItemsForInstruction = viandeStepForInstruction ? (viandeStepForInstruction.items || viandeStepForInstruction.viandeItems || VIANDES) : VIANDES;
            viandeItemsForInstruction.forEach(function (v) {
                var count = (selections.viandes['v_' + v.id] || 0) + (selections.viandes[v.key] || 0);
                if (count > 0) {
                    viandeNames.push(count > 1 ? count + '\u00d7 ' + v.name : v.name);
                }
            });
            // Viandes supplémentaires — append inline with price
            if (selections.viandeSupplItems && lastItemData && lastItemData.variations) {
                Object.keys(selections.viandeSupplItems).forEach(function (key) {
                    var sc = selections.viandeSupplItems[key] || 0;
                    if (sc <= 0) return;
                    var vid = parseInt(key.replace('v_', ''));
                    var viandeName = key;
                    Object.keys(lastItemData.variations).forEach(function (attrId) {
                        var found = lastItemData.variations[attrId].find(function (v) { return v.id === vid; });
                        if (found) viandeName = found.name;
                    });
                    viandeNames.push('+' + (sc > 1 ? sc + '\u00d7 ' : '') + viandeName + ' (+' + fmtPrice(sc * VIANDE_SUPPL_PRICE) + ')');
                });
            }
            if (viandeNames.length > 0) {
                parts.push('VIANDES : ' + viandeNames.join(', '));
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

        // Sauces: "SAUCE : X, Y, Z" — all sauces on one line, no "(1ère Gratuite)" / "SUPPL" split
        if (selections.sauceOrder && selections.sauceOrder.length > 0) {
            var allSauceItems = [];
            steps.forEach(function (s) {
                if (s.sauceItems) allSauceItems = allSauceItems.concat(s.sauceItems);
                if (s.items && s.type === 'sauce') allSauceItems = allSauceItems.concat(s.items);
            });
            var allSauceNames = [];
            selections.sauceOrder.forEach(function (sKey) {
                var sId = typeof sKey === 'string' ? parseInt((sKey.match(/_(\d+)$/) || [])[1] || sKey) : sKey;
                var sauce = allSauceItems.find(function (ss) { return ss.id === sId; });
                if (sauce) allSauceNames.push(sauce.name);
            });
            if (allSauceNames.length > 0) {
                parts.push('SAUCE : ' + allSauceNames.join(', '));
            }
        }

        // sauceSingle for omelette/snacking
        if (selections.sauceSingle) {
            var sauceSingleStep = steps.find(function (s) { return s.type === 'sauce_single'; });
            if (sauceSingleStep && sauceSingleStep.items) {
                var sauceSingleItem = sauceSingleStep.items.find(function (ss) {
                    return ss.id === selections.sauceSingle;
                });
                if (sauceSingleItem) {
                    parts.push('SAUCE : ' + sauceSingleItem.name);
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
                // [H2 FIX] Check both 'c_<id>' and numeric key for included/excluded state
                // [X7 FIX] Align with buildTicketInstruction: "not explicitly false = included"
                // so default-included crudités (initialized to true but possibly undefined after
                // a partial restore) still appear in the recap.
                var cKey = 'c_' + g.id;
                var cVal = selections.garnitures[cKey];
                var numVal = selections.garnitures[g.id];
                // Explicitly excluded if any key is false
                var isExcluded = cVal === false || numVal === false;
                // Included = not excluded (matches buildTicketInstruction semantics)
                if (isExcluded) garnExcluded.push(g.name);
                else garnIncluded.push(g.name);
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
        // [P1-2 FIX] Keys are 'p_<id>' on single-page path — strip prefix before numeric comparison
        if (selections.supplements) {
            var supplNames = [];
            Object.keys(selections.supplements).forEach(function (key) {
                if (!selections.supplements[key]) return;
                var numericId = String(key).replace(/^p_/, '');
                steps.forEach(function (s) {
                    (s.paidItems || []).concat(s.items || []).forEach(function (p) {
                        if (String(p.id) === numericId) supplNames.push(p.name);
                    });
                });
            });
            if (supplNames.length > 0) {
                parts.push('SUPPLÉMENTS: ' + supplNames.join(', '));
            }
        }

        // Formule/Menu
        if (selections.menuChoice) {
            // [X2 FIX] Handle addon_N path (single-page formule) — resolve real addon name from lastItemData
            var addonNMatch = selections.menuChoice.match(/^addon_(\d+)$/);
            if (addonNMatch && lastItemData && lastItemData.addons) {
                var addonNId = parseInt(addonNMatch[1]);
                var addonNItem = lastItemData.addons.find(function (a) { return a.id === addonNId; });
                if (addonNItem) {
                    var addonNLabel = addonNItem.addon_item_name || addonNItem.name || ('Addon #' + addonNId);
                    parts.push('FORMULE: ' + addonNLabel);
                }
            } else if (selections.menuChoice === 'full') {
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
        if (selections.fritesGrande) fritesOptions.push('Grande portion (+' + fmtPrice(FRITES_GRANDE_PRICE) + ')');
        if (selections.fritesCheddar) fritesOptions.push('Cheddar (+' + fmtPrice(FRITES_CHEDDAR_PRICE) + ')');
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
                selections.sauceFritesOrder.forEach(function (rawId) {
                    // [X3 FIX] sauceFritesOrder stores 'sf_<id>' strings on single-page path.
                    // Extract numeric id before comparing to sfItems[n].id (which is a number).
                    var numericSfId = typeof rawId === 'string'
                        ? parseInt((rawId.match(/_(\d+)$/) || [])[1] || rawId)
                        : rawId;
                    var sauce = sfItems.find(function (ss) { return ss.id === numericSfId; });
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

        var result = parts.length > 0 ? parts.join('. ') + '.' : null;

        // [S24 FIX] Smarter truncation: keep full labels, truncate content if needed
        // Priority: keep VIANDES, SAUCE, FORMULE complete; truncate long lists last
        if (result && result.length > 190) {
            // First pass: try shorter separators
            result = parts.join(' | ') + '.';
        }
        if (result && result.length > 200) {
            // Second pass: truncate the longest content sections (supplements/garnitures)
            var maxPerSection = Math.floor((200 - parts.length * 10) / parts.length);
            var shortenedParts = parts.map(function (part) {
                if (part.length > maxPerSection + 15) {
                    // Keep label, truncate content
                    var colonIdx = part.indexOf(':');
                    if (colonIdx > 0) {
                        var label = part.substring(0, colonIdx + 1);
                        var content = part.substring(colonIdx + 2);
                        // Truncate content, keep first items
                        var items = content.split(', ');
                        if (items.length > 3) {
                            return label + ' ' + items.slice(0, 3).join(', ') + '...';
                        }
                    }
                }
                return part;
            });
            result = shortenedParts.join(' | ') + '.';
        }
        if (result && result.length > 250) {
            // Last resort: hard truncate with ellipsis
            result = result.substring(0, 247) + '...';
        }

        return result;
    }

    /**
     * Build a clean multi-line display string for the cashier's cart view.
     * No prices, no KDS-style uppercase, just the essentials in reading order.
     * Used to populate data-wizard-cart-display → cart_display in Vue store.
     */
    function buildCartDisplay() {
        var lines = [];

        // Viandes (no prices)
        if (selections.viandes) {
            var viandeNames = [];
            var viandeStepForDisplay = steps.find(function (s) { return s.type === 'viande' || s.type === 'viande_sauce'; });
            var viandeItemsForDisplay = viandeStepForDisplay ? (viandeStepForDisplay.items || viandeStepForDisplay.viandeItems || VIANDES) : VIANDES;
            viandeItemsForDisplay.forEach(function (v) {
                var count = (selections.viandes['v_' + v.id] || 0) + (selections.viandes[v.key] || 0);
                if (count > 0) {
                    viandeNames.push(count > 1 ? count + '\u00d7 ' + v.name : v.name);
                }
            });
            if (selections.viandeSupplItems) {
                Object.keys(selections.viandeSupplItems).forEach(function (key) {
                    var sc = selections.viandeSupplItems[key] || 0;
                    if (sc <= 0) return;
                    var vid = parseInt(key.replace('v_', ''));
                    var viandeName = key;
                    if (lastItemData && lastItemData.variations) {
                        Object.keys(lastItemData.variations).forEach(function (attrId) {
                            var found = lastItemData.variations[attrId].find(function (v) { return v.id === vid; });
                            if (found) viandeName = found.name;
                        });
                    }
                    viandeNames.push('+' + (sc > 1 ? sc + '\u00d7 ' : '') + viandeName);
                });
            }
            if (viandeNames.length > 0) {
                lines.push('Viandes: ' + viandeNames.join(', '));
            }
        }

        // Pain
        if (selections.pain && wizardItemData && wizardItemData.variations) {
            var painVariation = null;
            Object.values(wizardItemData.variations).forEach(function (group) {
                if (Array.isArray(group)) {
                    group.forEach(function (v) {
                        if (v.id === selections.pain) painVariation = v;
                    });
                }
            });
            if (painVariation) lines.push('Pain: ' + painVariation.name);
        }

        // Garnitures / crudités
        if (selections.garnitures) {
            var allGarnItems = [];
            steps.forEach(function (s) {
                var items = s.garnitureItems || s.freeItems || (s.type === 'garnitures' ? s.items : null) || [];
                items.forEach(function (g) {
                    if (!allGarnItems.find(function (x) { return x.id === g.id; })) allGarnItems.push(g);
                });
            });
            var garnIncluded = [];
            var garnExcluded = [];
            allGarnItems.forEach(function (g) {
                var cKey = 'c_' + g.id;
                var cVal = selections.garnitures[cKey];
                var numVal = selections.garnitures[g.id];
                if (cVal === false || numVal === false) garnExcluded.push(g.name);
                else garnIncluded.push(g.name);
            });
            if (garnExcluded.length > 0) lines.push('Sans: ' + garnExcluded.join(', '));
            if (garnIncluded.length > 0) lines.push('Crudités: ' + garnIncluded.join(', '));
        }

        // Sauce sandwich
        if (selections.sauceOrder && selections.sauceOrder.length > 0) {
            var allSauceItems = [];
            steps.forEach(function (s) {
                if (s.sauceItems) allSauceItems = allSauceItems.concat(s.sauceItems);
                if (s.items && s.type === 'sauce') allSauceItems = allSauceItems.concat(s.items);
            });
            var sauceNames = [];
            selections.sauceOrder.forEach(function (sKey) {
                var sId = typeof sKey === 'string' ? parseInt((sKey.match(/_(\d+)$/) || [])[1] || sKey) : sKey;
                var sauce = allSauceItems.find(function (ss) { return ss.id === sId; });
                if (sauce) sauceNames.push(sauce.name);
            });
            if (sauceNames.length > 0) lines.push('Sauce: ' + sauceNames.join(', '));
        }

        // Sauce unique (omelette / snacking)
        if (selections.sauceSingle) {
            var sauceSingleStepCD = steps.find(function (s) { return s.type === 'sauce_single'; });
            if (sauceSingleStepCD && sauceSingleStepCD.items) {
                var sauceSingleItemCD = sauceSingleStepCD.items.find(function (ss) { return ss.id === selections.sauceSingle; });
                if (sauceSingleItemCD) lines.push('Sauce: ' + sauceSingleItemCD.name);
            }
        }

        // Accompagnement (assiettes)
        if (selections.accompagnement) {
            var accompStepCD = steps.find(function (s) { return s.type === 'sauce_accompagnement'; });
            if (accompStepCD && accompStepCD.accompItems) {
                var accompItemCD = accompStepCD.accompItems.find(function (a) { return a.id === selections.accompagnement; });
                if (accompItemCD) lines.push('Accompagnement: ' + accompItemCD.name);
            }
        }

        // Suppléments (no prices)
        if (selections.supplements) {
            var supplNamesCD = [];
            Object.keys(selections.supplements).forEach(function (key) {
                if (!selections.supplements[key]) return;
                var numericId = String(key).replace(/^p_/, '');
                steps.forEach(function (s) {
                    (s.paidItems || []).concat(s.items || []).forEach(function (p) {
                        if (String(p.id) === numericId) supplNamesCD.push(p.name);
                    });
                });
            });
            if (supplNamesCD.length > 0) lines.push('Suppléments: ' + supplNamesCD.join(', '));
        }

        return lines.join('\n');
    }

    /* ==============================
       [S25] SINGLE-PAGE RENDERER
       ============================== */
    /**
     * New single-page order flow: all questions on one screen
     * No multi-step navigation, just scrollable sections
     */
    /**
     * [S25] Render single-page POS cashier UI from real item data
     * Derives all sections from lastItemData instead of hardcoded lists
     */
    function renderSinglePage() {
        var basePrice = 0;
        if (lastItemData) {
            basePrice = lastItemData.offer && lastItemData.offer.length > 0
                ? parseFloat(lastItemData.offer[0].convert_price) || 0
                : parseFloat(lastItemData.convert_price) || 0;
        }

        var category = detectCategory(lastItemData);
        currentCategory = category; // hoist to module scope for buildTicketInstruction
        var h = '<div class="pos-wizard single-page">';

        // Header : image + nom + prix + Qté inline à droite
        if (lastItemData) {
            h += '<div class="wizard-item-header">';
            if (lastItemData.thumb) {
                h += '<img src="' + escapeHtml(lastItemData.thumb) + '" alt="item" class="wizard-item-img">';
            }
            h += '<div class="wizard-item-info">';
            h += '<h2>' + escapeHtml(lastItemData.name) + '</h2>';
            h += '<p class="wizard-item-price">' + fmtPrice(basePrice) + '</p>';
            h += '</div>';
            // Qté inline à droite du nom
            h += '<div class="wizard-qty-inline">';
            h += '<button type="button" class="wizard-qty-btn" data-qty="minus">−</button>';
            h += '<span class="wizard-qty-value">' + itemQuantity + '</span>';
            h += '<button type="button" class="wizard-qty-btn" data-qty="plus">+</button>';
            h += '</div>';
            h += '</div>';
        }

        // Pain/Galette (sandwich only) — affiché seul sans section "Qté"
        if (lastItemData && lastItemData.itemAttributes && category === 'sandwich') {
            var painAttr = lastItemData.itemAttributes.find(function (attr) {
                var n = normalizeStr(attr.name);
                return n.includes('pain') || n.includes('type');
            });

            if (painAttr && lastItemData.variations && lastItemData.variations[painAttr.id]) {
                var painVariations = lastItemData.variations[painAttr.id];
                h += '<div class="wizard-top-row">';
                h += '<div class="pain-section">';
                h += '<h4>🥖 Type de pain</h4>';
                h += '<div class="pain-segment">';
                painVariations.forEach(function (variation) {
                    var sel = selections.pain === variation.id ? ' selected' : '';
                    var emoji = variation.name.toLowerCase().includes('galette') ? '🫓' : '🥖';
                    h += '<button type="button" class="pain-btn' + sel + '" data-type="pain" data-id="' + variation.id + '">';
                    h += '<span class="pain-emoji">' + emoji + '</span>';
                    h += escapeHtml(variation.name);
                    h += '</button>';
                });
                h += '</div>';
                h += '</div>';
                h += '</div>'; // .wizard-top-row
            }
        }

        // === SECTIONS 2+3: VIANDES + CRUDITÉS (2 colonnes) ===
        var hasViandes = false, hasCrudites = false;
        var viandeAttrs = [];
        var crudites = [];

        if (lastItemData && lastItemData.itemAttributes) {
            viandeAttrs = lastItemData.itemAttributes.filter(function (attr) {
                var n = normalizeStr(attr.name);
                return n.includes('viande') || n.includes('meat');
            });
            hasViandes = viandeAttrs.length > 0 && !!lastItemData.variations;
        }
        if (lastItemData && lastItemData.extras) {
            crudites = lastItemData.extras.filter(function (extra) {
                return extra.convert_price === 0 && isCruditeName(extra.name);
            });
            hasCrudites = crudites.length > 0;
        }

        // === SECTIONS 4+5: SAUCE + SUPPLÉMENTS — computed early so layout can be decided ===
        var sauceVariations = [];
        var hasSauce = false, hasSupplements = false;
        var supplements = [];

        if (lastItemData && lastItemData.itemAttributes) {
            var sauceAttr = lastItemData.itemAttributes.find(function (attr) {
                var n = normalizeStr(attr.name);
                return n.includes('sauce') && !n.includes('frites');
            });
            if (sauceAttr && lastItemData.variations && lastItemData.variations[sauceAttr.id]) {
                sauceVariations = lastItemData.variations[sauceAttr.id];
                hasSauce = true;
            }
        }
        if (lastItemData && lastItemData.extras) {
            supplements = lastItemData.extras.filter(function (extra) {
                if (normalizeStr(extra.name).includes('sauce')) return false;
                return extra.convert_price > 0 || isSupplementName(extra.name);
            });
            hasSupplements = supplements.length > 0;
        }

        // Helper: render the sauce block HTML (reused in both layout paths)
        function _renderSauceBlock() {
            var sauceCount = selections.sauceOrder ? selections.sauceOrder.length : 0;
            var sh = '<div class="wizard-2col-block sauce-section">';
            sh += '<div class="section-header">';
            sh += '<h4>🥄 Sauce</h4>';
            if (sauceCount === 0) {
                sh += '<span class="sauce-badge free">1ère gratuite</span>';
            } else if (sauceCount === 1) {
                sh += '<span class="sauce-badge free">✅ Gratuite</span>';
            } else {
                var extraCost = (sauceCount - 1) * SAUCE_EXTRA_PRICE;
                sh += '<span class="sauce-badge paid">+' + fmtPrice(extraCost) + '</span>';
            }
            sh += '</div>';
            sh += '<div class="sauce-chips-grid">';
            sauceVariations.forEach(function (sauce) {
                var key = 's_' + sauce.id;
                var sel = selections.sauces && selections.sauces[key] ? ' selected' : '';
                var idx = selections.sauceOrder ? selections.sauceOrder.indexOf(key) : -1;
                var badge = idx === 0 ? ' <span class="chip-free">✓</span>' : (idx > 0 ? ' <span class="chip-paid">+' + fmtPrice(SAUCE_EXTRA_PRICE) + '</span>' : '');
                sh += '<button type="button" class="sauce-chip' + sel + '" data-type="sauce" data-id="' + key + '">';
                sh += escapeHtml(sauce.name) + badge;
                sh += '</button>';
            });
            sh += '</div>';
            sh += '</div>'; // .wizard-2col-block sauce
            return sh;
        }

        // Helper: render the supplements block HTML
        function _renderSupplBlock() {
            var supplSelected = selections.supplements ? Object.values(selections.supplements).filter(Boolean).length : 0;
            var supplOpen = supplSelected > 0 || selections.supplExpanded === true;
            var supplToggleLabel = supplSelected > 0
                ? '➕ Suppléments (' + supplSelected + ' choisi' + (supplSelected > 1 ? 's' : '') + ') ' + (supplOpen ? '▲' : '▼')
                : '➕ Suppléments ' + (supplOpen ? '▲' : '▼');
            var sh = '<div class="wizard-2col-block supplements-section">';
            sh += '<button type="button" class="suppl-toggle' + (supplSelected > 0 ? ' has-items' : '') + '" data-action="toggle-suppl">' + supplToggleLabel + '</button>';
            sh += '<div class="suppl-panel' + (supplOpen ? '' : ' collapsed') + '">';
            sh += '<div class="wizard-options supplement-grid">';
            supplements.forEach(function (sup) {
                var key = 'p_' + sup.id;
                var sel = selections.supplements && selections.supplements[key] ? ' selected' : '';
                var emoji = getEmoji(SUPPLEMENT_EMOJIS, sup.name);
                var price = sup.currency_price || '1,00 €'; // [LOCK G-FROZEN-WIZARD-MONEY-MISSED 2026-06-22] FR fallback (was en-US '€1.00')
                sh += '<div class="wizard-option supplement-opt micro-opt' + sel + '" data-type="supplement" data-key="' + key + '">';
                sh += '<span class="check-mark"><i class="fa-solid fa-check"></i></span>';
                sh += '<span class="option-icon supplement-icon">' + emoji + '</span>';
                sh += '<span class="option-name">' + escapeHtml(sup.name) + '</span>';
                sh += '<span class="option-price paid">+' + escapeHtml(price) + '</span>';
                sh += '</div>';
            });
            sh += '</div>';
            sh += '</div>'; // .suppl-panel
            sh += '</div>'; // .wizard-2col-block supplements
            return sh;
        }

        // === SECTION VIANDES (pleine largeur) ===
        if (hasViandes) {
            // Deduplicate by id AND normalized name (API sends "Viande 1" + "Viande 2" with same list)
            var seenViandeIds = {};
            var seenViandeNames = {};
            var viandeVariations = [];
            viandeAttrs.forEach(function (attr) {
                var vars = lastItemData.variations[attr.id] || [];
                vars.forEach(function (v) {
                    var normName = normalizeStr(v.name || '');
                    if (!seenViandeIds[v.id] && !seenViandeNames[normName]) {
                        seenViandeIds[v.id] = true;
                        seenViandeNames[normName] = true;
                        viandeVariations.push(v);
                    }
                });
            });
            var maxViandes = detectViandeCountFromData(lastItemData);
            if (maxViandes === 0) maxViandes = viandeAttrs.length;
            var totalV = selections.totalViandes || 0;

            h += '<div class="wizard-section viande-section">';
            h += '<div class="section-header">';
            h += '<h4>🥩 Viande' + (maxViandes > 1 ? 's' : '') + '</h4>';
            h += '<span class="quota-badge ' + (totalV === maxViandes ? 'complete' : '') + '">' + totalV + '/' + maxViandes + '</span>';
            h += '</div>';

            h += '<div class="wizard-viande-list">';
            viandeVariations.forEach(function (variation) {
                var key = 'v_' + variation.id;
                var count = selections.viandes[key] || 0;
                var canAdd = totalV < maxViandes;
                var emoji = getEmoji(VIANDE_EMOJIS, variation.name);
                h += '<div class="wizard-viande-row' + (count > 0 ? ' active' : '') + '">';
                h += '<div class="viande-info">';
                h += '<span class="viande-emoji">' + emoji + '</span>';
                h += '<span class="viande-name">' + escapeHtml(variation.name) + '</span>';
                h += '</div>';
                h += '<div class="viande-controls">';
                h += '<button type="button" class="viande-btn minus' + (count <= 0 ? ' disabled' : '') + '" data-viande="' + key + '" data-action="minus">−</button>';
                h += '<span class="viande-count">' + count + '</span>';
                h += '<button type="button" class="viande-btn plus' + (!canAdd ? ' disabled' : '') + '" data-viande="' + key + '" data-action="plus">+</button>';
                h += '</div>';
                h += '</div>';
            });
            h += '</div>';

            // Viandes supplémentaires — bouton toggle + liste dépliable
            var totalVSuppl = 0;
            if (selections.viandeSupplItems) {
                Object.keys(selections.viandeSupplItems).forEach(function (k) { totalVSuppl += selections.viandeSupplItems[k] || 0; });
            }
            var isExpanded = totalVSuppl > 0 || (selections.viandeSupplExpanded === true);
            var toggleLabel = totalVSuppl > 0
                ? '🥩+ ' + totalVSuppl + ' viande' + (totalVSuppl > 1 ? 's' : '') + ' extra (+' + fmtPrice(totalVSuppl * VIANDE_SUPPL_PRICE) + ') ' + (isExpanded ? '▲' : '▼')
                : '➕ Viande supplémentaire (+' + fmtPrice(VIANDE_SUPPL_PRICE) + '/viande) ' + (isExpanded ? '▲' : '▼');
            h += '<button type="button" class="viande-suppl-toggle' + (totalVSuppl > 0 ? ' has-items' : '') + '" data-action="toggle-suppl">' + toggleLabel + '</button>';
            h += '<div class="wizard-viande-suppl-section' + (isExpanded ? '' : ' collapsed') + '" id="viande-suppl-panel">';
            viandeVariations.forEach(function (variation) {
                var key = 'v_' + variation.id;
                var sc = (selections.viandeSupplItems && selections.viandeSupplItems[key]) || 0;
                var emoji = getEmoji(VIANDE_EMOJIS, variation.name);
                h += '<div class="wizard-viande-suppl-row' + (sc > 0 ? ' active' : '') + '" data-suppl-id="' + key + '">';
                h += '<div class="viande-info">';
                h += '<span class="viande-emoji">' + emoji + '</span>';
                h += '<span class="viande-name">' + escapeHtml(variation.name) + '</span>';
                h += '</div>';
                h += '<div class="viande-controls">';
                h += '<button type="button" class="viande-suppl-btn viande-btn minus' + (sc <= 0 ? ' disabled' : '') + '" data-viande-suppl="' + key + '" data-action="minus">−</button>';
                h += '<span class="viande-suppl-count viande-count">' + sc + '</span>';
                h += '<button type="button" class="viande-suppl-btn viande-btn plus" data-viande-suppl="' + key + '" data-action="plus">+</button>';
                h += '</div>';
                h += '</div>';
            });
            h += '</div>';
            h += '</div>'; // .wizard-section viande
        }

        // === SECTION CRUDITÉS + SAUCE (toujours côte à côte, juste après les viandes) ===
        // Crudités gauche, Sauce droite — peu importe si viandes présentes ou non.
        if (hasCrudites || hasSauce) {
            h += '<div class="wizard-2col">';

            if (hasCrudites) {
                h += '<div class="wizard-2col-block crudites-section">';
                h += '<h4>🥬 Crudités</h4>';
                h += '<p class="section-hint">Cliquez pour retirer.</p>';
                h += '<div class="garniture-toggle">';
                crudites.forEach(function (c) {
                    var key = 'c_' + c.id;
                    var isIncluded = selections.garnitures && selections.garnitures[key];
                    if (selections.garnitures && selections.garnitures[key] === undefined) {
                        isIncluded = true;
                    }
                    var stateClass = isIncluded ? ' included' : ' removed';
                    var label = isIncluded ? ('✓ ' + escapeHtml(c.name)) : ('✕ Sans ' + escapeHtml(c.name));
                    var emoji = getEmoji(GARNITURE_EMOJIS, c.name);
                    h += '<button type="button" class="garniture-toggle-btn' + stateClass + '" data-garniture="' + key + '">' + emoji + ' ' + label + '</button>';
                });
                h += '</div>';
                h += '</div>'; // .wizard-2col-block crudites
            }

            if (hasSauce) {
                h += _renderSauceBlock();
            }

            h += '</div>'; // .wizard-2col crudites+sauce
        }

        // === SUPPLÉMENTS (seuls sur leur ligne) ===
        if (hasSupplements) {
            h += '<div class="wizard-2col">';
            h += _renderSupplBlock();
            h += '</div>';
        }

        // === SECTION 5b: ACCOMPAGNEMENT (Assiettes only) ===
        // [A1 FIX] Show accompagnement radio for assiette category
        if (category === 'assiette' && lastItemData && lastItemData.extras) {
            var accompKeywords = ['riz', 'frites', 'salade', 'bourgoul', 'semoule', 'couscous', 'pates', 'legume'];
            var accompItems = lastItemData.extras.filter(function (ex) {
                var n = normalizeStr(ex.name);
                return ex.convert_price === 0 && accompKeywords.some(function (kw) { return n.includes(kw); });
            });
            if (accompItems.length > 0) {
                h += '<div class="wizard-section accompagnement-section">';
                h += '<h4>🍽️ Accompagnement</h4>';
                h += '<div class="wizard-options accompagnement-grid">';
                accompItems.forEach(function (acc) {
                    var isSelected = selections.accompagnement === acc.id;
                    var emoji = normalizeStr(acc.name).includes('riz') ? '🍚' :
                                normalizeStr(acc.name).includes('frite') ? '🍟' :
                                normalizeStr(acc.name).includes('salade') ? '🥗' : '🍽️';
                    h += '<div class="wizard-option radio-opt' + (isSelected ? ' selected' : '') + '" data-type="accompagnement" data-id="' + acc.id + '">';
                    h += '<span class="radio-mark"><i class="fa-solid fa-circle-dot"></i></span>';
                    h += '<span class="option-icon">' + emoji + '</span>';
                    h += '<span class="option-name">' + escapeHtml(acc.name) + '</span>';
                    h += '<span class="option-price">Inclus</span>';
                    h += '</div>';
                });
                h += '</div>';
                h += '</div>';
            }
        }

        // === SECTION 6: FORMULE (from addons) ===
        if (lastItemData && lastItemData.addons && lastItemData.addons.length > 0) {
            h += '<div class="wizard-section formule-section">';
            h += '<h4>🍟🥤 Formule</h4>';
            h += '<div class="wizard-formule-cards">';

            // Sans formule
            var selNone = selections.menuChoice === 'none' ? ' selected' : '';
            h += '<div class="formule-card' + selNone + '" data-action="menu-choice" data-value="none">';
            h += '<span class="formule-icon">🚫</span>';
            h += '<span class="formule-name">Sans formule</span>';
            h += '<span class="formule-price">—</span>';
            h += '</div>';

            // Addons as formule choices
            lastItemData.addons.forEach(function (addon) {
                var value = 'addon_' + addon.id;
                var sel = selections.menuChoice === value ? ' selected' : '';
                var icon = addon.addon_item_name.toLowerCase().includes('boisson') ? '🥤' :
                           addon.addon_item_name.toLowerCase().includes('frite') ? '🍟' : '🍟🥤';
                h += '<div class="formule-card' + sel + '" data-action="menu-choice" data-value="' + value + '">';
                h += '<span class="formule-icon">' + icon + '</span>';
                h += '<span class="formule-name">' + escapeHtml(addon.addon_item_name) + '</span>';
                h += '<span class="formule-price">+' + escapeHtml(addon.addon_item_currency_price) + '</span>';
                h += '</div>';
            });

            h += '</div>';

            // Determine visibility for frites-related sub-sections
            var showSauceFrites = false;
            if (selections.menuChoice && selections.menuChoice !== 'none') {
                var sfRenderMatch = selections.menuChoice.match(/^addon_(\d+)$/);
                if (sfRenderMatch && lastItemData.addons) {
                    var sfRenderAddon = lastItemData.addons.find(function (a) { return a.id === parseInt(sfRenderMatch[1]); });
                    if (sfRenderAddon) {
                        var sfRenderName = normalizeStr(sfRenderAddon.addon_item_name || '');
                        showSauceFrites = sfRenderName.includes('frite') || sfRenderName.includes('menu');
                    }
                }
            }
            // Frites upgrade options (hardcoded — shown when formule includes frites or menu)
            h += '<div class="frites-upgrades-inline' + (showSauceFrites ? ' visible' : '') + '">';
            h += '<h4>Options frites</h4>';
            h += '<div class="wizard-options supplement-grid">';
            var selGrande = selections.fritesGrande ? ' selected' : '';
            h += '<div class="wizard-option frites-upgrade-opt micro-opt' + selGrande + '" data-upgrade="fritesGrande">';
            h += '<span class="check-mark"><i class="fa-solid fa-check"></i></span>';
            h += '<span class="option-name">Grande Portion</span>';
            h += '<span class="option-price paid">+' + fmtPrice(FRITES_GRANDE_PRICE) + '</span>';
            h += '</div>';
            var selCheddar = selections.fritesCheddar ? ' selected' : '';
            h += '<div class="wizard-option frites-upgrade-opt micro-opt' + selCheddar + '" data-upgrade="fritesCheddar">';
            h += '<span class="check-mark"><i class="fa-solid fa-check"></i></span>';
            h += '<span class="option-name">Cheddar Fondu</span>';
            h += '<span class="option-price paid">+' + fmtPrice(FRITES_CHEDDAR_PRICE) + '</span>';
            h += '</div>';
            h += '</div>';
            h += '</div>';

            // Sauce frites section — reuses the same sauce list as the main sauce section
            if (sauceVariations.length > 0) {
                h += '<div class="sauce-frites-inline' + (showSauceFrites ? ' visible' : '') + '">';
                h += '<h4>🍟 Sauce pour frites</h4>';
                h += '<div class="sauce-chips-grid">';
                var sfRenderCount = selections.sauceFritesOrder ? selections.sauceFritesOrder.length : 0;
                sauceVariations.forEach(function (sauce) {
                    var key = 'sf_' + sauce.id;
                    var sel = selections.sauceFrites && selections.sauceFrites[key] ? ' selected' : '';
                    var sfIdx = selections.sauceFritesOrder ? selections.sauceFritesOrder.indexOf(key) : -1;
                    var badge = sfIdx === 0 ? ' <span class="chip-free">✓</span>' : (sfIdx > 0 ? ' <span class="chip-paid">+' + fmtPrice(SAUCE_EXTRA_PRICE) + '</span>' : '');
                    h += '<button type="button" class="sauce-chip' + sel + '" data-type="sauce_frite" data-id="' + key + '">';
                    h += escapeHtml(sauce.name) + badge;
                    h += '</button>';
                });
                h += '</div>';
                h += '</div>';
            }

            h += '</div>';
        }

        // === SECTION 7: COMMENTAIRE ===
        h += '<div class="wizard-section comment-section">';
        h += '<h4>📝 Instruction spéciale</h4>';
        h += '<textarea class="wizard-comment-field" placeholder="Ex: Pas trop de sauce, sandwich pas trop sec...">' + escapeHtml(instructionText || '') + '</textarea>';
        h += '</div>';

        // === TICKET PREVIEW ===
        var ticket = buildTicketInstruction();
        h += '<div class="wizard-ticket-preview">';
        h += '<div class="ticket-label">Aperçu ticket</div>';
        h += '<div class="ticket-content">' + escapeHtml(ticket || 'Aucune sélection') + '</div>';
        h += '</div>';

        // === STICKY BOTTOM BAR ===
        var runTotal = calculateRunningTotal();
        h += '<div class="wizard-sticky-bar">';
        h += '<button type="button" class="wizard-btn-cancel" data-action="cancel-wizard">';
        h += '<i class="fa-solid fa-xmark"></i> Annuler';
        h += '</button>';
        h += '<div class="sticky-total">';
        h += '<span class="total-label">Total</span>';
        h += '<span class="total-value">' + fmtPrice(runTotal) + '</span>';
        h += '</div>';
        h += '<button type="button" class="wizard-btn-cart" data-action="add-to-cart">';
        h += '<i class="fa-solid fa-cart-shopping"></i> Ajouter au panier';
        h += '</button>';
        h += '</div>';

        h += '</div>';
        return h;
    }

    // Helper to check if name is a crudite
    // [M2 FIX] Expanded list of crudité names. [L1 FIX] Removed duplicate 'salade'.
    function isCruditeName(name) {
        var n = normalizeStr(name);
        return n.includes('salade') || n.includes('tomate') || n.includes('oignon') ||
               n.includes('crudite') || n.includes('legume') || n.includes('concombre') ||
               n.includes('mais') || n.includes('carotte') || n.includes('poivron') ||
               n.includes('laitue') || n.includes('roquette') || n.includes('epinard') ||
               n.includes('betterave') || n.includes('radis') || n.includes('celeri');
    }

    // Helper to check if name is a paid supplement (not a sauce extra, not a crudite)
    function isSupplementName(name) {
        var n = normalizeStr(name);
        // Exclude sauce extras (e.g. "Sauce supplémentaire: Ketchup")
        if (n.includes('sauce')) return false;
        return n.includes('jambon') || n.includes('fromage') || n.includes('boursin') ||
               n.includes('oeuf') || n.includes('raclette') || n.includes('cheddar') ||
               n.includes('bacon') || n.includes('supplement');
    }

    /**
     * Update reactive elements in single-page view without full re-render
     */
    function updateSinglePageUI() {
        if (!wizardEl) return;

        // [B1/R4 FIX] Calculate max viandes using detectViandeCountFromData first (reads name/description)
        var maxViandes = detectViandeCountFromData(lastItemData);
        if (maxViandes === 0 && lastItemData && lastItemData.itemAttributes) {
            var viandeAttrs = lastItemData.itemAttributes.filter(function (attr) {
                var n = normalizeStr(attr.name);
                return n.includes('viande') || n.includes('meat');
            });
            maxViandes = viandeAttrs.length || 1;
        }
        if (maxViandes === 0) maxViandes = 1;

        // Update viande quota badge
        var quotaBadge = wizardEl.querySelector('.viande-section .quota-badge');
        if (quotaBadge) {
            var total = selections.totalViandes || 0;
            quotaBadge.textContent = total + '/' + maxViandes;
            quotaBadge.className = 'quota-badge ' + (total === maxViandes ? 'complete' : '');
        }

        // Update complete text
        var completeText = wizardEl.querySelector('.viande-section .complete-text');
        if (completeText) {
            var total = selections.totalViandes || 0;
            completeText.style.display = total === maxViandes ? 'inline' : 'none';
        }

        // Update viande row active states and counts
        wizardEl.querySelectorAll('.wizard-viande-row').forEach(function (row) {
            var viandeKey = row.querySelector('.viande-btn')?.getAttribute('data-viande');
            if (!viandeKey) return;
            var count = selections.viandes[viandeKey] || 0;
            row.classList.toggle('active', count > 0);
            var countEl = row.querySelector('.viande-count');
            if (countEl) countEl.textContent = count;
        });

        // Update viande button disabled states
        // [V1 FIX] Exclude .viande-suppl-btn — supplementary viande buttons have no quota cap
        // and must remain enabled even when the main viande slots are full.
        var totalV = selections.totalViandes || 0;
        wizardEl.querySelectorAll('.viande-btn.plus:not(.viande-suppl-btn)').forEach(function (btn) {
            var canAdd = totalV < maxViandes;
            btn.classList.toggle('disabled', !canAdd);
        });

        // Update viandes supplémentaires per-viande counts
        wizardEl.querySelectorAll('.viande-suppl-btn').forEach(function (btn) {
            var key = btn.getAttribute('data-viande-suppl');
            if (!key) return;
            var sc = (selections.viandeSupplItems && selections.viandeSupplItems[key]) || 0;
            var action = btn.getAttribute('data-action');
            if (action === 'minus') btn.classList.toggle('disabled', sc <= 0);
            var row = wizardEl.querySelector('[data-suppl-id="' + key + '"]');
            if (row) {
                row.classList.toggle('active', sc > 0);
                var countEl = row.querySelector('.viande-suppl-count');
                if (countEl) countEl.textContent = sc;
            }
        });

        // Update pain selection — .pain-btn (single-page) or .pain-opt (step mode)
        wizardEl.querySelectorAll('.pain-btn, .pain-opt').forEach(function (opt) {
            var id = opt.getAttribute('data-id');
            var parsedId = parseInt(id);
            var storedId = isNaN(parsedId) ? id : parsedId;
            opt.classList.toggle('selected', selections.pain === storedId);
        });

        // [A1 FIX] Update accompagnement radio selection
        wizardEl.querySelectorAll('.wizard-option[data-type="accompagnement"]').forEach(function (opt) {
            var id = parseInt(opt.getAttribute('data-id'));
            opt.classList.toggle('selected', selections.accompagnement === id);
        });

        // Update garniture toggles
        wizardEl.querySelectorAll('.garniture-toggle-btn').forEach(function (btn) {
            var garnId = btn.getAttribute('data-garniture');
            // Included by default (undefined or true); only false means removed
            var isIncluded = !selections.garnitures || selections.garnitures[garnId] !== false;
            btn.className = 'garniture-toggle-btn ' + (isIncluded ? 'included' : 'removed');
            // Get the crudite name from lastItemData.extras using the 'c_123' key
            var displayName = garnId;
            if (lastItemData && lastItemData.extras) {
                var garnMatch = garnId.match(/_(\d+)$/);
                if (garnMatch) {
                    var garnExtra = lastItemData.extras.find(function (e) { return e.id === parseInt(garnMatch[1]); });
                    if (garnExtra) displayName = garnExtra.name;
                }
            }
            // [V6 FIX] Use spread to extract first grapheme cluster — charAt(0) returns only the
            // first UTF-16 code unit, leaving a lone surrogate for multi-unit emojis (🥬, 🧅, etc.)
            var emojiChars = Array.from(btn.textContent.trim());
            var emoji = emojiChars.length > 0 ? emojiChars[0] : '';
            btn.innerHTML = emoji + ' ' + (isIncluded ? '✓ ' + escapeHtml(displayName) : '✕ Sans ' + escapeHtml(displayName));
        });

        // Update sauce badges
        var sauceCount = selections.sauceOrder ? selections.sauceOrder.length : 0;
        var sauceBadge = wizardEl.querySelector('.sauce-section .sauce-badge');
        if (sauceBadge) {
            if (sauceCount === 0) {
                sauceBadge.textContent = '1ère gratuite';
                sauceBadge.className = 'sauce-badge free';
            } else if (sauceCount === 1) {
                sauceBadge.textContent = '✅ 1 sauce gratuite';
                sauceBadge.className = 'sauce-badge free';
            } else {
                var extraCost = (sauceCount - 1) * SAUCE_EXTRA_PRICE;
                sauceBadge.textContent = sauceCount + ' sauces = +' + fmtPrice(extraCost);
                sauceBadge.className = 'sauce-badge paid';
            }
        }

        // Update sauce selections — .sauce-opt (multi-step cards) ou .sauce-chip (single-page chips)
        wizardEl.querySelectorAll('.sauce-opt, .sauce-chip[data-type="sauce"]').forEach(function (opt) {
            var sauceId = opt.getAttribute('data-id'); // string key like 's_123'
            var sel = selections.sauces && selections.sauces[sauceId];
            opt.classList.toggle('selected', !!sel);
            // For cards: update price label
            var priceLabel = opt.querySelector('.option-price');
            if (priceLabel) {
                var idx = selections.sauceOrder ? selections.sauceOrder.indexOf(sauceId) : -1;
                if (idx === 0) priceLabel.textContent = 'Gratuit';
                else if (idx > 0) priceLabel.textContent = '+' + fmtPrice(SAUCE_EXTRA_PRICE);
                else priceLabel.textContent = sauceCount === 0 ? 'Gratuit' : '+' + fmtPrice(SAUCE_EXTRA_PRICE);
                priceLabel.className = 'option-price ' + (idx === 0 ? 'free' : (idx > 0 ? 'paid' : ''));
            }
            // For chips: update inline badge
            var chipPaid = opt.querySelector('.chip-paid');
            var chipFree = opt.querySelector('.chip-free');
            if (chipPaid || chipFree) {
                var idx2 = selections.sauceOrder ? selections.sauceOrder.indexOf(sauceId) : -1;
                if (chipPaid) chipPaid.style.display = (idx2 > 0) ? 'inline' : 'none';
                if (chipFree) chipFree.style.display = (idx2 === 0) ? 'inline' : 'none';
            }
        });

        // Update supplement selections — only process elements with data-key (single-page format)
        wizardEl.querySelectorAll('.supplement-opt[data-key]').forEach(function (opt) {
            var supKey = opt.getAttribute('data-key');
            opt.classList.toggle('selected', !!(selections.supplements && selections.supplements[supKey]));
        });

        // Update formule cards
        wizardEl.querySelectorAll('.formule-card').forEach(function (card) {
            var value = card.getAttribute('data-value');
            card.classList.toggle('selected', selections.menuChoice === value);
        });

        // Compute showSF: true when formule includes frites or menu
        var showSF = false;
        if (selections.menuChoice && selections.menuChoice !== 'none') {
            var sfMatch = selections.menuChoice.match(/^addon_(\d+)$/);
            if (sfMatch && lastItemData && lastItemData.addons) {
                var sfAddonId = parseInt(sfMatch[1]);
                var sfAddon = lastItemData.addons.find(function (a) { return a.id === sfAddonId; });
                if (sfAddon) {
                    var sfAddonName = normalizeStr(sfAddon.addon_item_name || '');
                    showSF = sfAddonName.includes('frite') || sfAddonName.includes('menu');
                }
            }
        }

        // Update sauce frites visibility
        var sfInline = wizardEl.querySelector('.sauce-frites-inline');
        if (sfInline) {
            sfInline.classList.toggle('visible', showSF);
        }

        // Update frites upgrades visibility and selected state
        var fritesUpgradesEl = wizardEl.querySelector('.frites-upgrades-inline');
        if (fritesUpgradesEl) {
            fritesUpgradesEl.classList.toggle('visible', showSF);
            wizardEl.querySelectorAll('.frites-upgrade-opt').forEach(function (opt) {
                var upgrade = opt.getAttribute('data-upgrade');
                opt.classList.toggle('selected', !!selections[upgrade]);
            });
        }

        // Update sauce frites badges
        var sfCount = selections.sauceFritesOrder ? selections.sauceFritesOrder.length : 0;
        var sfBadge = wizardEl.querySelector('.sauce-frites-inline .sauce-badge');
        if (sfBadge) {
            if (sfCount === 0) {
                sfBadge.textContent = '1ère gratuite';
                sfBadge.className = 'sauce-badge free';
            } else if (sfCount === 1) {
                sfBadge.textContent = '✅ 1 sauce gratuite';
                sfBadge.className = 'sauce-badge free';
            } else {
                var sfExtra = (sfCount - 1) * SAUCE_EXTRA_PRICE;
                sfBadge.textContent = sfCount + ' sauces = +' + fmtPrice(sfExtra);
                sfBadge.className = 'sauce-badge paid';
            }
        }

        // Update sauce frites — .sauce-frite-opt (cards) ou .sauce-chip[data-type="sauce_frite"] (chips)
        wizardEl.querySelectorAll('.sauce-frite-opt, .sauce-chip[data-type="sauce_frite"]').forEach(function (opt) {
            var sauceId = opt.getAttribute('data-id');
            var sel = selections.sauceFrites && selections.sauceFrites[sauceId];
            opt.classList.toggle('selected', !!sel);
            var idx = selections.sauceFritesOrder ? selections.sauceFritesOrder.indexOf(sauceId) : -1;
            var priceLabel = opt.querySelector('.option-price');
            if (priceLabel) {
                if (idx === 0) priceLabel.textContent = 'Gratuit';
                else if (idx > 0) priceLabel.textContent = '+' + fmtPrice(SAUCE_EXTRA_PRICE);
                else priceLabel.textContent = sfCount === 0 ? 'Gratuit' : '+' + fmtPrice(SAUCE_EXTRA_PRICE);
                priceLabel.className = 'option-price ' + (idx === 0 ? 'free' : (idx > 0 ? 'paid' : ''));
            }
            var chipPaid = opt.querySelector('.chip-paid');
            var chipFree = opt.querySelector('.chip-free');
            if (chipPaid || chipFree) {
                if (chipPaid) chipPaid.style.display = (idx > 0) ? 'inline' : 'none';
                if (chipFree) chipFree.style.display = (idx === 0) ? 'inline' : 'none';
            }
        });

        // Update total
        var runTotal = calculateRunningTotal();
        var totalEl = wizardEl.querySelector('.sticky-total .total-value');
        if (totalEl) totalEl.textContent = fmtPrice(runTotal);

        // Update ticket preview
        var ticketContent = wizardEl.querySelector('.ticket-content');
        if (ticketContent) ticketContent.textContent = buildTicketInstruction() || 'Aucune sélection';
    }

    /**
     * Build structured multi-line ticket instruction for KDS/printer.
     * Line 1: [Pain] [viandes] - [crudités noms complets] - [sauces]
     * Line 2+: + [Formule] (+prix) then ↳ extras frites
     * Last line: [NOTE utilisateur]
     *
     * Examples:
     * - Kefta+Viande Hachée - Tomate, Oignon - Mayonnaise
     *   + Menu (Frites + Boisson) (+3.00€)
     *   ↳ Grande Portion (+1.00€)
     *   ↳ Cheddar Fondu (+1.00€)
     *   ↳ Sauce frites: Harissa, Blanche
     */
    function buildTicketInstruction() {
        var line1Parts = [];
        var extraLines = [];

        // Pain (for sandwich): full name
        if (selections.pain && lastItemData && lastItemData.itemAttributes) {
            var painAttrTkt = lastItemData.itemAttributes.find(function (attr) {
                var n = normalizeStr(attr.name);
                return n.includes('pain') || n.includes('type');
            });
            if (painAttrTkt && lastItemData.variations && lastItemData.variations[painAttrTkt.id]) {
                var painVarTkt = lastItemData.variations[painAttrTkt.id].find(function (v) {
                    return v.id === selections.pain;
                });
                if (painVarTkt) {
                    line1Parts.push(painVarTkt.name);
                }
            }
        }

        // Viandes: "Viandes : X, Y" — all selections merged on one label (no "Viande 1 / Viande 2")
        var hasAnyViande = selections.viandes && Object.keys(selections.viandes).some(function (k) { return (selections.viandes[k] || 0) > 0; });
        if (hasAnyViande && lastItemData && lastItemData.itemAttributes) {
            var viandeAttrsTkt = lastItemData.itemAttributes.filter(function (attr) {
                var n = normalizeStr(attr.name);
                return n.includes('viande') || n.includes('meat');
            });
            if (viandeAttrsTkt.length > 0 && lastItemData.variations) {
                var seenTktIds = {};
                var allViandeVarsTkt = [];
                viandeAttrsTkt.forEach(function (attr) {
                    (lastItemData.variations[attr.id] || []).forEach(function (v) {
                        if (!seenTktIds[v.id]) { seenTktIds[v.id] = true; allViandeVarsTkt.push(v); }
                    });
                });
                var viandeParts = [];
                allViandeVarsTkt.forEach(function (variation) {
                    var key = 'v_' + variation.id;
                    var count = selections.viandes[key] || 0;
                    if (count > 0) {
                        viandeParts.push(count > 1 ? count + '\u00d7' + variation.name : variation.name);
                    }
                });
                // Viandes supplémentaires — append inline
                if (selections.viandeSupplItems && lastItemData.variations) {
                    Object.keys(selections.viandeSupplItems).forEach(function (key) {
                        var sc = selections.viandeSupplItems[key] || 0;
                        if (sc <= 0) return;
                        var vid = parseInt(key.replace('v_', ''));
                        var fullName = key;
                        Object.keys(lastItemData.variations).forEach(function (attrId) {
                            var found = lastItemData.variations[attrId].find(function (v) { return v.id === vid; });
                            if (found) fullName = found.name;
                        });
                        viandeParts.push('+' + (sc > 1 ? sc + '\u00d7' : '') + fullName);
                    });
                }
                if (viandeParts.length > 0) line1Parts.push('Viandes : ' + viandeParts.join(', '));
            }
        } else if (selections.viandeSupplItems && lastItemData && lastItemData.variations) {
            // Cas sans viande principale mais avec suppléments
            var supplVParts = [];
            Object.keys(selections.viandeSupplItems).forEach(function (key) {
                var sc = selections.viandeSupplItems[key] || 0;
                if (sc <= 0) return;
                var vid = parseInt(key.replace('v_', ''));
                var fullName = key;
                Object.keys(lastItemData.variations).forEach(function (attrId) {
                    var found = lastItemData.variations[attrId].find(function (v) { return v.id === vid; });
                    if (found) fullName = found.name;
                });
                supplVParts.push((sc > 1 ? sc + '\u00d7' : '') + fullName);
            });
            if (supplVParts.length > 0) line1Parts.push('Viandes : ' + supplVParts.join(', '));
        }

        // Crudités: full names of INCLUDED items (default = all included unless explicitly false)
        if (lastItemData && lastItemData.extras) {
            var cruditesTkt = lastItemData.extras.filter(function (e) {
                return e.convert_price === 0 && isCruditeName(e.name);
            });
            if (cruditesTkt.length > 0) {
                var cruditeNames = [];
                cruditesTkt.forEach(function (c) {
                    var key = 'c_' + c.id;
                    var isIncluded = !selections.garnitures || selections.garnitures[key] !== false;
                    if (isIncluded) {
                        cruditeNames.push(c.name);
                    }
                });
                if (cruditeNames.length > 0) {
                    line1Parts.push('- ' + cruditeNames.join(', '));
                } else {
                    line1Parts.push('- Sans crudités');
                }
            }
        }

        // Sauces: "Sauce : X, Y, Z" — no "(1ère Gratuite)" label, just the names
        if (selections.sauceOrder && selections.sauceOrder.length > 0 && lastItemData && lastItemData.itemAttributes) {
            var sauceAttrTkt = lastItemData.itemAttributes.find(function (attr) {
                var n = normalizeStr(attr.name);
                return n.includes('sauce') && !n.includes('frites');
            });
            if (sauceAttrTkt && lastItemData.variations && lastItemData.variations[sauceAttrTkt.id]) {
                var sauceVarsTkt = lastItemData.variations[sauceAttrTkt.id];
                var sauceNames = [];
                selections.sauceOrder.forEach(function (sauceKey) {
                    var sauceMatch = sauceKey.match(/_(\d+)$/);
                    if (sauceMatch) {
                        var sauceId = parseInt(sauceMatch[1]);
                        var sauceVar = sauceVarsTkt.find(function (v) { return v.id === sauceId; });
                        if (sauceVar) sauceNames.push(sauceVar.name);
                    }
                });
                if (sauceNames.length > 0) {
                    line1Parts.push('Sauce : ' + sauceNames.join(', '));
                }
            }
        }

        // sauce_single for omelette/snacking — resolve name from item data
        var sauceSingleId = selections.sauceSingle;
        if (!sauceSingleId && selections.sauceOrder && selections.sauceOrder.length > 0) {
            var ssKey = selections.sauceOrder[0];
            var ssMatch = ssKey.match(/_(\d+)$/);
            if (ssMatch) sauceSingleId = parseInt(ssMatch[1]);
        }
        if (sauceSingleId && lastItemData && lastItemData.itemAttributes &&
            (currentCategory === 'omelette' || currentCategory === 'snacking')) {
            var ssSauceAttr = lastItemData.itemAttributes.find(function (attr) {
                var n = normalizeStr(attr.name);
                return n.includes('sauce');
            });
            if (ssSauceAttr && lastItemData.variations && lastItemData.variations[ssSauceAttr.id]) {
                var ssVar = lastItemData.variations[ssSauceAttr.id].find(function (v) { return v.id === sauceSingleId; });
                if (ssVar) line1Parts.push('Sauce : ' + ssVar.name);
            }
        }

        // Suppléments: "Supplément : X (+€Y), Z (+€W)" — name + price each
        if (selections.supplements && lastItemData && lastItemData.extras) {
            var supParts = [];
            lastItemData.extras.forEach(function (extra_item) {
                var key = 'p_' + extra_item.id;
                if (selections.supplements[key]) {
                    var price = parseFloat(extra_item.convert_price) || 0;
                    supParts.push(extra_item.name + (price > 0 ? ' (+' + fmtPrice(price) + ')' : ''));
                }
            });
            if (supParts.length > 0) line1Parts.push('Supplément : ' + supParts.join(', '));
        }

        // Formule: full addon name on its own line
        if (selections.menuChoice && selections.menuChoice !== 'none') {
            var formuleMatch = selections.menuChoice.match(/^addon_(\d+)$/);
            if (formuleMatch && lastItemData && lastItemData.addons) {
                var formuleAddonId = parseInt(formuleMatch[1]);
                var formuleAddon = lastItemData.addons.find(function (a) { return a.id === formuleAddonId; });
                if (formuleAddon) {
                    var formulePrice = formuleAddon.addon_item_currency_price || '';
                    var formuleLine = '+ ' + formuleAddon.addon_item_name;
                    if (formulePrice) formuleLine += ' (+' + formulePrice + ')';
                    extraLines.push(formuleLine);
                }
            }
            // Legacy multi-step wizard values
            if (!formuleMatch) {
                if (selections.menuChoice === 'full') extraLines.push('+ Menu complet');
                else if (selections.menuChoice === 'frites') extraLines.push('+ Frites seules');
                else if (selections.menuChoice === 'boisson') extraLines.push('+ Boisson seule');
            }
        }

        // Frites options: ↳ lines after formule
        if (selections.fritesGrande) {
            extraLines.push('\u21b3 Grande Portion (+' + fmtPrice(FRITES_GRANDE_PRICE) + ')');
        }
        if (selections.fritesCheddar) {
            extraLines.push('\u21b3 Cheddar Fondu (+' + fmtPrice(FRITES_CHEDDAR_PRICE) + ')');
        }

        // Sauce frites: all selected, full names, on one ↳ line
        if (selections.sauceFritesOrder && selections.sauceFritesOrder.length > 0 && lastItemData) {
            var sfVarsTkt = [];
            if (lastItemData.itemAttributes) {
                // [H3 FIX] Prefer attribute that contains 'frites', fallback to any sauce attribute
                var sfAttrTkt = lastItemData.itemAttributes.find(function (attr) {
                    var n = normalizeStr(attr.name);
                    return n.includes('sauce') && n.includes('frite');
                }) || lastItemData.itemAttributes.find(function (attr) {
                    var n = normalizeStr(attr.name);
                    return n.includes('sauce');
                });
                if (sfAttrTkt && lastItemData.variations && lastItemData.variations[sfAttrTkt.id]) {
                    sfVarsTkt = lastItemData.variations[sfAttrTkt.id];
                }
            }
            var sfNames = [];
            selections.sauceFritesOrder.forEach(function (key) {
                var sfMatch = key.match(/_(\d+)$/);
                if (sfMatch) {
                    var sfId = parseInt(sfMatch[1]);
                    var sfVar = sfVarsTkt.find(function (v) { return v.id === sfId; });
                    if (sfVar) sfNames.push(sfVar.name);
                }
            });
            if (sfNames.length > 0) {
                extraLines.push('\u21b3 Sauce frites: ' + sfNames.join(', '));
            }
        }

        // User comment: last line
        if (instructionText && instructionText.trim()) {
            extraLines.push('[' + instructionText.trim() + ']');
        }

        var allLines = [];
        // Product name first — KDS cook knows immediately what to prepare
        if (lastItemData && lastItemData.name) {
            allLines.push(lastItemData.name.toUpperCase());
        }
        var firstLine = line1Parts.join(' ');
        if (firstLine) allLines.push(firstLine);
        extraLines.forEach(function (l) { allLines.push(l); });

        return allLines.join('\n');
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

        // 2. Click the correct sauce/variation radio (use 1st selected sauce)
        // [S25] Extract numeric ID from string key like 's_123'
        // [O1 FIX] Also handle sauce_single (omelette/snacking) — stored as plain numeric ID
        var sauceIdToSync = null;
        if (selections.sauceOrder && selections.sauceOrder.length > 0) {
            var firstSauceKey = selections.sauceOrder[0];
            // Extract numeric ID from key like 's_123'
            var match = firstSauceKey.match(/_(\d+)$/);
            if (match) sauceIdToSync = parseInt(match[1]);
        } else if (selections.sauceSingle) {
            // sauce_single is stored as a plain integer ID
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
            // [N5 FIX] Compare as strings to handle both numeric IDs and string fallback values
            var painSelects = originalBody.querySelectorAll('select');
            painSelects.forEach(function (sel) {
                var opts = sel.querySelectorAll('option');
                opts.forEach(function (opt) {
                    if (String(opt.value) === String(selections.pain)) {
                        sel.value = opt.value;
                        sel.dispatchEvent(new Event('change', { bubbles: true }));
                    }
                });
            });
        }

        // 2b. Add extra sauces (index 1+) to allSelectedExtras map
        // [S25] Updated to extract numeric IDs from string keys and look up names from item data
        var extraSauceCheckedIds = {};
        if (selections.sauceOrder && selections.sauceOrder.length > 1) {
            var extraSauceKeys = selections.sauceOrder.slice(1); // all sauces after the 1st

            // Get sauce names from item data
            if (lastItemData && lastItemData.itemAttributes) {
                var sauceAttr = lastItemData.itemAttributes.find(function (attr) {
                    var n = normalizeStr(attr.name);
                    return n.includes('sauce') && !n.includes('frites');
                });

                if (sauceAttr && lastItemData.variations && lastItemData.variations[sauceAttr.id]) {
                    var sauceVariations = lastItemData.variations[sauceAttr.id];

                    extraSauceKeys.forEach(function (sauceKey) {
                        // Extract numeric ID from key like 's_123'
                        var match = sauceKey.match(/_(\d+)$/);
                        if (!match) return;
                        var sauceId = parseInt(match[1]);

                        var sauce = sauceVariations.find(function (s) { return s.id === sauceId; });
                        if (!sauce) return;

                        // Find the extra checkbox for "Sauce supplémentaire: {sauce.name}"
                        var extraCheckboxes = originalBody.querySelectorAll('.extra .custom-checkbox-field');
                        extraCheckboxes.forEach(function (cb) {
                            var label = cb.closest('.extra')
                                ? cb.closest('.extra').querySelector('label, span, .option-name, h3, h4')
                                : null;
                            if (label) {
                                var labelText = (label.textContent || '').toLowerCase();
                                var sauceName = (sauce.name || '').toLowerCase();
                                if (labelText.includes('sauce suppl') && labelText.includes(sauceName)) {
                                    extraSauceCheckedIds[parseInt(cb.value)] = true;
                                }
                            }
                        });
                    });
                }
            }
        }

        // 3. Check/uncheck extras
        var extraCheckboxes = originalBody.querySelectorAll('.extra .custom-checkbox-field');
        var allSelectedExtras = {};

        // Collect all crudité IDs from item data (free extras that are crudités)
        var allCruditeIds = [];
        if (lastItemData && lastItemData.extras) {
            lastItemData.extras.forEach(function (e) {
                if (e.convert_price === 0 && isCruditeName(e.name)) {
                    allCruditeIds.push(e.id);
                }
            });
        }

        // Décocher TOUTES les garnitures d'abord pour éviter les contradictions
        extraCheckboxes.forEach(function (cb) {
            var cbId = parseInt(cb.value);
            if (allCruditeIds.indexOf(cbId) !== -1 && cb.checked) {
                cb.click();
            }
        });

        // Cocher les crudités incluses: default = all included unless explicitly set to false
        // This ensures default-included crudités appear in item_extras.names
        allCruditeIds.forEach(function (cid) {
            var key = 'c_' + cid;
            var isIncluded = !selections.garnitures || selections.garnitures[key] !== false;
            if (isIncluded) {
                allSelectedExtras[cid] = true;
            }
        });

        // Also sync any explicitly-true garnitures (non-crudité free extras)
        // Support 'c_123' prefixed keys (single-page) AND bare numeric keys (multi-step)
        if (selections.garnitures) {
            Object.keys(selections.garnitures).forEach(function (key) {
                if (selections.garnitures[key]) {
                    var match = key.match(/_(\d+)$/);
                    if (match) {
                        allSelectedExtras[parseInt(match[1])] = true;
                    } else if (/^\d+$/.test(key)) {
                        allSelectedExtras[parseInt(key)] = true;
                    }
                }
            });
        }
        if (selections.supplements) {
            Object.keys(selections.supplements).forEach(function (key) {
                if (selections.supplements[key]) {
                    // Support both 'p_123' / 'sup_123' prefixed keys AND bare numeric keys (multi-step path)
                    var match = key.match(/_(\d+)$/);
                    if (match) {
                        allSelectedExtras[parseInt(match[1])] = true;
                    } else if (/^\d+$/.test(key)) {
                        allSelectedExtras[parseInt(key)] = true;
                    }
                }
            });
        }
        if (selections.accompagnement) {
            allSelectedExtras[selections.accompagnement] = true;
        }

        // [X6 FIX] Include viandeSupplItems as extras so they appear in Vue item_extras.
        // Each key is 'v_<id>'; the count is stored but extras are binary (present/absent).
        // We mark each extra viande id as selected once — quantity is reflected in instruction.
        if (selections.viandeSupplItems) {
            Object.keys(selections.viandeSupplItems).forEach(function (key) {
                var sc = selections.viandeSupplItems[key] || 0;
                if (sc > 0) {
                    var match = key.match(/_(\d+)$/);
                    if (match) allSelectedExtras[parseInt(match[1])] = true;
                }
            });
        }

        // Include extra sauces found in 2b
        Object.keys(extraSauceCheckedIds).forEach(function(sid) {
            allSelectedExtras[sid] = true;
        });

        extraCheckboxes.forEach(function (cb) {
            var cbId = parseInt(cb.value);
            var shouldBeChecked = !!allSelectedExtras[cbId];
            if (cb.checked !== shouldBeChecked) {
                cb.click();
            }
        });

        // 4. Click addon cards
        // [S25] Updated to work with real item data and new 'addon_123' menuChoice format
        var addonCards = originalBody.querySelectorAll('.addon');

        if (lastItemData && lastItemData.addons && addonCards.length > 0) {
            var addons = lastItemData.addons;

            if (selections.menuChoice && selections.menuChoice !== 'none') {
                var targetAddonForSync = null;

                // Single-page wizard: 'addon_N' format
                var spMatch = selections.menuChoice.match(/^addon_(\d+)$/);
                if (spMatch) {
                    var spId = parseInt(spMatch[1]);
                    targetAddonForSync = addons.find(function (a) { return a.id === spId; });
                }

                // Multi-step wizard: 'full' / 'frites' / 'boisson' — resolve by name from addons list
                if (!targetAddonForSync) {
                    if (selections.menuChoice === 'full') {
                        targetAddonForSync = addons.find(function (a) {
                            return (a.addon_item_name || '').toLowerCase().includes('menu');
                        });
                        if (!targetAddonForSync) {
                            // Fallback: first addon that includes both frites and boisson keywords OR any formule
                            targetAddonForSync = addons.find(function (a) {
                                var n = (a.addon_item_name || '').toLowerCase();
                                return n.includes('frites') || n.includes('boisson') || n.includes('formule');
                            });
                        }
                    } else if (selections.menuChoice === 'frites') {
                        targetAddonForSync = addons.find(function (a) {
                            var n = (a.addon_item_name || '').toLowerCase();
                            return n.includes('frites') && !n.includes('menu');
                        });
                    } else if (selections.menuChoice === 'boisson') {
                        targetAddonForSync = addons.find(function (a) {
                            var n = (a.addon_item_name || '').toLowerCase();
                            return n.includes('boisson') || n.includes('coca') || n.includes('fanta') || n.includes('sprite');
                        });
                    }
                }

                if (targetAddonForSync) {
                    addonCards.forEach(function (card) {
                        var addonId = parseInt(card.getAttribute('data-addon-id'), 10);
                        var addonName = (card.getAttribute('data-addon-name') || '').toLowerCase();
                        var shouldBeSelected =
                            addonId === targetAddonForSync.id ||
                            addonName.includes((targetAddonForSync.addon_item_name || '').toLowerCase());

                        // [BUG-W-ADDON-01] Use data-addon-active, not DOM ancestors
                        var isActive = card.getAttribute('data-addon-active') === '1';
                        if (shouldBeSelected && !isActive) {
                            card.click();
                        }
                    });
                }
            }
        }

        // [W-5 FIX] Sync boissonChoice to Vue modal addon cards
        // [N3 FIX] Match by full name first to avoid first-word collisions (e.g. "Coca Zero" vs "Coca Cola")
        if (selections.boissonChoice && selections.boissonChoice !== 'none') {
            var boissonStepSync = steps.find(function (s) { return s.type === 'boisson_choice'; });
            if (boissonStepSync && boissonStepSync.boissonItems) {
                var boissonItemSync = boissonStepSync.boissonItems.find(function (b) { return b.id === selections.boissonChoice; });
                if (boissonItemSync) {
                    var boissonAddonCards = originalBody.querySelectorAll('.addon[data-addon-id]');
                    var boissonFullName = boissonItemSync.name.toLowerCase();
                    var boissonFirstWord = boissonFullName.split(' ')[0];
                    // Try full-name match first; fall back to first-word if no exact match found
                    var exactMatch = null;
                    boissonAddonCards.forEach(function (card) {
                        var addonName = (card.getAttribute('data-addon-name') || '').toLowerCase();
                        if (addonName === boissonFullName || addonName.includes(boissonFullName)) {
                            exactMatch = card;
                        }
                    });
                    var targetCard = exactMatch;
                    if (!targetCard) {
                        boissonAddonCards.forEach(function (card) {
                            var addonName = (card.getAttribute('data-addon-name') || '').toLowerCase();
                            if (!targetCard && addonName.includes(boissonFirstWord)) {
                                targetCard = card;
                            }
                        });
                    }
                    if (targetCard) {
                        var isActive = targetCard.getAttribute('data-addon-active') === '1';
                        if (!isActive) targetCard.click();
                    }
                }
            }
        }

        // 4c. Sync viande selections to original modal dropdowns (Viande 1, Viande 2, ...)
        // [S25] Updated to work with real item data structure
        if (selections.viandes && lastItemData && lastItemData.itemAttributes) {
            var selectedViandes = [];

            // Get viande variations from item data
            var viandeAttrs = lastItemData.itemAttributes.filter(function (attr) {
                var n = normalizeStr(attr.name);
                return n.includes('viande') || n.includes('meat');
            });

            // Collect viandes deduped by id AND name (same fix as renderSinglePage)
            if (viandeAttrs.length > 0 && lastItemData.variations) {
                var seenSyncIds = {};
                var seenSyncNames = {};
                var allSyncVariations = [];
                viandeAttrs.forEach(function (attr) {
                    (lastItemData.variations[attr.id] || []).forEach(function (v) {
                        var normName = normalizeStr(v.name || '');
                        if (!seenSyncIds[v.id] && !seenSyncNames[normName]) {
                            seenSyncIds[v.id] = true;
                            seenSyncNames[normName] = true;
                            allSyncVariations.push(v);
                        }
                    });
                });
                allSyncVariations.forEach(function (variation) {
                    var key = 'v_' + variation.id;
                    var count = selections.viandes[key] || 0;
                    for (var i = 0; i < count; i++) selectedViandes.push(variation.name);
                });
            }

            if (selectedViandes.length > 0) {
                var viandeSelects = Array.from(originalBody.querySelectorAll('select')).filter(function (sel) {
                    var scope = sel.closest('.row, .col, .form-group, .mb-2, .mb-3') || sel.parentElement;
                    var txt = normalizeStr(scope ? scope.textContent : (sel.name || ''));
                    return txt.includes('viande') || txt.includes('meat');
                });
                selectedViandes.forEach(function (viandeName, idx) {
                    var target = viandeSelects[idx];
                    if (!target) return;
                    var normalizedName = normalizeStr(viandeName);
                    // [W5 FIX] Exact match first to avoid "Kefta" matching "Kefta Épicée"
                    var match = Array.from(target.options).find(function (opt) {
                        return normalizeStr(opt.textContent || '') === normalizedName;
                    });
                    // Fallback: substring match if no exact match found
                    if (!match) {
                        match = Array.from(target.options).find(function (opt) {
                            return normalizeStr(opt.textContent || '').includes(normalizedName);
                        });
                    }
                    if (match) {
                        target.value = match.value;
                        target.dispatchEvent(new Event('change', { bubbles: true }));
                    }
                });
            }
        }

        // 5. Set instruction — [S25] Use buildTicketInstruction() for compact ticket format
        var fullInstruction = buildTicketInstruction();

        var textarea = originalBody.querySelector('textarea');
        if (textarea && fullInstruction) {
            var nativeTextSetter = Object.getOwnPropertyDescriptor(window.HTMLTextAreaElement.prototype, 'value').set;
            nativeTextSetter.call(textarea, fullInstruction);
            textarea.dispatchEvent(new Event('input', { bubbles: true }));
        }

        // 6. [Bridge pricing fix] Total + addons regroupés (filet si clic .addon n’a pas mis à jour Vue)
        function buildWizardPosLineAddonsPayload() {
            var out = [];
            if (!lastItemData || !lastItemData.addons || !selections.menuChoice || selections.menuChoice === 'none') {
                return out;
            }

            /** Construit la liste des extras menu (sauce frites, grande portion, cheddar) */
            function buildMenuExtras() {
                var extras = [];

                // Options frites
                if (selections.fritesGrande) extras.push('Grande Portion (+' + fmtPrice(FRITES_GRANDE_PRICE) + ')');
                if (selections.fritesCheddar) extras.push('Cheddar Fondu (+' + fmtPrice(FRITES_CHEDDAR_PRICE) + ')');

                // Sauce frites — les sauces viennent de lastItemData.itemAttributes (même source que sauceVariations)
                if (selections.sauceFritesOrder && selections.sauceFritesOrder.length > 0) {
                    // [H3 FIX] Priorité à l'attribut "sauce frites" (contient 'frites'), sinon fallback sauce générique
                    var sfVariations = [];
                    if (lastItemData && lastItemData.itemAttributes) {
                        // 1st pass: attribute whose name contains both 'sauce' AND 'frites'
                        var sfSauceAttr = lastItemData.itemAttributes.find(function (attr) {
                            var n = normalizeStr(attr.name || '');
                            return n.includes('sauce') && n.includes('frite');
                        });
                        // 2nd pass fallback: any sauce attribute (generic sandwich sauce list)
                        if (!sfSauceAttr) {
                            sfSauceAttr = lastItemData.itemAttributes.find(function (attr) {
                                var n = normalizeStr(attr.name || '');
                                return n.includes('sauce');
                            });
                        }
                        if (sfSauceAttr && lastItemData.variations && lastItemData.variations[sfSauceAttr.id]) {
                            sfVariations = lastItemData.variations[sfSauceAttr.id];
                        }
                    }
                    // Fallback : chercher dans steps
                    if (sfVariations.length === 0) {
                        steps.forEach(function (s) {
                            if (sfVariations.length === 0 && s.sauceItems && s.sauceItems.length > 0) {
                                sfVariations = s.sauceItems;
                            }
                        });
                    }

                    var sfNames = [];
                    selections.sauceFritesOrder.forEach(function (key) {
                        // key = 'sf_123' → extraire l'id numérique
                        var numId = parseInt(String(key).replace('sf_', ''), 10);
                        var found = sfVariations.find(function (ss) { return ss.id === numId; });
                        if (found) sfNames.push(found.name);
                    });
                    if (sfNames.length > 0) {
                        extras.push('Sauce frites: ' + sfNames.join(', '));
                    }
                }

                return extras;
            }

            function addonToPayload(ad) {
                var cvt = ad.offer && ad.offer.length > 0 ? parseFloat(ad.offer[0].convert_price) : parseFloat(ad.addon_item_convert_price) || 0;
                var cur = ad.offer && ad.offer.length > 0 ? ad.offer[0].currency_price : ad.addon_item_currency_price;
                var lineItemId = ad.addon_item_id != null ? ad.addon_item_id : ad.item_addon_id;
                // [P6-1 FIX] Build addon instruction from menu_extras so it persists to DB and receipt
                var extras = buildMenuExtras();
                var addonInstruction = extras.length > 0 ? extras.join('\n') : '';
                return {
                    parent_addon_id: String(ad.id),
                    name: ad.addon_item_name,
                    image: ad.thumb || '',
                    item_id: lineItemId,
                    quantity: 1,
                    discount: 0,
                    currency_price: cur,
                    convert_price: cvt,
                    item_variations: { variations: {}, names: {} },
                    item_extras: { extras: [], names: [] },
                    item_variation_total: parseFloat(ad.variation_total_convert_price) || 0,
                    item_extra_total: 0,
                    instruction: addonInstruction,
                    total_price: parseFloat(ad.total_convert_price) || 0,
                    // Extras menu (sauce frites, grande portion, cheddar) pour affichage panier
                    menu_extras: extras,
                    // Données brutes pour restauration wizard édition
                    menu_restore: {
                        fritesGrande: !!selections.fritesGrande,
                        fritesCheddar: !!selections.fritesCheddar,
                        sauceFrites: selections.sauceFrites ? JSON.parse(JSON.stringify(selections.sauceFrites)) : {},
                        sauceFritesOrder: selections.sauceFritesOrder ? selections.sauceFritesOrder.slice() : [],
                        // [X4 FIX] Persist boissonChoice so it can be restored on cart edit
                        boissonChoice: selections.boissonChoice || null,
                        // [X5 FIX] Persist viandeSupplItems so paid extra viandes survive cart edit
                        viandeSupplItems: selections.viandeSupplItems ? JSON.parse(JSON.stringify(selections.viandeSupplItems)) : {}
                    }
                };
            }

            var ad = null;

            // Single-page wizard: 'addon_N' format
            var m = selections.menuChoice.match(/^addon_(\d+)$/);
            if (m) {
                var aid = parseInt(m[1], 10);
                ad = lastItemData.addons.find(function (a) { return a.id === aid; });
            }

            // Multi-step wizard: 'full' / 'frites' / 'boisson' — resolve by name
            if (!ad) {
                if (selections.menuChoice === 'full') {
                    ad = lastItemData.addons.find(function (a) {
                        return (a.addon_item_name || '').toLowerCase().includes('menu');
                    });
                    if (!ad) {
                        ad = lastItemData.addons.find(function (a) {
                            var n = (a.addon_item_name || '').toLowerCase();
                            return n.includes('frites') || n.includes('boisson') || n.includes('formule');
                        });
                    }
                } else if (selections.menuChoice === 'frites') {
                    ad = lastItemData.addons.find(function (a) {
                        var n = (a.addon_item_name || '').toLowerCase();
                        return n.includes('frites') && !n.includes('menu');
                    });
                } else if (selections.menuChoice === 'boisson') {
                    ad = lastItemData.addons.find(function (a) {
                        var n = (a.addon_item_name || '').toLowerCase();
                        return n.includes('boisson') || n.includes('coca') || n.includes('fanta') || n.includes('sprite');
                    });
                }
            }

            if (ad) out.push(addonToPayload(ad));
            return out;
        }

        var wizardTotalBeforeSubmit = calculateRunningTotal();
        var modalRoot = originalBody ? originalBody.closest('.modal') : null;
        if (modalRoot) {
            modalRoot.setAttribute('data-wizard-total', String(wizardTotalBeforeSubmit));
            var wizardBundled = buildWizardPosLineAddonsPayload();
            if (wizardBundled.length > 0) {
                modalRoot.setAttribute('data-wizard-pos-line-addons', JSON.stringify(wizardBundled));
            } else {
                modalRoot.removeAttribute('data-wizard-pos-line-addons');
            }
            // Cart display: clean multi-line summary for cashier view (no prices)
            modalRoot.setAttribute('data-wizard-cart-display', buildCartDisplay() || '');
        }

        function parseMoneyLoose(text) {
            if (!text) return 0;
            // Support "11,50€", "€11.50", "11.50 EUR", etc.
            var cleaned = String(text).replace(/\s/g, '').replace(/[^0-9,.-]/g, '');
            if (!cleaned) return 0;
            // Convert comma decimal to dot when needed
            if (cleaned.indexOf(',') !== -1 && cleaned.indexOf('.') === -1) {
                cleaned = cleaned.replace(',', '.');
            } else if (cleaned.indexOf(',') !== -1 && cleaned.indexOf('.') !== -1) {
                cleaned = cleaned.replace(/,/g, '');
            }
            var n = parseFloat(cleaned);
            return isNaN(n) ? 0 : n;
        }

        function readModalAddButtonTotal(addBtn) {
            if (!addBtn) return 0;
            var txt = addBtn.textContent || '';
            // 1) Dernier segment après « - » (libellé i18n souvent « Ajouter - 12,50 € »)
            var parts = txt.split('-');
            if (parts.length >= 2) {
                var fromDash = parseMoneyLoose(parts[parts.length - 1]);
                if (fromDash > 0) return fromDash;
            }
            // 2) Fallback : dernier groupe numérique (libellés avec plusieurs tirets / sans tiret)
            var matches = txt.match(/\d+[.,]\d+|\d+/g);
            if (matches && matches.length > 0) {
                return parseMoneyLoose(matches[matches.length - 1]);
            }
            return 0;
        }

        function submitWhenSynced() {
            // Dispatch a custom event on the modal — ItemComponent listens and calls addToCart()
            // directly, bypassing the :disabled="temp.total_price <= 0" guard on the Vue button.
            var modalRoot = originalBody ? originalBody.closest('.modal') : null;
            if (!modalRoot) {
                console.error('[Wizard] Modal root not found for wizard:add-to-cart dispatch');
                showValidationError('Erreur interne. Veuillez réessayer.');
                return;
            }
            modalRoot.dispatchEvent(new CustomEvent('wizard:add-to-cart', { bubbles: false }));

            // Close wizard after a short delay to let Vue process the cart update
            setTimeout(function () {
                if (originalBody) originalBody.style.display = 'none';
                closeWizard(true);
            }, 200);
        }

        // Small delay to let Vue consume all dispatched changes before submitting.
        setTimeout(function () {
            submitWhenSynced();
        }, 180);
    }

    /* ==============================
       WIZARD OPEN / CLOSE
       ============================== */
    function openWizard(modal) {
        // Allow Vue to inject item data directly via DOM attribute (edit-from-cart path),
        // bypassing the XHR/fetch interceptor which may miss relative URLs.
        var injectedDataAttr = modal.getAttribute('data-wizard-item-data');
        if (injectedDataAttr) {
            try {
                var injected = JSON.parse(injectedDataAttr);
                if (injected && (injected.itemAttributes || injected.variations || injected.extras)) {
                    lastItemData = injected;
                }
            } catch (e) { /* ignore */ }
            modal.removeAttribute('data-wizard-item-data');
        }

        if (!lastItemData) return;

        // [BUG-W1 FIX] Store item data for buildWizardInstruction()
        wizardItemData = lastItemData;

        var modalDialog = modal.querySelector('.modal-dialog');
        originalBody = modal.querySelector('.modal-body');
        if (!originalBody || !modalDialog) return;

        // Avoid stale total bridge from a previous failed/aborted wizard submit.
        modal.removeAttribute('data-wizard-total');
        modal.removeAttribute('data-wizard-pos-line-addons');

        // Hide Vue modal header to avoid duplicate product info
        var originalHeader = modal.querySelector('.modal-header');
        if (originalHeader) {
            originalHeader.setAttribute('data-wiz-hidden', '1');
            originalHeader.style.display = 'none';
        }

        originalBody.style.display = 'none';

        // [POS-V4-WIZARD-VIEWPORT-FIT] Hide Vue-injected wizard footer (Add to cart) so the
        // wizard's own sticky CTA remains the single source of truth (bound to running total).
        // Without this, the Vue button stays visible above the wizard and submits temp.total_price = 0.
        var vueFooter = modal.querySelector('[data-wiz-vue-footer]');
        if (vueFooter) {
            vueFooter.setAttribute('data-wiz-hidden', '1');
            vueFooter.style.display = 'none';
        }

        // [NEW SPRINT 4] Inject CSS styles if not already present
        if (!document.getElementById('pos-wizard-styles')) {
            var styleEl = document.createElement('style');
            styleEl.id = 'pos-wizard-styles';
            styleEl.textContent = `
                /* [Sprint 4] Split-screen layout */
                .wizard-split { display: flex; gap: 16px; }
                .wizard-split > .wizard-col { flex: 1; min-width: 0; }
                .wizard-split > .wizard-col h4 { font-size: 14px; font-weight: 700; margin-bottom: 12px; color: #1B1B3A; font-family: 'Rubik', sans-serif; }

                /* [Sprint 4] Section styling — aligned with design system */
                .wizard-section { margin-bottom: 20px; }
                .wizard-section h4 { font-size: 14px; font-weight: 700; margin-bottom: 8px; color: #1B1B3A; font-family: 'Rubik', sans-serif; }
                .wizard-hint { font-size: 11px; color: #8E8EA9; margin-bottom: 10px; }

                /* [Sprint 4] Garniture toggle buttons — using design system colors */
                .garniture-toggle { display: flex; gap: 8px; flex-wrap: wrap; }
                .garniture-toggle-btn { padding: 10px 16px; border-radius: 20px; border: 2px solid #EFF0F6; background: white; cursor: pointer; font-size: 13px; font-family: 'Rubik', sans-serif; font-weight: 600; transition: all 0.2s; }
                .garniture-toggle-btn:hover { border-color: #D0D0E0; }
                .garniture-toggle-btn.included { background: #43C6AC; border-color: #43C6AC; color: white; }
                .garniture-toggle-btn.removed { background: #E93C3C; border-color: #E93C3C; color: white; }

                /* [Sprint 25] Viandes supplémentaires — bouton toggle + liste dépliable */
                .viande-suppl-toggle { display: flex; align-items: center; justify-content: center; width: 100%; margin-top: 10px; padding: 9px 14px; border: 2px dashed #E93C3C; border-radius: 10px; background: #FFF5F5; color: #E93C3C; font-size: 13px; font-weight: 700; font-family: 'Rubik', sans-serif; cursor: pointer; transition: all 0.2s; }
                .viande-suppl-toggle:hover { background: #FFE8E8; }
                .viande-suppl-toggle.has-items { background: #E93C3C; color: white; border-style: solid; }
                .wizard-viande-suppl-section { margin-top: 8px; }
                .wizard-viande-suppl-section.collapsed { display: none; }
                .wizard-viande-suppl-row { display: flex; align-items: center; justify-content: space-between; padding: 8px 10px; border: 2px solid #EFF0F6; border-radius: 10px; background: #FAFAFF; transition: all 0.2s; margin-bottom: 6px; }
                .wizard-viande-suppl-row.active { border-color: #E93C3C; background: linear-gradient(135deg, #FFF0F0, #FFE8EC); }
                .wizard-viande-suppl-row .viande-suppl-count { font-size: 16px; font-weight: 700; color: #1B1B3A; min-width: 18px; text-align: center; font-family: 'Rubik', sans-serif; }

                /* [Sprint 23] Recap clear ticket sections — aligned with design system */
                .wizard-ticket-head { margin-bottom: 10px; padding: 10px 12px; border: 1px solid #EFF0F6; border-radius: 10px; background: #FAFAFF; }
                .wizard-ticket-title { font-size: 15px; font-weight: 700; color: #1B1B3A; font-family: 'Rubik', sans-serif; }
                .wizard-ticket-subtitle { font-size: 11px; color: #8E8EA9; margin-top: 2px; }
                .wizard-recap-section-title { margin-top: 10px; margin-bottom: 6px; font-size: 11px; font-weight: 700; color: #8E8EA9; text-transform: uppercase; letter-spacing: 0.5px; font-family: 'Rubik', sans-serif; }
                .wizard-instruction-summary { margin-top: 10px; padding: 10px 12px; border-radius: 10px; background: #FFF8F0; border: 1px solid #FFD9A0; font-size: 12px; color: #B05A00; font-family: 'Rubik', sans-serif; max-height: 80px; overflow-y: auto; line-height: 1.5; }

                /* [Sprint 4] Sauce frites inline */
                .sauce-frites-inline { display: none; margin-top: 16px; padding-top: 16px; border-top: 2px dashed #EFF0F6; }
                .sauce-frites-inline.visible { display: block; animation: slideDown 0.3s ease; }
                .frites-options-inline { display: none; margin-top: 12px; padding: 12px; border-radius: 12px; background: #FAFAFF; border: 1px solid #EFF0F6; }
                .frites-options-inline.visible { display: block; animation: slideDown 0.3s ease; }
                /* [S25] Frites upgrade options */
                .frites-upgrades-inline { display: none; margin-top: 12px; padding-top: 12px; border-top: 1px dashed #EFF0F6; }
                .frites-upgrades-inline.visible { display: block; animation: slideDown 0.3s ease; }
                .frites-upgrades-inline h4 { font-size: 13px; font-weight: 600; color: #6B6B8A; margin: 0 0 8px 0; text-transform: uppercase; letter-spacing: 0.5px; }
                @keyframes slideDown { from { opacity: 0; transform: translateY(-8px); } to { opacity: 1; transform: translateY(0); } }

                /* [Sprint 4] Supplement grid — 2 columns for larger cards */
                .supplement-grid { grid-template-columns: repeat(2, 1fr) !important; }

                /* [Sprint 4] Compact grids */
                .sauce-grid.compact .wizard-option { padding: 8px 10px; }
                .sauce-grid.compact .option-icon { font-size: 16px; }
                .sauce-grid.compact .option-name { font-size: 10px; }

                /* [Sprint 4] Edit buttons — aligned with design system */
                .edit-step-btn { background: none; border: none; cursor: pointer; font-size: 11px; color: #8E8EA9; padding: 2px 6px; margin-left: 8px; opacity: 0.6; transition: opacity 0.2s; }
                .edit-step-btn:hover { opacity: 1; color: #E93C3C; }
                .wizard-recap-row:hover .edit-step-btn { opacity: 1; }

                /* [Sprint 4] Keyboard navigation hints */
                .pos-wizard { position: relative; }
                .keyboard-hint { position: absolute; bottom: 4px; right: 8px; font-size: 10px; color: #B0B0C8; }

                /* [Sprint 23 Fix P2] Validation error message — aligned with design system */
                .wizard-validation-error {
                    background: #FFF0F0;
                    border: 1px solid #FFB3B3;
                    color: #E93C3C;
                    padding: 8px 12px;
                    border-radius: 8px;
                    font-size: 12px;
                    font-weight: 600;
                    font-family: 'Rubik', sans-serif;
                    margin-bottom: 10px;
                    animation: shake 0.4s ease;
                }
                @keyframes shake {
                    0%, 100% { transform: translateX(0); }
                    25% { transform: translateX(-5px); }
                    75% { transform: translateX(5px); }
                }

                /* [S25] SINGLE-PAGE LAYOUT STYLES */
                .pos-wizard.single-page {
                    padding-bottom: 8px;
                }

                .pos-wizard.single-page .wizard-quantity-section {
                    display: flex;
                    align-items: center;
                    justify-content: space-between;
                    padding: 12px 16px;
                    background: #FAFAFF;
                    border-radius: 12px;
                    margin-bottom: 16px;
                }

                .pos-wizard.single-page .qty-label {
                    font-size: 14px;
                    font-weight: 600;
                    color: #1B1B3A;
                    font-family: 'Rubik', sans-serif;
                }

                .pos-wizard.single-page .wizard-section {
                    margin-bottom: 24px;
                    padding-bottom: 20px;
                    border-bottom: 1px solid #EFF0F6;
                }

                .pos-wizard.single-page .section-header {
                    display: flex;
                    align-items: center;
                    justify-content: space-between;
                    margin-bottom: 12px;
                }

                .pos-wizard.single-page .section-header h4 {
                    font-size: 15px;
                    font-weight: 700;
                    color: #1B1B3A;
                    font-family: 'Rubik', sans-serif;
                    margin: 0;
                }

                .pos-wizard.single-page .section-hint {
                    font-size: 12px;
                    color: #8E8EA9;
                    margin-bottom: 10px;
                }

                .pos-wizard.single-page .quota-badge {
                    font-size: 13px;
                    font-weight: 600;
                    padding: 4px 10px;
                    border-radius: 12px;
                    background: #FFF0F0;
                    color: #E93C3C;
                    border: 1px solid #E93C3C;
                }

                .pos-wizard.single-page .quota-badge.complete {
                    background: #E8F8F5;
                    color: #43C6AC;
                    border-color: #43C6AC;
                }

                .pos-wizard.single-page .complete-text {
                    display: inline-block;
                    margin-left: 10px;
                    font-size: 12px;
                    color: #43C6AC;
                    font-weight: 600;
                }

                .pos-wizard.single-page .pain-grid {
                    display: grid;
                    grid-template-columns: repeat(2, 1fr);
                    gap: 12px;
                }

                .pos-wizard.single-page .pain-icon {
                    font-size: 32px;
                    margin-bottom: 8px;
                }

                .pos-wizard.single-page .sauce-icon,
                .pos-wizard.single-page .supplement-icon {
                    font-size: 24px;
                    margin-bottom: 4px;
                }

                .pos-wizard.single-page .wizard-formule-cards {
                    display: grid;
                    grid-template-columns: repeat(4, 1fr);
                    gap: 12px;
                }

                .pos-wizard.single-page .formule-card {
                    display: flex;
                    flex-direction: column;
                    align-items: center;
                    padding: 16px 8px;
                    border: 2px solid #EFF0F6;
                    border-radius: 12px;
                    background: white;
                    cursor: pointer;
                    transition: all 0.2s;
                }

                .pos-wizard.single-page .formule-card:hover {
                    border-color: #D0D0E0;
                }

                .pos-wizard.single-page .formule-card.selected {
                    border-color: #43C6AC;
                    background: #F0FDFA;
                }

                .pos-wizard.single-page .formule-icon {
                    font-size: 28px;
                    margin-bottom: 8px;
                }

                .pos-wizard.single-page .formule-name {
                    font-size: 12px;
                    font-weight: 600;
                    color: #1B1B3A;
                    text-align: center;
                    font-family: 'Rubik', sans-serif;
                }

                .pos-wizard.single-page .formule-price {
                    font-size: 13px;
                    font-weight: 700;
                    color: #E93C3C;
                    margin-top: 4px;
                }

                .pos-wizard.single-page .wizard-comment-field {
                    width: 100%;
                    min-height: 80px;
                    padding: 12px;
                    border: 2px solid #EFF0F6;
                    border-radius: 12px;
                    font-family: 'Rubik', sans-serif;
                    font-size: 14px;
                    resize: vertical;
                }

                .pos-wizard.single-page .wizard-comment-field:focus {
                    outline: none;
                    border-color: #43C6AC;
                }

                .pos-wizard.single-page .wizard-ticket-preview {
                    margin: 20px 0;
                    padding: 12px;
                    background: #FFF8F0;
                    border: 1px solid #FFD9A0;
                    border-radius: 10px;
                }

                .pos-wizard.single-page .ticket-label {
                    font-size: 11px;
                    font-weight: 700;
                    color: #B05A00;
                    text-transform: uppercase;
                    letter-spacing: 0.5px;
                    margin-bottom: 6px;
                    font-family: 'Rubik', sans-serif;
                }

                .pos-wizard.single-page .ticket-content {
                    font-size: 14px;
                    font-weight: 600;
                    color: #1B1B3A;
                    font-family: 'Rubik', sans-serif;
                    line-height: 1.4;
                    word-break: break-word;
                }

                /* Wizard scroll container: modal-dialog becomes a flex column,
                   #pos-wizard-root scrolls internally, sticky bar stays at bottom */
                #item-variation-modal .modal-dialog {
                    display: flex;
                    flex-direction: column;
                    max-height: 92vh;
                    overflow: hidden;
                }
                #pos-wizard-root {
                    flex: 1 1 auto;
                    overflow-y: auto;
                    overflow-x: hidden;
                    display: flex;
                    flex-direction: column;
                    min-height: 0;
                }
                #pos-wizard-root .pos-wizard.single-page {
                    flex: 1 1 auto;
                    overflow-y: auto;
                    min-height: 0;
                    padding-bottom: 0;
                }

                .pos-wizard.single-page .wizard-sticky-bar {
                    position: sticky;
                    bottom: 0;
                    flex-shrink: 0;
                    display: flex;
                    align-items: center;
                    justify-content: space-between;
                    padding: 14px 20px;
                    background: white;
                    border-top: 2px solid #EFF0F6;
                    box-shadow: 0 -4px 20px rgba(0, 0, 0, 0.08);
                    z-index: 10;
                    gap: 12px;
                }

                .pos-wizard.single-page .sticky-total {
                    display: flex;
                    flex-direction: column;
                }

                .pos-wizard.single-page .total-label {
                    font-size: 12px;
                    color: #8E8EA9;
                    font-family: 'Rubik', sans-serif;
                }

                .pos-wizard.single-page .total-value {
                    font-size: 24px;
                    font-weight: 700;
                    color: #43C6AC;
                    font-family: 'Rubik', sans-serif;
                }

                .pos-wizard.single-page .wizard-btn-cart {
                    padding: 14px 28px;
                    background: #43C6AC;
                    color: white;
                    border: none;
                    border-radius: 12px;
                    font-size: 16px;
                    font-weight: 700;
                    cursor: pointer;
                    font-family: 'Rubik', sans-serif;
                    transition: all 0.2s;
                }

                .pos-wizard.single-page .wizard-btn-cart:hover {
                    background: #3BB99D;
                    transform: translateY(-2px);
                }
            `;
            document.head.appendChild(styleEl);
        }

        // [S25] Reset all single-page selections to clean state before rendering
        // Prevents stale keys from a previous item corrupting the new item's display
        // [M1 FIX] Also reset accompagnement, individualAddons, sauceSingle, boissonChoice
        // [A1 FIX] buildSteps must run AFTER reset so its initializations are not wiped
        selections.supplements = {};
        selections.sauces = {};
        selections.sauceOrder = [];
        selections.garnitures = {};
        selections.menuChoice = null;
        selections.sauceFrites = {};
        selections.sauceFritesOrder = [];
        selections.viandes = {};
        selections.totalViandes = 0;
        selections.viandeSupplItems = {};
        selections.viandeSupplExpanded = false;
        selections.supplExpanded = false;
        selections.pain = null;
        selections.fritesGrande = false;
        selections.fritesCheddar = false;
        selections.accompagnement = null;
        selections.individualAddons = {};
        selections.sauceSingle = null;
        selections.boissonChoice = null;

        // [S25] Initialize steps AFTER reset so buildSteps initializations (accompagnement, etc.) are preserved
        // [A1 FIX] buildSteps must run before edit-restore so restore can override defaults
        steps = buildSteps(lastItemData);

        // [P3 FIX] Simple products (boissons, desserts) — no viandes, no sauces, no garnitures,
        // no addons, no paid extras. The wizard would be empty and confusing.
        // Let the native Vue modal (quantity + Add to Cart) handle these products instead.
        var hasExtras = lastItemData.extras && lastItemData.extras.length > 0;
        var hasAddons = lastItemData.addons && lastItemData.addons.length > 0;
        var hasVariations = lastItemData.itemAttributes && lastItemData.itemAttributes.length > 0;
        var isSimpleProduct = steps.length === 0 && !hasExtras && !hasAddons && !hasVariations;
        if (isSimpleProduct) {
            // Restore Vue modal header that was hidden above
            if (originalHeader) {
                originalHeader.style.display = '';
                originalHeader.removeAttribute('data-wiz-hidden');
            }
            if (originalBody) originalBody.style.display = '';
            // [POS-V4-WIZARD-VIEWPORT-FIT] Restore Vue-injected footer for simple products
            // (no wizard rendered → Vue native CTA must be visible).
            if (vueFooter) {
                vueFooter.style.display = '';
                vueFooter.removeAttribute('data-wiz-hidden');
            }
            // Clear any stale wizard data-attributes from previous wizard session
            modal.removeAttribute('data-wizard-total');
            modal.removeAttribute('data-wizard-pos-line-addons');
            modal.removeAttribute('data-wizard-cart-display');
            originalBody = null;
            lastItemData = null;
            return;
        }

        // [EDIT-RESTORE] Restaurer selections depuis édition panier APRÈS buildSteps (override defaults)
        var restoreAttr = modal.getAttribute('data-wizard-restore-selections');
        var isEditRestore = false;
        if (restoreAttr) {
            try {
                var restored = JSON.parse(restoreAttr);
                // Fusionner dans selections (priorité à restored)
                Object.assign(selections, restored);
                // Restaurer quantité et instruction
                if (restored.instruction) instructionText = restored.instruction;
                // [Y1 FIX] Sync itemQuantity from cart line so syncAndSubmit writes the correct qty
                // back to the Vue modal. Without this, itemQuantity stays at its last value (1 after
                // closeWizard) and overwrites the real cart quantity on save.
                if (restored._cartQuantity && restored._cartQuantity > 0) {
                    itemQuantity = restored._cartQuantity;
                }
                modal.removeAttribute('data-wizard-restore-selections');
                isEditRestore = true;

                // [W1 FIX] Recompute totalViandes from selections.viandes after restore.
                // buildWizardRestorePayload fills viandes counts but not totalViandes,
                // so totalViandes stays 0 after Object.assign — causing:
                //   1. buildTicketInstruction to skip the viande block (gates on totalViandes > 0)
                //   2. renderSinglePage canAdd logic to allow over-selection
                if (selections.viandes && typeof selections.viandes === 'object') {
                    var recomputedTotal = 0;
                    Object.keys(selections.viandes).forEach(function (k) {
                        recomputedTotal += (selections.viandes[k] || 0);
                    });
                    selections.totalViandes = recomputedTotal;
                }
            } catch (e) {
                console.warn('[Wizard] Failed to restore selections:', e);
            }
        } else {
            // Nouvelle commande : réinitialiser
            itemQuantity = 1;
            instructionText = '';
        }

        // [S25] Create single-page wizard instead of multi-step
        wizardEl = document.createElement('div');
        wizardEl.id = 'pos-wizard-root';
        wizardEl.innerHTML = renderSinglePage();
        modalDialog.appendChild(wizardEl);

        // [S25] Bind single-page events
        bindSinglePageEvents();

        // [W2 FIX] After edit-restore, sync the rendered UI to match selections state.
        // renderSinglePage() uses selections at render time, but restore runs after render
        // so any restored state (viandes, sauces, garnitures, accompagnement, etc.) needs
        // a UI sync pass to reflect the pre-selected state visually.
        if (isEditRestore) {
            updateSinglePageUI();
        }
    }

    function closeWizard(keepOriginalHidden) {
        if (wizardEl) {
            wizardEl.remove();
            wizardEl = null;
        }
        if (originalBody) {
            // Restore Vue modal header
            var modalEl = originalBody.closest('.modal');
            if (modalEl) {
                modalEl.removeAttribute('data-wizard-total');
                modalEl.removeAttribute('data-wizard-pos-line-addons');
                modalEl.removeAttribute('data-wizard-cart-display');
                var hiddenHeader = modalEl.querySelector('.modal-header[data-wiz-hidden]');
                if (hiddenHeader) {
                    hiddenHeader.style.display = '';
                    hiddenHeader.removeAttribute('data-wiz-hidden');
                }
                // [POS-V4-WIZARD-VIEWPORT-FIT] Restore Vue-injected wizard footer for fallback (simple products).
                var hiddenVueFooter = modalEl.querySelector('[data-wiz-vue-footer][data-wiz-hidden]');
                if (hiddenVueFooter) {
                    hiddenVueFooter.style.display = '';
                    hiddenVueFooter.removeAttribute('data-wiz-hidden');
                }
            }
            if (!keepOriginalHidden) {
                originalBody.style.display = '';
            }
            originalBody = null;
        }
        lastItemData = null;
        currentStep = 0;
        steps = [];
        selections = {};
        itemQuantity = 1;
        instructionText = '';
        currentCategory = 'unknown';
    }

    function updateWizardUI() {
        if (!wizardEl) return;
        // Guard: do not run in single-page mode — it uses NaN keys that corrupt selections
        if (wizardEl.querySelector('.pos-wizard.single-page')) return;

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
                        priceEl.innerText = '+' + fmtPrice(SAUCE_EXTRA_PRICE);
                    } else {
                        priceEl.className = 'option-price';
                        priceEl.innerText = (count === 0 ? 'Gratuit' : '+' + fmtPrice(SAUCE_EXTRA_PRICE));
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
                        priceEl.innerText = '+' + fmtPrice(SAUCE_EXTRA_PRICE);
                    } else {
                        priceEl.className = 'option-price';
                        priceEl.innerText = (count === 0 ? 'Gratuit' : '+' + fmtPrice(SAUCE_EXTRA_PRICE));
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
            var name = btn.getAttribute('data-name') || '';
            var emoji = btn.getAttribute('data-emoji') || '🥬';
            btn.classList.remove('included', 'removed');
            if (isSelected) {
                btn.classList.add('included');
                btn.innerHTML = emoji + ' ✓ ' + escapeHtml(name);
            } else {
                btn.classList.add('removed');
                btn.innerHTML = emoji + ' ✕ Sans ' + escapeHtml(name);
            }
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

        // Update viandes supplémentaires per-viande button states (single-page)
        var totalSupplUI = 0;
        wizardEl.querySelectorAll('.viande-suppl-btn').forEach(function (btn) {
            var key = btn.getAttribute('data-viande-suppl');
            if (!key) return;
            var sc = (selections.viandeSupplItems && selections.viandeSupplItems[key]) || 0;
            totalSupplUI += sc;
            var action = btn.getAttribute('data-action');
            if (action === 'minus') btn.classList.toggle('disabled', sc <= 0);
            var row = btn.closest('[data-suppl-id]');
            if (row) {
                row.classList.toggle('active', sc > 0);
                var countEl = row.querySelector('.viande-suppl-count');
                if (countEl) countEl.textContent = sc;
            }
        });
        // Sync toggle button label & state
        var toggleBtnUI = wizardEl.querySelector('.viande-suppl-toggle');
        if (toggleBtnUI) {
            var isExpandedUI = !wizardEl.querySelector('#viande-suppl-panel.collapsed');
            toggleBtnUI.textContent = totalSupplUI > 0
                ? '🥩+ ' + totalSupplUI + ' viande' + (totalSupplUI > 1 ? 's' : '') + ' extra (+' + fmtPrice(totalSupplUI * VIANDE_SUPPL_PRICE) + ') ' + (isExpandedUI ? '▲' : '▼')
                : '➕ Viande supplémentaire (+' + fmtPrice(VIANDE_SUPPL_PRICE) + '/viande) ' + (isExpandedUI ? '▲' : '▼');
            toggleBtnUI.classList.toggle('has-items', totalSupplUI > 0);
            // If items were added, keep panel visible
            if (totalSupplUI > 0) {
                var panel = wizardEl.querySelector('#viande-suppl-panel');
                if (panel) panel.classList.remove('collapsed');
            }
        }
    }

    /**
     * [S25] Refresh the single-page view
     * Used when full re-render is needed (e.g., after initial load)
     */
    function refreshWizard() {
        if (!wizardEl) return;
        wizardEl.innerHTML = renderSinglePage();
        bindSinglePageEvents();
    }


    /* ==============================
       NAVIGATION HELPERS
       ============================== */

    /**
     * [Sprint 23 Fix P2] Validate if current step can proceed
     * Checks mandatory selections for each step type
     * Returns: { canProceed: boolean, errorMessage: string|null }
     */
    function canProceedFromStep(step) {
        if (!step) return { canProceed: true, errorMessage: null };

        switch (step.type) {
            case 'viande':
            case 'viande_sauce':
                // Must select required number of viandes
                if (!selections.viandes || selections.totalViandes < selections.maxViandes) {
                    var needed = (selections.maxViandes || 0) - (selections.totalViandes || 0);
                    return {
                        canProceed: false,
                        errorMessage: 'Sélectionnez ' + needed + ' viande' + (needed > 1 ? 's' : '') + ' supplémentaire' + (needed > 1 ? 's' : '')
                    };
                }
                // Also check sauce for viande_sauce step
                if (step.type === 'viande_sauce' && (!selections.sauceOrder || selections.sauceOrder.length === 0)) {
                    return { canProceed: false, errorMessage: 'Sélectionnez au moins une sauce' };
                }
                return { canProceed: true, errorMessage: null };

            case 'sauce':
            case 'sauce_single':
                // Must select at least one sauce
                if ((!selections.sauceOrder || selections.sauceOrder.length === 0) && !selections.sauceSingle) {
                    return { canProceed: false, errorMessage: 'Sélectionnez au moins une sauce' };
                }
                return { canProceed: true, errorMessage: null };

            case 'sauce_garnitures':
                // Must select at least one sauce
                if (!selections.sauceOrder || selections.sauceOrder.length === 0) {
                    return { canProceed: false, errorMessage: 'Sélectionnez au moins une sauce' };
                }
                // Garnitures are optional (all included by default)
                return { canProceed: true, errorMessage: null };

            case 'sauce_supplements':
                // Sauce is optional for this step (can have suppléments sans sauce)
                return { canProceed: true, errorMessage: null };

            case 'sauce_accompagnement':
                // Must select at least one sauce
                if (!selections.sauceOrder || selections.sauceOrder.length === 0) {
                    return { canProceed: false, errorMessage: 'Sélectionnez au moins une sauce' };
                }
                // Accompagnement is optional for assiettes with defaults
                return { canProceed: true, errorMessage: null };

            case 'pain':
                // Must select pain type for sandwichs
                if (!selections.pain) {
                    return { canProceed: false, errorMessage: 'Sélectionnez Pain ou Galette' };
                }
                return { canProceed: true, errorMessage: null };

            case 'accompagnement':
                // Must select accompagnement for assiettes
                if (!selections.accompagnement) {
                    return { canProceed: false, errorMessage: 'Sélectionnez un accompagnement (Riz, Frites ou Salade)' };
                }
                return { canProceed: true, errorMessage: null };

            case 'perso':
            case 'supplements':
            case 'garnitures':
            case 'supplements_menu':
            case 'menu':
            case 'menu_choice':
            case 'boisson_choice':
            case 'sauce_frites':
            case 'recap':
                // These steps are all optional
                return { canProceed: true, errorMessage: null };

            default:
                return { canProceed: true, errorMessage: null };
        }
    }

    /**
     * [Sprint 23 Fix P2] Show validation error message in wizard footer
     */
    function showValidationError(message) {
        var errorEl = wizardEl.querySelector('.wizard-validation-error');
        if (!errorEl) {
            errorEl = document.createElement('div');
            errorEl.className = 'wizard-validation-error';
            var footer = wizardEl.querySelector('.wizard-footer');
            if (footer) {
                footer.insertBefore(errorEl, footer.firstChild);
            } else {
                wizardEl.appendChild(errorEl);
            }
        }
        errorEl.textContent = '⚠️ ' + message;
        errorEl.style.display = 'block';

        // Auto-hide after 3 seconds
        setTimeout(function () {
            if (errorEl) errorEl.style.display = 'none';
        }, 3000);
    }

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

        // Garniture toggle (multi-step path)
        // [H2 FIX] Use 'c_<id>' keys to match syncAndSubmit and single-page path
        wizardEl.querySelectorAll('.wizard-option[data-type="garniture"]').forEach(function (opt) {
            opt.addEventListener('click', function () {
                var rawId = this.getAttribute('data-id');
                var id = parseInt(rawId);
                if (!selections.garnitures) selections.garnitures = {};
                var key = isNaN(id) ? rawId : ('c_' + id);
                selections.garnitures[key] = !selections.garnitures[key];
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

        // Viandes supplémentaires +/- buttons (multi-step path, per-viande)
        wizardEl.querySelectorAll('.viande-suppl-btn').forEach(function (btn) {
            btn.addEventListener('click', function () {
                var key = this.getAttribute('data-viande-suppl');
                if (!key) return;
                var action = this.getAttribute('data-action');
                if (!selections.viandeSupplItems) selections.viandeSupplItems = {};
                if (action === 'plus') {
                    selections.viandeSupplItems[key] = (selections.viandeSupplItems[key] || 0) + 1;
                } else if (action === 'minus') {
                    var cur = selections.viandeSupplItems[key] || 0;
                    if (cur > 0) {
                        selections.viandeSupplItems[key] = cur - 1;
                        if (selections.viandeSupplItems[key] === 0) delete selections.viandeSupplItems[key];
                    }
                }
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
                    // [Sprint 23 Fix P2] Validate current step before proceeding
                    var activeSteps = getActiveSteps();
                    var activeIdx = getActiveStepIndex();
                    var currentStepObj = activeSteps[activeIdx];
                    var validation = canProceedFromStep(currentStepObj);

                    if (!validation.canProceed) {
                        showValidationError(validation.errorMessage);
                        return; // Block navigation
                    }
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

        // [H2 FIX] Removed duplicate .garniture-toggle-btn listener from bindEvents().
        // Single-page garniture toggles use data-garniture="c_<id>" and are handled
        // exclusively by bindSinglePageEvents() to avoid NaN key corruption.

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
       [S25] SINGLE-PAGE EVENT BINDERS
       ============================== */
    function bindSinglePageEvents() {
        if (!wizardEl) return;

        // Quantity +/- buttons
        wizardEl.querySelectorAll('[data-qty]').forEach(function (btn) {
            btn.addEventListener('click', function () {
                var action = this.getAttribute('data-qty');
                if (action === 'minus' && itemQuantity > 1) {
                    itemQuantity--;
                } else if (action === 'plus') {
                    itemQuantity++;
                }
                updateSinglePageUI();
            });
        });

        // Viande +/- buttons
        wizardEl.querySelectorAll('.viande-btn').forEach(function (btn) {
            btn.addEventListener('click', function () {
                var viandeKey = this.getAttribute('data-viande');
                var action = this.getAttribute('data-action');
                var currentCount = selections.viandes[viandeKey] || 0;
                var total = selections.totalViandes || 0;

                // [B1/R4 FIX] Determine max from detectViandeCountFromData (reads name/description)
                // then fallback to attribute count
                var max = detectViandeCountFromData(lastItemData);
                if (max === 0 && lastItemData && lastItemData.itemAttributes) {
                    var viandeAttrs = lastItemData.itemAttributes.filter(function (attr) {
                        var n = normalizeStr(attr.name);
                        return n.includes('viande') || n.includes('meat');
                    });
                    max = viandeAttrs.length || 1;
                }
                if (max === 0) max = 1;

                if (action === 'plus') {
                    if (total < max) {
                        selections.viandes[viandeKey] = currentCount + 1;
                        selections.totalViandes = total + 1;
                    }
                } else if (action === 'minus' && currentCount > 0) {
                    selections.viandes[viandeKey] = currentCount - 1;
                    selections.totalViandes = total - 1;
                }
                updateSinglePageUI();
            });
        });

        // Bouton toggle "Viande supplémentaire"
        var toggleBtn = wizardEl.querySelector('.viande-suppl-toggle');
        if (toggleBtn) {
            toggleBtn.addEventListener('click', function () {
                selections.viandeSupplExpanded = !selections.viandeSupplExpanded;
                var panel = wizardEl.querySelector('#viande-suppl-panel');
                if (panel) panel.classList.toggle('collapsed', !selections.viandeSupplExpanded);
                // Update toggle label
                var totalSuppl = 0;
                if (selections.viandeSupplItems) {
                    Object.keys(selections.viandeSupplItems).forEach(function (k) { totalSuppl += selections.viandeSupplItems[k] || 0; });
                }
                var expanded = selections.viandeSupplExpanded;
                this.textContent = totalSuppl > 0
                    ? '🥩+ ' + totalSuppl + ' viande' + (totalSuppl > 1 ? 's' : '') + ' extra (+' + fmtPrice(totalSuppl * VIANDE_SUPPL_PRICE) + ') ' + (expanded ? '▲' : '▼')
                    : '➕ Viande supplémentaire (+' + fmtPrice(VIANDE_SUPPL_PRICE) + '/viande) ' + (expanded ? '▲' : '▼');
                this.classList.toggle('has-items', totalSuppl > 0);
            });
        }

        // Viandes supplémentaires +/- buttons (per-viande)
        wizardEl.querySelectorAll('.viande-suppl-btn').forEach(function (btn) {
            btn.addEventListener('click', function () {
                var key = this.getAttribute('data-viande-suppl');
                if (!key) return;
                var action = this.getAttribute('data-action');
                if (!selections.viandeSupplItems) selections.viandeSupplItems = {};
                if (action === 'plus') {
                    selections.viandeSupplItems[key] = (selections.viandeSupplItems[key] || 0) + 1;
                } else if (action === 'minus') {
                    var cur = selections.viandeSupplItems[key] || 0;
                    if (cur > 0) {
                        selections.viandeSupplItems[key] = cur - 1;
                        if (selections.viandeSupplItems[key] === 0) delete selections.viandeSupplItems[key];
                    }
                }
                updateSinglePageUI();
            });
        });

        // Pain/Galette selection — .pain-btn (single-page segment) or .pain-opt (step mode)
        wizardEl.querySelectorAll('.pain-btn, .pain-opt').forEach(function (opt) {
            opt.addEventListener('click', function () {
                var id = this.getAttribute('data-id');
                var parsedId = parseInt(id);
                selections.pain = isNaN(parsedId) ? id : parsedId;
                updateSinglePageUI();
            });
        });

        // Crudites toggle — default is included (true), click to remove (false), click again to restore (true)
        wizardEl.querySelectorAll('.garniture-toggle-btn').forEach(function (btn) {
            btn.addEventListener('click', function () {
                var garnId = this.getAttribute('data-garniture');
                if (!selections.garnitures) selections.garnitures = {};
                // undefined or true = included; toggle to false (removed) and back
                var current = selections.garnitures[garnId];
                selections.garnitures[garnId] = (current === false) ? true : false;
                updateSinglePageUI();
            });
        });

        // Sauce selection (multi-select) — .sauce-opt (multi-step) ou .sauce-chip (single-page)
        wizardEl.querySelectorAll('.sauce-opt, [data-type="sauce"]').forEach(function (opt) {
            opt.addEventListener('click', function () {
                var sauceId = this.getAttribute('data-id'); // String key like 's_123'
                if (!selections.sauces) selections.sauces = {};
                if (!selections.sauceOrder) selections.sauceOrder = [];

                if (selections.sauces[sauceId]) {
                    // Deselect
                    selections.sauces[sauceId] = false;
                    var idx = selections.sauceOrder.indexOf(sauceId);
                    if (idx > -1) selections.sauceOrder.splice(idx, 1);
                } else {
                    // Select
                    selections.sauces[sauceId] = true;
                    selections.sauceOrder.push(sauceId);
                }
                updateSinglePageUI();
            });
        });

        // [A1 FIX] Accompagnement radio (assiettes) — single-page binding
        wizardEl.querySelectorAll('.wizard-option[data-type="accompagnement"]').forEach(function (opt) {
            opt.addEventListener('click', function () {
                var id = parseInt(this.getAttribute('data-id'));
                selections.accompagnement = id;
                updateSinglePageUI();
            });
        });

        // Toggle suppléments collapse
        var supplToggleBtn = wizardEl.querySelector('.suppl-toggle');
        if (supplToggleBtn) {
            supplToggleBtn.addEventListener('click', function () {
                selections.supplExpanded = !selections.supplExpanded;
                var panel = wizardEl.querySelector('.suppl-panel');
                if (panel) panel.classList.toggle('collapsed', !selections.supplExpanded);
                var supplSelected = selections.supplements ? Object.values(selections.supplements).filter(Boolean).length : 0;
                var expanded = selections.supplExpanded;
                this.textContent = supplSelected > 0
                    ? '➕ Suppléments (' + supplSelected + ' choisi' + (supplSelected > 1 ? 's' : '') + ') ' + (expanded ? '▲' : '▼')
                    : '➕ Suppléments ' + (expanded ? '▲' : '▼');
                this.classList.toggle('has-items', supplSelected > 0);
            });
        }

        // Supplements toggle — only bind elements with data-key (single-page format)
        wizardEl.querySelectorAll('.supplement-opt[data-key]').forEach(function (opt) {
            opt.addEventListener('click', function () {
                var supKey = this.getAttribute('data-key');
                if (!supKey) return; // safety guard
                if (!selections.supplements) selections.supplements = {};
                selections.supplements[supKey] = !selections.supplements[supKey];
                updateSinglePageUI();
            });
        });

        // Formule cards
        wizardEl.querySelectorAll('.formule-card').forEach(function (card) {
            card.addEventListener('click', function () {
                var value = this.getAttribute('data-value');
                selections.menuChoice = value;
                updateSinglePageUI();
            });
        });

        // Frites upgrade options (Grande Portion / Cheddar Fondu)
        wizardEl.querySelectorAll('.frites-upgrade-opt').forEach(function (opt) {
            opt.addEventListener('click', function () {
                var upgrade = this.getAttribute('data-upgrade');
                selections[upgrade] = !selections[upgrade];
                updateSinglePageUI();
            });
        });

        // Sauce frites — .sauce-frite-opt (multi-step) ou .sauce-chip[data-type="sauce_frite"] (single-page)
        wizardEl.querySelectorAll('.sauce-frite-opt, [data-type="sauce_frite"]').forEach(function (opt) {
            opt.addEventListener('click', function () {
                var sauceId = this.getAttribute('data-id'); // string key like 'sf_123'
                if (!selections.sauceFrites) selections.sauceFrites = {};
                if (!selections.sauceFritesOrder) selections.sauceFritesOrder = [];

                if (selections.sauceFrites[sauceId]) {
                    selections.sauceFrites[sauceId] = false;
                    var idx = selections.sauceFritesOrder.indexOf(sauceId);
                    if (idx > -1) selections.sauceFritesOrder.splice(idx, 1);
                } else {
                    selections.sauceFrites[sauceId] = true;
                    selections.sauceFritesOrder.push(sauceId);
                }
                updateSinglePageUI();
            });
        });

        // Comment textarea
        var commentTa = wizardEl.querySelector('.wizard-comment-field');
        if (commentTa) {
            commentTa.addEventListener('input', function () {
                instructionText = this.value;
                updateSinglePageUI();
            });
        }

        // Add to cart button
        var addBtn = wizardEl.querySelector('[data-action="add-to-cart"]');
        if (addBtn) {
            addBtn.addEventListener('click', function () {
                syncAndSubmit();
            });
        }

        // Cancel / close wizard button — dismiss without saving
        var cancelBtn = wizardEl.querySelector('[data-action="cancel-wizard"]');
        if (cancelBtn) {
            cancelBtn.addEventListener('click', function () {
                var modal = document.getElementById('item-variation-modal');
                closeWizard(false);
                if (modal) modal.classList.remove('active');
                document.body.style.overflowY = 'auto';
            });
        }

        // Keyboard: Enter to submit
        if (!window.wizardSinglePageKeyboardBound) {
            window.wizardSinglePageKeyboardBound = true;
            document.addEventListener('keydown', function (e) {
                if (!wizardEl) return;
                if (e.target.tagName === 'TEXTAREA') return;

                if (e.key === 'Enter' && e.ctrlKey) {
                    e.preventDefault();
                    syncAndSubmit();
                }
            });
        }
    }

    /* ==============================
       OBSERVE MODAL OPENING
       ============================== */

    // Track the modal node we are currently observing so we can re-attach when
    // Vue Router destroys and recreates the component (SPA navigation).
    var _observedModal = null;
    var _modalObserver = null;

    function _attachModalObserver(modal) {
        if (_modalObserver) _modalObserver.disconnect();
        _observedModal = modal;

        _modalObserver = new MutationObserver(function () {
            var currentModal = document.getElementById('item-variation-modal');
            // If Vue replaced the node, re-attach to the new one immediately.
            if (currentModal && currentModal !== _observedModal) {
                _attachModalObserver(currentModal);
                return;
            }
            if (modal.classList.contains('active') && !wizardEl) {
                var retries = 0;
                var tryOpen = function () {
                    if (lastItemData || modal.getAttribute('data-wizard-item-data')) {
                        openWizard(modal);
                    } else if (retries < 20) {
                        retries++;
                        setTimeout(tryOpen, 50);
                    }
                    // else: data never arrived — Vue modal stays visible as fallback
                };
                setTimeout(tryOpen, 0);
            } else if (!modal.classList.contains('active') && wizardEl) {
                closeWizard();
            }
        });

        _modalObserver.observe(modal, { attributes: true, attributeFilter: ['class'] });
    }

    // Body-level observer: detects when Vue mounts / re-mounts #item-variation-modal
    // after SPA navigation. Runs once, never stops.
    function _watchBodyForModal() {
        var bodyObserver = new MutationObserver(function () {
            var modal = document.getElementById('item-variation-modal');
            if (modal && modal !== _observedModal) {
                // New modal node detected (Vue re-mounted the POS component).
                // Reset stale item data so the wizard doesn't open with a previous product's data.
                lastItemData = null;
                if (wizardEl) closeWizard();
                _attachModalObserver(modal);
            } else if (!modal && _observedModal) {
                // Modal was removed from DOM (navigated away from POS).
                // Clean up so state doesn't leak into the next visit.
                if (wizardEl) closeWizard();
                lastItemData = null;
                _observedModal = null;
                if (_modalObserver) { _modalObserver.disconnect(); _modalObserver = null; }
            }
        });
        bodyObserver.observe(document.body, { childList: true, subtree: true });
    }

    function init() {
        // Attach immediately if the modal already exists (e.g. hard refresh on /admin/pos).
        var modal = document.getElementById('item-variation-modal');
        if (modal) _attachModalObserver(modal);

        // Always watch the body so we catch Vue re-mounting the modal after navigation.
        _watchBodyForModal();
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

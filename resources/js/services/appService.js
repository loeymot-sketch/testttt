import VueSimpleAlert from "vue3-simple-alert";
import store from "../store";
import statusEnum from "../enums/modules/statusEnum";
import orderStatusEnum from "../enums/modules/orderStatusEnum";
import askEnum from "../enums/modules/askEnum";
import taxTypeEnum from "../enums/modules/taxTypeEnum";
import currencyPositionEnum from "../enums/modules/currencyPositionEnum";
import { ref } from "vue";

export default {
    sideDrawerShow: function (id = 'sideDrawer') {
        const drawerDivs = document?.querySelectorAll(".drawer");
        const drawerSets = document?.querySelectorAll("[data-drawer]");

        drawerSets?.forEach((drawerSet) => {
            const targetElm = document?.querySelector(drawerSet?.dataset?.drawer);
            drawerSets?.forEach(drawerBtn => drawerBtn?.classList?.remove("active"));
            drawerDivs?.forEach(drawerDiv => drawerDiv?.classList?.remove("active"));
            targetElm?.classList?.add("active");
            drawerSet?.classList?.add("active");
            document.body.style.overflowY = "hidden";
            document?.querySelector(".backdrop")?.classList?.add("active");
        });
    },
    sideDrawerHide: function (id = 'sideDrawer') {
        const drawerDivs = document?.querySelectorAll(".drawer");
        const drawerSets = document?.querySelectorAll("[data-drawer]");
        // Always tear down drawer/backdrop state — do NOT gate on #sidebar.
        // Dashboard and many routes have no #sidebar; hiding used to no-op and left
        // .backdrop.active stuck (full-screen dim overlay after SPA navigation).
        drawerSets?.forEach((drawerBtn) => drawerBtn?.classList?.remove("active"));
        drawerDivs?.forEach((drawerDiv) => drawerDiv?.classList?.remove("active"));
        document?.querySelector(".backdrop")?.classList?.remove("active");
        document.body.style.overflowY = "auto";
    },

    modalShow: function (id = '.modal') {
        const modalTarget = document?.querySelector(id);
        if (modalTarget) {
            modalTarget.classList.add('active');
            document.body.style.overflowY = 'hidden';
        }
    },

    modalHide: function (id = ".modal") {
        let modalDivs = document?.querySelectorAll(id);
        document.body.style.overflowY = "auto";
        modalDivs?.forEach((modalDiv) => modalDiv?.classList?.remove("active"));
    },

    phoneNumber: function (e) {
        let char = String.fromCharCode(e.keyCode);
        if (/^[+]?[0-9]*$/.test(char)) return true;
        else e.preventDefault();
    },

    onlyNumber: function (e) {
        let res = (e.charCode !== 8 && e.charCode === 0 || (e.charCode >= 48 && e.charCode <= 57));
        if (res)
            return true;
        else
            e.preventDefault();
    },

    floatNumber: function (e) {
        let char = String.fromCharCode(e.keyCode);
        if (/^[.]?[0-9]*$/.test(char)) return true;
        else e.preventDefault();
    },

    // [HEAL-money-fr 2026-06-26] FR (ADR-007) money rendering. Previously this
    // returned "0.00€" (US decimal point + glued symbol, no space) — visible on
    // the POS cart Sous-total/Total. French typography requires a comma decimal,
    // an NBSP (U+00A0) thousands separator, and an NBSP between the amount and the
    // symbol. Aligned with the codebase canonical convention in
    // helpers/posFormatCents.js ("1 234,56 €"). The number is built manually
    // (not via Intl) so output is deterministic and never depends on the runtime's
    // locale-data (and so the thousands separator is NBSP U+00A0, not Intl's narrow
    // NBSP U+202F). Signature is unchanged: (amount, decimal, currency, position).
    currencyFormat(amount, decimal, currency, position) {
        const NBSP = " ";
        // [PRIX-AFFICHÉ-DÉCIMALES 2026-07-01] Le réglage `site_digit_after_decimal_point`
        // arrive en CHAÎNE ('2') depuis l'API settings. `Number.isFinite('2')` === false
        // → l'ancien code retombait sur 0 décimale → TOUS les prix caisse s'affichaient
        // arrondis à l'entier (« 9 € » au lieu de « 9,40 € »). On coerce en nombre ;
        // défaut 2 (EUR) si absent/invalide. Un 0 numérique explicite reste respecté.
        const parsedDecimal = typeof decimal === "number" ? decimal : parseInt(decimal, 10);
        const digits = Number.isFinite(parsedDecimal) ? parsedDecimal : 2;
        const num = parseFloat(amount);
        const safe = Number.isFinite(num) ? num : 0;

        // toFixed gives a US-formatted fixed-precision string; split into the
        // integer + fractional parts so we can apply FR grouping/decimal.
        const fixed = Math.abs(safe).toFixed(digits);
        const sign = safe < 0 ? "-" : "";
        const [whole, fraction] = fixed.split(".");
        const groupedWhole = whole.replace(/\B(?=(\d{3})+(?!\d))/g, NBSP);
        const number = sign + (fraction !== undefined ? `${groupedWhole},${fraction}` : groupedWhole);

        if (position === currencyPositionEnum.LEFT) {
            return `${currency}${NBSP}${number}`;
        }
        return `${number}${NBSP}${currency}`;
    },
    logoutConfirmation: function () {
        return new VueSimpleAlert.confirm(
            "You will able to log in again using the kiosk machine!",
            "Are you sure?",
            "warning",
            {
                confirmButtonText: "Yes, Log Out!",
                cancelButtonText: "No, Cancel!",
                confirmButtonColor: "#696cff",
                cancelButtonColor: "#8592a3",
            }
        );
    },
    destroyConfirmation: function () {
        return new VueSimpleAlert.confirm(
            "You will not be able to recover the deleted record!",
            "Are you sure?",
            "warning",
            {
                confirmButtonText: "Yes, Delete it!",
                cancelButtonText: "No, Cancel!",
                confirmButtonColor: "#696cff",
                cancelButtonColor: "#8592a3",
            }
        );
    },
    acceptOrder: function () {
        return new VueSimpleAlert.confirm(
            "You will not be able to cancel the order!",
            "Are you sure?",
            "warning",
            {
                confirmButtonText: "Yes, Accept it!",
                cancelButtonText: "No, Cancel!",
                confirmButtonColor: "#696cff",
                cancelButtonColor: "#8592a3",
            }
        );
    },
    // [GOAL-2026-05-30 ORD-01] Confirm dialog for the online-order "Encaisser & Valider
    // (Kiosk)" single-click cash-collect-then-accept path. OnlineOrderShowComponent
    // called appService.confirmCashPayment() but the method did not exist -> the click
    // threw a synchronous TypeError and the button was silently dead. Mirrors acceptOrder
    // (returns the VueSimpleAlert.confirm promise; the component proceeds on confirm).
    confirmCashPayment: function () {
        return new VueSimpleAlert.confirm(
            "Encaisser cette commande en espèces et la valider ?",
            "Confirmer l'encaissement",
            "question",
            {
                confirmButtonText: "Oui, encaisser",
                cancelButtonText: "Annuler",
                confirmButtonColor: "#696cff",
                cancelButtonColor: "#8592a3",
            }
        );
    },
    cancelOrder: function () {
        return new VueSimpleAlert.confirm(
            "You will not be able to accept the order!",
            "Are you sure?",
            "warning",
            {
                confirmButtonText: "Yes, Cancel it!",
                cancelButtonText: "No, Cancel",
                confirmButtonColor: "#696cff",
                cancelButtonColor: "#8592a3",
            }
        );
    },

    distance: function (lat1, lng1, lat2, lng2) {
        let radiationLat1 = Math.PI * lat1 / 180
        let radiationLat2 = Math.PI * lat2 / 180
        let theta = lng1 - lng2;
        let radiationTheta = Math.PI * theta / 180
        let distance = Math.sin(radiationLat1) * Math.sin(radiationLat2) + Math.cos(radiationLat1) * Math.cos(radiationLat2) * Math.cos(radiationTheta);
        distance = Math.acos(distance)
        distance = distance * 180 / Math.PI
        distance = distance * 60 * 1.1515
        distance = distance * 1.609344
        return distance;
    },

    recursiveRouter: function (routes, permission) {
        let perms = permission;
        if (perms && !Array.isArray(perms) && Array.isArray(perms.data)) {
            perms = perms.data;
        }
        if (!Array.isArray(perms)) {
            perms = [];
        }
        const hydrated = perms.length > 0;
        for (let i = 0; i < routes.length; i++) {
            const route = routes[i];
            if (!route) {
                continue;
            }
            if (route.meta && route.meta.permissionUrl) {
                const key = route.meta.permissionUrl;
                const entry = perms.find((p) => p && (p.url === key || p.name === key));
                if (entry) {
                    route.meta.access = entry.access;
                    if (entry.title) {
                        route.meta.title = entry.title;
                    }
                } else if (hydrated) {
                    // Table déjà chargée, clé introuvable : ne plus laisser
                    // le caissier ouvrir le cockpit / une URL non mappée.
                    route.meta.access = false;
                }
            }
            if (route.children) {
                this.recursiveRouter(route.children, perms);
            }
        }
    },

    textShortener: function (text, number = 30) {
        if (text) {
            if (!(text.length < number)) {
                return text.substring(0, number) + "..";
            }
        }
        return text;
    },
    statusClass: function (status) {
        if (status === statusEnum.ACTIVE) {
            // WCAG AA on small badge copy (axe serious): green-600 on green-100 was borderline/low.
            return "db-table-badge text-green-900 bg-green-100";
        } else {
            return "db-table-badge text-red-800 bg-red-100";
        }
    },

    orderStatusClass: function (status) {
        if (status == orderStatusEnum.ACCEPT || status == orderStatusEnum.PREPARING) {
            return "py-0.5 px-2 rounded-full text-[10px] font-rubik leading-4 first-letter:capitalize whitespace-nowrap text-green-900 bg-green-100";
        }
        else if (status == orderStatusEnum.PENDING) {
            return "py-0.5 px-2 rounded-full text-[10px] font-rubik leading-4 first-letter:capitalize whitespace-nowrap text-amber-900 bg-amber-100";
        }
        else if (status == orderStatusEnum.PREPARED) {
            return "py-0.5 px-2 rounded-full text-[10px] font-rubik leading-4 first-letter:capitalize whitespace-nowrap text-purple-900 bg-purple-100";
        }
        else if (status == orderStatusEnum.OUT_FOR_DELIVERY) {
            return "py-0.5 px-2 rounded-full text-[10px] font-rubik leading-4 first-letter:capitalize whitespace-nowrap text-sky-900 bg-sky-100";
        }
        else if (status == orderStatusEnum.DELIVERED) {
            return "py-0.5 px-2 rounded-full text-[10px] font-rubik leading-4 first-letter:capitalize whitespace-nowrap text-pink-900 bg-pink-100";
        }
        else {
            return "py-0.5 px-2 rounded-full text-[10px] font-rubik leading-4 first-letter:capitalize whitespace-nowrap text-red-900 bg-red-100";
        }
    },

    askClass: function (ask) {
        if (ask === askEnum.YES) {
            return "db-table-badge text-green-600 bg-green-100";
        } else {
            return "db-table-badge text-red-600 bg-red-100";
        }
    },

    taxTypeClass: function (type) {
        if (type === taxTypeEnum.FIXED) {
            return "db-table-badge text-blue-500 bg-blue-100";
        } else {
            return "db-table-badge text-orange-500 bg-orange-100";
        }
    },

    // [2026-09-02] Un objet `Date` passé tel quel devient, via `encodeURIComponent`,
    // « Sun Mar 01 2026 00:00:00 GMT+0100 (heure normale d’Europe centrale) » — une chaîne
    // que le serveur REFUSE de lire (mesuré : `Carbon::parse` → « Could not parse »).
    // Choisir une période sur le tableau de bord ne renvoyait donc aucun chiffre.
    // Le jour est pris en heure LOCALE : `toISOString()` reculerait le 1er mars à minuit
    // à Paris au 28 février, puisqu'il vaut 23:00 UTC la veille.
    jourCivilLocal: function (d) {
        const deuxChiffres = (n) => String(n).padStart(2, "0");

        return `${d.getFullYear()}-${deuxChiffres(d.getMonth() + 1)}-${deuxChiffres(d.getDate())}`;
    },

    // [2026-09-02] `toLocaleString()` sans argument suit la locale du NAVIGATEUR, pas
    // celle du produit. Photographié en campagne : « Généré à 9/2/2026, 7:38:28 PM » et
    // « 7/16/2026, 6:57:02 AM » dans une interface entièrement française. « 9/2/2026 » se
    // lit 9 février pour un lecteur français et 2 septembre pour un américain ; sur une
    // date de clôture Z, l'ambiguïté porte sur une pièce fiscale.
    dateHeureFr: function (valeur) {
        if (valeur === null || valeur === undefined || valeur === '') return "\u2014";
        const d = valeur instanceof Date ? valeur : new Date(valeur);
        if (Number.isNaN(d.getTime())) return String(valeur);

        return d.toLocaleString('fr-FR', {
            day: '2-digit', month: '2-digit', year: 'numeric',
            hour: '2-digit', minute: '2-digit', second: '2-digit',
            hour12: false,
        });
    },

    requestHandler: function (requests) {
        let i = 1;
        let what = "?";
        let response = "";

        for (let request in requests) {
            if (requests[request] !== "" && requests[request] !== null) {
                if (i !== 1) {
                    response += "&";
                }
                const valeur = requests[request] instanceof Date
                    ? this.jourCivilLocal(requests[request])
                    : requests[request];
                response += request + "=" + encodeURIComponent(valeur);
            }
            i++;
        }

        if (response) {
            response = what + response;
        }

        return response;
    },

    responsiveLoad: function () {
        let mainHeader = document?.querySelector(".db-header");
        let subHeader = document?.querySelector(".sub-header");
        let mainHeight = mainHeader?.scrollHeight;

        if (subHeader) {
            subHeader.style.top = `${mainHeight}px`;
        }
    },


    permissionChecker: function (permissionName) {
        let i, permissions = store.getters.authPermission;
        for (i = 0; i < permissions.length; i++) {
            if (typeof permissions[i].name !== "undefined" && permissions[i].name) {
                if (permissions[i].name === permissionName) {
                    return permissions[i].access;
                }
            }
        }
    },

    formDataShow: function (formData) {
        for (let pair of formData.entries()) {
            // [P13_LOG_HYGIENE] console.log(pair[0] + " : " + pair[1]);
        }
    },

    // pos customization code starts here
    singleSlideDown: function (dataAttr, attrName, toggleClass) {
        const btnElement = document?.querySelector(dataAttr);
        const tabElement = document?.querySelector(btnElement?.dataset[attrName]);
        document?.addEventListener("click", function (event) {
            if (btnElement && tabElement) {
                if (!btnElement?.contains(event?.target)) {
                    if (!tabElement?.contains(event?.target)) {
                        btnElement?.classList?.remove(toggleClass);
                        tabElement.style.display = "none";
                    }
                } else {
                    btnElement?.classList?.add(toggleClass);
                    tabElement.style.display = `block`;
                }
            }
        });
    },


    singleGroupActive: function (parentClass, addedClass) {
        const singleElements = document?.querySelectorAll(parentClass);

        singleElements?.forEach((singleElement) => {
            for (let i = 0; i < singleElement.childElementCount; i++) {
                singleElement?.children[i]?.addEventListener("click", function () {
                    for (let a = 0; a < singleElement.childElementCount; a++) singleElement?.children[a]?.classList?.remove(addedClass);
                    singleElement?.children[i]?.classList?.add(addedClass);
                })
            }
        })
    },
    //pos customization code ends here

    handleTab: function (event, targetID, targetButton, targetDiv, active) {
        const targetBtns = document.querySelectorAll(targetButton);
        const targetDivs = document.querySelectorAll(targetDiv);
        const currentBtn = event.currentTarget;
        const currentDiv = document.querySelector(targetID);
        targetBtns.forEach(item => item.classList.remove(active));
        targetDivs.forEach(item => item.classList.remove(active));
        currentBtn.classList.add(active);
        currentDiv.classList.add(active);
    },

    //handle tab end here
    openCanvas: function (targetID) {
        setTimeout(() => {
            const targetElement = document.getElementById(targetID);
            targetElement.classList.add('active');
            document.body.classList.add('overflow-hidden');
            document.body.style.overflowY = 'hidden';
        }, 50);
    },

    closeCanvas: function (targetID) {
        const targetElement = document.getElementById(targetID);
        targetElement.classList.remove('active');
        document.body.classList.remove('overflow-hidden');
        document.body.style.overflowY = 'auto'
    },

    closeBackdrop: function (event) {
        const containerElement = event.currentTarget.firstElementChild
        const isWrapperElement = event.target.contains(containerElement)

        if (isWrapperElement) {
            event.currentTarget.classList.remove('active');
            document.body.classList.remove('overflow-hidden');
            document.body.style.overflowY = 'auto';
        }
    },

    //Handle canvas end here
    handleSlide: function (id) {
        const targetElement = document.querySelector(`#${id}`);

        targetElement.classList.add("transition-all", "duration-300", "ease-in-out");

        if (targetElement.style.visibility == 'visible') {
            targetElement.style.height = '0px';
            targetElement.style.overflow = 'hidden';
            targetElement.style.opacity = '0';
            targetElement.style.visibility = 'hidden';
            document.querySelectorAll('.table-filter-btn').forEach(btn => btn.classList.remove('rotated'));
        }
        else {
            targetElement.style.height = targetElement.scrollHeight + 'px'
            setTimeout(() => {
                targetElement.style.overflow = 'visible';
            }, 300);
            targetElement.style.opacity = '1';
            targetElement.style.visibility = 'visible';
            document.querySelectorAll('.table-filter-btn').forEach(btn => btn.classList.add('rotated'));
        }
    },

    //Kds filter open close here

    openFilterSlide: function (event) {
        const btn = event.currentTarget
        const options = btn.nextElementSibling;
        const isExpanded = btn.getAttribute("aria-expanded") === "true";
        const checkboxes = document.querySelectorAll(".filter");
        checkboxes.forEach(function (otherBtn) {
            if (otherBtn != btn) {
                const otherOptions = otherBtn.nextElementSibling;
                if (otherBtn.getAttribute("aria-expanded") === "true") {
                    otherOptions.style.height = "0px";
                    otherOptions.style.margin = "0px";
                    otherBtn.querySelector(".icon").classList.remove("fa-chevron-up");
                    otherBtn.querySelector(".icon").classList.add("fa-chevron-down");
                    otherBtn.setAttribute("aria-expanded", "false");
                }
            }
        });

        if (isExpanded) {
            options.style.height = "0px";
            options.style.margin = "0px";
            btn.querySelector(".icon").classList.remove("fa-chevron-up");
            btn.querySelector(".icon").classList.add("fa-chevron-down");
        } else {
            options.style.height = `${options.scrollHeight}px`;
            options.style.margin = "8px 0px 0px 0px";
            btn.querySelector(".icon").classList.remove("fa-chevron-down");
            btn.querySelector(".icon").classList.add("fa-chevron-up");
        }

        btn.setAttribute("aria-expanded", !isExpanded);

    },

    closeFilterSlide: function (event) {
        const filterBtns = document.querySelectorAll('.filter');
        if (!event.target.closest(".filter")) {
            filterBtns.forEach(function (btn) {
                const options = btn.nextElementSibling;
                if (btn.getAttribute("aria-expanded") === "true") {
                    options.style.height = "0px";
                    options.style.margin = "0px";
                    btn.querySelector(".icon").classList.remove("fa-chevron-up");
                    btn.querySelector(".icon").classList.add("fa-chevron-down");
                    btn.setAttribute("aria-expanded", "false");
                }
            });
        }
    },

    // open setting menu

    openSettingMenu: function (event) {
        const btn = event.currentTarget;
        const options = btn.nextElementSibling;
        const isExpanded = btn.getAttribute("aria-expanded") === "true";
        document.querySelectorAll(".settings-btn").forEach((otherBtn) => {
            if (otherBtn !== btn && otherBtn.getAttribute("aria-expanded") === "true") {
                const otherOptions = otherBtn.nextElementSibling;
                otherOptions.style.height = "0px";
                otherOptions.style.margin = "0px";
                otherBtn.querySelector(".icon").classList.remove("fa-chevron-up");
                otherBtn.querySelector(".icon").classList.add("fa-chevron-down");
                otherBtn.setAttribute("aria-expanded", "false");
            }
        });

        if (isExpanded) {
            options.style.height = "0px";
            options.style.margin = "0px";
            btn.querySelector(".icon").classList.remove("fa-chevron-up");
            btn.querySelector(".icon").classList.add("fa-chevron-down");
        } else {
            options.style.height = "auto";
            const pixel = options.scrollHeight;
            options.style.height = "0px";
            requestAnimationFrame(() => {
                options.style.height = `${pixel}px`;
                options.style.margin = "8px 0px 0px 0px";
            });

            btn.querySelector(".icon").classList.remove("fa-chevron-down");
            btn.querySelector(".icon").classList.add("fa-chevron-up");
        }

        btn.setAttribute("aria-expanded", !isExpanded);
    },

    closeSettingMenu: function (event) {
        if (!event.target.closest(".settings-btn")) {
            document.querySelectorAll(".settings-btn").forEach((btn) => {
                if (btn.getAttribute("aria-expanded") === "true") {
                    const options = btn.nextElementSibling;
                    options.style.height = "0px";
                    options.style.margin = "0px";
                    btn.querySelector(".icon").classList.remove("fa-chevron-up");
                    btn.querySelector(".icon").classList.add("fa-chevron-down");
                    btn.setAttribute("aria-expanded", "false");
                }
            });
        }
    },

};

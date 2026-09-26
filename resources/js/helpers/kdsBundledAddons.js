/**
 * [T-KDS-MENU-DOUBLON 2026-08-19 · GOAL owner] Repli des lignes de formule déjà
 * décrites sous leur produit parent, pour l'écran cuisine et le ticket cuisine.
 *
 * LE PROBLÈME
 * -----------
 * Une formule ajoutée à un produit est enregistrée DEUX FOIS :
 *   · dans l'`instruction` du produit parent (« + Menu (Frites + Boisson)
 *     (+2,50 €) / ↳ Sauce frites: Mayonnaise / BOISSON: … ») ;
 *   · comme `order_item` distinct portant le PRIX de la formule.
 * La seconde ligne est indispensable en comptabilité, mais sur l'écran cuisine
 * elle réaffiche une information déjà présente sous le sandwich — c'est le
 * « c'est écrit deux fois la même chose » rapporté par le propriétaire.
 *
 * POURQUOI CE REPLI EST PUREMENT VISUEL
 * ------------------------------------
 * Aucune écriture, aucun impact sur le prix, la TVA, `composition_snapshot` ou la
 * chaîne fiscale : on filtre une liste au moment du rendu.
 *
 * POURQUOI ON SE FIE À L'INSTRUCTION
 * ----------------------------------
 * `order_items` n'a AUCUNE colonne de lien parent (schéma vérifié le 2026-08-19)
 * et `composition_snapshot.addons` du parent est vide. Le seul signal fiable
 * existant est le « + <nom de la formule> » que le wizard écrit lui-même dans
 * l'instruction du parent (public/js/pos-wizard.js, buildTicketInstruction).
 *
 * RÈGLE DE SÛRETÉ
 * ---------------
 * On ne replie QUE ce qu'un parent revendique, et seulement à hauteur de la
 * quantité revendiquée. Une formule commandée SEULE reste toujours affichée :
 * la masquer signifierait que la cuisine ne la prépare pas.
 *
 * Un jumeau PHP existe pour le ticket imprimé — les deux doivent rester alignés.
 */

/** Normalise un libellé pour comparaison : sans accent, sans casse, espaces réduits. */
function normalizeLabel(value) {
    return String(value || '')
        .normalize('NFD')
        .replace(/[̀-ͯ]/g, '')
        .replace(/\s+/g, ' ')
        .trim()
        .toLowerCase();
}

/**
 * Extrait les noms de formules revendiqués par l'instruction d'une ligne.
 *
 * Le wizard écrit « + Menu (Frites + Boisson) (+2,50 €) ». On retire la
 * parenthèse de PRIX finale (celle qui commence par « + ») sans toucher au nom,
 * qui peut lui-même contenir des parenthèses.
 *
 * @param {string|null|undefined} instruction
 * @returns {string[]} noms normalisés
 */
export function claimedAddonNames(instruction) {
    const raw = typeof instruction === 'string' ? instruction : '';

    // [RED-TEAM 2026-08-19] N'examiner QUE la partie composée par le wizard.
    // La note libre du caissier est toujours écrite EN DERNIER, entre crochets
    // (`pos-wizard.js` : `extraLines.push('[' + instructionText.trim() + ']')`),
    // et c'est un `<textarea>` : elle peut donc contenir des retours à la ligne
    // et des lignes commençant par « + ». Sans cette coupe, une note telle que
    //     + Frites
    //     Merci
    // sur un sandwich faisait DISPARAÎTRE de la cuisine la vraie ligne « Frites »
    // commandée à côté — facturée, jamais préparée. On tronque au premier crochet.
    const bracket = raw.indexOf('[');
    const text = bracket === -1 ? raw : raw.slice(0, bracket);

    const names = [];

    text.split('\n').forEach((rawLine) => {
        const line = rawLine.trim();
        if (!line.startsWith('+')) return;

        const withoutPlus = line.slice(1).trim();
        // Retire une parenthèse de prix FINALE : « (+2,50 €) ».
        const withoutPrice = withoutPlus.replace(/\s*\(\s*\+[^()]*\)\s*$/, '').trim();
        if (withoutPrice) names.push(normalizeLabel(withoutPrice));
    });

    return names;
}

/**
 * Retire (ou décrémente) les lignes de formule déjà décrites sous leur parent.
 *
 * @param {Array<object>|null|undefined} orderItems
 * @returns {Array<object>} nouvelle liste ; les objets modifiés sont des copies.
 */
/** Puce des options de formule écrite par le wizard (« ↳ Sauce frites: … »). */
const PUCE_OPTION = '↳';

/**
 * Consignes de CUISINE portées par une instruction — celles qu'un repli ne doit jamais
 * faire disparaître : les options de formule (lignes « ↳ »), la sauce des frites et la
 * boisson incluse. Le reste (nom du produit en tête, note libre entre crochets)
 * appartient à la ligne repliée et n'a pas à migrer.
 *
 * Jumeau strict : KitchenBundledAddonCollapser::kitchenDirectives().
 *
 * @param {string|null|undefined} instruction
 * @returns {string[]}
 */
/**
 * [AUDIT-SUPERVISEUR 2026-08-25 · E-009] Les extras d'une ligne, lus avec LA MÊME
 * priorité que le rendu (`kdsSymbolic.readExtras`) : l'instantané NF525 d'abord,
 * l'ancienne colonne ensuite. Lire dans un autre ordre ici produirait un legs qui
 * ne correspond pas à ce que l'écran affiche.
 */
function extrasDeLaLigne(item) {
    const snap = item && item.composition_snapshot;
    if (snap && Array.isArray(snap.extras) && snap.extras.length > 0) return snap.extras;
    return (item && Array.isArray(item.item_extras)) ? item.item_extras : [];
}

/** Clé de dédoublonnage d'un extra : son nom, quelle que soit la forme qui le porte. */
function extraKey(e) {
    const nom = String((e && (e.extra_name || e.name || e.item_name)) || '').trim().toLowerCase();
    return nom || JSON.stringify(e || {});
}

/**
 * Rend une COPIE du parent portant ses extras + ceux hérités d'une ligne repliée,
 * écrits dans la source que le rendu lira réellement. Jamais de mutation de l'objet
 * source : la ligne comptable reste intacte (aucun effet prix / TVA / fiscal).
 */
function avecExtrasHerites(parent, herites) {
    const snap = parent && parent.composition_snapshot;
    const snapPorte = !!(snap && Array.isArray(snap.extras) && snap.extras.length > 0);
    const actuels = snapPorte ? snap.extras : ((parent && Array.isArray(parent.item_extras)) ? parent.item_extras : []);

    const vus = new Set(actuels.map(extraKey));
    const ajouts = [];
    herites.forEach((e) => {
        const cle = extraKey(e);
        if (vus.has(cle)) return;
        vus.add(cle);
        ajouts.push(e);
    });
    if (ajouts.length === 0) return parent;

    const fusionnes = [...actuels, ...ajouts];

    return snapPorte
        ? { ...parent, composition_snapshot: { ...snap, extras: fusionnes } }
        : { ...parent, item_extras: fusionnes };
}

function kitchenDirectives(instruction) {
    const raw = typeof instruction === 'string' ? instruction : '';
    const bracket = raw.indexOf('[');
    const text = bracket === -1 ? raw : raw.slice(0, bracket);

    const out = [];
    text.split('\n').forEach((rawLine) => {
        const line = rawLine.trim();
        if (!line) return;
        if (line.startsWith(PUCE_OPTION)
            || /^boisson\s*:/i.test(line)
            || /sauce\s*frites\s*:/i.test(line)) {
            out.push(line);
        }
    });

    return out;
}

/** Clé d'unicité d'une consigne — deux « Sauce frites : … » ne coexistent jamais. */
function directiveKey(line) {
    return /sauce\s*frites\s*:/i.test(line) ? 'sauce-frites' : normalizeLabel(line);
}

/**
 * Insère les consignes héritées AVANT la note libre du caissier, qui doit rester la
 * DERNIÈRE ligne (les deux surfaces tronquent au premier crochet pour l'ignorer).
 */
function appendDirectives(instruction, ajouts) {
    const raw = typeof instruction === 'string' ? instruction : '';
    const bracket = raw.indexOf('[');
    const tete = (bracket === -1 ? raw : raw.slice(0, bracket)).replace(/[\r\n]+$/, '');
    const note = bracket === -1 ? '' : raw.slice(bracket);

    const bloc = ajouts.join('\n');
    const fusion = tete === '' ? bloc : `${tete}\n${bloc}`;

    return note === '' ? fusion : `${fusion}\n${note}`;
}

/**
 * Retire (ou décrémente) les lignes de formule déjà décrites sous leur parent, en LÉGUANT
 * au parent les consignes que la ligne repliée était seule à porter.
 *
 * @param {Array<object>|null|undefined} orderItems
 * @returns {Array<object>} nouvelle liste ; les objets modifiés sont des copies.
 */
export function collapseBundledAddonItems(orderItems) {
    const items = Array.isArray(orderItems) ? orderItems : [];
    if (items.length === 0) return [];

    // 1. Recenser ce que chaque ligne revendique, en quantité.
    //    Une ligne ne se revendique JAMAIS elle-même (garde anti-auto-suppression).
    //    On mémorise AUSSI l'index du parent revendiquant — une entrée par unité — parce
    //    qu'un repli n'est pas une suppression : la ligne repliée doit LÉGUER ses consignes.
    const quota = new Map();
    const claimers = new Map();
    items.forEach((item, index) => {
        const ownName = normalizeLabel(item && (item.item_name || item.name));
        const qty = Math.max(1, parseInt(item && item.quantity, 10) || 1);

        claimedAddonNames(item && item.instruction).forEach((claimed) => {
            if (claimed === ownName) return;
            quota.set(claimed, (quota.get(claimed) || 0) + qty);
            if (!claimers.has(claimed)) claimers.set(claimed, []);
            const slots = claimers.get(claimed);
            for (let k = 0; k < qty; k += 1) slots.push(index);
        });
    });

    if (quota.size === 0) return items.slice();

    // 2. Consommer le quota sur les lignes correspondantes.
    const out = new Map(); // index d'origine => objet à rendre (ordre conservé)
    const legs = new Map(); // index du parent => consignes héritées
    items.forEach((item, index) => {
        const name = normalizeLabel(item && (item.item_name || item.name));
        const remaining = quota.get(name) || 0;
        if (remaining <= 0) {
            out.set(index, item);
            return;
        }

        const qty = Math.max(1, parseInt(item && item.quantity, 10) || 1);
        const consumed = Math.min(remaining, qty);
        quota.set(name, remaining - consumed);

        // 3. LEGS — [OWNER 2026-08-19, 2ᵉ passe] Le repli initial DÉTRUISAIT ce que la ligne
        //    de formule était SEULE à porter : sa sauce frites, sa boisson. Résultat mesuré
        //    en base : plus aucun badge « MENU » sur l'écran cuisine, et un menu complet
        //    affiché « FRITES » — voire rien du tout. On ne jette plus, on transmet.
        // [AUDIT-SUPERVISEUR 2026-08-25 · E-009] Les EXTRAS de la ligne repliée étaient
        // perdus eux aussi. Le legs ne transmettait que les consignes d'instruction :
        // un Cheddar payé, porté par une ligne de formule repliée, disparaissait de
        // l'écran de cuisine — ce qui ANNULAIT sur le chemin de production le correctif
        // posé la veille dans `kdsSymbolic`. Vérifié vivant sur 21 commandes réelles.
        // Un supplément facturé ne peut pas être effacé par un repli d'AFFICHAGE : le
        // repli existe pour alléger la lecture, pas pour retirer de l'information payée.
        const consignes = kitchenDirectives(item && item.instruction);
        const extrasLegues = extrasDeLaLigne(item);
        const slots = claimers.get(name) || [];
        for (let k = 0; k < consumed; k += 1) {
            const parent = slots.shift();
            if (parent === undefined) continue;
            if (consignes.length === 0 && extrasLegues.length === 0) continue;
            if (!legs.has(parent)) legs.set(parent, { consignes: [], extras: [] });
            legs.get(parent).consignes.push(...consignes);
            legs.get(parent).extras.push(...extrasLegues);
        }

        const left = qty - consumed;
        if (left <= 0) return; // entièrement décrite par son parent → repliée
        out.set(index, { ...item, quantity: left });
    });

    // 4. Appliquer les legs sur des COPIES — jamais sur l'objet source.
    legs.forEach((legue, parentIndex) => {
        if (!out.has(parentIndex)) return;
        let parent = out.get(parentIndex);

        // 4a. Consignes d'instruction (comportement d'origine, inchangé).
        const instruction = (parent && typeof parent.instruction === 'string') ? parent.instruction : '';
        const deja = new Set(kitchenDirectives(instruction).map(directiveKey));
        const ajouts = [];
        legue.consignes.forEach((ligne) => {
            const cle = directiveKey(ligne);
            if (deja.has(cle)) return;
            deja.add(cle);
            ajouts.push(ligne);
        });
        if (ajouts.length > 0) {
            parent = { ...parent, instruction: appendDirectives(instruction, ajouts) };
        }

        // 4b. [E-009] Extras hérités. On les écrit dans la source que le rendu LIRA
        // réellement : `kdsSymbolic.readExtras()` préfère `composition_snapshot.extras`
        // quand il est non vide, et retombe sinon sur `item_extras`. Écrire dans la
        // mauvaise des deux reviendrait à croire le défaut corrigé sans qu'il le soit.
        if (legue.extras.length > 0) {
            parent = avecExtrasHerites(parent, legue.extras);
        }

        out.set(parentIndex, parent);
    });

    return Array.from(out.values());
}

/**
 * NATURE de la formule revendiquée par l'instruction du produit parent : « MENU »,
 * « FRITES », « BOISSON », ou '' si aucune formule n'est revendiquée.
 *
 * [OWNER 2026-08-19, 2ᵉ passe] La caisse ne scelle AUCUN addon dans le
 * `composition_snapshot` du produit parent (vérifié en base : `"addons": []` sur 100 %
 * des lignes). Le badge ne tenait donc que par la ligne de formule vendue à côté ; depuis
 * qu'elle est repliée (le doublon signalé par l'owner), le mot « MENU » avait disparu de
 * l'écran cuisine et un menu complet s'affichait « FRITES » — voire rien du tout. Le
 * parent revendique pourtant lui-même « + Menu (Frites + Boisson) » : on lit cette
 * revendication (le MÊME extracteur que le repli, jamais un second qui dériverait).
 *
 * Mêmes règles que le canal addon : frites + boisson = MENU, une moitié seule = partielle.
 * Jumeau strict : KitchenTicketSymbolicFormatter::claimedFormuleBadge().
 *
 * @param {string|null|undefined} instruction
 * @returns {'MENU'|'FRITES'|'BOISSON'|''}
 */
export function claimedFormuleBadge(instruction) {
    if (typeof instruction !== 'string' || instruction === '') return '';

    let hasFull = false, hasFrites = false, hasBoisson = false;
    for (const nom of claimedAddonNames(instruction)) {
        const frites = /frite/.test(nom);
        const boisson = /boisson|drink/.test(nom);
        const formule = /\bmenu\b|\bformule\b/.test(nom);

        if (frites) hasFrites = true;
        if (boisson) hasBoisson = true;
        if (formule && !frites && !boisson) hasFull = true; // « Menu complet » : la formule entière
    }

    if (hasFull || (hasFrites && hasBoisson)) return 'MENU';
    if (hasFrites) return 'FRITES';
    if (hasBoisson) return 'BOISSON';

    return '';
}

export default { collapseBundledAddonItems, claimedAddonNames, claimedFormuleBadge };

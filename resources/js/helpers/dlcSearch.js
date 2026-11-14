/**
 * Recherche de produit DLC « comme on tape » : sans accent ni majuscule, au pluriel comme au singulier, avec les fautes
 * de frappe (« ognion », « chompignion ») et les mots du quotidien (« champi », « patate », « mayo »).
 *
 * Tous les mots tapés doivent être retrouvés (« poulet cuit » = les deux). Chaque mot est cherché d'abord tel quel
 * (mot exact > début de mot > morceau de mot), puis, à défaut seulement, à une faute près (deux au-delà de 5 lettres).
 * Les résultats exacts passent avant les approchants ; `fuzzy` dit qu'il n'y en a que d'approchants, pour que l'écran
 * le signale (« résultats proches de … ») au lieu de laisser croire que la saisie était juste.
 */

/** Minuscules, sans accent, œ/æ dépliés, tout ce qui n'est pas lettre/chiffre devient espace. */
export function fold(value) {
    return String(value || "")
        .normalize("NFD").replace(/[̀-ͯ]/g, "")
        .toLowerCase()
        .replace(/œ/g, "oe").replace(/æ/g, "ae")
        .replace(/[^a-z0-9]+/g, " ")
        .trim();
}

/** « oignons » → « oignon » ; ce qui est court (≤ 3 lettres) ne bouge pas. Appliqué des deux côtés : toujours cohérent. */
export function stem(word) {
    return word.length > 3 && /[sx]$/.test(word) ? word.slice(0, -1) : word;
}

/** Mots du quotidien → mot du catalogue (plusieurs mots possibles : tous doivent être retrouvés). */
const SYNONYMS = {
    champi: "champignon",
    patate: "pomme terre",
    pdt: "pomme terre",
    mayo: "mayonnaise",
    ketch: "ketchup",
    steack: "steak",
    stek: "steak",
};

function tokensOf(value) {
    return fold(value).split(" ").filter(Boolean).map(stem);
}

/** Distance d'édition (Damerau, transposition d'une paire comprise) — petites chaînes, coût négligeable. */
export function editDistance(a, b) {
    if (a === b) return 0;
    const la = a.length;
    const lb = b.length;
    if (!la) return lb;
    if (!lb) return la;
    const d = Array.from({ length: la + 1 }, (_, i) => [i, ...new Array(lb).fill(0)]);
    for (let j = 1; j <= lb; j++) d[0][j] = j;
    for (let i = 1; i <= la; i++) {
        for (let j = 1; j <= lb; j++) {
            const cost = a[i - 1] === b[j - 1] ? 0 : 1;
            d[i][j] = Math.min(d[i - 1][j] + 1, d[i][j - 1] + 1, d[i - 1][j - 1] + cost);
            if (i > 1 && j > 1 && a[i - 1] === b[j - 2] && a[i - 2] === b[j - 1]) d[i][j] = Math.min(d[i][j], d[i - 2][j - 2] + 1);
        }
    }
    return d[la][lb];
}

/** Fautes admises pour un mot tapé : aucune sous 4 lettres, une jusqu'à 5, deux ensuite. */
function allowedTypos(len) {
    if (len < 4) return 0;
    return len <= 5 ? 1 : 2;
}

/** Score d'un mot tapé contre un mot du produit : 100 exact, 80 début de mot, 60 morceau, 0 sinon. */
function exactScore(t, w) {
    if (w === t) return 100;
    if (t.length >= 2 && w.startsWith(t)) return 80;
    if (t.length >= 3 && w.includes(t)) return 60;
    return 0;
}

/** Approchant : le mot entier, ou son début (on tape encore). Deux fautes seulement si la 1re lettre est juste. */
function fuzzyScore(t, w) {
    const max = allowedTypos(t.length);
    if (!max) return 0;
    const d = Math.min(editDistance(t, w), editDistance(t, w.slice(0, t.length)));
    if (d > max) return 0;
    if (d === 2 && t[0] !== w[0]) return 0;
    return 40 - d * 5;
}

/**
 * @returns {{score: number, fuzzy: boolean}|null}  null = ne correspond pas.
 */
export function matchProduct(query, product) {
    const typed = fold(query).split(" ").filter(Boolean).flatMap((t) => (SYNONYMS[stem(t)] || t).split(" ")).map(stem);
    if (!typed.length) return { score: 0, fuzzy: false };
    const nameWords = tokensOf(product.name);
    const catWords = tokensOf(product.category);
    let score = 0;
    let fuzzy = false;
    for (const t of typed) {
        let best = 0;
        let bestFuzzy = false;
        for (const w of nameWords) {
            const e = exactScore(t, w);
            if (e > best) { best = e; bestFuzzy = false; }
        }
        for (const w of catWords) {
            const e = Math.round(exactScore(t, w) / 2);
            if (e > best) { best = e; bestFuzzy = false; }
        }
        if (!best) {
            for (const w of nameWords) {
                const f = fuzzyScore(t, w);
                if (f > best) { best = f; bestFuzzy = true; }
            }
        }
        if (!best) return null;
        score += best;
        fuzzy = fuzzy || bestFuzzy;
    }
    return { score, fuzzy };
}

/**
 * Filtre et classe : les meilleures correspondances d'abord (à égalité l'ordre reçu est conservé — l'appelant trie
 * déjà par usage). Sans saisie, la liste revient telle quelle.
 *
 * @returns {{items: Array, fuzzy: boolean}} `fuzzy` = vrai quand AUCUN résultat n'est exact (tous approchants).
 */
export function searchProducts(products, query) {
    if (!fold(query)) return { items: products, fuzzy: false };
    const scored = [];
    products.forEach((p, i) => {
        const m = matchProduct(query, p);
        if (m) scored.push({ p, i, ...m });
    });
    scored.sort((a, b) => Number(a.fuzzy) - Number(b.fuzzy) || b.score - a.score || a.i - b.i);
    return { items: scored.map((s) => s.p), fuzzy: scored.length > 0 && scored.every((s) => s.fuzzy) };
}

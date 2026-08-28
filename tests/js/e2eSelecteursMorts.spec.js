import fs from 'fs';
import path from 'path';
import { describe, expect, it } from 'vitest';

/**
 * [GOAL CONSOLIDATION_V1_PRODUCTION_20260825 — généralisation des découvertes W8 et W10]
 *
 * LA CLASSE DE DÉFAUT
 * -------------------
 * Deux causes systémiques de pourrissement E2E ont été trouvées le 2026-08-25, avec le même
 * schéma : le produit bouge légitimement, le harnais reste figé, et le test **accuse le produit**.
 *   - une route devient idempotente → 12 specs prenaient un 422 ;
 *   - une interface est refondue (KDS V2) → 14 specs visent un balisage jamais rendu.
 *
 * Cette sentinelle généralise : **un sélecteur qu'aucun fichier du produit ne pose est un test
 * mort**. Il ne trouvera jamais rien, et son échec ressemblera à un défaut fonctionnel.
 *
 * LA MESURE — et les trois corrections qu'elle a demandées
 * --------------------------------------------------------
 * Le chiffre brut était **132**. Il est descendu à **23** après deux corrections de MA méthode :
 *   - **132 → 55** : les gabarits. Le produit écrit `` :data-testid=`kds-cols-${n}` `` ; une
 *     recherche de chaînes exactes déclarait `kds-cols-4` mort alors qu'il est bien posé.
 *     On retient donc aussi les PRÉFIXES des gabarits.
 *   - **55 → 23** : le codebase mobile. Les specs `*mobile*` visent l'application `mobile/`,
 *     séparée par mandat propriétaire (CLAUDE.md §3bis) — vérifié : `loyalty-balance`,
 *     `redeem-wizard`, `qr-mode-toggle` existent bien dans `mobile/`. Les compter ici serait
 *     accuser à tort.
 *
 * Un compteur gonflé perd sa crédibilité, et un compteur auquel on ne croit plus finit désactivé.
 * Mieux vaut 23 vérifiables que 132 impressionnants.
 */

/** Plafond relevé le 2026-08-25. IL NE DOIT QUE DESCENDRE. */
const PLAFOND_MORTS = 23;

function lister(repertoire, extensions) {
    const sortie = [];
    for (const entree of fs.readdirSync(repertoire, { withFileTypes: true })) {
        if (['node_modules', '__screenshots__', '.git', 'vendor'].includes(entree.name)) continue;
        const complet = path.join(repertoire, entree.name);
        if (entree.isDirectory()) sortie.push(...lister(complet, extensions));
        else if (extensions.some((x) => entree.name.endsWith(x))) sortie.push(complet);
    }
    return sortie;
}

/** Sélecteurs posés par le produit : littéraux + préfixes de gabarits. */
function selecteursDuProduit() {
    const exacts = new Set();
    const prefixes = new Set();
    const fichiers = [
        ...lister(path.resolve(process.cwd(), 'resources'), ['.vue', '.js', '.blade.php']),
        ...lister(path.resolve(process.cwd(), 'public/js'), ['.js']),
    ];
    for (const f of fichiers) {
        const s = fs.readFileSync(f, 'utf8');
        for (const m of s.matchAll(/data-testid=["']([a-z0-9_-]{3,})["']/gi)) exacts.add(m[1]);
        for (const m of s.matchAll(/`([a-z0-9_-]+-)\$\{/gi)) prefixes.add(m[1]);
        for (const m of s.matchAll(/["']([a-z0-9_-]+-)["']\s*\+/gi)) prefixes.add(m[1]);
    }
    return { exacts, prefixes };
}

/** Sélecteurs cherchés par les specs — hors specs mobile (codebase séparé). */
function selecteursCherches() {
    const carte = new Map();
    for (const f of lister(path.resolve(process.cwd(), 'tests/e2e'), ['.spec.js'])) {
        const base = path.basename(f);
        if (/mobile/i.test(base)) continue;
        const s = fs.readFileSync(f, 'utf8');
        const ajoute = (k) => {
            if (!carte.has(k)) carte.set(k, new Set());
            carte.get(k).add(base);
        };
        for (const m of s.matchAll(/data-testid=["']([a-z0-9_-]{3,})["']/gi)) ajoute(m[1]);
        for (const m of s.matchAll(/getByTestId\(\s*["'`]([a-z0-9_-]{3,})["'`]/gi)) ajoute(m[1]);
    }
    return carte;
}

const { exacts, prefixes } = selecteursDuProduit();
const cherches = selecteursCherches();
const estVivant = (k) => exacts.has(k) || [...prefixes].some((p) => k.startsWith(p) && k.length > p.length);
const morts = [...cherches.keys()].filter((k) => !estVivant(k));

describe('Sélecteurs E2E — visent-ils quelque chose qui existe ?', () => {
    it('le balayage du produit trouve bien des sélecteurs', () => {
        // Un balayage vide déclarerait TOUT mort — le pire faux positif possible.
        expect(exacts.size).toBeGreaterThan(300);
    });

    it('le balayage des specs trouve bien des sélecteurs', () => {
        expect(cherches.size).toBeGreaterThan(200);
    });

    it('ne laisse pas grandir le nombre de sélecteurs morts', () => {
        const detail = morts
            .map((k) => `  ${k}  ← ${[...cherches.get(k)].slice(0, 2).join(', ')}`)
            .join('\n');
        expect(
            morts.length,
            `${morts.length} sélecteurs sont cherchés par des specs mais posés par AUCUN fichier ` +
                `du produit (plafond ${PLAFOND_MORTS}). Ces tests ne trouveront jamais rien, et ` +
                `leur échec ressemblera à un défaut fonctionnel :\n${detail}\n\n` +
                `Deux issues : reposer le sélecteur dans le produit, ou corriger la spec.`,
        ).toBeLessThanOrEqual(PLAFOND_MORTS);
    });

    it('tient compte des gabarits, sinon il accuserait à tort', () => {
        // Régression nommée : `kds-cols-4` est posé par `` `kds-cols-${n}` ``.
        expect(estVivant('kds-cols-4')).toBe(true);
        expect(prefixes.size).toBeGreaterThan(50);
    });

    /**
     * [W11ter] Les ROUTES — mesurées correctement, après deux échecs de méthode.
     *
     * Deux tentatives ratées avant celle-ci, gardées ici parce qu'elles expliquent la forme du code :
     *   - **« 0 chemin mort »** — faux : le routeur déclare un attrape-tout `path: "/:pathMatch(.*)*"`
     *     (`router/index.js:158`) qui appariait TOUTE URL. Un test incapable d'échouer ne mesure rien.
     *   - **« 45 chemins morts »** — faux aussi : les routes sont des ENFANTS à chemin relatif
     *     (`path: "/admin/settings"` puis `children: [{ path: "taxes" }]`). Une extraction à plat
     *     collectait `"taxes"` isolément et déclarait `/admin/settings/taxes` inexistante.
     *
     * La version ci-dessous **recompose l'arbre** (appariement d'accolades + jointure parent/enfant),
     * ajoute les routes **Blade** de `routes/web.php` — c'est là que vivent `/admin/roue*` — et
     * ignore les fichiers statiques. Elle donne **1**, contre 0 et 45 pour les versions fausses.
     *
     * Ce qu'elle a trouvé : `/admin/stock-rupture-dashboard`, morte et **documentée comme telle
     * dans CLAUDE.md:346** (« l'ancien … 404 → route SPA réelle `admin.stock.rupture` »), encore
     * visitée par **3 specs** — corrigées. Reste `/admin/delivery-boys/create`, dont le routeur ne
     * déclare que `""`, `show/:id` et `show/:id/:orderId` : le bon chemin ne se devine pas.
     */
    it('aucune spec ne visite un chemin sans route déclarée', () => {
        const finBloc = (s, i) => {
            let p = 0;
            for (let k = i; k < s.length; k++) {
                const c = s[k];
                if (c === '{' || c === '[' || c === '(') p++;
                else if (c === '}' || c === ']' || c === ')') { p--; if (p === 0) return k; }
                else if (c === '"' || c === "'" || c === '`') {
                    const q = c; k++;
                    while (k < s.length && s[k] !== q) { if (s[k] === '\\') k++; k++; }
                }
            }
            return -1;
        };
        const joindre = (p, e) => (!e ? (p || '/') : e.startsWith('/') ? e : !p ? '/' + e : p.replace(/\/+$/, '') + '/' + e);

        const chemins = (src, prefixe, out) => {
            const re = /path:\s*["'`]([^"'`]*)["'`]/g;
            let m;
            while ((m = re.exec(src)) !== null) {
                const complet = joindre(prefixe, m[1]);
                const reste = src.slice(m.index);
                const posEnf = reste.search(/children:\s*\[/);
                const proch = reste.slice(1).search(/path:\s*["'`]/);
                if (posEnf > -1 && (proch === -1 || posEnf < proch)) {
                    const deb = m.index + posEnf + reste.slice(posEnf).indexOf('[');
                    const f = finBloc(src, deb);
                    if (f > -1) { chemins(src.slice(deb + 1, f), complet, out); re.lastIndex = f; out.add(complet); continue; }
                }
                out.add(complet);
            }
            return out;
        };

        const spa = new Set();
        for (const f of [
            ...lister(path.resolve(process.cwd(), 'resources/js/router'), ['.js']),
            path.resolve(process.cwd(), 'resources/js/pos-app.js'),
        ]) {
            if (fs.existsSync(f)) chemins(fs.readFileSync(f, 'utf8'), '', spa);
        }
        const declarees = [...spa].filter((r) => !/\(\.\*\)|pathMatch|^\*$/.test(r));
        expect(declarees.length, 'aucune route SPA résolue : le balayage est cassé').toBeGreaterThan(100);

        const web = fs.readFileSync(path.resolve(process.cwd(), 'routes/web.php'), 'utf8');
        for (const m of web.matchAll(/Route::(get|post|match|any)\(\s*\[?[^,]*?['"]([^'"]+)['"]/g)) {
            declarees.push(m[2].startsWith('/') ? m[2] : '/' + m[2]);
        }

        const motifs = declarees.map((r) => ({
            brut: r,
            re: new RegExp('^' + r.replace(/\{[^}]+\}/g, '[^/]+').replace(/:[A-Za-z0-9_]+/g, '[^/]+').replace(/\//g, '\\/') + '$'),
        }));
        const STATIQUES = /\.(html|js|css|png|jpg|svg|ico|json)$/;

        const visites = new Map();
        for (const f of lister(path.resolve(process.cwd(), 'tests/e2e'), ['.spec.js'])) {
            const base = path.basename(f);
            for (const m of fs.readFileSync(f, 'utf8').matchAll(/goto\(\s*[`'"]([^`'"$]+)[`'"]/g)) {
                let u = m[1];
                if (/^https?:/.test(u)) { try { u = new URL(u).pathname; } catch (_) { continue; } }
                if (!u.startsWith('/')) continue;
                u = u.split('?')[0].replace(/\/+$/, '') || '/';
                if (STATIQUES.test(u)) continue;
                if (!visites.has(u)) visites.set(u, new Set());
                visites.get(u).add(base);
            }
        }

        const inconnues = [...visites.keys()].filter((u) => !motifs.some((m) => m.re.test(u) || m.brut === u));
        expect(
            inconnues.length,
            `${inconnues.length} chemins visités sans route déclarée (plafond 1) :\n  ` +
                inconnues.map((u) => `${u}  ← ${[...visites.get(u)].slice(0, 2).join(', ')}`).join('\n  '),
        ).toBeLessThanOrEqual(1);
    });

    it('exclut le codebase mobile, séparé par mandat propriétaire', () => {
        // Les specs mobile visent `mobile/`, hors périmètre V1 (CLAUDE.md §3bis).
        const specsMobile = lister(path.resolve(process.cwd(), 'tests/e2e'), ['.spec.js'])
            .filter((f) => /mobile/i.test(path.basename(f)));
        expect(specsMobile.length).toBeGreaterThan(0);
        for (const s of specsMobile) expect([...cherches.values()].every((v) => !v.has(path.basename(s)))).toBe(true);
    });
});

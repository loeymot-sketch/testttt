import { describe, it, expect } from 'vitest';
import fs from 'node:fs';
import path from 'node:path';

/**
 * LES DEUX BOUTONS DU POPUP « TOUJOURS LÀ ? » DOIVENT PORTER LEUR TEXTE.
 *
 * Constat du propriétaire sur sa borne, en salle : « il y a deux boutons, une blanche
 * une orange, il y a rien qui est écrit dessus ».
 *
 * Il avait raison, et la cause n'était PAS une couleur — c'est la première hypothèse
 * qu'on se donne devant un bouton illisible, et elle était fausse :
 *
 *   `KsButton` affiche son texte par son SLOT PAR DÉFAUT. Il n'a pas de prop `label`
 *   (ses props sont variant, size, disabled, loading, fullWidth, icon, type). Le
 *   `:label="…"` que ce composant lui passait retombait donc en simple attribut HTML
 *   sur la balise racine — invisible — et le slot rendait LE VIDE.
 *
 * Le repli `|| 'Je suis là'` ne pouvait pas rattraper : `$t()` renvoie la CLÉ quand la
 * traduction manque, jamais une valeur fausse. Il n'a d'ailleurs jamais servi, les deux
 * clés existent et sont justes.
 *
 * Ce que le client voyait : deux rectangles muets, sur un écran qui lui demande s'il est
 * toujours là, pendant que sa commande s'efface en compte à rebours.
 *
 * Ces tests lisent la SOURCE : ils tiennent quel que soit le harnais de rendu.
 */

const RACINE = path.resolve(__dirname, '../..');
const OVERLAY = path.join(RACINE, 'resources/js/components/frontend/kiosk/KioskInactivityOverlayComponent.vue');
const BOUTON = path.join(RACINE, 'resources/js/components/frontend/kiosk/ds/KsButton.vue');
const FR = path.join(RACINE, 'resources/js/languages/fr.json');

const lire = (f) => fs.readFileSync(f, 'utf8');

/** Le bloc d'un `<KsButton>` identifié par son data-testid. */
function bouton(src, testid) {
    const i = src.indexOf(testid);
    if (i === -1) return null;
    const debut = src.lastIndexOf('<KsButton', i);
    if (debut === -1) return null;
    const fin = src.indexOf('</KsButton>', i);
    // Forme auto-fermante : on s'arrête au « /> ».
    const auto = src.indexOf('/>', i);
    if (fin === -1 || (auto !== -1 && auto < fin)) return src.slice(debut, auto + 2);
    return src.slice(debut, fin + 11);
}

describe('borne — popup d\'inactivité', () => {
    it('LE CONSTAT DE DÉPART : KsButton n\'a PAS de prop `label`', () => {
        const src = lire(BOUTON);
        const props = src.match(/props:\s*\{([\s\S]*?)\n    \},/);
        expect(props, 'bloc props de KsButton introuvable').not.toBeNull();

        expect(
            /\blabel\s*:/.test(props[1]),
            'KsButton a désormais une prop `label`. Si c\'est voulu, ce test doit être '
            + 'réécrit — mais tant qu\'elle n\'existe pas, passer `:label` à ce composant '
            + 'produit un bouton VIDE, silencieusement.'
        ).toBe(false);

        // Et il rend bien son texte par le slot par défaut.
        expect(src).toMatch(/<span class="ks-btn__label"><slot\s*\/><\/span>/);
    });

    it.each([
        ['kiosk-inactivity-stay', 'kiosk.inactivity.stay'],
        ['kiosk-inactivity-leave', 'kiosk.inactivity.leave'],
    ])('le bouton « %s » passe son texte par le SLOT, pas par `:label`', (testid, cle) => {
        const b = bouton(lire(OVERLAY), testid);
        expect(b, `bouton ${testid} introuvable`).not.toBeNull();

        expect(
            /:label=/.test(b),
            `RÉGRESSION : le bouton « ${testid} » repasse son texte par \`:label\`. `
            + 'KsButton n\'a pas cette prop — le bouton redevient VIDE, sans que rien ne '
            + 'le signale : ni erreur, ni avertissement, ni test rouge.'
        ).toBe(false);

        expect(
            b.includes(`{{ $t('${cle}') }}`),
            `le bouton doit rendre « ${cle} » DANS son slot`
        ).toBe(true);
    });

    it('les deux libellés existent en français — le repli n\'a jamais eu à servir', () => {
        const l = JSON.parse(lire(FR)).kiosk.inactivity;

        expect(l.stay, 'libellé « je suis là » absent').toBeTruthy();
        expect(l.leave, 'libellé « abandonner » absent').toBeTruthy();

        // Et ils ne doivent pas être des clés brutes échappées dans la traduction.
        expect(l.stay).not.toMatch(/^kiosk\./);
        expect(l.leave).not.toMatch(/^kiosk\./);
    });

    it('AUCUN autre KsButton de la borne ne repasse par `:label`', () => {
        // Le défaut est silencieux : il ne lève rien. Le seul garde possible est de
        // refuser le motif partout où KsButton est utilisé.
        const dossier = path.join(RACINE, 'resources/js/components/frontend/kiosk');
        const fautifs = [];

        const parcourir = (rep) => {
            for (const e of fs.readdirSync(rep, { withFileTypes: true })) {
                const p = path.join(rep, e.name);
                if (e.isDirectory()) { parcourir(p); continue; }
                if (!e.name.endsWith('.vue')) continue;
                const src = fs.readFileSync(p, 'utf8');
                // On ne regarde QUE les balises KsButton — d'autres composants du même
                // dossier (KsPriceLine) ont légitimement une prop `label`.
                const re = /<KsButton[\s\S]*?(?:\/>|<\/KsButton>)/g;
                let m;
                while ((m = re.exec(src)) !== null) {
                    if (/:?label=/.test(m[0])) fautifs.push(`${e.name} → ${m[0].slice(0, 70)}…`);
                }
            }
        };
        parcourir(dossier);

        expect(
            fautifs.join('\n  '),
            'Un KsButton reçoit une prop `label` qui n\'existe pas : il rendra un bouton vide.'
        ).toBe('');
    });
});

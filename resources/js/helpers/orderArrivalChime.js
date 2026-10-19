/**
 * [OWNER 2026-08-19] SÉQUENCEUR DE SONNERIE — « 3 sonneries espacées, puis stop ».
 *
 * POURQUOI CE FICHIER EXISTE
 * --------------------------
 * Trois surfaces doivent prévenir qu'une commande arrive : la CAISSE, l'ÉCRAN CUISINE et
 * l'ÉCRAN DE STATUT. Elles avaient chacune leur propre bout de code sonore, et elles avaient
 * déjà divergé :
 *   · caisse  : un bip de synthèse de 0,4 s — répété 3 fois pour le SEUL canal « web » ;
 *   · cuisine : un carillon MP3 joué UNE fois, avec anti-rafale ;
 *   · statut  : un accord de 3 tons, sur « commande prête », joué UNE fois.
 * Le motif de défaut dominant de ce dépôt est « un correctif appliqué à une moitié du
 * mécanisme, pas à sa jumelle ». Le RYTHME de la sonnerie vit donc ici, une seule fois, et
 * chaque surface n'apporte que SA façon d'émettre un son.
 *
 * CE QUE CE MODULE NE FAIT PAS
 * ----------------------------
 * Il ne connaît ni l'audio, ni le DOM, ni Vue. Il ordonnance des appels. C'est ce qui le rend
 * testable sans navigateur, et c'est ce qui permet à la caisse de jouer un MP3 là où l'écran
 * de statut synthétise un accord, sans que le rythme puisse diverger entre les deux.
 *
 * POURQUOI UNE NOUVELLE ARRIVÉE REMPLACE LA SÉQUENCE EN COURS
 * ----------------------------------------------------------
 * L'implémentation caisse empilait ses minuteries sans borne : cinq commandes en une minute
 * donnaient quinze bips entrelacés, c'est-à-dire un bruit continu qu'on finit par ignorer —
 * exactement l'inverse du but. Une arrivée ANNULE donc la séquence en attente et repart de
 * zéro. Le personnel entend toujours 3 sonneries après la DERNIÈRE arrivée, jamais davantage,
 * et aucune commande ne passe inaperçue.
 */

/** Nombre de sonneries par arrivée (owner 2026-08-19 : « 3 sonneries espacées, puis stop »). */
export const SONNERIES_PAR_ARRIVEE = 3;

/**
 * Écart entre deux sonneries. 10 s est la valeur déjà éprouvée en caisse sur les commandes du
 * site : assez long pour laisser le temps de lever la tête, assez court pour qu'on relie la
 * deuxième sonnerie à la première.
 */
export const INTERVALLE_ENTRE_SONNERIES_MS = 10000;

/**
 * Crée un séquenceur. Chaque surface en garde UN, et l'annule à sa destruction — sinon une
 * minuterie survit au composant et tente de jouer un son sur un élément démonté.
 *
 * @param {object}   [options]
 * @param {number}   [options.sonneries]   nombre total de sonneries (≥ 1)
 * @param {number}   [options.intervalleMs] écart entre deux sonneries
 * @param {Function} [options.setTimeoutFn]   injectable pour les bancs d'essai
 * @param {Function} [options.clearTimeoutFn] injectable pour les bancs d'essai
 * @returns {{declencher: Function, annuler: Function, enAttente: Function}}
 */
export function creerSequenceurDeSonnerie(options = {}) {
    const sonneries = Math.max(1, Number(options.sonneries) || SONNERIES_PAR_ARRIVEE);
    const intervalleMs = Math.max(
        0,
        Number.isFinite(Number(options.intervalleMs))
            ? Number(options.intervalleMs)
            : INTERVALLE_ENTRE_SONNERIES_MS
    );
    const poser = options.setTimeoutFn || ((fn, ms) => setTimeout(fn, ms));
    const retirer = options.clearTimeoutFn || ((id) => clearTimeout(id));

    /** Minuteries encore en attente — vidée par `annuler()` et par toute nouvelle arrivée. */
    let enCours = [];

    const purger = () => {
        enCours.forEach((id) => {
            try {
                retirer(id);
            } catch (e) {
                /* défensif : un ordonnanceur de test peut refuser un identifiant inconnu */
            }
        });
        enCours = [];
    };

    /**
     * Une sonnerie ne doit JAMAIS pouvoir interrompre la séquence : sur une tablette dont
     * l'autoplay est encore bloqué, `jouer()` lève, et sans cette garde les sonneries
     * suivantes — celles qui auraient sonné APRÈS le premier geste de l'utilisateur, donc
     * celles qui pouvaient encore sauver la commande — ne partaient jamais.
     */
    const jouerSansPropager = (jouer) => {
        try {
            jouer();
        } catch (e) {
            /* défensif */
        }
    };

    return {
        /**
         * Une commande vient d'arriver : sonne tout de suite, puis (sonneries − 1) fois.
         * Remplace toute séquence encore en attente.
         *
         * @param {Function} jouer émet UN son ; propre à chaque surface
         */
        declencher(jouer) {
            if (typeof jouer !== 'function') {
                return;
            }
            purger();
            jouerSansPropager(jouer);

            for (let n = 1; n < sonneries; n += 1) {
                const id = poser(() => {
                    // La minuterie a rendu la main : elle ne fait plus partie des « en attente ».
                    enCours = enCours.filter((autre) => autre !== id);
                    jouerSansPropager(jouer);
                }, intervalleMs * n);
                enCours.push(id);
            }
        },

        /** À appeler à la destruction du composant : plus aucune sonnerie ne partira. */
        annuler() {
            purger();
        },

        /** Nombre de sonneries encore à venir — lisible par les bancs d'essai. */
        enAttente() {
            return enCours.length;
        },
    };
}

/**
 * [RAPPEL-COMMANDES 2026-10-19 · owner] « Parfois on les oublie. »
 *
 * Les 3 sonneries d'arrivée s'arrêtent au bout de 20 secondes : une commande web laissée
 * « à traiter » devient silencieuse, et se perd derrière le comptoir. Ce rappel sonne UNE fois
 * toutes les `INTERVALLE_RAPPEL_MS` TANT QU'IL RESTE au moins une commande à traiter, puis se
 * tait de lui-même dès que le compteur retombe à zéro.
 *
 * Même doctrine que le séquenceur : aucune connaissance de l'audio, du DOM ni de Vue — il
 * ordonnance des appels (minuteries injectables → testable sans navigateur).
 */
export const INTERVALLE_RAPPEL_MS = 120000;

/**
 * @param {object}   [options]
 * @param {number}   [options.intervalleMs]
 * @param {Function} [options.setIntervalFn]   injectable pour les bancs d'essai
 * @param {Function} [options.clearIntervalFn] injectable pour les bancs d'essai
 * @returns {{actualiser: Function, arreter: Function, actif: Function}}
 */
export function creerRappelPeriodique(options = {}) {
    const intervalleMs = Math.max(
        1000,
        Number.isFinite(Number(options.intervalleMs)) ? Number(options.intervalleMs) : INTERVALLE_RAPPEL_MS
    );
    const poser = options.setIntervalFn || ((fn, ms) => setInterval(fn, ms));
    const retirer = options.clearIntervalFn || ((id) => clearInterval(id));

    let minuterie = null;
    let compte = 0;
    let rappeler = null;

    const arreter = () => {
        if (minuterie !== null) {
            try {
                retirer(minuterie);
            } catch (e) {
                /* défensif */
            }
        }
        minuterie = null;
    };

    return {
        /**
         * @param {number}   n       nombre de commandes encore à traiter
         * @param {Function} jouer   émet UN son de rappel, reçoit le nombre en attente
         */
        actualiser(n, jouer) {
            compte = Math.max(0, Number(n) || 0);
            if (typeof jouer === 'function') {
                rappeler = jouer;
            }
            if (compte === 0 || typeof rappeler !== 'function') {
                arreter();
                return;
            }
            if (minuterie !== null) {
                return; // déjà armé : le prochain rappel lira le compteur à jour
            }
            minuterie = poser(() => {
                if (compte === 0) {
                    arreter();
                    return;
                }
                try {
                    rappeler(compte);
                } catch (e) {
                    /* un son bloqué ne doit pas tuer les rappels suivants */
                }
            }, intervalleMs);
        },
        /** À appeler à la destruction du composant. */
        arreter,
        /** Lisible par les bancs d'essai. */
        actif() {
            return minuterie !== null;
        },
    };
}

export default {
    creerSequenceurDeSonnerie, SONNERIES_PAR_ARRIVEE, INTERVALLE_ENTRE_SONNERIES_MS,
    creerRappelPeriodique, INTERVALLE_RAPPEL_MS,
};

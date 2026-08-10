<template>
    <!-- Composant sans rendu : il ne fait qu'écouter et imprimer. -->
    <span style="display:none" aria-hidden="true"></span>
</template>

<script>
/**
 * [WEB-PAYEE-MUETTE 2026-08-10 · P0 owner] L'imprimeur des tickets CUISINE.
 *
 * POURQUOI CE COMPOSANT EXISTE
 * ----------------------------
 * Le serveur applicatif est chez l'hébergeur et NE PEUT PAS joindre l'imprimante, branchée au
 * PC de la caisse sur son réseau local. Le chemin serveur→imprimante
 * (KitchenTicketAutoPrinter::printOnce) sort donc en « pas d'imprimante » et n'a JAMAIS rien
 * sorti en production : table `printers` vide, zéro ligne de journal depuis l'origine.
 *
 * Le 2026-08-10 une commande site payée 31,40 € n'a produit aucun papier et aucun signal en
 * caisse ; elle n'existait que sur l'écran cuisine. Ce composant est la moitié manquante : il
 * tourne sur le poste, réclame les tickets à sortir, et imprime.
 *
 * C'est le jumeau EXACT de PromoFlyerPrintListener — même cadence, mêmes précautions, même
 * modèle réclamation/accusé. Si tu corriges un défaut ici, va voir là-bas : c'est la même
 * mécanique, et la leçon la plus chère de ce projet est qu'un correctif appliqué à une seule
 * moitié d'une paire laisse l'autre cassée.
 *
 * CE QU'IL NE FAUT PAS CASSER
 * ---------------------------
 *  - Il tourne sur TOUS les écrans admin, y compris pendant un encaissement : il doit rester
 *    silencieux et bon marché, sans jamais bloquer l'interface ni afficher d'erreur.
 *  - Il ne démarre PAS si le pont d'impression local est absent. Sur un téléphone ou un poste
 *    bureau, réclamer des tickets les consommerait loin de l'imprimante — donc les perdrait.
 *  - La réclamation est atomique côté serveur : deux onglets ouverts ne peuvent pas sortir le
 *    même ticket deux fois.
 *  - On accuse TOUJOURS réception, succès comme échec. Un ticket réclamé sans accusé reste
 *    marqué « imprimé » alors qu'aucun papier n'est sorti : la cuisine ne l'a pas, et plus
 *    rien ne le lui donnera.
 */
import axios from "axios";
import { isCaisseBridgeAvailable, printEscPosViaCaisseBridge } from "../../../helpers/posLocalPrinter";

// 5 s : même cadence que le ticket promo et que le sondage caisse. Une commande qui tombe
// sort donc en cuisine en moins de 5 secondes, sans dépendre d'aucun temps réel.
const POLL_MS = 5000;

// On laisse l'écran finir de se charger avant de sonder — au démarrage il a mieux à faire.
const START_DELAY_MS = 4000;

// Le pont peut démarrer APRÈS l'écran (PC caisse qui boote) ou être redémarré en cours de
// service. On re-teste donc tant qu'il est absent, au lieu de conclure une fois pour toutes.
const BRIDGE_RECHECK_MS = 60000;

export default {
    name: "KitchenTicketPrintListener",
    data() {
        return {
            _timer: null,
            _running: false,
            _bridgeCheckedAt: 0,
            _bridgeAvailable: false,
        };
    },
    mounted() {
        this._startTimer = window.setTimeout(() => this._start(), START_DELAY_MS);
    },
    beforeUnmount() {
        if (this._startTimer) window.clearTimeout(this._startTimer);
        if (this._timer) window.clearInterval(this._timer);
        this._timer = null;
    },
    methods: {
        _start() {
            if (this._timer) return;
            this._timer = window.setInterval(() => this._tick(), POLL_MS);
            this._tick();
        },

        async _tick() {
            // Onglet caché = personne ne regarde, et le PC caisse en garde souvent plusieurs
            // ouverts. Inutile de réclamer depuis chacun : l'onglet visible fait le travail.
            if (typeof document !== 'undefined' && document.hidden) return;

            // Un cycle à la fois : si l'impression traîne (papier, pont lent), on n'empile pas
            // les réclamations — sinon un pont lent produirait une avalanche de tickets pris
            // mais jamais sortis.
            if (this._running) return;
            this._running = true;

            try {
                if (!(await this._hasBridge())) return;

                const { data } = await axios.post("admin/pos/kitchen-tickets/pending");
                const orders = (data && data.orders) || [];

                for (const order of orders) {
                    await this._printOne(order);
                }
            } catch (_) {
                // Silence volontaire : cet écran peut être ouvert par un compte sans droit
                // caisse, ou hors ligne. Un bandeau d'erreur toutes les 5 secondes pendant un
                // encaissement serait bien pire que l'absence de ticket.
            } finally {
                this._running = false;
            }
        },

        /**
         * Le pont local n'est présent que sur le PC caisse. Tant qu'il est absent on re-teste
         * périodiquement : le conclure une fois pour toutes condamnerait au silence la seule
         * machine capable d'atteindre l'imprimante si elle démarre après l'écran.
         */
        async _hasBridge() {
            const now = Date.now();

            if (this._bridgeAvailable) return true;
            if (this._bridgeCheckedAt && (now - this._bridgeCheckedAt) < BRIDGE_RECHECK_MS) {
                return false;
            }

            try {
                this._bridgeAvailable = await isCaisseBridgeAvailable();
            } catch (_) {
                this._bridgeAvailable = false;
            }

            this._bridgeCheckedAt = now;
            return this._bridgeAvailable;
        },

        async _printOne(order) {
            let success = false;
            let error = null;

            try {
                // Les octets sont rendus par le SERVEUR (même rendu que le ticket cuisine du
                // KDS et de la borne) : le papier est identique quelle que soit la surface.
                // Cet endpoint sait rendre SANS aucune imprimante déclarée en base — c'est
                // exactement le cas de cette production.
                const { data } = await axios.get(`admin/pos/orders/${order.id}/escpos`, {
                    params: { ticket: 'kitchen' },
                });
                const b64 = data && data.escpos_b64;

                if (!b64) {
                    error = "Aucun contenu à imprimer";
                } else {
                    const result = await printEscPosViaCaisseBridge(b64);
                    success = !!(result && result.ok);
                    if (!success) error = (result && result.error) || "Pont d'impression indisponible";
                }
            } catch (e) {
                error = (e && e.message) || "Erreur d'impression";
            }

            // Toujours accuser : sans ça, un ticket non sorti resterait marqué « imprimé ».
            try {
                await axios.post(`admin/pos/kitchen-tickets/${order.id}/ack`, {
                    success,
                    error: error ? String(error).slice(0, 255) : null,
                });
            } catch (_) {
                // Le prochain cycle ne le reprendra pas (il reste réclamé) : c'est le seul cas
                // résiduel, borné à une panne réseau entre le poste et le serveur au moment
                // précis de l'accusé. L'exploitant garde la réimpression manuelle depuis le KDS.
            }
        },
    },
};
</script>

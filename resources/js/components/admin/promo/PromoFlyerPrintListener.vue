<template>
    <!-- Composant sans rendu : il ne fait qu'écouter et imprimer. -->
    <span style="display:none" aria-hidden="true"></span>
</template>

<script>
/**
 * [FLYER PROMO UBER 2026-08-07] L'imprimeur de tickets promotionnels.
 *
 * POURQUOI CE COMPOSANT EXISTE
 * ----------------------------
 * Le serveur applicatif est dans le cloud et NE PEUT PAS joindre l'imprimante,
 * qui est branchée au PC de la caisse sur son réseau local (mesuré : la
 * production ne joint pas 192.168.192.168, et `tools/caisse-bridge` documente
 * déjà cette contrainte). Il n'existe donc AUCUN moyen pour le serveur
 * d'imprimer tout seul : quelque chose doit tourner sur le PC caisse.
 *
 * Ce composant est ce quelque chose. Il est monté dans la coquille admin, donc
 * présent dès qu'UN écran d'administration est ouvert sur ce PC — pas seulement
 * l'écran de caisse. L'exploitant tape un prénom sur son téléphone ; le ticket
 * sort ici.
 *
 * CE QU'IL NE FAUT PAS CASSER
 * ---------------------------
 *  - Il tourne sur TOUS les écrans admin, y compris pendant un encaissement.
 *    Il doit donc rester silencieux et bon marché : un POST toutes les 5 s,
 *    aucune notification, aucun blocage d'interface.
 *  - Il ne démarre PAS si le pont d'impression local est absent : sur le
 *    téléphone de l'exploitant, ou sur un poste bureau, réclamer des tickets
 *    consommerait des tentatives d'impression pour rien et les ferait échouer
 *    à distance de l'imprimante.
 *  - La réclamation est atomique côté serveur ; deux onglets ouverts ne peuvent
 *    pas sortir le même ticket deux fois.
 */
import axios from "axios";
import { isCaisseBridgeAvailable, printEscPosViaCaisseBridge } from "../../../helpers/posLocalPrinter";

// 5 s : cadence demandée par l'exploitant pour que ses appareils se voient
// « frais ». Le coût est un POST court par écran caisse, sans charge notable.
const POLL_MS = 5000;

// On laisse l'application se charger avant de commencer à sonder : au
// démarrage, l'écran a mieux à faire (catalogue, commandes en cours).
const START_DELAY_MS = 4000;

export default {
    name: "PromoFlyerPrintListener",
    data() {
        return {
            _timer: null,
            _running: false,
            _bridgeChecked: false,
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
            // Un cycle à la fois : si l'impression traîne (papier, pont lent),
            // on ne veut pas empiler les réclamations.
            if (this._running) return;
            this._running = true;

            try {
                if (!(await this._hasBridge())) return;

                const { data } = await axios.post("admin/promo-flyer/pending");
                const flyers = (data && data.flyers) || [];

                for (const flyer of flyers) {
                    await this._printOne(flyer);
                }
            } catch (_) {
                // Silence volontaire : cet écran peut être ouvert par un compte
                // sans droit caisse, ou hors ligne. Un bandeau d'erreur toutes
                // les 5 secondes pendant un encaissement serait bien pire que
                // l'absence de ticket.
            } finally {
                this._running = false;
            }
        },

        /**
         * Le pont local n'est présent que sur le PC caisse. On ne teste qu'une
         * fois : le résultat ne change pas en cours de session, et le helper
         * garde déjà son propre cache court.
         */
        async _hasBridge() {
            if (this._bridgeChecked) return this._bridgeAvailable;

            try {
                this._bridgeAvailable = await isCaisseBridgeAvailable();
            } catch (_) {
                this._bridgeAvailable = false;
            }

            this._bridgeChecked = true;
            return this._bridgeAvailable;
        },

        async _printOne(flyer) {
            let success = false;
            let error = null;

            try {
                const { data } = await axios.get(`admin/promo-flyer/${flyer.id}/escpos`);
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

            // On confirme TOUJOURS, succès comme échec : sans accusé, le ticket
            // resterait réclamé jusqu'à expiration du verrou et l'exploitant
            // n'aurait aucun moyen de savoir que rien n'est sorti.
            try {
                await axios.post(`admin/promo-flyer/${flyer.id}/ack`, {
                    success,
                    error: error ? String(error).slice(0, 255) : null,
                });
            } catch (_) {
                // Le verrou expirera de lui-même et le ticket sera re-proposé.
            }
        },
    },
};
</script>

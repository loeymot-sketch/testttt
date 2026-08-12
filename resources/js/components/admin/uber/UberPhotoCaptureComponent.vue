<template>
    <div class="col-12">
        <div class="db-card">
            <div class="db-card-header">
                <h3 class="db-card-title">Commande Uber — photo du ticket</h3>
            </div>

            <div class="db-card-body">
                <!-- ── ÉTAT 3 · ENVOYÉE ──────────────────────────────────────────────────
                     L'écran se remet seul en position photo : en coup de feu on enchaîne les
                     tickets, et l'écran doit être prêt avant la main. Le décompte est VISIBLE
                     et interruptible — un retour automatique qu'on ne peut pas arrêter est une
                     porte qui claque. -->
                <div v-if="envoyee" class="uber-cap-done" data-testid="uber-photo-done">
                    <div class="uber-cap-done-main">
                        <span class="uber-cap-done-check">✓</span>
                        <div>
                            <strong>{{ envoyee.numero || "Commande" }} — {{ envoyee.client || "sans nom" }}</strong>
                            <span class="uber-cap-done-sub">partie en cuisine</span>
                        </div>
                    </div>
                    <div class="uber-cap-done-actions">
                        <button type="button" class="uber-cap-btn uber-cap-btn--ghost"
                                :disabled="busy || reimprimees.includes(envoyee.id)"
                                data-testid="uber-photo-reprint-done" @click="reimprimer(envoyee)">
                            {{ reimprimees.includes(envoyee.id) ? "✓ Demandé" : "🖨 Réimprimer" }}
                        </button>
                        <button v-if="compte_a_rebours > 0" type="button" class="uber-cap-btn uber-cap-btn--ghost"
                                @click="rester">
                            Rester ({{ compte_a_rebours }})
                        </button>
                        <button type="button" class="uber-cap-btn uber-cap-btn--primary" @click="ticket_suivant">
                            Ticket suivant
                        </button>
                    </div>
                </div>

                <!-- ── ÉTAT 1 · PRÊT ─────────────────────────────────────────────────────
                     Le doigt vise gros : cet écran s'utilise debout, une tablette dans une
                     main, en plein service. Rien de plus petit que ~56 px de haut. -->
                <div v-if="!envoyee" class="uber-cap-actions">
                    <label class="uber-cap-btn uber-cap-btn--primary" data-testid="uber-photo-pick">
                        <input
                            ref="fileInput"
                            type="file"
                            accept="image/*"
                            capture="environment"
                            multiple
                            class="uber-cap-file"
                            @change="onFiles"
                        />
                        📷 {{ photos.length ? "Reprendre la photo" : "Photographier le ticket" }}
                    </label>

                    <button
                        type="button"
                        class="uber-cap-btn"
                        :disabled="!photos.length || busy"
                        data-testid="uber-photo-read"
                        @click="lire"
                    >
                        {{ busy ? "Lecture en cours…" : "Lire le ticket" }}
                    </button>

                    <button type="button" class="uber-cap-btn uber-cap-btn--ghost" :disabled="busy" @click="tout_effacer">
                        Recommencer
                    </button>
                </div>

                <p v-if="!envoyee" class="uber-cap-hint">
                    Ticket trop long ? Photographiez-le en plusieurs fois — les photos forment
                    <strong>une seule</strong> commande. Ajoutez la suite avant d'envoyer.
                </p>

                <!-- Vignettes des photos prises, avec retrait possible avant lecture. -->
                <ul v-if="photos.length && !envoyee" class="uber-cap-thumbs" data-testid="uber-photo-thumbs">
                    <li v-for="(p, i) in photos" :key="p.key" class="uber-cap-thumb">
                        <!-- Si le fichier n'est pas une image affichable (HEIC non supporté par
                             le navigateur, fichier d'un autre type), on retombe sur une pastille
                             lisible : une icône d'image cassée laisse croire que la photo est
                             perdue, alors qu'elle est bien envoyée. -->
                        <img v-if="p.url" :src="p.url" :alt="`Photo ${i + 1}`" @error="p.url = ''" />
                        <span v-else class="uber-cap-thumb-fallback">Photo {{ i + 1 }}</span>
                        <button type="button" class="uber-cap-thumb-del" :disabled="busy" @click="retirer(i)">✕</button>
                    </li>
                </ul>

                <!-- ── 2. CE QUE LA CUISINE VERRA ────────────────────────────────────────
                     L'aperçu est calculé par les services de la cuisine eux-mêmes : ce qui
                     est validé ici est très exactement ce qui sera préparé. -->
                <div v-if="capture && !envoyee" class="uber-cap-result" data-testid="uber-photo-result">
                    <div v-if="capture.status === 'failed'" class="uber-cap-alert" data-testid="uber-photo-failed">
                        <strong>Ticket illisible.</strong>
                        {{ capture.erreur || "Reprenez la photo de plus près, bien à plat et sans reflet." }}
                        <span v-if="capture.lecteur === 'mock'" class="uber-cap-alert-sub">
                            La lecture automatique n'est pas activée sur cette installation : saisissez la
                            commande depuis la caisse, ou demandez l'activation du lecteur.
                        </span>
                    </div>

                    <template v-else>
                        <div class="uber-cap-head">
                            <div class="uber-cap-field">
                                <label>Client</label>
                                <input v-model="edition.customer_name" type="text" maxlength="60" class="db-field-control"
                                       data-testid="uber-photo-client" placeholder="Prénom sur la commande" />
                            </div>
                            <div class="uber-cap-field">
                                <label>N° de commande</label>
                                <input v-model="edition.display_id" type="text" maxlength="60" class="db-field-control" />
                            </div>
                            <div class="uber-cap-cuisson" data-testid="uber-photo-cuisson">
                                <span class="uber-cap-cuisson-label">CUISSON</span>
                                <span class="uber-cap-cuisson-value">{{ capture.apercu.cuisson || "—" }}</span>
                            </div>
                        </div>

                        <!-- [TICKET COUPÉ] Tout ticket Uber finit par un montant payé. N'en avoir
                             lu aucun est le signe le plus net que la photo s'est arrêtée avant le
                             bas du papier. On le dit AVANT l'envoi, pendant qu'il est encore
                             temps — pas après, quand la cuisine prépare une commande amputée. -->
                        <div v-if="capture.ticket_coupe" class="uber-cap-alert uber-cap-alert--warn"
                             data-testid="uber-photo-truncated">
                            <strong>Ce ticket semble coupé :</strong> aucun montant total n'a été lu.
                            Photographiez la suite du papier — elle rejoindra cette même commande.
                        </div>

                        <div v-if="capture.articles_non_reconnus > 0" class="uber-cap-alert uber-cap-alert--warn">
                            <strong>{{ capture.articles_non_reconnus }} article(s) non reconnus</strong> dans la carte.
                            Ils partiront en cuisine avec leur nom d'origine — corrigez le nom si besoin.
                        </div>

                        <ul class="uber-cap-lines">
                            <li v-for="(l, i) in capture.apercu.lignes" :key="i"
                                :class="['uber-cap-line', l.non_mappe ? 'is-unknown' : '']">
                                <div class="uber-cap-line-main">
                                    <span class="uber-cap-qty">{{ l.quantity }}×</span>
                                    <span class="uber-cap-sym">{{ l.symbolique || l.titre }}</span>
                                    <span v-if="l.menu" class="uber-cap-menu">{{ l.menu }}</span>
                                </div>
                                <div class="uber-cap-line-sub">
                                    <span v-for="(s, k) in l.supplements" :key="'s' + k" class="uber-cap-supp">{{ s }}</span>
                                    <span v-for="(b, k) in l.boissons" :key="'b' + k" class="uber-cap-drink">{{ b }}</span>
                                </div>
                                <div v-if="l.note" class="uber-cap-note">{{ l.note }}</div>
                                <div class="uber-cap-raw">
                                    <input v-model="edition.items[i].title" type="text" maxlength="180"
                                           class="db-field-control" :data-testid="`uber-photo-title-${i}`" />
                                    <input v-model.number="edition.items[i].quantity" type="number" min="1" max="99"
                                           class="db-field-control uber-cap-qty-input" />
                                    <button type="button" class="uber-cap-line-del" @click="retirer_ligne(i)">Retirer</button>
                                </div>
                            </li>
                        </ul>

                        <div class="uber-cap-send">
                            <button type="button" class="uber-cap-btn uber-cap-btn--send"
                                    :disabled="busy || !edition.items.length || !!capture.order_id"
                                    data-testid="uber-photo-send" @click="envoyer">
                                {{ capture.order_id ? "✓ Déjà envoyée en cuisine" : "Envoyer en cuisine" }}
                            </button>

                            <!-- Toujours offert, alerte ou pas : la détection du ticket coupé est
                                 un garde-fou, jamais une autorisation. Le personnel voit le papier,
                                 pas nous. -->
                            <label v-if="!capture.order_id"
                                   :class="['uber-cap-btn', capture.ticket_coupe ? 'uber-cap-btn--primary' : '']"
                                   data-testid="uber-photo-add-more">
                                <input type="file" accept="image/*" capture="environment" multiple
                                       class="uber-cap-file" @change="ajouter_suite" />
                                ＋ Ajouter la suite du ticket
                            </label>

                            <button type="button" class="uber-cap-btn uber-cap-btn--ghost"
                                    :disabled="busy || !!capture.order_id" @click="jeter">
                                Jeter cette lecture
                            </button>
                        </div>
                    </template>
                </div>

                <!-- ── HISTORIQUE DU SERVICE ─────────────────────────────────────────────
                     Tout se fait ICI : rouvrir une commande, revoir ce que la cuisine a reçu,
                     ressortir le papier. Changer de page en plein service, c'est perdre le fil
                     de ce qu'on était en train de faire. -->
                <div v-if="recentes.length" class="uber-cap-recent" data-testid="uber-photo-history">
                    <h4>Commandes du service <span class="uber-cap-recent-count">{{ recentes.length }}</span></h4>
                    <ul>
                        <li v-for="r in recentes" :key="r.id" :class="{ 'is-open': ouverte === r.id }">
                            <!-- La ligne ENTIÈRE est la cible : un doigt gras sur une tablette ne
                                 vise pas un chevron de 16 px. -->
                            <button type="button" class="uber-cap-recent-row" :data-testid="`uber-photo-history-row-${r.id}`"
                                    @click="basculer(r.id)">
                                <span class="uber-cap-recent-state" :class="`is-${r.status}`">{{ etat(r.status) }}</span>
                                <span class="uber-cap-recent-num">{{ r.numero || "—" }}</span>
                                <span class="uber-cap-recent-client">{{ r.client || "sans nom" }}</span>
                                <span class="uber-cap-recent-cuisson">{{ (r.apercu && r.apercu.cuisson) || "" }}</span>
                                <span class="uber-cap-recent-heure">{{ heure(r.cree_le) }}</span>
                                <span class="uber-cap-recent-chevron">{{ ouverte === r.id ? "▾" : "▸" }}</span>
                            </button>

                            <div v-if="ouverte === r.id" class="uber-cap-recent-detail">
                                <p v-if="!r.apercu || !r.apercu.lignes.length" class="uber-cap-recent-vide">
                                    Aucune ligne lisible sur cette capture.
                                </p>
                                <ul v-else class="uber-cap-recent-lines">
                                    <li v-for="(l, i) in r.apercu.lignes" :key="i">
                                        <span class="uber-cap-qty">{{ l.quantity }}×</span>
                                        <span class="uber-cap-sym">{{ l.symbolique || l.titre }}</span>
                                        <span v-if="l.menu" class="uber-cap-menu">{{ l.menu }}</span>
                                        <span v-for="(b, k) in l.boissons" :key="'b' + k" class="uber-cap-drink">{{ b }}</span>
                                        <span v-for="(s, k) in l.supplements" :key="'s' + k" class="uber-cap-supp">{{ s }}</span>
                                        <em v-if="l.note" class="uber-cap-note">{{ l.note }}</em>
                                    </li>
                                </ul>

                                <div class="uber-cap-recent-actions">
                                    <!-- Pas de commande, pas de papier : le bouton n'existe pas
                                         plutôt que d'exister et de refuser. -->
                                    <button v-if="r.order_id" type="button" class="uber-cap-btn uber-cap-btn--ghost"
                                            :disabled="busy || reimprimees.includes(r.id)"
                                            :data-testid="`uber-photo-reprint-${r.id}`"
                                            @click="reimprimer(r)">
                                        {{ reimprimees.includes(r.id) ? "✓ Demandé — le ticket sort" : "🖨 Réimprimer le ticket cuisine" }}
                                    </button>
                                    <span v-else class="uber-cap-recent-note">
                                        Jamais envoyée en cuisine — rien à réimprimer.
                                    </span>
                                </div>
                            </div>
                        </li>
                    </ul>
                </div>
            </div>
        </div>
    </div>
</template>

<script>
/**
 * [UBER-PHOTO 2026-08-10 · owner] Écran TABLETTE : photographier un ticket Uber et l'envoyer
 * en cuisine.
 *
 * Le parcours tient en trois gestes — photographier, vérifier, envoyer — parce qu'il s'exécute
 * au comptoir avec un livreur qui attend. Tout ce qui pouvait être fait côté serveur l'est :
 * l'écran ne calcule aucun symbole lui-même, il AFFICHE l'aperçu que la cuisine a produit. Un
 * calcul recopié ici finirait par diverger de ce qui s'imprime réellement.
 *
 * La vérification humaine n'est pas une formalité : une lecture automatique peut se tromper, et
 * l'erreur ne se verrait qu'au moment de remettre le sac. Deux secondes de relecture coûtent
 * moins qu'un plat refait.
 */
import axios from "axios";
import alertService from "../../../services/alertService";

export default {
    name: "UberPhotoCaptureComponent",
    data() {
        return {
            photos: [],
            busy: false,
            capture: null,
            edition: { customer_name: "", display_id: "", items: [] },
            recentes: [],
            // La commande qui vient de partir : c'est elle qui met l'écran en état ENVOYÉE.
            envoyee: null,
            compte_a_rebours: 0,
            minuteur: null,
            // Ligne d'historique dépliée (une seule : deux détails ouverts sur une tablette,
            // c'est un écran qu'on ne lit plus).
            ouverte: null,
            // Réimpressions demandées à l'instant : le bouton se tait un moment (voir reimprimer()).
            reimprimees: [],
        };
    },
    mounted() {
        this.charger_recentes();
    },
    beforeUnmount() {
        this.liberer_urls();
        this.arreter_minuteur();
    },
    methods: {
        async onFiles(event) {
            const fichiers = Array.from(event.target.files || []);
            for (const brut of fichiers) {
                const f = await this.alleger(brut);
                this.photos.push({
                    key: `${f.name}-${f.size}-${this.photos.length}-${brut.lastModified || 0}`,
                    file: f,
                    // `createObjectURL` n'existe pas dans jsdom ni sur certains navigateurs
                    // embarqués : l'absence de vignette ne doit jamais empêcher d'envoyer.
                    url: typeof URL !== "undefined" && URL.createObjectURL ? URL.createObjectURL(f) : "",
                });
            }
            // On vide l'input pour que reprendre LA MÊME photo redéclenche bien l'événement.
            event.target.value = "";
        },

        /**
         * [POIDS 2026-08-12] Réduit la photo AVANT l'envoi.
         *
         * Le serveur refuse tout fichier de plus de 2 Mo (`upload_max_filesize` de PHP) alors
         * qu'une photo de tablette en pèse 2 à 5. L'envoi échouait alors sur un « le
         * téléversement a échoué » qui n'explique rien et ne dit pas quoi faire.
         *
         * Un ticket se lit parfaitement à 1600 px de large — vérifié : la même commande découpée
         * à 1330 px a été lue sans une erreur. On envoie donc quelques centaines de kilo-octets
         * au lieu de plusieurs mégaoctets : plus rien ne bute sur la limite, et l'envoi est
         * nettement plus rapide sur la connexion du restaurant.
         *
         * Si quoi que ce soit manque (canvas indisponible, image illisible, navigateur
         * embarqué), on renvoie le fichier D'ORIGINE : mieux vaut un envoi lourd qu'un envoi
         * impossible.
         */
        async alleger(fichier) {
            const LARGEUR_MAX = 1600;
            const SEUIL_OCTETS = 900 * 1024;

            try {
                if (!fichier || fichier.size <= SEUIL_OCTETS) return fichier;
                if (typeof document === "undefined" || typeof createImageBitmap !== "function") return fichier;

                const image = await createImageBitmap(fichier);
                const ratio = Math.min(1, LARGEUR_MAX / Math.max(image.width, image.height));
                if (ratio >= 1) return fichier;

                const toile = document.createElement("canvas");
                toile.width = Math.round(image.width * ratio);
                toile.height = Math.round(image.height * ratio);
                const ctx = toile.getContext("2d");
                if (!ctx) return fichier;
                ctx.drawImage(image, 0, 0, toile.width, toile.height);

                const blob = await new Promise((r) => toile.toBlob(r, "image/jpeg", 0.85));
                if (!blob || blob.size >= fichier.size) return fichier;

                return new File([blob], (fichier.name || "ticket").replace(/\.[^.]+$/, "") + ".jpg", {
                    type: "image/jpeg",
                    lastModified: Date.now(),
                });
            } catch (e) {
                return fichier;
            }
        },

        retirer(i) {
            const [p] = this.photos.splice(i, 1);
            if (p && p.url && URL.revokeObjectURL) URL.revokeObjectURL(p.url);
        },

        liberer_urls() {
            for (const p of this.photos) {
                if (p.url && typeof URL !== "undefined" && URL.revokeObjectURL) URL.revokeObjectURL(p.url);
            }
        },

        tout_effacer() {
            this.liberer_urls();
            this.photos = [];
            this.capture = null;
            this.edition = { customer_name: "", display_id: "", items: [] };
        },

        async lire() {
            if (!this.photos.length || this.busy) return;
            this.busy = true;
            try {
                const form = new FormData();
                for (const p of this.photos) form.append("photos[]", p.file);

                const { data } = await axios.post("admin/uber/photo/scan", form, {
                    headers: { "Content-Type": "multipart/form-data" },
                });
                this.appliquer(data);
                if (data.deja_lue) {
                    alertService.info("Ce ticket a déjà été lu — voici la lecture existante.");
                }
            } catch (e) {
                alertService.error(this.message(e, "La lecture du ticket a échoué."));
            } finally {
                this.busy = false;
            }
        },

        appliquer(data) {
            this.capture = data;
            const ticket = data.ticket || {};
            this.edition = {
                customer_name: data.client || "",
                display_id: data.numero || "",
                items: (ticket.items || []).map((it) => ({
                    title: it.title || "",
                    quantity: it.quantity || 1,
                    options: it.options || [],
                    note: it.note || "",
                })),
            };
        },

        retirer_ligne(i) {
            this.edition.items.splice(i, 1);
            if (this.capture && this.capture.apercu) this.capture.apercu.lignes.splice(i, 1);
        },

        async envoyer() {
            if (!this.capture || this.busy || !this.edition.items.length) return;
            this.busy = true;
            try {
                const { data } = await axios.post(`admin/uber/photo/${this.capture.id}/confirm`, {
                    customer_name: this.edition.customer_name,
                    display_id: this.edition.display_id,
                    items: this.edition.items,
                });
                this.capture = data.capture || this.capture;
                alertService.success(
                    data.status === "already_confirmed"
                        ? "Cette commande était déjà partie en cuisine."
                        : "Commande envoyée en cuisine."
                );
                // On bascule en état ENVOYÉE : l'écran affiche ce qui est parti, propose de le
                // réimprimer, et se remet seul en position photo. Le geste suivant du personnel,
                // c'est le ticket suivant — pas de chercher un bouton « Recommencer ».
                this.envoyee = {
                    id: this.capture.id,
                    order_id: data.order_id || this.capture.order_id,
                    numero: this.capture.numero || this.edition.display_id,
                    client: this.capture.client || this.edition.customer_name,
                };
                this.demarrer_retour();
                this.charger_recentes();
            } catch (e) {
                alertService.error(this.message(e, "L'envoi en cuisine a échoué."));
            } finally {
                this.busy = false;
            }
        },

        async jeter() {
            if (!this.capture || this.busy) return;
            this.busy = true;
            try {
                await axios.post(`admin/uber/photo/${this.capture.id}/discard`);
                this.tout_effacer();
                this.charger_recentes();
            } catch (e) {
                alertService.error(this.message(e, "Impossible de jeter cette lecture."));
            } finally {
                this.busy = false;
            }
        },

        /**
         * [TICKET EN PLUSIEURS PHOTOS 2026-08-12 · owner « si trop longue commande en 2 photos »]
         *
         * On AJOUTE la photo aux précédentes et on relit L'ENSEMBLE : le serveur dédoublonne sur
         * l'empreinte de toutes les photos, donc l'ensemble « photo 1 + photo 2 » est une capture
         * neuve, et il n'y a rien à recoller à la main.
         *
         * La lecture partielle qui la précède est JETÉE : sans cela elle resterait dans
         * l'historique comme une commande « à valider », et quelqu'un finirait par l'envoyer —
         * la même commande partirait deux fois en cuisine, amputée la première.
         */
        async ajouter_suite(event) {
            const avant = this.photos.length;
            await this.onFiles(event);
            if (this.photos.length === avant) return;

            const partielle = this.capture && !this.capture.order_id ? this.capture.id : null;
            await this.lire();

            if (partielle && this.capture && this.capture.id !== partielle) {
                try {
                    await axios.post(`admin/uber/photo/${partielle}/discard`);
                } catch (e) {
                    // Jeter la lecture partielle est du confort : l'échouer ne doit pas empêcher
                    // d'envoyer la commande complète, qui est déjà lue et à l'écran.
                }
                this.charger_recentes();
            }
        },

        /**
         * [RÉIMPRESSION 2026-08-12 · owner] Le papier se perd : il tombe, il bourre, il part avec
         * l'emballage. Rephotographier le ticket créerait une SECONDE commande, donc un second
         * plat — c'est précisément ce que ce bouton évite.
         */
        async reimprimer(cible) {
            if (!cible || this.busy || this.reimprimees.includes(cible.id)) return;
            this.busy = true;
            try {
                const { data } = await axios.post(`admin/uber/photo/${cible.id}/reprint`);
                alertService.success(data.message || "Réimpression demandée.");
                // Le pont met jusqu'à 5 s à venir chercher le papier. Sans cette pause, un doigt
                // impatient tape trois fois et sort trois tickets — c'est arrivé pendant les
                // essais. Le bouton dit ce qu'il a fait, puis redevient disponible.
                this.reimprimees.push(cible.id);
                setTimeout(() => {
                    this.reimprimees = this.reimprimees.filter((id) => id !== cible.id);
                }, 12000);
            } catch (e) {
                alertService.error(this.message(e, "La réimpression n'a pas pu être demandée."));
            } finally {
                this.busy = false;
            }
        },

        basculer(id) {
            this.ouverte = this.ouverte === id ? null : id;
        },

        /** Retour automatique à l'écran photo — visible, et interruptible d'un doigt. */
        demarrer_retour() {
            this.arreter_minuteur();
            this.compte_a_rebours = 4;
            this.minuteur = setInterval(() => {
                this.compte_a_rebours -= 1;
                if (this.compte_a_rebours <= 0) this.ticket_suivant();
            }, 1000);
        },

        arreter_minuteur() {
            if (this.minuteur) {
                clearInterval(this.minuteur);
                this.minuteur = null;
            }
        },

        /** « Rester » : on garde la commande à l'écran, sans jamais la renvoyer. */
        rester() {
            this.arreter_minuteur();
            this.compte_a_rebours = 0;
        },

        ticket_suivant() {
            this.arreter_minuteur();
            this.compte_a_rebours = 0;
            this.envoyee = null;
            this.tout_effacer();
        },

        /** Heure courte, pour situer la commande dans le service sans encombrer la ligne. */
        heure(iso) {
            if (!iso) return "";
            const d = new Date(iso);
            if (Number.isNaN(d.getTime())) return "";
            return `${String(d.getHours()).padStart(2, "0")}:${String(d.getMinutes()).padStart(2, "0")}`;
        },

        async charger_recentes() {
            try {
                const { data } = await axios.get("admin/uber/photo/recent", { params: { limit: 20 } });
                this.recentes = data.data || [];
            } catch (e) {
                // Un historique indisponible ne doit pas empêcher de prendre une commande.
                this.recentes = [];
            }
        },

        etat(status) {
            return {
                pending: "en cours",
                extracted: "à valider",
                failed: "illisible",
                confirmed: "en cuisine",
                discarded: "jetée",
            }[status] || status;
        },

        message(e, defaut) {
            return (e && e.response && e.response.data && e.response.data.message) || defaut;
        },
    },
};
</script>

<style scoped>
.uber-cap-actions { display: flex; flex-wrap: wrap; gap: 12px; margin-bottom: 8px; }
.uber-cap-btn {
    min-height: 56px; padding: 0 22px; border-radius: 10px; border: 1px solid #cbd5e1;
    background: #fff; font-size: 17px; font-weight: 700; cursor: pointer; display: inline-flex;
    align-items: center; gap: 8px;
}
.uber-cap-btn:disabled { opacity: .45; cursor: not-allowed; }
/* Vert de marque Uber Eats — le même que la vignette de l'écran de cuisine, pour que
   l'oeil fasse le lien entre les deux écrans sans y penser. */
.uber-cap-btn--primary { background: #06C167; border-color: #06C167; color: #fff; }
.uber-cap-btn--send { background: #F4501E; border-color: #F4501E; color: #fff; min-width: 240px; justify-content: center; }
.uber-cap-btn--ghost { background: transparent; color: #475569; }
.uber-cap-file { position: absolute; width: 1px; height: 1px; opacity: 0; }
.uber-cap-hint { color: #64748b; font-size: 14px; margin: 4px 0 14px; }

.uber-cap-thumbs { display: flex; flex-wrap: wrap; gap: 10px; list-style: none; padding: 0; margin: 0 0 16px; }
.uber-cap-thumb { position: relative; width: 96px; height: 96px; border-radius: 8px; overflow: hidden; border: 1px solid #e2e8f0; background: #f8fafc; }
.uber-cap-thumb img { width: 100%; height: 100%; object-fit: cover; }
.uber-cap-thumb-fallback { display: flex; align-items: center; justify-content: center; height: 100%; font-size: 12px; color: #64748b; }
.uber-cap-thumb-del { position: absolute; top: 2px; right: 2px; width: 26px; height: 26px; border-radius: 50%; border: none; background: rgba(15,23,42,.72); color: #fff; cursor: pointer; }

.uber-cap-result { border-top: 1px solid #e2e8f0; padding-top: 16px; }
.uber-cap-head { display: flex; flex-wrap: wrap; gap: 16px; align-items: flex-end; margin-bottom: 12px; }
.uber-cap-field { flex: 1 1 220px; }
.uber-cap-field label { display: block; font-size: 13px; color: #475569; margin-bottom: 4px; }
.uber-cap-cuisson { display: flex; flex-direction: column; padding: 8px 16px; border-radius: 10px; background: #1a1a1a; color: #fff; }
.uber-cap-cuisson-label { font-size: 11px; letter-spacing: .12em; opacity: .8; }
.uber-cap-cuisson-value { font-size: 26px; font-weight: 800; line-height: 1.1; }

.uber-cap-alert { border: 1px solid #fca5a5; background: #fef2f2; color: #7f1d1d; border-radius: 8px; padding: 12px; margin-bottom: 12px; }
.uber-cap-alert--warn { border-color: #fcd34d; background: #fffbeb; color: #78350f; }
.uber-cap-alert-sub { display: block; margin-top: 6px; font-size: 13px; }

.uber-cap-lines { list-style: none; padding: 0; margin: 0; }
.uber-cap-line { border: 1px solid #e2e8f0; border-radius: 10px; padding: 12px; margin-bottom: 10px; }
.uber-cap-line.is-unknown { border-color: #fcd34d; background: #fffbeb; }
.uber-cap-line-main { display: flex; align-items: baseline; gap: 10px; flex-wrap: wrap; }
.uber-cap-qty { font-size: 20px; font-weight: 800; color: #64748b; }
.uber-cap-sym { font-size: 22px; font-weight: 800; letter-spacing: .02em; }
.uber-cap-menu { font-size: 15px; font-weight: 700; background: #1a1a1a; color: #fff; border-radius: 6px; padding: 2px 10px; }
.uber-cap-line-sub { display: flex; flex-wrap: wrap; gap: 10px; margin-top: 6px; }
.uber-cap-supp { font-weight: 700; color: #92400e; }
.uber-cap-drink { color: #1d4ed8; font-weight: 600; }
.uber-cap-note { margin-top: 6px; color: #b91c1c; font-weight: 700; }
.uber-cap-raw { display: flex; gap: 8px; margin-top: 10px; }
.uber-cap-qty-input { max-width: 90px; }
.uber-cap-line-del { border: none; background: transparent; color: #94a3b8; cursor: pointer; }

.uber-cap-send { display: flex; gap: 12px; margin-top: 16px; flex-wrap: wrap; }
.uber-cap-recent { margin-top: 24px; border-top: 1px solid #e2e8f0; padding-top: 12px; }
.uber-cap-recent h4 { font-size: 14px; color: #475569; margin-bottom: 8px; display: flex; align-items: center; gap: 8px; }
.uber-cap-recent-count {
    background: #eef2f7; color: #475569; border-radius: 999px; padding: 1px 9px; font-size: 12px; font-weight: 700;
}
.uber-cap-recent ul { list-style: none; padding: 0; margin: 0; }
.uber-cap-recent li { border-bottom: 1px dashed #e2e8f0; }
.uber-cap-recent li.is-open { background: #f8fafc; border-radius: 10px; }

/* La ligne entière est le bouton : sur une tablette, un doigt ne vise pas un chevron. */
.uber-cap-recent-row {
    width: 100%; display: flex; align-items: center; gap: 14px; min-height: 52px; padding: 6px 10px;
    background: none; border: 0; font-size: 15px; text-align: left; cursor: pointer; color: inherit;
}
.uber-cap-recent-row:hover { background: #f1f5f9; border-radius: 10px; }
.uber-cap-recent-state { font-weight: 700; min-width: 92px; }
.uber-cap-recent-state.is-confirmed { color: #06C167; }
.uber-cap-recent-state.is-failed { color: #b91c1c; }
.uber-cap-recent-num { font-weight: 700; min-width: 84px; }
.uber-cap-recent-client { flex: 1 1 auto; min-width: 0; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.uber-cap-recent-cuisson { font-family: ui-monospace, Menlo, monospace; color: #b45309; font-weight: 700; }
.uber-cap-recent-heure { color: #94a3b8; font-variant-numeric: tabular-nums; }
.uber-cap-recent-chevron { color: #94a3b8; width: 14px; }

.uber-cap-recent-detail { padding: 4px 12px 14px; }
.uber-cap-recent-lines { display: flex; flex-direction: column; gap: 6px; margin-bottom: 12px; }
.uber-cap-recent-lines li { display: flex; flex-wrap: wrap; align-items: center; gap: 8px; border: 0; padding: 2px 0; }
.uber-cap-recent-vide { color: #94a3b8; font-size: 14px; margin-bottom: 12px; }
.uber-cap-recent-actions { display: flex; align-items: center; gap: 12px; flex-wrap: wrap; }
.uber-cap-recent-note { color: #94a3b8; font-size: 14px; }

/* État ENVOYÉE : large, vert, et il s'efface tout seul. */
.uber-cap-done {
    display: flex; flex-wrap: wrap; align-items: center; justify-content: space-between; gap: 16px;
    background: #ecfdf5; border: 1px solid #a7f3d0; border-radius: 12px; padding: 18px 20px; margin-bottom: 18px;
}
.uber-cap-done-main { display: flex; align-items: center; gap: 14px; font-size: 18px; }
.uber-cap-done-check {
    width: 40px; height: 40px; border-radius: 50%; background: #06C167; color: #fff;
    display: inline-flex; align-items: center; justify-content: center; font-size: 22px; font-weight: 800;
}
.uber-cap-done-sub { display: block; color: #047857; font-size: 15px; }
.uber-cap-done-actions { display: flex; gap: 10px; flex-wrap: wrap; }
</style>

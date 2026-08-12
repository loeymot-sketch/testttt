<template>
    <div class="col-12">
        <div class="db-card">
            <div class="db-card-header">
                <h3 class="db-card-title">Commande Uber — photo du ticket</h3>
            </div>

            <div class="db-card-body">
                <!-- ── 1. PRENDRE LES PHOTOS ─────────────────────────────────────────────
                     Le doigt vise gros : cet écran s'utilise debout, une tablette dans une
                     main, en plein service. Rien de plus petit que ~56 px de haut. -->
                <div class="uber-cap-actions">
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
                        📷 Photographier le ticket
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

                <p class="uber-cap-hint">
                    Le ticket ne tient pas sur un seul écran ? Photographiez-le en plusieurs fois :
                    toutes les photos forment <strong>une seule</strong> commande.
                </p>

                <!-- Vignettes des photos prises, avec retrait possible avant lecture. -->
                <ul v-if="photos.length" class="uber-cap-thumbs" data-testid="uber-photo-thumbs">
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
                <div v-if="capture" class="uber-cap-result" data-testid="uber-photo-result">
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
                            <button type="button" class="uber-cap-btn uber-cap-btn--ghost"
                                    :disabled="busy || !!capture.order_id" @click="jeter">
                                Jeter cette lecture
                            </button>
                        </div>
                    </template>
                </div>

                <!-- ── 3. CE QUI VIENT DE PASSER ────────────────────────────────────────── -->
                <div v-if="recentes.length" class="uber-cap-recent">
                    <h4>Dernières commandes photographiées</h4>
                    <ul>
                        <li v-for="r in recentes" :key="r.id">
                            <span class="uber-cap-recent-state" :class="`is-${r.status}`">{{ etat(r.status) }}</span>
                            <span>{{ r.client || "—" }}</span>
                            <span>{{ r.numero || "" }}</span>
                            <span>{{ r.apercu && r.apercu.cuisson ? r.apercu.cuisson : "" }}</span>
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
        };
    },
    mounted() {
        this.charger_recentes();
    },
    beforeUnmount() {
        this.liberer_urls();
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

        async charger_recentes() {
            try {
                const { data } = await axios.get("admin/uber/photo/recent", { params: { limit: 8 } });
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
.uber-cap-recent h4 { font-size: 14px; color: #475569; margin-bottom: 8px; }
.uber-cap-recent ul { list-style: none; padding: 0; margin: 0; }
.uber-cap-recent li { display: flex; gap: 14px; padding: 6px 0; border-bottom: 1px dashed #e2e8f0; font-size: 14px; }
.uber-cap-recent-state { font-weight: 700; min-width: 92px; }
.uber-cap-recent-state.is-confirmed { color: #06C167; }
.uber-cap-recent-state.is-failed { color: #b91c1c; }
</style>

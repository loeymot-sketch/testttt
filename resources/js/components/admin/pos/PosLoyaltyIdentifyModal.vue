<template>
    <transition name="pos-loy-id-fade">
        <div
            v-if="open"
            class="pos-loy-id__backdrop"
            role="dialog"
            aria-modal="true"
            aria-label="Fidélité — identifier le client"
            @click.self="fermer"
        >
            <section class="pos-loy-id" ref="panneau">
                <header class="pos-loy-id__head">
                    <h2 class="pos-loy-id__title">Fidélité client</h2>
                    <button
                        type="button"
                        class="pos-loy-id__close"
                        aria-label="Fermer"
                        data-testid="loy-id-close"
                        @click="fermer"
                    >&times;</button>
                </header>

                <!-- ── LE CHOIX DU MOYEN ───────────────────────────────────────────── -->
                <nav class="pos-loy-id__tabs" role="tablist">
                    <button
                        v-for="t in onglets"
                        :key="t.cle"
                        type="button"
                        role="tab"
                        :aria-selected="onglet === t.cle"
                        :class="['pos-loy-id__tab', onglet === t.cle && 'is-active']"
                        :data-testid="`loy-id-tab-${t.cle}`"
                        @click="changerOnglet(t.cle)"
                    >{{ t.libelle }}</button>
                </nav>

                <div class="pos-loy-id__body">
                    <!-- Bandeaux -->
                    <p v-if="erreur" class="pos-loy-id__alert" role="alert" data-testid="loy-id-error">{{ erreur }}</p>
                    <p v-if="succes" class="pos-loy-id__ok" role="status" data-testid="loy-id-success">{{ succes }}</p>

                    <!-- ── TÉLÉPHONE ───────────────────────────────────────────────── -->
                    <div v-if="onglet === 'phone'" class="pos-loy-id__field">
                        <label for="loy-id-phone" class="pos-loy-id__label">Numéro de téléphone du client</label>
                        <input
                            id="loy-id-phone"
                            ref="champPhone"
                            v-model="phone"
                            type="tel"
                            inputmode="tel"
                            autocomplete="off"
                            placeholder="06 12 34 56 78"
                            class="pos-loy-id__input pos-loy-id__input--big"
                            data-testid="loy-id-phone-input"
                            @keyup.enter="chercher"
                        />
                        <p class="pos-loy-id__hint">Le moyen le plus rapide. Le client n'a rien à présenter.</p>
                    </div>

                    <!-- ── CODE ────────────────────────────────────────────────────── -->
                    <div v-else-if="onglet === 'code'" class="pos-loy-id__field">
                        <label for="loy-id-code" class="pos-loy-id__label">Code fidélité</label>
                        <input
                            id="loy-id-code"
                            ref="champCode"
                            v-model="code"
                            type="text"
                            autocomplete="off"
                            placeholder="ABC12345"
                            class="pos-loy-id__input pos-loy-id__input--big pos-loy-id__input--mono"
                            data-testid="loy-id-code-input"
                            @keyup.enter="chercher"
                        />
                        <p class="pos-loy-id__hint">Sur son écran, ou imprimé sur un ticket précédent.</p>
                    </div>

                    <!-- ── QR À LA CAMÉRA ──────────────────────────────────────────── -->
                    <div v-else class="pos-loy-id__field">
                        <div v-if="!cameraPossible" class="pos-loy-id__nocam" data-testid="loy-id-no-camera">
                            <p>Cette tablette ne sait pas lire un QR par la caméra.</p>
                            <p class="pos-loy-id__hint">
                                Demandez son numéro de téléphone — c'est aussi rapide, et ça marche partout.
                            </p>
                            <button type="button" class="pos-loy-id__btn" @click="changerOnglet('phone')">
                                Passer au numéro
                            </button>
                        </div>
                        <div v-else>
                            <!-- La vidéo reste montée pendant le scan ; on ne la démonte qu'à l'arrêt,
                                 sinon le flux se coupe à chaque re-rendu. -->
                            <video
                                ref="video"
                                class="pos-loy-id__video"
                                playsinline
                                muted
                                data-testid="loy-id-video"
                            ></video>
                            <p class="pos-loy-id__hint">
                                {{ scanEnCours ? 'Présentez le QR devant la caméra…' : 'Caméra arrêtée.' }}
                            </p>
                            <button
                                type="button"
                                class="pos-loy-id__btn"
                                data-testid="loy-id-scan-toggle"
                                @click="scanEnCours ? arreterScan() : demarrerScan()"
                            >{{ scanEnCours ? 'Arrêter' : 'Démarrer la caméra' }}</button>
                        </div>
                    </div>

                    <!-- ── PLUSIEURS COMPTES : L'HUMAIN TRANCHE ────────────────────── -->
                    <div v-if="candidats.length" class="pos-loy-id__choix" data-testid="loy-id-candidates">
                        <p class="pos-loy-id__label">
                            {{ candidats.length }} comptes portent ce numéro — lequel est-ce&nbsp;?
                        </p>
                        <button
                            v-for="c in candidats"
                            :key="c.loyalty_code"
                            type="button"
                            class="pos-loy-id__candidat"
                            :data-testid="`loy-id-candidate-${c.loyalty_code}`"
                            @click="choisir(c)"
                        >
                            <span class="pos-loy-id__candidat-nom">{{ c.name }}</span>
                            <span class="pos-loy-id__candidat-meta">
                                {{ c.phone_masked }} · {{ c.balance }} pts
                            </span>
                        </button>
                    </div>

                    <!-- ── LE CLIENT TROUVÉ ────────────────────────────────────────── -->
                    <div v-if="client" class="pos-loy-id__client" data-testid="loy-id-customer">
                        <p class="pos-loy-id__client-nom">{{ client.name }}</p>
                        <p class="pos-loy-id__client-meta">{{ client.phone_masked }} · {{ client.loyalty_code }}</p>

                        <dl class="pos-loy-id__solde">
                            <div>
                                <dt>Solde</dt>
                                <dd data-testid="loy-id-balance">{{ client.balance }} pts</dd>
                            </div>
                            <div>
                                <dt>Utilisable</dt>
                                <dd data-testid="loy-id-usable">
                                    <template v-if="client.can_use">
                                        {{ client.usable_points }} pts = {{ euros(client.usable_eur) }}
                                    </template>
                                    <template v-else>—</template>
                                </dd>
                            </div>
                        </dl>

                        <!-- On DIT ce qui manque : un client qui comprend revient. -->
                        <p v-if="!client.can_use" class="pos-loy-id__manque" data-testid="loy-id-missing">
                            Encore <strong>{{ client.missing_points }} points</strong> avant de pouvoir les utiliser
                            (seuil&nbsp;: {{ client.effective_floor }} pts).
                        </p>

                        <!--
                          UN BOUTON PÂLE DOIT DIRE POURQUOI. Sans cette ligne, le caissier voit
                          « Cumuler sur cette vente » inactif et appuie trois fois — c'est le défaut
                          exact que le propriétaire avait signalé sur l'ancienne entrée fidélité
                          (« grisée / inaccessible »), et le corriger d'un côté pour le refaire de
                          l'autre n'aurait servi à rien.
                        -->
                        <p v-if="!orderId" class="pos-loy-id__manque" data-testid="loy-id-no-order">
                            Aucune commande en cours&nbsp;: ajoutez des articles et validez la vente, puis revenez
                            ici pour lui créditer ses points.
                        </p>

                        <!--
                          L'HISTORIQUE, REPLIÉ. Un solde sans histoire ne se défend pas : le client qui
                          conteste, le responsable qui cherche un écart, le caissier qui a rattaché la
                          mauvaise vente posent la même question. Replié parce que le geste courant est
                          « encaisser », pas « enquêter » — et déplié en un appui quand la question vient.
                        -->
                        <button
                            type="button"
                            class="pos-loy-id__lien-histo"
                            data-testid="loy-id-history-toggle"
                            @click="basculerHistorique"
                        >{{ historiqueOuvert ? 'Masquer l\'historique' : 'Voir l\'historique des points' }}</button>

                        <div v-if="historiqueOuvert" class="pos-loy-id__histo" data-testid="loy-id-history">
                            <p v-if="historiqueEnCours" class="pos-loy-id__hint">Lecture…</p>
                            <p v-else-if="!historique.length" class="pos-loy-id__hint" data-testid="loy-id-history-empty">
                                Aucun mouvement de points pour ce client.
                            </p>
                            <ul v-else class="pos-loy-id__histo-liste">
                                <li v-for="e in historique" :key="e.id" class="pos-loy-id__histo-ligne">
                                    <span class="pos-loy-id__histo-quoi">
                                        {{ e.label }}
                                        <span class="pos-loy-id__histo-quand">{{ e.when }}</span>
                                    </span>
                                    <span :class="['pos-loy-id__histo-pts', e.points < 0 && 'is-moins']">
                                        {{ e.signed }} pts
                                    </span>
                                    <span class="pos-loy-id__histo-solde">→ {{ e.balance }}</span>
                                </li>
                            </ul>
                        </div>

                        <!--
                          [FIDÉLITÉ COMPTOIR 2026-08-14 · propriétaire] « Quand je lui ajoute un
                          montant équivalent fidélité, par exemple sept euros, je veux directement
                          les rajouter dans son compte. » Distinct de « Cumuler sur cette vente » :
                          ici le caissier choisit lui-même la somme (geste commercial, oubli d'une
                          vente passée), sans lien avec le montant réel du panier.
                        -->
                        <button
                            type="button"
                            class="pos-loy-id__lien-histo"
                            data-testid="loy-id-credit-toggle"
                            @click="creditManuelOuvert = !creditManuelOuvert"
                        >{{ creditManuelOuvert ? 'Masquer le crédit manuel' : 'Créditer manuellement (€)' }}</button>

                        <div v-if="creditManuelOuvert" class="pos-loy-id__field" data-testid="loy-id-credit-panel">
                            <label for="loy-id-credit-euros" class="pos-loy-id__label">Montant à créditer</label>
                            <input
                                id="loy-id-credit-euros"
                                v-model="creditEuros"
                                type="number"
                                inputmode="decimal"
                                min="0.01"
                                max="200"
                                step="0.01"
                                placeholder="7,00"
                                class="pos-loy-id__input"
                                data-testid="loy-id-credit-euros"
                                @keyup.enter="crediterManuellement"
                            />
                            <p class="pos-loy-id__hint">
                                Converti en points au même barème que la remise en caisse.
                            </p>
                            <button
                                type="button"
                                class="pos-loy-id__btn pos-loy-id__btn--primary"
                                style="margin-top: 0.5rem"
                                :disabled="occupe || !creditEurosValide"
                                data-testid="loy-id-credit-submit"
                                @click="crediterManuellement"
                            >{{ occupe ? '…' : `Créditer ${creditEurosValide ? euros(creditEuros) : ''}` }}</button>
                        </div>
                    </div>

                    <!-- ── AUCUN COMPTE : ON PROPOSE DE L'INSCRIRE ─────────────────── -->
                    <div v-if="aucunCompte" class="pos-loy-id__vide" data-testid="loy-id-empty">
                        <p>Aucun compte à ce numéro.</p>
                        <label for="loy-id-new-name" class="pos-loy-id__label">Prénom (facultatif)</label>
                        <input
                            id="loy-id-new-name"
                            v-model="nouveauNom"
                            type="text"
                            class="pos-loy-id__input"
                            data-testid="loy-id-new-name"
                        />
                        <label for="loy-id-new-email" class="pos-loy-id__label">E-mail (facultatif)</label>
                        <input
                            id="loy-id-new-email"
                            v-model="nouveauMail"
                            type="email"
                            class="pos-loy-id__input"
                            data-testid="loy-id-new-email"
                        />
                        <p class="pos-loy-id__hint">
                            Le numéro suffit. L'e-mail sert à lui envoyer son code de connexion plus tard.
                        </p>
                    </div>
                </div>

                <!-- ── LES ACTIONS ─────────────────────────────────────────────────── -->
                <footer class="pos-loy-id__actions">
                    <button type="button" class="pos-loy-id__btn pos-loy-id__btn--ghost" @click="fermer">
                        Fermer
                    </button>

                    <!--
                      Pas de « Rechercher » dans l'onglet Scanner : il n'y a rien à taper, la caméra
                      lance la recherche elle-même. Un bouton inactif dans un onglet où il n'a aucun
                      sens, c'est un caissier qui cherche ce qu'il a mal fait.
                    -->
                    <button
                        v-if="!client && !aucunCompte && onglet !== 'qr'"
                        type="button"
                        class="pos-loy-id__btn pos-loy-id__btn--primary"
                        :disabled="occupe || !critereRempli"
                        data-testid="loy-id-search"
                        @click="chercher"
                    >{{ occupe ? '…' : 'Rechercher' }}</button>

                    <button
                        v-if="aucunCompte"
                        type="button"
                        class="pos-loy-id__btn pos-loy-id__btn--primary"
                        :disabled="occupe"
                        data-testid="loy-id-create"
                        @click="creerCompte"
                    >{{ occupe ? '…' : 'Créer le compte' }}</button>

                    <template v-if="client">
                        <button
                            type="button"
                            class="pos-loy-id__btn pos-loy-id__btn--primary"
                            :disabled="occupe || !orderId"
                            data-testid="loy-id-attach"
                            @click="rattacher"
                        >{{ occupe ? '…' : 'Cumuler sur cette vente' }}</button>

                        <button
                            v-if="client.can_use && orderId"
                            type="button"
                            class="pos-loy-id__btn pos-loy-id__btn--accent"
                            data-testid="loy-id-use"
                            @click="utiliserLesPoints"
                        >Utiliser {{ euros(client.usable_eur) }}</button>
                    </template>
                </footer>
            </section>
        </div>
    </transition>
</template>

<script>
/**
 * IDENTIFIER LE CLIENT AU COMPTOIR — l'écran qui manquait à toute la fidélité de caisse.
 *
 * ── LA DEMANDE DU PROPRIÉTAIRE ───────────────────────────────────────────────────────────────
 * « Une section pour créer un compte pour un client, utiliser ses points accumulés ou lui en
 *   ajouter pour sa commande ; l'accumulation avec le numéro de téléphone, c'est préférable ; on
 *   scanne le QR directement avec la tablette, on n'a pas de lecteur de code-barres. »
 *
 * ── POURQUOI CET ÉCRAN CHANGE TOUT ───────────────────────────────────────────────────────────
 * Mesuré en base : 1411 ventes de caisse arrivées à DELIVERED, UNE SEULE rattachée à un client. Le
 * crédit et le débit fonctionnaient depuis des mois — personne ne pouvait dire QUI est le client.
 *
 * ── LES TROIS DÉCISIONS D'INTERFACE QUI COMPTENT ─────────────────────────────────────────────
 * 1. LE TÉLÉPHONE D'ABORD. C'est l'onglet ouvert par défaut : le client n'a rien à présenter, rien
 *    à installer, rien à retrouver dans son téléphone. Le QR est plus élégant et plus rare.
 * 2. LA MACHINE NE TRANCHE JAMAIS entre deux comptes. 5 numéros de la base sont portés par
 *    plusieurs comptes (l'un par 5) : on affiche la liste avec prénom, numéro masqué et solde, et
 *    le caissier choisit. Choisir à sa place, c'est débiter le solde de quelqu'un d'autre.
 * 3. ON MONTRE CE QUI EST UTILISABLE, PAS LE SOLDE BRUT. Un client à 900 points avec un seuil à
 *    1000 ne peut rien utiliser : l'écran l'annonce et DIT ce qui manque. Afficher « 900 pts » sans
 *    plus, c'est promettre une remise que la caisse refusera devant la file d'attente.
 *
 * ── DEUX GESTES DISTINCTS, DEUX BOUTONS DISTINCTS ────────────────────────────────────────────
 * « Cumuler sur cette vente » rattache le client pour qu'il GAGNE ses points.
 * « Utiliser X € » ouvre la fenêtre de remise existante pour qu'il les DÉPENSE.
 * Les confondre dans un seul bouton, c'est vider un solde quand on voulait le remplir.
 *
 * ── LA CAMÉRA ────────────────────────────────────────────────────────────────────────────────
 * `BarcodeDetector` est natif quand il existe : aucune bibliothèque, aucun script externe (une page
 * de caisse ne charge rien depuis l'extérieur). Quand il n'existe pas, on le DIT et on renvoie au
 * numéro de téléphone — plutôt qu'un bouton mort qui laisse le caissier appuyer trois fois.
 *
 * Le flux vidéo est arrêté à la fermeture, à l'arrêt manuel, et à la destruction du composant. Une
 * caméra qu'on oublie d'éteindre reste allumée toute la journée sur la tablette du comptoir.
 *
 * ── LE SERVEUR RESTE L'AUTORITÉ ──────────────────────────────────────────────────────────────
 * Toutes les gardes tiennent côté serveur (permission, caisse, compte d'équipe, compte supprimé,
 * plancher, doublon). Cet écran n'est qu'une façon d'appeler ; il ne décide de rien.
 */
import axios from 'axios';

export default {
    name: 'PosLoyaltyIdentifyModal',
    props: {
        open: { type: Boolean, default: false },
        orderId: { type: [Number, String], default: null },
    },
    emits: ['close', 'attached', 'use-points'],
    data() {
        return {
            onglet: 'phone',
            phone: '',
            code: '',
            client: null,
            candidats: [],
            aucunCompte: false,
            nouveauNom: '',
            nouveauMail: '',
            erreur: '',
            succes: '',
            occupe: false,
            historiqueOuvert: false,
            historiqueEnCours: false,
            historique: [],
            creditManuelOuvert: false,
            creditEuros: '',
            scanEnCours: false,
            flux: null,
            boucleScan: null,
        };
    },
    computed: {
        onglets() {
            return [
                { cle: 'phone', libelle: 'Téléphone' },
                { cle: 'code', libelle: 'Code' },
                { cle: 'qr', libelle: 'Scanner' },
            ];
        },
        cameraPossible() {
            return typeof window !== 'undefined'
                && 'BarcodeDetector' in window
                && !!(navigator.mediaDevices && navigator.mediaDevices.getUserMedia);
        },
        critereRempli() {
            if (this.onglet === 'phone') return this.chiffres(this.phone).length >= 9;
            if (this.onglet === 'code') return this.code.trim().length >= 4;
            return false;
        },
        creditEurosValide() {
            const v = Number(this.creditEuros);
            return Number.isFinite(v) && v > 0 && v <= 200;
        },
    },
    watch: {
        open(ouvert) {
            if (ouvert) {
                this.reinitialiser();
                this.$nextTick(() => {
                    const champ = this.$refs.champPhone;
                    if (champ && champ.focus) champ.focus();
                });
            } else {
                this.arreterScan();
            }
        },
    },
    beforeUnmount() {
        // Une caméra qu'on oublie reste allumée toute la journée sur la tablette du comptoir.
        this.arreterScan();
    },
    methods: {
        euros(v) {
            return `${(Number(v) || 0).toFixed(2).replace('.', ',')} €`;
        },
        chiffres(v) {
            return String(v || '').replace(/\D+/g, '');
        },
        reinitialiser() {
            this.client = null;
            this.candidats = [];
            this.aucunCompte = false;
            this.erreur = '';
            this.succes = '';
            this.nouveauNom = '';
            this.nouveauMail = '';
            // L'historique appartient à UN client : le laisser ouvert d'une recherche à l'autre
            // afficherait les mouvements de la personne précédente sous le nom de la suivante.
            this.historiqueOuvert = false;
            this.historique = [];
            this.creditManuelOuvert = false;
            this.creditEuros = '';
        },
        changerOnglet(cle) {
            if (cle !== 'qr') this.arreterScan();
            this.onglet = cle;
            this.reinitialiser();
        },
        fermer() {
            this.arreterScan();
            this.$emit('close');
        },

        // ── RECHERCHE ────────────────────────────────────────────────────────────────────────

        async chercher() {
            if (this.occupe || !this.critereRempli) return;

            this.reinitialiser();
            this.occupe = true;

            const corps = this.onglet === 'code'
                ? { code: this.code.trim() }
                : { phone: this.phone.trim() };

            try {
                const { data } = await axios.post('/admin/pos-loyalty/lookup', corps);
                this.traiterResultat(data && data.data ? data.data : {});
            } catch (e) {
                this.erreur = this.messageErreur(e, 'Recherche impossible.');
            } finally {
                this.occupe = false;
            }
        },

        traiterResultat(r) {
            if (r.status === 'found') {
                this.client = r.customer;
                return;
            }
            if (r.status === 'ambiguous') {
                this.candidats = r.candidates || [];
                return;
            }
            if (r.status === 'not_found') {
                // On ne propose l'inscription QUE depuis un numéro : c'est la clé du compte. Un code
                // fidélité inconnu est une faute de frappe, pas un client à inscrire.
                if (this.onglet === 'phone') {
                    this.aucunCompte = true;
                } else {
                    this.erreur = 'Aucun compte pour ce code.';
                }
                return;
            }
            this.erreur = r.message || 'Critère non reconnu.';
        },

        choisir(c) {
            this.candidats = [];
            this.client = c;
        },

        // ── INSCRIPTION ──────────────────────────────────────────────────────────────────────

        async creerCompte() {
            if (this.occupe) return;
            this.occupe = true;
            this.erreur = '';

            try {
                const { data } = await axios.post('/admin/pos-loyalty/customers', {
                    phone: this.phone.trim(),
                    name: this.nouveauNom.trim() || null,
                    email: this.nouveauMail.trim() || null,
                }, { headers: { 'X-Idempotency-Key': this.cleIdempotence('new') } });

                const d = (data && data.data) || {};
                this.aucunCompte = false;
                this.client = d.customer || null;
                // On ne dit pas « compte créé » à quelqu'un qui était déjà inscrit.
                this.succes = d.created ? 'Compte créé.' : 'Ce client était déjà inscrit.';
            } catch (e) {
                this.erreur = this.messageErreur(e, 'Création impossible.');
            } finally {
                this.occupe = false;
            }
        },

        // ── LES DEUX GESTES ──────────────────────────────────────────────────────────────────

        async rattacher() {
            if (this.occupe || !this.client || !this.orderId) return;
            this.occupe = true;
            this.erreur = '';

            try {
                const { data } = await axios.post(
                    `/admin/pos-order/${this.orderId}/attach-loyalty`,
                    { loyalty_code: this.client.loyalty_code },
                    { headers: { 'X-Idempotency-Key': this.cleIdempotence('att') } }
                );

                const d = (data && data.data) || {};
                if (d.customer) this.client = d.customer;

                const pts = Number(d.points_awarded) || 0;
                // Zéro point n'est pas un échec : la commande n'a pas encore atteint son
                // déclencheur. Dire « +0 point » serait inquiétant ; dire « c'est noté » est juste.
                this.succes = pts > 0
                    ? `+${pts} points pour ${this.client.name}.`
                    : 'Client rattaché — ses points seront crédités à la fin de la commande.';

                this.$emit('attached', d);
            } catch (e) {
                this.erreur = this.messageErreur(e, 'Rattachement impossible.');
            } finally {
                this.occupe = false;
            }
        },

        /**
         * [FIDÉLITÉ COMPTOIR 2026-08-14] Crédit manuel — le caissier choisit lui-même la somme,
         * distincte du gain automatique proportionnel à une vente (« Cumuler sur cette vente »).
         * `orderId` n'est envoyé QUE pour tracer « pour quelle vente » dans l'historique — cette
         * route ne modifie jamais le total ni la remise d'une commande.
         */
        async crediterManuellement() {
            if (this.occupe || !this.client || !this.creditEurosValide) return;
            this.occupe = true;
            this.erreur = '';

            try {
                const { data } = await axios.post(
                    '/admin/pos-loyalty/credit-manual',
                    {
                        loyalty_code: this.client.loyalty_code,
                        euros: Number(this.creditEuros),
                        order_id: this.orderId || null,
                    },
                    { headers: { 'X-Idempotency-Key': this.cleIdempotence('cred') } }
                );

                const d = (data && data.data) || {};
                if (d.customer) this.client = d.customer;

                const pts = Number(d.points_added) || 0;
                this.succes = `+${pts} points crédités (${this.euros(this.creditEuros)}).`;
                this.creditEuros = '';
                this.creditManuelOuvert = false;
            } catch (e) {
                this.erreur = this.messageErreur(e, 'Crédit impossible.');
            } finally {
                this.occupe = false;
            }
        },

        async basculerHistorique() {
            if (this.historiqueOuvert) { this.historiqueOuvert = false; return; }
            if (!this.client) return;

            this.historiqueOuvert = true;
            this.historiqueEnCours = true;

            try {
                const { data } = await axios.get('/admin/pos-loyalty/history', {
                    params: { loyalty_code: this.client.loyalty_code, limit: 20 },
                });
                this.historique = (data && data.data && data.data.entries) || [];
            } catch (e) {
                this.erreur = this.messageErreur(e, 'Historique indisponible.');
                this.historiqueOuvert = false;
            } finally {
                this.historiqueEnCours = false;
            }
        },

        utiliserLesPoints() {
            // La fenêtre de remise existe déjà et porte toutes ses gardes : on lui passe le relais
            // plutôt que de refaire un second chemin de débit.
            this.$emit('use-points', {
                loyalty_code: this.client.loyalty_code,
                usable_points: this.client.usable_points,
            });
        },

        // ── LA CAMÉRA ────────────────────────────────────────────────────────────────────────

        async demarrerScan() {
            if (!this.cameraPossible || this.scanEnCours) return;
            this.erreur = '';

            try {
                this.flux = await navigator.mediaDevices.getUserMedia({
                    // Caméra arrière : sur une tablette de comptoir, la façade regarde le caissier.
                    video: { facingMode: 'environment' },
                });
                const video = this.$refs.video;
                if (!video) { this.arreterScan(); return; }

                video.srcObject = this.flux;
                await video.play();

                const detecteur = new window.BarcodeDetector({ formats: ['qr_code'] });
                this.scanEnCours = true;

                this.boucleScan = setInterval(async () => {
                    if (!this.scanEnCours) return;
                    try {
                        const trouves = await detecteur.detect(video);
                        if (trouves && trouves.length) {
                            const brut = trouves[0].rawValue;
                            this.arreterScan();
                            await this.chercherParQr(brut);
                        }
                    } catch (e) {
                        // Une image illisible est ordinaire pendant un scan : on ne casse pas la
                        // boucle pour ça, la suivante réussira.
                    }
                }, 350);
            } catch (e) {
                this.erreur = 'Caméra indisponible — utilisez le numéro de téléphone.';
                this.arreterScan();
            }
        },

        arreterScan() {
            this.scanEnCours = false;
            if (this.boucleScan) {
                clearInterval(this.boucleScan);
                this.boucleScan = null;
            }
            if (this.flux) {
                this.flux.getTracks().forEach((p) => p.stop());
                this.flux = null;
            }
            const video = this.$refs.video;
            if (video) video.srcObject = null;
        },

        async chercherParQr(brut) {
            this.occupe = true;
            this.reinitialiser();

            try {
                const { data } = await axios.post('/admin/pos-loyalty/lookup', { qr: brut });
                this.traiterResultat(data && data.data ? data.data : {});
            } catch (e) {
                this.erreur = this.messageErreur(e, 'QR non reconnu.');
            } finally {
                this.occupe = false;
            }
        },

        // ── OUTILS ───────────────────────────────────────────────────────────────────────────

        cleIdempotence(prefixe) {
            // Une clé stable à la seconde : un double appui involontaire est rejoué, une seconde
            // action volontaire quelques secondes plus tard passe.
            const t = Math.floor(Date.now() / 5000);
            return `pos-loy-${prefixe}-${this.orderId || 'x'}-${this.chiffres(this.phone) || this.code}-${t}`;
        },

        messageErreur(e, defaut) {
            const r = e && e.response ? e.response : null;
            if (!r) return defaut;
            if (r.status === 403) return 'Vous n\'avez pas le droit de faire cette action.';
            if (r.status === 429) return 'Trop de recherches — patientez quelques secondes.';
            // Le serveur renvoie une phrase lisible par le caissier : on la préfère à la nôtre.
            if (r.data && r.data.message) return r.data.message;
            if (r.data && r.data.errors) {
                const premier = Object.values(r.data.errors)[0];
                if (Array.isArray(premier) && premier.length) return premier[0];
            }
            return defaut;
        },
    },
};
</script>

<style scoped>
.pos-loy-id__backdrop {
    position: fixed;
    inset: 0;
    z-index: 9500;
    display: flex;
    align-items: center;
    justify-content: center;
    padding: 1rem;
    background: rgba(16, 16, 20, 0.62);
}

.pos-loy-id {
    width: 100%;
    max-width: 30rem;
    max-height: 92vh;
    overflow-y: auto;
    background: #fff;
    border-radius: 1rem;
    box-shadow: 0 1.5rem 3rem rgba(0, 0, 0, 0.28);
}

.pos-loy-id__head {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 1rem 1.25rem 0.75rem;
    border-bottom: 1px solid #ececf2;
}

.pos-loy-id__title {
    margin: 0;
    font-size: 1.0625rem;
    font-weight: 700;
    color: #1a1a1a;
}

.pos-loy-id__close {
    width: 2rem;
    height: 2rem;
    font-size: 1.5rem;
    line-height: 1;
    color: #6b6b76;
    background: none;
    border: 0;
    border-radius: 0.5rem;
    cursor: pointer;
}

.pos-loy-id__close:hover { background: #f3f3f7; }

.pos-loy-id__tabs {
    display: flex;
    gap: 0.25rem;
    padding: 0.75rem 1.25rem 0;
}

.pos-loy-id__tab {
    flex: 1;
    /* 44 px de haut : une cible tactile qu'un doigt atteint sans viser. */
    min-height: 2.75rem;
    font-size: 0.9375rem;
    font-weight: 600;
    color: #6b6b76;
    background: #f5f5f9;
    border: 0;
    border-radius: 0.625rem;
    cursor: pointer;
}

.pos-loy-id__tab.is-active { color: #fff; background: #f4501e; }

.pos-loy-id__body { padding: 1rem 1.25rem; }

.pos-loy-id__label {
    display: block;
    margin-bottom: 0.375rem;
    font-size: 0.8125rem;
    font-weight: 600;
    color: #4a4a55;
}

.pos-loy-id__input {
    width: 100%;
    min-height: 2.75rem;
    padding: 0 0.75rem;
    font-size: 0.9375rem;
    color: #1a1a1a;
    background: #fff;
    border: 1px solid #d9dbe9;
    border-radius: 0.625rem;
}

.pos-loy-id__input--big {
    min-height: 3.25rem;
    font-size: 1.25rem;
    letter-spacing: 0.02em;
}

.pos-loy-id__input--mono {
    font-family: ui-monospace, SFMono-Regular, Menlo, monospace;
    text-transform: uppercase;
}

.pos-loy-id__input + .pos-loy-id__label { margin-top: 0.75rem; }

.pos-loy-id__hint {
    margin: 0.5rem 0 0;
    font-size: 0.75rem;
    color: #8a8a95;
}

.pos-loy-id__alert,
.pos-loy-id__ok {
    margin: 0 0 0.75rem;
    padding: 0.625rem 0.75rem;
    font-size: 0.875rem;
    border-radius: 0.625rem;
}

.pos-loy-id__alert { color: #8f1d0f; background: #fdeceb; }
.pos-loy-id__ok { color: #14572f; background: #e8f6ed; }

.pos-loy-id__choix { margin-top: 1rem; }

.pos-loy-id__candidat {
    display: flex;
    flex-direction: column;
    gap: 0.125rem;
    width: 100%;
    margin-top: 0.5rem;
    padding: 0.75rem;
    text-align: left;
    background: #f9f9fc;
    border: 1px solid #e6e6ef;
    border-radius: 0.625rem;
    cursor: pointer;
}

.pos-loy-id__candidat:hover { border-color: #f4501e; }
.pos-loy-id__candidat-nom { font-size: 0.9375rem; font-weight: 600; color: #1a1a1a; }
.pos-loy-id__candidat-meta { font-size: 0.75rem; color: #8a8a95; }

.pos-loy-id__client {
    margin-top: 1rem;
    padding: 0.875rem;
    background: #f9f9fc;
    border: 1px solid #e6e6ef;
    border-radius: 0.75rem;
}

.pos-loy-id__client-nom { margin: 0; font-size: 1.0625rem; font-weight: 700; color: #1a1a1a; }
.pos-loy-id__client-meta { margin: 0.125rem 0 0.75rem; font-size: 0.75rem; color: #8a8a95; }

.pos-loy-id__solde {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 0.5rem;
    margin: 0;
}

.pos-loy-id__solde dt { font-size: 0.6875rem; text-transform: uppercase; color: #8a8a95; }
.pos-loy-id__solde dd { margin: 0.125rem 0 0; font-size: 1.125rem; font-weight: 700; color: #1a1a1a; }

.pos-loy-id__manque {
    margin: 0.75rem 0 0;
    padding-top: 0.75rem;
    font-size: 0.8125rem;
    color: #6b6b76;
    border-top: 1px dashed #e0e0ea;
}

.pos-loy-id__lien-histo {
    /* [FIDÉLITÉ COMPTOIR 2026-08-14] `display: block` — deux boutons du même style
       (historique, crédit manuel) se suivent dans le DOM ; en `inline-block` par défaut
       ils collaient l'un à l'autre sans espace ni séparateur visuel. */
    display: block;
    margin-top: 0.75rem;
    padding: 0;
    font-size: 0.8125rem;
    font-weight: 600;
    color: #f4501e;
    text-decoration: underline;
    background: none;
    border: 0;
    cursor: pointer;
}

.pos-loy-id__histo { margin-top: 0.625rem }

.pos-loy-id__histo-liste {
    /* Une liste haute défile DANS son cadre : sans ça, vingt lignes poussent les boutons d'action
       hors de l'écran sur une tablette, et le caissier ne peut plus rien valider. */
    max-height: 11rem;
    margin: 0;
    padding: 0;
    overflow-y: auto;
    list-style: none;
}

.pos-loy-id__histo-ligne {
    display: grid;
    grid-template-columns: 1fr auto auto;
    gap: 0.5rem;
    align-items: baseline;
    padding: 0.4375rem 0;
    font-size: 0.8125rem;
    border-bottom: 1px solid #ececf2;
}

.pos-loy-id__histo-quoi { color: #4a4a55 }
.pos-loy-id__histo-quand { margin-left: 0.375rem; font-size: 0.6875rem; color: #a0a0aa }
.pos-loy-id__histo-pts { font-weight: 700; font-variant-numeric: tabular-nums; color: #14572f }
.pos-loy-id__histo-pts.is-moins { color: #8f1d0f }
.pos-loy-id__histo-solde { font-variant-numeric: tabular-nums; color: #a0a0aa }

.pos-loy-id__vide { margin-top: 1rem; }
.pos-loy-id__vide > p:first-child { margin: 0 0 0.75rem; font-weight: 600; color: #1a1a1a; }

.pos-loy-id__nocam { text-align: center; }
.pos-loy-id__nocam > p:first-child { font-weight: 600; color: #1a1a1a; }

.pos-loy-id__video {
    width: 100%;
    aspect-ratio: 4 / 3;
    background: #101014;
    border-radius: 0.75rem;
    object-fit: cover;
}

.pos-loy-id__actions {
    display: flex;
    flex-wrap: wrap;
    gap: 0.5rem;
    padding: 0.875rem 1.25rem 1.125rem;
    border-top: 1px solid #ececf2;
}

.pos-loy-id__btn {
    flex: 1 1 8rem;
    min-height: 2.75rem;
    padding: 0 1rem;
    font-size: 0.9375rem;
    font-weight: 600;
    color: #1a1a1a;
    background: #f0f0f5;
    border: 0;
    border-radius: 0.625rem;
    cursor: pointer;
}

.pos-loy-id__btn:disabled { opacity: 0.5; cursor: not-allowed; }
.pos-loy-id__btn--ghost { flex: 0 0 auto; color: #6b6b76; background: none; }
.pos-loy-id__btn--primary { color: #fff; background: #f4501e; }
.pos-loy-id__btn--accent { color: #1a1a1a; background: #ffb800; }

.pos-loy-id-fade-enter-active,
.pos-loy-id-fade-leave-active { transition: opacity 0.15s ease; }
.pos-loy-id-fade-enter-from,
.pos-loy-id-fade-leave-to { opacity: 0; }
</style>

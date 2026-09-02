<template>
    <!--
      [GOAL CAISSE CONTRÔLE 2026-09-02] Le tiroir de contrôle des commandes.

      DEMANDE DU PROPRIÉTAIRE (verbatim) : « pour les commandes qui sont en cours je veux pas que
      ça ouvre une nouvelle page, vraiment directement en petite barre à droite ». Mesuré avant
      correctif : depuis `/admin/pos-v4`, ouvrir le suivi coûte une navigation DURE de 15,6 s
      (`reports/goal-caisse-controle-2026-09-02/captures-avant/mesures-pos-app-js.json`).

      RECOUVREMENT, PAS POUSSÉE. Le bord droit est déjà occupé par `#pos-cart` (340→400 px) et
      l'aire produits est calculée pour lui. Une seconde barre PERMANENTE ramènerait le catalogue
      à ~540 px sur un écran de 1280 — alors que la grille produits passe DÉJÀ sous la ligne de
      flottaison. Le tiroir se superpose donc (comme `.kiosk-cash-panel`, mécanique que le
      propriétaire utilise déjà), et le ticket en cours n'est ni démonté ni rétréci — ce que le
      bandeau permanent lui dit noir sur blanc.

      ZÉRO REQUÊTE PROPRE : tout arrive en `props` depuis `PosComponent`, qui poll déjà. Le tiroir
      ne peut donc pas alourdir le budget réseau de la caisse (`tests/e2e/pos-request-budget.spec.js`).
    -->
    <!--
      TÉLÉPORTÉ DANS `<body>`. Sans cela, `position: fixed` ne se cale PAS sur la fenêtre : un
      ancêtre de la caisse porte une transformation, ce qui en fait le bloc conteneur des
      descendants fixés. Constaté au navigateur le 2026-09-02 sur la première version de ce
      tiroir — il débordait de ~97 px à droite et son bouton « Encaisser » sortait de l'écran.
      Le panneau borne existant vit avec le même décalage depuis toujours (visible sur la capture
      d'audit `captures-avant/02-panneau-encaisser-borne.png`, coupée au bord droit) ; on ne le
      reproduit pas.
    -->
    <Teleport to="body">
    <div
        v-if="open"
        class="pos-ctrl-voile"
        data-testid="pos-control-drawer-overlay"
        @click.self="fermer"
    >
        <aside
            ref="tiroir"
            class="pos-ctrl"
            role="dialog"
            aria-modal="true"
            aria-labelledby="pos-ctrl-titre"
            data-testid="pos-control-drawer"
            @keydown.esc.stop.prevent="fermer"
            @keydown.tab="piegerFocus"
        >
            <header class="pos-ctrl__tete">
                <h2
                    id="pos-ctrl-titre"
                    ref="titre"
                    class="pos-ctrl__titre"
                    tabindex="-1"
                >{{ $t('pos.controle.titre') }}</h2>
                <button
                    type="button"
                    class="pos-ctrl__fermer"
                    :aria-label="$t('pos.controle.fermer')"
                    data-testid="pos-control-close"
                    @click="fermer"
                >✕</button>
            </header>

            <!-- 32 px contre l'angoisse de perdre une saisie : la première question du caissier
                 devant un panneau qui recouvre son panier est « et mon ticket ? ». -->
            <p class="pos-ctrl__ticket" data-testid="pos-control-ticket-preserved">
                <span aria-hidden="true">🧾</span>
                {{ phraseTicket }}
            </p>

            <div
                class="pos-ctrl__onglets"
                role="tablist"
                :aria-label="$t('pos.controle.titre')"
                @keydown.left.prevent="ongletVoisin(-1)"
                @keydown.right.prevent="ongletVoisin(1)"
            >
                <button
                    v-for="onglet in onglets"
                    :key="onglet.id"
                    :ref="`onglet-${onglet.id}`"
                    type="button"
                    role="tab"
                    class="pos-ctrl__onglet"
                    :class="[`pos-ctrl__onglet--${onglet.id}`, { 'pos-ctrl__onglet--actif': onglet.id === ongletActif }]"
                    :aria-selected="onglet.id === ongletActif ? 'true' : 'false'"
                    :tabindex="onglet.id === ongletActif ? 0 : -1"
                    :aria-controls="`pos-ctrl-panneau-${onglet.id}`"
                    :data-testid="`pos-control-tab-${onglet.id}`"
                    @click="ongletActif = onglet.id"
                >
                    <span class="pos-ctrl__onglet-haut">
                        <span aria-hidden="true">{{ onglet.icone }}</span>
                        <span
                            class="pos-ctrl__compteur pos-v5-tabular"
                            :data-testid="`pos-control-count-${onglet.id}`"
                        >{{ onglet.commandes.length }}</span>
                    </span>
                    <span class="pos-ctrl__onglet-libelle">{{ onglet.libelle }}</span>
                </button>
            </div>

            <p v-if="sousTitreOnglet" class="pos-ctrl__sous-titre">{{ sousTitreOnglet }}</p>

            <div
                :id="`pos-ctrl-panneau-${ongletActif}`"
                class="pos-ctrl__liste"
                role="tabpanel"
                :aria-label="ongletCourant.libelle"
                :data-testid="`pos-control-panel-${ongletActif}`"
            >
                <!-- ÉTAT VIDE — il ne se contente jamais d'affirmer le calme : il DATE sa mesure.
                     Sans l'horodatage, un panneau vide ne distingue pas une période calme d'un
                     poll mort (mandat Q10, déjà acquis sur les panneaux raccourcis). -->
                <p
                    v-if="ongletCourant.commandes.length === 0"
                    class="pos-ctrl__vide"
                    :data-testid="`pos-control-empty-${ongletActif}`"
                >
                    <span class="pos-ctrl__vide-icone" aria-hidden="true">✓</span>
                    <span>{{ ongletCourant.vide }}</span>
                    <span v-if="libelleFraicheur" class="pos-ctrl__vide-date">{{ libelleFraicheur }}</span>
                </p>

                <!-- ── CARTE RICHE : « laquelle est celle du client devant moi ? » ────────────
                     Hiérarchie typographique = ordre dans lequel le client s'annonce en
                     fast-food : son NUMÉRO d'abord (la borne le lui imprime), son prénom,
                     puis ce qu'il a pris. Ce n'est pas la hiérarchie comptable. -->
                <template v-else-if="densiteOnglet === 'riche'">
                    <article
                        v-for="commande in ongletCourant.commandes"
                        :key="commande.id"
                        class="pos-ctrl-carte"
                        :class="classeAge(commande)"
                        :data-testid="`pos-control-card-${commande.id}`"
                    >
                        <button
                            type="button"
                            class="pos-ctrl-carte__corps"
                            :data-testid="`pos-control-open-${commande.id}`"
                            :aria-label="$t('pos.controle.voir_tout')"
                            @click="detail = commande"
                        >
                            <span class="pos-ctrl-carte__entete">
                                <span class="pos-ctrl-carte__numero pos-v5-tabular">N°{{ numero(commande) }}</span>
                                <span :class="classesDuCanal(canal(commande))">
                                    <span aria-hidden="true">{{ icone(commande) }}</span>
                                    <span class="pos-sr-only">{{ libelleCanal(commande) }}</span>
                                </span>
                                <span
                                    class="pos-ctrl-carte__age pos-v5-tabular"
                                    :class="classeAgeTexte(commande)"
                                    :data-testid="`pos-control-age-${commande.id}`"
                                >{{ age(commande) }}</span>
                            </span>

                            <span v-if="nomClient(commande)" class="pos-ctrl-carte__client">
                                <span aria-hidden="true">👤</span> {{ nomClient(commande) }}
                            </span>
                            <span v-if="telClient(commande)" class="pos-ctrl-carte__tel pos-v5-tabular">
                                {{ telClient(commande) }}
                            </span>

                            <span class="pos-ctrl-carte__lignes">
                                <span
                                    v-for="(ligne, i) in apercuLignes(commande)"
                                    :key="i"
                                    class="pos-ctrl-carte__ligne"
                                >
                                    <span class="pos-ctrl-carte__produit">
                                        {{ ligne.quantity }}× {{ nomDuProduit(ligne) }}
                                    </span>
                                    <span v-if="compo(ligne).texte" class="pos-ctrl-carte__compo">
                                        {{ compo(ligne).texte }}<template v-if="compo(ligne).tronque"> +{{ compo(ligne).restants }}</template>
                                    </span>
                                </span>
                                <span
                                    v-if="lignesEnPlus(commande) > 0"
                                    class="pos-ctrl-carte__reste"
                                    :data-testid="`pos-control-more-${commande.id}`"
                                >+ {{ lignesEnPlus(commande) }} {{ motArticles(lignesEnPlus(commande)) }}</span>
                            </span>

                            <!-- LE RANG CUISINE. Il est calculé sur la règle SERVEUR
                                 (KitchenReleaseRule), pas sur le bucket du tableau de suivi :
                                 ce bucket range toute commande à encaisser dans « À encaisser »
                                 quel que soit son statut cuisine, d'où le « EN PRÉPARATION 1 »
                                 constaté pendant que quatre commandes cuisaient. -->
                            <span
                                v-if="rang(commande)"
                                class="pos-ctrl-carte__rang"
                                :data-testid="`pos-control-rank-${commande.id}`"
                            >
                                <span aria-hidden="true">🍳</span> {{ phraseRang(rang(commande)) }}
                            </span>
                            <span class="pos-ctrl-carte__heure pos-v5-tabular">
                                {{ $t('pos.controle.commandee_a', { heure: heure(commande) }) }}
                            </span>
                        </button>

                        <div class="pos-ctrl-carte__agir">
                            <span
                                class="pos-ctrl-carte__montant pos-v5-tabular"
                                :class="{ 'pos-ctrl-carte__montant--du': aEncaisser(commande) }"
                                :data-testid="`pos-control-amount-${commande.id}`"
                            >{{ formatPrice(montant(commande)) }}</span>
                            <button
                                v-if="ongletActif === 'encaisser'"
                                type="button"
                                class="pos-ctrl-cta pos-ctrl-cta--encaisser"
                                :disabled="commande._collecting"
                                :data-testid="`pos-control-collect-${commande.id}`"
                                @click="$emit('encaisser', commande)"
                            >{{ commande._collecting ? '…' : $t('label.pos_shortcut_cash_cta') }}</button>
                            <button
                                v-else
                                type="button"
                                class="pos-ctrl-cta pos-ctrl-cta--livrer"
                                :disabled="commande._delivering"
                                :data-testid="`pos-control-deliver-${commande.id}`"
                                @click="$emit('livrer', commande)"
                            >{{ commande._delivering ? '…' : $t('label.pos_shortcut_delivered_cta') }}</button>
                        </div>
                    </article>

                    <!-- LES COMMANDES D'AVANT AUJOURD'HUI : ni cachées, ni mélangées.
                         L'endpoint d'encaissement n'a aucun filtre de date et trie du plus
                         ancien d'abord : une commande morte de la veille occupait donc
                         MÉCANIQUEMENT la tête de file, devant celle du client présent. -->
                    <button
                        v-if="ongletActif === 'encaisser' && anciennesCount > 0"
                        type="button"
                        class="pos-ctrl__anciennes"
                        data-testid="pos-control-older-pending"
                        @click="$emit('ouvrir-encaissement')"
                    >
                        <span aria-hidden="true">⏳</span>
                        <span class="pos-ctrl__anciennes-texte">
                            {{ $t('pos.controle.plus_anciennes', { n: anciennesCount }) }}
                        </span>
                        <span class="pos-ctrl__anciennes-lien">{{ $t('pos.controle.ouvrir_encaissement') }} →</span>
                    </button>
                </template>

                <!-- ── LIGNE COMPACTE : « combien devant, et depuis quand ? » ────────────────
                     Densité de DÉNOMBREMENT, pas d'identification : dix lignes visibles valent
                     mieux que trois cartes quand la question est « combien ». -->
                <template v-else>
                    <button
                        v-for="(commande, index) in ongletCourant.commandes"
                        :key="commande.id"
                        type="button"
                        class="pos-ctrl-ligne"
                        :data-testid="`pos-control-row-${commande.id}`"
                        @click="detail = commande"
                    >
                        <span
                            v-if="ongletActif === 'cuisine'"
                            class="pos-ctrl-ligne__rang pos-v5-tabular"
                            :data-testid="`pos-control-rank-${commande.id}`"
                        >{{ ordinal(index + 1) }}</span>
                        <span class="pos-ctrl-ligne__numero pos-v5-tabular">N°{{ numero(commande) }}</span>
                        <span :class="classesDuCanal(canal(commande))">
                            <span aria-hidden="true">{{ icone(commande) }}</span>
                            <span class="pos-sr-only">{{ libelleCanal(commande) }}</span>
                        </span>
                        <span
                            class="pos-ctrl-ligne__age pos-v5-tabular"
                            :class="classeAgeTexte(commande)"
                        >{{ age(commande) }}</span>
                        <span class="pos-ctrl-ligne__resume">{{ resumeLigne(commande) }}</span>
                        <span
                            v-if="aEncaisser(commande)"
                            class="pos-ctrl-ligne__cloche"
                            :title="$t('pos.tracker.cash_due_label')"
                            :data-testid="`pos-control-bell-${commande.id}`"
                        >
                            <span aria-hidden="true">🔔</span>
                            <span class="pos-sr-only">{{ $t('pos.tracker.cash_due_label') }}</span>
                        </span>
                    </button>
                </template>
            </div>

            <footer class="pos-ctrl__pied">
                <!-- UNE SEULE ligne de fraîcheur, qui dit la vérité en escalade. Surtout PAS de
                     bandeau « temps réel perdu » : il n'y a aucun serveur de sockets en
                     production, il serait donc allumé toute la journée et deviendrait du papier
                     peint — défaut que le tableau de suivi a déjà dû corriger. -->
                <p
                    class="pos-ctrl__fraicheur"
                    :class="`pos-ctrl__fraicheur--${niveauFraicheur}`"
                    data-testid="pos-control-freshness"
                >
                    <template v-if="niveauFraicheur === 'figee'">
                        ⚠ {{ $t('pos.controle.donnees_figees', { n: minutesDepuisRafraichissement }) }}
                    </template>
                    <template v-else>{{ libelleFraicheur }}</template>
                </p>
                <button
                    v-if="niveauFraicheur === 'figee'"
                    type="button"
                    class="pos-ctrl__actualiser"
                    data-testid="pos-control-refresh"
                    @click="$emit('rafraichir')"
                >{{ $t('pos.controle.actualiser') }}</button>
                <button
                    type="button"
                    class="pos-ctrl__page-complete"
                    data-testid="pos-control-full-page"
                    @click="$emit('ouvrir-suivi')"
                >{{ $t('pos.controle.page_complete') }} →</button>

                <!-- Annonce SOBRE : uniquement les compteurs, phrase entière, au plus une fois
                     toutes les 10 s. Annoncer chaque carte à 5 s de cadence serait un bavardage
                     continu qui rendrait le lecteur d'écran inutilisable. -->
                <p class="pos-sr-only" aria-live="polite" data-testid="pos-control-live">{{ annonce }}</p>
            </footer>
        </aside>

        <!-- « VOIR TOUT » — le détail intégral, et le seul endroit d'où l'on peut annuler.
             « Annuler » à côté d'« Encaisser » sur une dalle tactile en coup de feu est une
             annulation par erreur qui attend son heure. -->
        <div
            v-if="detail"
            class="pos-ctrl-detail"
            role="dialog"
            aria-modal="true"
            :aria-label="$t('pos.controle.voir_tout')"
            data-testid="pos-control-detail"
            @keydown.esc.stop.prevent="detail = null"
        >
            <header class="pos-ctrl-detail__tete">
                <span class="pos-ctrl-detail__numero pos-v5-tabular">N°{{ numero(detail) }}</span>
                <span :class="classesDuCanal(canal(detail))">
                    <span aria-hidden="true">{{ icone(detail) }}</span>
                    <span class="pos-sr-only">{{ libelleCanal(detail) }}</span>
                </span>
                <button
                    type="button"
                    class="pos-ctrl__fermer"
                    :aria-label="$t('pos.controle.fermer')"
                    data-testid="pos-control-detail-close"
                    @click="detail = null"
                >✕</button>
            </header>
            <p class="pos-ctrl-detail__meta pos-v5-tabular">
                {{ $t('pos.controle.commandee_a', { heure: heure(detail) }) }} · {{ age(detail) }}
                <template v-if="detail.order_serial_no"> · {{ detail.order_serial_no }}</template>
            </p>
            <p v-if="nomClient(detail)" class="pos-ctrl-detail__client">
                👤 {{ nomClient(detail) }}<template v-if="telClient(detail)"> · {{ telClient(detail) }}</template>
            </p>
            <ul class="pos-ctrl-detail__lignes">
                <li v-for="(ligne, i) in toutesLignes(detail)" :key="i">
                    <span class="pos-ctrl-carte__produit">{{ ligne.quantity }}× {{ nomDuProduit(ligne) }}</span>
                    <span v-if="resumeComplet(ligne)" class="pos-ctrl-carte__compo">{{ resumeComplet(ligne) }}</span>
                    <span v-if="ligne.instruction" class="pos-ctrl-detail__instruction">✎ {{ ligne.instruction }}</span>
                </li>
            </ul>
            <p class="pos-ctrl-detail__total pos-v5-tabular">{{ formatPrice(montant(detail)) }}</p>
            <button
                v-if="aEncaisser(detail)"
                type="button"
                class="pos-ctrl-detail__annuler"
                data-testid="pos-control-detail-cancel"
                @click="$emit('annuler', detail); detail = null"
            >{{ $t('label.cancel') }}</button>
        </div>
    </div>
    </Teleport>
</template>

<script>
import { adminPriceMixin } from '../../../helpers/formatPrice';
import { canalDe, iconeCanal, classeCanal } from '../../../support/canalCommande';
import {
    nomProduit,
    compoAffichee,
    resumeComposition,
    lignesCompletes,
    itemsPreview,
    extraItemsCount,
    ageCourt,
    heureCourte,
    minutesEcoulees,
} from '../../../support/compositionCommande';
import { rangCuisine } from '../../../support/fileCuisine';
import { filesDeControle, estTerminale } from '../../../support/filesControle';


// Seuils d'âge : l'âge est MESURÉ, jamais prédit. Mêmes paliers que les KDS contemporains.
const AGE_AMBRE_MIN = 10;
const AGE_ROUGE_MIN = 20;

// Fraîcheur : au-delà, la ligne de pied change de ton (§B8 de la revue adverse).
const FRAICHEUR_TIEDE_MS = 30000;
const FRAICHEUR_FIGEE_MS = 90000;

// L'annonce vocale ne se répète pas plus d'une fois toutes les 10 s.
const ANNONCE_MIN_MS = 10000;

export default {
    name: 'PosControlDrawer',
    mixins: [adminPriceMixin],
    props: {
        /** Tiroir ouvert ? Le composant n'est PAS monté quand il est fermé (`v-if`). */
        open: { type: Boolean, default: false },
        /**
         * Les commandes de la JOURNÉE DE SERVICE, telles que servies par `admin/pos-order`
         * (`SimpleOrderResource`, composition compacte). Le tiroir ne fait AUCUNE requête :
         * c'est `PosComponent` qui poll, et il poll déjà.
         */
        orders: { type: Array, default: () => [] },
        /** Commandes à encaisser ANTÉRIEURES à aujourd'hui — un compteur, jamais des cartes. */
        anciennesCount: { type: Number, default: 0 },
        /** Horodatage du dernier rafraîchissement RÉUSSI (null = jamais). */
        lastRefresh: { type: Number, default: null },
        /** Incrémenté par le parent (~5 s) pour que les « il y a X min » avancent. */
        tick: { type: Number, default: 0 },
        /** Le ticket en cours, pour le bandeau qui rassure. */
        cartCount: { type: Number, default: 0 },
        cartTotal: { type: [Number, String], default: 0 },
        /** Onglet à ouvrir (« encaisser » par défaut — la seule file où l'inaction coûte). */
        initialTab: { type: String, default: 'encaisser' },
    },
    emits: ['close', 'encaisser', 'livrer', 'annuler', 'rafraichir', 'ouvrir-suivi', 'ouvrir-encaissement'],
    data() {
        return {
            ongletActif: this.initialTab,
            detail: null,
            annonce: '',
            _derniereAnnonce: 0,
        };
    },
    computed: {
        maintenant() {
            // Dépend de `tick` pour que Vue réévalue les âges à chaque battement du parent.
            return this.tick >= 0 ? Date.now() : Date.now();
        },
        /**
         * Les quatre files, lues d'UNE SEULE source : `resources/js/support/filesControle.js`.
         * Le bouton qui ouvre ce tiroir affiche les mêmes compteurs, et le ticket en cours la
         * même profondeur de cuisine — les écrire ici aurait recréé le désaccord d'origine, où
         * le badge de la caisse annonçait « 3 » à 40 px d'un tableau qui annonçait « 7 actives ».
         */
        files() {
            return filesDeControle(this.orders);
        },
        listeEncaisser() { return this.files.encaisser; },
        listeCuisine() { return this.files.cuisine; },
        listePretes() { return this.files.pretes; },
        listeLivrees() { return this.files.livrees; },
        onglets() {
            return [
                {
                    id: 'encaisser',
                    icone: '💶',
                    libelle: this.$t('pos.tracker.col_accept'),
                    sousTitre: this.$t('pos.controle.sous_titre_encaisser'),
                    vide: this.$t('pos.tracker.empty_accept'),
                    commandes: this.listeEncaisser,
                },
                {
                    id: 'cuisine',
                    icone: '🍳',
                    libelle: this.$t('pos.controle.onglet_cuisine'),
                    sousTitre: this.$t('pos.controle.sous_titre_cuisine'),
                    vide: this.$t('pos.tracker.empty_preparing'),
                    commandes: this.listeCuisine,
                },
                {
                    id: 'pretes',
                    icone: '🛎️',
                    libelle: this.$t('pos.controle.onglet_pretes'),
                    sousTitre: '',
                    vide: this.$t('pos.tracker.empty_prepared'),
                    commandes: this.listePretes,
                },
                {
                    id: 'livrees',
                    icone: '✅',
                    libelle: this.$t('pos.controle.onglet_livrees'),
                    sousTitre: '',
                    vide: this.$t('pos.tracker.empty_delivered'),
                    commandes: this.listeLivrees,
                },
            ];
        },
        ongletCourant() {
            return this.onglets.find((o) => o.id === this.ongletActif) || this.onglets[0];
        },
        sousTitreOnglet() {
            return this.ongletCourant.sousTitre;
        },
        /**
         * La densité suit la QUESTION posée par la file : identifier un client demande une carte
         * riche ; dénombrer une file demande des lignes compactes.
         */
        densiteOnglet() {
            return (this.ongletActif === 'encaisser' || this.ongletActif === 'pretes')
                ? 'riche'
                : 'compacte';
        },
        phraseTicket() {
            if (!this.cartCount) return this.$t('pos.controle.ticket_vide');
            return this.$t('pos.controle.ticket_conserve', {
                n: this.cartCount,
                articles: this.motArticles(this.cartCount),
                montant: this.formatPrice(this.cartTotal),
            });
        },
        millisecondesDepuisRafraichissement() {
            if (!this.lastRefresh) return null;
            return Math.max(0, this.maintenant - this.lastRefresh);
        },
        minutesDepuisRafraichissement() {
            const ms = this.millisecondesDepuisRafraichissement;
            return ms === null ? 0 : Math.floor(ms / 60000);
        },
        niveauFraicheur() {
            const ms = this.millisecondesDepuisRafraichissement;
            if (ms === null) return 'inconnue';
            if (ms >= FRAICHEUR_FIGEE_MS) return 'figee';
            if (ms >= FRAICHEUR_TIEDE_MS) return 'tiede';
            return 'fraiche';
        },
        libelleFraicheur() {
            const ms = this.millisecondesDepuisRafraichissement;
            if (ms === null) return '';
            const secondes = Math.floor(ms / 1000);
            if (secondes < 60) return this.$t('pos.controle.verifie_sec', { n: secondes });
            return this.$t('pos.controle.verifie_min', { n: Math.floor(secondes / 60) });
        },
    },
    watch: {
        // `immediate` : le tiroir peut naître déjà ouvert (retour d'un rendu serveur, ou test qui
        // le monte ouvert). Sans cela, la première annonce et le premier placement du focus
        // n'auraient jamais lieu dans ce cas — un défaut invisible à l'œil et bien réel au
        // lecteur d'écran.
        open: {
            immediate: true,
            handler(ouvert) {
                if (!ouvert) return;
            // Le tiroir ne MÉMORISE PAS le dernier onglet : en coup de feu, un état persistant
            // invisible est un piège (on rouvre en croyant voir la file argent, on voit les livrées).
                this.ongletActif = this.initialTab;
                this.detail = null;
                this.$nextTick(() => {
                    // Le focus va au TITRE, pas à la croix : le premier mot entendu doit être
                    // « Contrôle des commandes », pas « Fermer ».
                    if (this.$refs.titre) this.$refs.titre.focus();
                });
                this.annoncer();
            },
        },
        listeEncaisser() { this.annoncer(); },
        listePretes() { this.annoncer(); },
    },
    methods: {
        fermer() {
            if (this.detail) { this.detail = null; return; }
            this.$emit('close');
        },
        // ── Lecture d'une commande (règles PARTAGÉES avec le tableau de suivi) ──────────
        canal(commande) { return canalDe(commande); },
        icone(commande) { return iconeCanal(canalDe(commande)); },
        classesDuCanal(canal) { return classeCanal(canal); },
        libelleCanal(commande) { return this.$t(`pos.tracker.source_${canalDe(commande)}`); },
        numero(commande) {
            return commande?.queue_number || commande?.order_serial_no || commande?.id;
        },
        nomDuProduit(ligne) { return nomProduit(ligne, this.$t('label.deleted_item')); },
        compo(ligne) { return compoAffichee(ligne); },
        resumeComplet(ligne) { return resumeComposition(ligne); },
        apercuLignes(commande) { return itemsPreview(commande); },
        toutesLignes(commande) { return lignesCompletes(commande); },
        lignesEnPlus(commande) { return extraItemsCount(commande); },
        age(commande) {
            return ageCourt(commande?.created_at, this.$t('pos.tracker.now'), this.maintenant);
        },
        heure(commande) { return heureCourte(commande?.created_at); },
        nomClient(commande) {
            const nom = String(commande?.customer_name || '').trim();
            return nom || '';
        },
        telClient(commande) {
            const tel = String(commande?.customer_phone || '').trim();
            return tel || '';
        },
        montant(commande) {
            return this.aEncaisser(commande)
                ? (commande.cash_pending_amount ?? commande.total ?? 0)
                : (commande?.total ?? commande?.order_amount ?? 0);
        },
        aEncaisser(commande) {
            return commande?.is_cash_pending === true && !estTerminale(commande);
        },
        /** Résumé d'une ligne compacte : « Galette Cayenne + 1 ». */
        resumeLigne(commande) {
            const lignes = lignesCompletes(commande);
            if (!lignes.length) return '';
            const premier = `${lignes[0].quantity}× ${this.nomDuProduit(lignes[0])}`;
            const reste = lignes.length - 1;
            return reste > 0 ? `${premier} + ${reste}` : premier;
        },
        motArticles(n) {
            return n > 1 ? this.$t('pos.controle.articles') : this.$t('pos.controle.article');
        },
        // ── Âge : mesuré, jamais prédit ────────────────────────────────────────────────
        minutes(commande) {
            const m = minutesEcoulees(commande?.created_at, this.maintenant);
            return m === null ? 0 : m;
        },
        classeAgeTexte(commande) {
            const m = this.minutes(commande);
            if (m >= AGE_ROUGE_MIN) return 'pos-ctrl-age--rouge';
            if (m >= AGE_AMBRE_MIN) return 'pos-ctrl-age--ambre';
            return '';
        },
        classeAge(commande) {
            const m = this.minutes(commande);
            if (m >= AGE_ROUGE_MIN) return 'pos-ctrl-carte--rouge';
            if (m >= AGE_AMBRE_MIN) return 'pos-ctrl-carte--ambre';
            return '';
        },
        // ── Rang cuisine ───────────────────────────────────────────────────────────────
        rang(commande) { return rangCuisine(commande, this.orders); },
        ordinal(n) { return n === 1 ? '1ᵉʳ' : `${n}ᵉ`; },
        phraseRang(rang) {
            return this.$t('pos.controle.rang_cuisine', {
                rang: this.ordinal(rang.rang),
                total: rang.total,
            });
        },
        // ── Onglets, focus, annonce ────────────────────────────────────────────────────
        ongletVoisin(pas) {
            const ids = this.onglets.map((o) => o.id);
            const i = ids.indexOf(this.ongletActif);
            const suivant = ids[(i + pas + ids.length) % ids.length];
            this.ongletActif = suivant;
            this.$nextTick(() => {
                const cible = this.$refs[`onglet-${suivant}`];
                const el = Array.isArray(cible) ? cible[0] : cible;
                if (el && el.focus) el.focus();
            });
        },
        /**
         * Piège de focus. `@keydown.esc` et ce piège vivent sur la RACINE du tiroir, jamais en
         * écouteur `document` : la caisse a des champs de saisie (client, téléphone, recherche
         * produit) et un écouteur global leur volerait des touches.
         */
        piegerFocus(evenement) {
            const racine = this.$refs.tiroir;
            if (!racine) return;
            const focusables = racine.querySelectorAll(
                'button:not([disabled]), [href], input, select, textarea, [tabindex]:not([tabindex="-1"])'
            );
            if (!focusables.length) return;
            const premier = focusables[0];
            const dernier = focusables[focusables.length - 1];
            if (evenement.shiftKey && document.activeElement === premier) {
                evenement.preventDefault();
                dernier.focus();
            } else if (!evenement.shiftKey && document.activeElement === dernier) {
                evenement.preventDefault();
                premier.focus();
            }
        },
        annoncer() {
            if (!this.open) return;
            const maintenant = Date.now();
            if (maintenant - this._derniereAnnonce < ANNONCE_MIN_MS) return;
            this._derniereAnnonce = maintenant;
            this.annonce = this.$t('pos.controle.annonce', {
                encaisser: this.listeEncaisser.length,
                pretes: this.listePretes.length,
            });
        },
    },
};
</script>

<style scoped>
/* Le tiroir prend la BOÎTE du panier, élargie : 420 px comme `.kiosk-cash-panel`, dont la main
   du propriétaire connaît déjà la croix. Aucune couleur en dur — que des jetons `--pos-v5-*`
   (les panneaux raccourcis, eux, peignent avec trois jetons FANTÔMES qui n'existent nulle part
   et tombent silencieusement sur des valeurs de repli hors charte : on n'hérite pas de ça). */
.pos-ctrl-voile {
    position: fixed;
    inset: 0;
    background: rgba(26, 26, 26, 0.42);
    backdrop-filter: blur(2px);
    z-index: var(--pos-v5-z-modal, 2000);
}

.pos-ctrl {
    position: absolute;
    top: 64px;                       /* l'entête admin reste atteignable, comme #pos-cart */
    right: 12px;
    width: 420px;
    max-width: 100vw;
    height: calc(100dvh - 64px);
    display: flex;
    flex-direction: column;
    background: var(--pos-v5-bg-panel, #fff);
    border-left: 2px solid var(--pos-v5-brand-red, #F4501E);
    border-radius: var(--pos-v5-radius-xl, 20px) 0 0 var(--pos-v5-radius-xl, 20px);
    box-shadow: var(--pos-v5-shadow-modal, 0 24px 48px rgba(26, 26, 26, 0.2));
    overflow: hidden;
    animation: pos-ctrl-entree var(--pos-v5-duration-base, 220ms) cubic-bezier(0, 0, 0.2, 1);
}
@keyframes pos-ctrl-entree {
    from { transform: translateX(100%); }
    to   { transform: translateX(0); }
}

.pos-ctrl__tete {
    flex: 0 0 auto;
    height: 56px;
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 0 12px;
    border-bottom: 1px solid var(--pos-v5-border, #EEE6D9);
}
.pos-ctrl__titre {
    margin: 0;
    font-size: 17px;
    font-weight: 800;
    color: var(--pos-v5-ink, #1A1A1A);
}
.pos-ctrl__titre:focus-visible { outline: 2px solid var(--pos-v5-info, #2563EB); outline-offset: 2px; }
.pos-ctrl__fermer {
    width: 48px;
    height: 48px;
    border: 0;
    background: transparent;
    font-size: 20px;
    color: var(--pos-v5-ink-soft, #5A5A5A);
    cursor: pointer;
    border-radius: var(--pos-v5-radius-md, 12px);
}
.pos-ctrl__fermer:hover { background: var(--pos-v5-bg-subtle, #F7F3EC); }

.pos-ctrl__ticket {
    flex: 0 0 auto;
    margin: 0;
    height: 32px;
    display: flex;
    align-items: center;
    gap: 6px;
    padding: 0 12px;
    font-size: 12px;
    font-weight: 600;
    color: var(--pos-v5-ink-soft, #5A5A5A);
    background: var(--pos-v5-bg-subtle, #F7F3EC);
    border-bottom: 1px solid var(--pos-v5-border, #EEE6D9);
}

.pos-ctrl__onglets {
    flex: 0 0 auto;
    display: grid;
    grid-template-columns: repeat(4, 1fr);
    gap: 4px;
    padding: 6px 12px;
}
.pos-ctrl__onglet {
    height: 52px;                    /* ≥ 44 px de zone tactile */
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    gap: 1px;
    border: 0;
    border-bottom: 3px solid transparent;
    border-radius: var(--pos-v5-radius-md, 12px) var(--pos-v5-radius-md, 12px) 0 0;
    background: var(--pos-v5-bg-subtle, #F7F3EC);
    cursor: pointer;
    padding: 0 2px;
}
.pos-ctrl__onglet--actif {
    background: var(--pos-v5-brand-red-soft, #FFE8DD);
    border-bottom-color: var(--pos-v5-brand-red, #F4501E);
}
.pos-ctrl__onglet-haut { display: flex; align-items: center; gap: 4px; }
.pos-ctrl__compteur {
    font-size: 18px;
    font-weight: 800;
    color: var(--pos-v5-ink, #1A1A1A);
}
.pos-ctrl__onglet-libelle {
    font-size: 11px;
    font-weight: 700;
    color: var(--pos-v5-ink-soft, #5A5A5A);
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
    max-width: 100%;
}

.pos-ctrl__sous-titre {
    flex: 0 0 auto;
    margin: 0;
    padding: 4px 12px 0;
    font-size: 11px;
    color: var(--pos-v5-ink-muted, #8A8278);
}

/* La liste défile — et elle DOIT le dire. Doctrine acquise sur cet écran :
   `tests/e2e/goal-caisse-b014-defilement-decouvrable.spec.js` a documenté qu'un panneau de caisse
   qui cache 54 % de son contenu SANS aucun indice fait disparaître des commandes cliquables aux
   yeux du caissier. Deux signaux, comme là-bas : une barre de défilement qui occupe de la place
   (`scrollbar-gutter: stable` + `scrollbar-width: thin`, jamais masquée), et une ombre de bord
   haute/basse peinte en fond attaché — elle s'efface d'elle-même en haut et en bas de course. */
.pos-ctrl__liste {
    flex: 1 1 auto;
    overflow-y: auto;
    scrollbar-gutter: stable;
    scrollbar-width: thin;
    padding: 8px 12px 12px;
    display: flex;
    flex-direction: column;
    gap: 8px;
    background:
        linear-gradient(var(--pos-v5-bg-panel, #fff) 30%, rgba(255, 255, 255, 0)) top / 100% 24px no-repeat,
        linear-gradient(rgba(255, 255, 255, 0), var(--pos-v5-bg-panel, #fff) 70%) bottom / 100% 24px no-repeat,
        radial-gradient(farthest-side at 50% 0, rgba(26, 26, 26, 0.16), rgba(0, 0, 0, 0)) top / 100% 10px no-repeat,
        radial-gradient(farthest-side at 50% 100%, rgba(26, 26, 26, 0.16), rgba(0, 0, 0, 0)) bottom / 100% 10px no-repeat;
    background-attachment: local, local, scroll, scroll;
}

.pos-ctrl__vide {
    margin: 32px 0 0;
    display: flex;
    flex-direction: column;
    align-items: center;
    gap: 6px;
    text-align: center;
    font-size: 13px;
    color: var(--pos-v5-ink-soft, #5A5A5A);
}
.pos-ctrl__vide-icone { font-size: 26px; color: var(--pos-v5-success, #1B8A3A); }
.pos-ctrl__vide-date { font-size: 11px; color: var(--pos-v5-ink-muted, #8A8278); }

/* ── Carte riche ───────────────────────────────────────────────────────────────────── */
.pos-ctrl-carte {
    display: grid;
    grid-template-columns: 1fr 96px;
    gap: 8px;
    padding: 10px;
    background: var(--pos-v5-bg-panel, #fff);
    border: 1px solid var(--pos-v5-border, #EEE6D9);
    border-radius: var(--pos-v5-radius-md, 12px);
}
.pos-ctrl-carte--ambre { border-color: var(--pos-v5-warning, #B8730B); }
.pos-ctrl-carte--rouge { border-color: var(--pos-v5-danger, #C21E2F); }

.pos-ctrl-carte__corps {
    display: flex;
    flex-direction: column;
    align-items: flex-start;
    gap: 3px;
    text-align: left;
    border: 0;
    background: transparent;
    padding: 0;
    cursor: pointer;
    min-width: 0;
    width: 100%;
}
.pos-ctrl-carte__entete { display: flex; align-items: center; gap: 6px; flex-wrap: wrap; }
.pos-ctrl-carte__numero { font-size: 22px; font-weight: 900; color: var(--pos-v5-ink, #1A1A1A); line-height: 1.1; }
.pos-ctrl-carte__age { font-size: 12px; font-weight: 600; color: var(--pos-v5-ink-muted, #8A8278); }
.pos-ctrl-age--ambre { color: var(--pos-v5-warning, #B8730B); font-weight: 800; }
.pos-ctrl-age--rouge { color: var(--pos-v5-danger, #C21E2F); font-weight: 800; }
.pos-ctrl-carte__client { font-size: 15px; font-weight: 700; color: var(--pos-v5-ink, #1A1A1A); }
.pos-ctrl-carte__tel { font-size: 13px; font-weight: 600; color: var(--pos-v5-brand-red, #F4501E); }
.pos-ctrl-carte__lignes { display: flex; flex-direction: column; gap: 1px; width: 100%; }
.pos-ctrl-carte__ligne { display: flex; flex-direction: column; }
.pos-ctrl-carte__produit { font-size: 13px; font-weight: 700; color: var(--pos-v5-ink, #1A1A1A); }
.pos-ctrl-carte__compo { font-size: 11px; font-weight: 500; color: var(--pos-v5-ink-soft, #5A5A5A); padding-left: 16px; }
.pos-ctrl-carte__reste { font-size: 11px; font-weight: 600; font-style: italic; color: var(--pos-v5-ink-muted, #8A8278); }
.pos-ctrl-carte__rang {
    font-size: 11px;
    font-weight: 700;
    color: var(--pos-v5-info, #2563EB);
    background: var(--pos-v5-info-soft, #EFF6FF);
    border-radius: 6px;
    padding: 2px 6px;
}
.pos-ctrl-carte__heure { font-size: 11px; color: var(--pos-v5-ink-muted, #8A8278); }

.pos-ctrl-carte__agir {
    display: flex;
    flex-direction: column;
    align-items: flex-end;
    justify-content: space-between;
    gap: 8px;
}
.pos-ctrl-carte__montant { font-size: 18px; font-weight: 800; color: var(--pos-v5-ink, #1A1A1A); white-space: nowrap; }
.pos-ctrl-carte__montant--du { color: var(--pos-v5-brand-red, #F4501E); }

.pos-ctrl-cta {
    min-height: var(--pos-v5-tap-comfort, 48px);
    width: 100%;
    border: 0;
    border-radius: var(--pos-v5-radius-md, 12px);
    font-size: 13px;
    font-weight: 800;
    color: #fff;
    cursor: pointer;
    padding: 0 6px;
}
.pos-ctrl-cta--encaisser { background: var(--pos-v5-brand-red, #F4501E); box-shadow: var(--pos-v5-shadow-cta, none); }
.pos-ctrl-cta--livrer { background: var(--pos-v5-success, #1B8A3A); }
.pos-ctrl-cta:disabled { opacity: 0.55; cursor: default; }

.pos-ctrl__anciennes {
    display: flex;
    align-items: center;
    gap: 8px;
    min-height: var(--pos-v5-tap-comfort, 48px);
    padding: 0 10px;
    border: 1px dashed var(--pos-v5-warning, #B8730B);
    border-radius: var(--pos-v5-radius-md, 12px);
    background: transparent;
    cursor: pointer;
    text-align: left;
}
.pos-ctrl__anciennes-texte { flex: 1 1 auto; font-size: 12px; font-weight: 700; color: var(--pos-v5-warning, #B8730B); }
.pos-ctrl__anciennes-lien { font-size: 11px; font-weight: 700; color: var(--pos-v5-ink-soft, #5A5A5A); white-space: nowrap; }

/* ── Ligne compacte ────────────────────────────────────────────────────────────────── */
.pos-ctrl-ligne {
    display: flex;
    align-items: center;
    gap: 8px;
    min-height: 48px;
    padding: 0 8px;
    border: 1px solid var(--pos-v5-border, #EEE6D9);
    border-radius: var(--pos-v5-radius-md, 12px);
    background: var(--pos-v5-bg-subtle, #F7F3EC);
    cursor: pointer;
    text-align: left;
    width: 100%;
}
.pos-ctrl-ligne__rang { font-size: 16px; font-weight: 900; color: var(--pos-v5-ink, #1A1A1A); min-width: 30px; }
.pos-ctrl-ligne__numero { font-size: 15px; font-weight: 800; color: var(--pos-v5-ink, #1A1A1A); }
.pos-ctrl-ligne__age { font-size: 13px; font-weight: 700; color: var(--pos-v5-ink-soft, #5A5A5A); }
.pos-ctrl-ligne__resume {
    flex: 1 1 auto;
    min-width: 0;
    font-size: 12px;
    color: var(--pos-v5-ink-soft, #5A5A5A);
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
}
.pos-ctrl-ligne__cloche { font-size: 14px; }

/* ── Pied ──────────────────────────────────────────────────────────────────────────── */
.pos-ctrl__pied {
    flex: 0 0 auto;
    display: flex;
    align-items: center;
    gap: 8px;
    padding: 8px 12px;
    border-top: 1px solid var(--pos-v5-border, #EEE6D9);
    background: var(--pos-v5-bg-subtle, #F7F3EC);
}
.pos-ctrl__fraicheur { flex: 1 1 auto; margin: 0; font-size: 11px; color: var(--pos-v5-ink-muted, #8A8278); }
.pos-ctrl__fraicheur--tiede { color: var(--pos-v5-warning, #B8730B); }
.pos-ctrl__fraicheur--figee { color: var(--pos-v5-danger, #C21E2F); font-weight: 700; }
.pos-ctrl__actualiser,
.pos-ctrl__page-complete {
    min-height: var(--pos-v5-tap-comfort, 48px);
    padding: 0 10px;
    border: 1px solid var(--pos-v5-border-strong, #D9C9B8);
    border-radius: var(--pos-v5-radius-md, 12px);
    background: var(--pos-v5-bg-panel, #fff);
    font-size: 12px;
    font-weight: 700;
    color: var(--pos-v5-ink, #1A1A1A);
    cursor: pointer;
    white-space: nowrap;
}

/* ── « Voir tout » ─────────────────────────────────────────────────────────────────── */
.pos-ctrl-detail {
    position: absolute;
    top: 64px;
    right: 12px;
    width: 420px;
    max-width: 100vw;
    max-height: calc(100dvh - 80px);
    overflow-y: auto;
    padding: 12px;
    background: var(--pos-v5-bg-panel, #fff);
    border: 2px solid var(--pos-v5-brand-red, #F4501E);
    border-radius: var(--pos-v5-radius-xl, 20px);
    box-shadow: var(--pos-v5-shadow-modal, 0 24px 48px rgba(26, 26, 26, 0.2));
}
.pos-ctrl-detail__tete { display: flex; align-items: center; gap: 8px; }
.pos-ctrl-detail__numero { flex: 1 1 auto; font-size: 22px; font-weight: 900; color: var(--pos-v5-ink, #1A1A1A); }
.pos-ctrl-detail__meta { margin: 4px 0 0; font-size: 12px; color: var(--pos-v5-ink-muted, #8A8278); }
.pos-ctrl-detail__client { margin: 6px 0 0; font-size: 14px; font-weight: 700; color: var(--pos-v5-ink, #1A1A1A); }
.pos-ctrl-detail__lignes { list-style: none; margin: 10px 0 0; padding: 0; display: flex; flex-direction: column; gap: 8px; }
.pos-ctrl-detail__lignes li { display: flex; flex-direction: column; }
.pos-ctrl-detail__instruction { font-size: 11px; font-weight: 600; color: var(--pos-v5-warning, #B8730B); padding-left: 16px; }
.pos-ctrl-detail__total { margin: 10px 0 0; font-size: 20px; font-weight: 800; text-align: right; color: var(--pos-v5-ink, #1A1A1A); }
.pos-ctrl-detail__annuler {
    margin-top: 10px;
    width: 100%;
    min-height: var(--pos-v5-tap-comfort, 48px);
    border: 1px solid var(--pos-v5-danger, #C21E2F);
    border-radius: var(--pos-v5-radius-md, 12px);
    background: transparent;
    color: var(--pos-v5-danger, #C21E2F);
    font-size: 13px;
    font-weight: 800;
    cursor: pointer;
}

@media (max-width: 480px) {
    .pos-ctrl, .pos-ctrl-detail { right: 0; width: 100vw; border-radius: 0; }
}
@media (prefers-reduced-motion: reduce) {
    .pos-ctrl { animation: none; }
}
</style>

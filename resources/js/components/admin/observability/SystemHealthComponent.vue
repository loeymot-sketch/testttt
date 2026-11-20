<template>
    <!--
        SystemHealthComponent — [PILOTAGE 2026-08-09]

        « Est-ce que ça va ? » — la réponse en un écran.

        Le logiciel SE SURVEILLAIT déjà : healthz:check contrôle cinq
        sous-systèmes chaque minute, la sauvegarde tourne à 3 h, une restauration
        de vérification à 5 h. Rien n'était visible : l'administration n'avait
        qu'un seul écran d'observabilité, la file d'expédition. Le système
        savait, et ne le disait pas.

        Un seul appel, qui n'invente aucune mesure :
            GET /api/admin/observability/system-health

        L'écran est conçu pour les pannes SILENCIEUSES — celles qui ne lèvent
        aucune erreur et qu'on découvre des semaines plus tard : un planificateur
        arrêté, une sauvegarde qui ne se fait plus, une file qui gonfle.
    -->
    <section class="space-y-4" data-testid="system-health" :aria-busy="chargement">
        <header class="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
            <div>
                <h2 class="text-lg font-semibold text-slate-800">État du système</h2>
                <p class="mt-1 text-sm text-slate-600">
                    Ce que le logiciel sait de lui-même, en un coup d'œil.
                </p>
            </div>
            <div class="flex items-center gap-3">
                <span v-if="mesureLe" class="text-xs text-slate-500" data-testid="system-health-mesure">
                    mesuré {{ mesureLe }}
                </span>
                <button
                    type="button"
                    class="rounded-md border border-slate-300 px-3 py-1.5 text-sm text-slate-700 hover:bg-slate-50"
                    :disabled="chargement"
                    data-testid="system-health-refresh"
                    @click="charger"
                >
                    Actualiser
                </button>
            </div>
        </header>

        <!-- Verdict : une seule phrase, lisible sans connaissance technique. -->
        <div
            class="rounded-lg border p-4"
            :class="verdictOk ? 'border-emerald-300 bg-emerald-50' : 'border-amber-400 bg-amber-50'"
            role="status"
            aria-live="polite"
            data-testid="system-health-verdict"
        >
            <p class="font-semibold" :class="verdictOk ? 'text-emerald-800' : 'text-amber-900'">
                {{ verdictOk ? 'Tout va bien.' : 'Des points demandent votre attention.' }}
            </p>
            <ul v-if="etat.alertes && etat.alertes.length" class="mt-2 list-disc space-y-1 pl-5 text-sm text-amber-900">
                <li v-for="(a, i) in etat.alertes" :key="i" data-testid="system-health-alerte">{{ a }}</li>
            </ul>
        </div>

        <!-- Les cinq contrôles, nommés en français : « fiscal_chain » ne parle à personne. -->
        <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-5">
            <div
                v-for="c in controles"
                :key="c.cle"
                class="rounded-lg border border-slate-200 bg-white p-3"
                :data-testid="`system-health-controle-${c.cle}`"
            >
                <p class="text-xs uppercase tracking-wide text-slate-500">{{ c.libelle }}</p>
                <p class="mt-1 font-semibold" :class="c.ok ? 'text-emerald-700' : 'text-red-700'">
                    {{ c.valeur }}
                </p>
                <p class="mt-1 text-xs text-slate-500">{{ c.explication }}</p>
            </div>
        </div>

        <div class="grid gap-3 sm:grid-cols-2">
            <!-- La fraîcheur compte, pas le nombre : dix sauvegardes d'un mois ne valent rien. -->
            <div class="rounded-lg border border-slate-200 bg-white p-4" data-testid="system-health-sauvegarde">
                <p class="text-xs uppercase tracking-wide text-slate-500">Dernière sauvegarde</p>
                <p class="mt-1 text-lg font-semibold" :class="sauvegardeOk ? 'text-emerald-700' : 'text-red-700'">
                    {{ sauvegardeTexte }}
                </p>
                <p class="mt-1 truncate text-xs text-slate-500">
                    {{ etat.sauvegarde && etat.sauvegarde.dernier_fichier ? etat.sauvegarde.dernier_fichier : 'aucun fichier trouvé' }}
                </p>
                <p
                    class="mt-2 text-xs font-medium"
                    :class="restaurationOk ? 'text-emerald-700' : 'text-red-700'"
                    data-testid="system-health-restauration"
                >
                    {{ restaurationTexte }}
                </p>
                <p class="mt-2 text-xs text-slate-500">
                    Vert seulement si un fichier <code>.sql.gz</code> a moins de
                    26 h <em>et</em> si la restauration de vérification de 5 h a
                    réellement remonté cette sauvegarde. Un fichier récent qu'on
                    n'a jamais réussi à restaurer ne protège de rien.
                </p>
            </div>

            <!-- Le point le plus traître : s'il s'arrête, tout s'arrête EN SILENCE. -->
            <div class="rounded-lg border border-slate-200 bg-white p-4" data-testid="system-health-planificateur">
                <p class="text-xs uppercase tracking-wide text-slate-500">Tâches automatiques</p>
                <p class="mt-1 text-lg font-semibold" :class="planificateurOk ? 'text-emerald-700' : 'text-red-700'">
                    {{ planificateurTexte }}
                </p>
                <p class="mt-2 text-xs text-slate-500">
                    S'il s'arrête, les sauvegardes, les relances et le contrôle de la
                    chaîne fiscale s'arrêtent avec lui, sans aucune erreur visible.
                </p>
            </div>
        </div>

        <!-- Voir ET agir au même endroit : un écran d'état sans levier oblige à
             appeler quelqu'un, ce qui est précisément ce qu'on veut éviter. -->
        <div class="rounded-lg border border-slate-200 bg-white p-4" data-testid="system-interrupteurs">
            <h3 class="text-sm font-semibold uppercase tracking-wide text-slate-500">Interrupteurs</h3>
            <!-- [G3 2026-09-03 · T3.4 · défaut V-15] Ce texte disait « journal serveur »,
                 c'est-à-dire un fichier rotaté et purgeable. La bascule écrit en réalité
                 dans `audit_logs` : chaîné HMAC, append-only, suppression refusée par
                 déclencheur SQL. Sous-vendre une trace opposable en fait douter le jour
                 où on en a besoin. Ne pas sur-vendre non plus : ce n'est pas une écriture
                 fiscale au sens du ticket Z. -->
            <p class="mt-1 text-xs text-slate-500">
                Prise en compte immédiate, sans mise en ligne. Chaque bascule est consignée dans le
                journal d'audit métier (audit_logs), signé en chaîne et non modifiable — ce n'est pas
                une écriture fiscale NF525 au sens du ticket Z.
            </p>
            <ul class="mt-3 divide-y divide-slate-100">
                <li
                    v-for="i in interrupteurs"
                    :key="i.nom"
                    class="flex items-start justify-between gap-4 py-3"
                    :data-testid="`interrupteur-${i.nom}`"
                >
                    <div class="min-w-0">
                        <p class="font-medium text-slate-800">{{ i.libelle }}</p>
                        <p class="text-sm text-slate-600">{{ i.description }}</p>
                        <p class="mt-0.5 text-xs text-slate-500">{{ i.consequence }}</p>
                    </div>
                    <button
                        type="button"
                        class="shrink-0 rounded-md px-3 py-1.5 text-sm font-medium"
                        :class="i.actif
                            ? 'bg-emerald-600 text-white hover:bg-emerald-700'
                            : 'border border-slate-300 text-slate-700 hover:bg-slate-50'"
                        :disabled="bascule === i.nom"
                        :aria-pressed="i.actif ? 'true' : 'false'"
                        :data-testid="`interrupteur-bouton-${i.nom}`"
                        @click="basculer(i)"
                    >
                        {{ i.actif ? 'Activé' : 'Désactivé' }}
                    </button>
                </li>
            </ul>
        </div>

        <!--
            [G3 · complément superviseur 2026-09-03] `role="alert"` : ce message apparaît APRÈS
            une action de l'exploitant. Sans annonce, un lecteur d'écran ne dit jamais que la
            bascule a échoué — et l'écran refuse (à raison) d'inverser le bouton, donc il ne
            reste RIEN pour signaler l'échec.
        -->
        <p
            v-if="messageErreur"
            class="text-sm text-red-700"
            role="alert"
            data-testid="system-health-erreur"
        >{{ messageErreur }}</p>
    </section>
</template>

<script>
import axios from 'axios';

const LIBELLES = {
    db: ['Base de données', 'Là où sont les commandes et la carte.'],
    redis: ['Cache', "Accélère l'affichage ; sa panne ralentit tout."],
    websocket: ['Temps réel', 'Ce qui pousse les commandes vers la cuisine.'],
    fiscal_chain: ['Chaîne fiscale', 'Intégrité NF525 des écritures.'],
    queue_pending: ["File d'attente", 'Messages en attente de traitement.'],
};

export default {
    name: 'SystemHealthComponent',
    data() {
        // [G3 · complément superviseur 2026-09-03] DEUX chaînes, pas une.
        //
        // `erreur` porte les échecs de LECTURE : le sondage a le droit de l'effacer, puisqu'une
        // lecture qui repasse rend l'ancien rouge périmé.
        // `erreurAction` porte les échecs de BASCULE : le sondage n'a PAS le droit d'y toucher.
        // Avec une chaîne unique, `charger()` remettait tout à `null` au tic suivant : l'échec
        // s'affichait une seconde puis disparaissait, le bouton restait sur son ancien état, et
        // l'exploitant concluait que son clic n'avait pas été pris.
        return { etat: {}, interrupteurs: [], bascule: null, chargement: false, erreur: null, erreurAction: null };
    },
    computed: {
        /**
         * [G3 · complément superviseur 2026-09-03] L'échec d'une ACTION passe devant l'échec
         * d'une lecture : c'est celui que l'exploitant vient de provoquer, et le seul qu'il
         * peut corriger. Il ne s'efface qu'à la tentative suivante, jamais au tic du sondage.
         */
        messageErreur() {
            return this.erreurAction || this.erreur;
        },
        verdictOk() {
            return this.etat.verdict === 'ok';
        },
        mesureLe() {
            if (!this.etat.mesure_le) return null;
            const d = new Date(this.etat.mesure_le);
            return Number.isNaN(d.getTime()) ? null : d.toLocaleString('fr-FR');
        },
        controles() {
            return Object.entries(this.etat.controles || {}).map(([cle, v]) => {
                const [libelle, explication] = LIBELLES[cle] || [cle, ''];
                // La file est un NOMBRE, pas un état : 0 est bon, 900 ne l'est pas.
                const estFile = cle === 'queue_pending';
                const fileInconnue = estFile && v === 'unknown';
                return {
                    cle,
                    libelle,
                    explication,
                    valeur: fileInconnue
                        ? 'mesure impossible'
                        : (estFile ? `${v} en attente` : (v === 'ok' ? 'en service' : String(v))),
                    ok: fileInconnue ? false : (estFile ? Number(v) <= 50 : v === 'ok'),
                };
            });
        },
        // [2026-09-02 · Codex P1-A] Le verdict de la restauration de vérification.
        // Absent = `unknown` : ne JAMAIS traiter l'absence de preuve comme une preuve.
        restauration() {
            const s = this.etat.sauvegarde;
            return (s && s.restauration) || { status: 'unknown', reasons: [] };
        },
        restaurationOk() {
            return this.restauration.status === 'green';
        },
        restaurationTexte() {
            const r = this.restauration;
            const raison = Array.isArray(r.reasons) && r.reasons.length ? ` : ${r.reasons[0]}` : '';
            if (r.status === 'green') {
                const age = r.age_hours === null || r.age_hours === undefined
                    ? ''
                    : ` il y a ${r.age_hours < 1 ? "moins d'une heure" : `${Math.round(r.age_hours)} h`}`;
                const duree = r.duration_s ? ` (${Math.round(r.duration_s)} s)` : '';
                return `Restauration de vérification réussie${age}${duree}`;
            }
            if (r.status === 'failed') {
                return `Restauration de vérification ÉCHOUÉE${raison}`;
            }
            if (r.status === 'stale') {
                // [G4 2026-09-03 · T4.3] Le seuil est passé à 26 h : compter en jours en
                // dessous de 48 h affichait « depuis 1 jours » pour un drill de 27 h.
                const h = Number(r.age_hours || 0);
                return h < 48
                    ? `Restauration de vérification non rejouée depuis ${Math.round(h)} h`
                    : `Restauration de vérification non rejouée depuis ${Math.round(h / 24)} jours`;
            }
            // [G4 2026-09-03 · T4.1 · défaut V-08] Le drill a réussi — sur un AUTRE fichier.
            // L'écran ne doit plus dire « cette sauvegarde a été remontée » quand il ne le
            // sait pas : il dit l'écart, que le serveur a déjà nommé.
            if (r.status === 'autre_fichier') {
                return `Restauration de vérification NON RAPPROCHÉE de cette sauvegarde${raison}`;
            }
            return "Restauration de vérification jamais mesurée — une sauvegarde non restaurée ne prouve rien";
        },
        // Le fichier ET sa restauration. Une sauvegarde de 2 h corrompue s'affichait
        // en vert : seule la date du fichier était lue.
        //
        // [G4 2026-09-03 · T4.4 · défaut N-01] La carte ne RECALCULE plus la fraîcheur.
        // Elle lisait la valeur publiée, arrondie à l'heure : à 26 h 20 le serveur envoyait
        // 26, la carte concluait « 26 <= 26 » donc VERT — au-dessus d'une bande d'alertes
        // du même écran qui disait que la sauvegarde était en retard. Le serveur décide
        // (`fraiche`), l'écran affiche. Et un verdict ABSENT n'est pas un verdict
        // favorable : sans la clé, la carte reste rouge.
        sauvegardeOk() {
            const s = this.etat.sauvegarde;
            return !!s && s.fraiche === true && this.restaurationOk;
        },
        sauvegardeTexte() {
            const s = this.etat.sauvegarde;
            if (this.erreur && !s) return 'mesure indisponible';
            if (!s || s.age_heures === null || s.age_heures === undefined) return 'aucune';
            // L'âge est publié en décimal (26.33) pour que le seuil soit vérifiable ;
            // l'arrondi n'a plus lieu qu'ICI, à l'affichage, où il ne décide de rien.
            const h = Number(s.age_heures);
            if (h < 1) return "à l'instant";
            if (h < 48) return `il y a ${Math.round(h)} h`;
            return `il y a ${Math.round(h / 24)} jours`;
        },
        planificateurOk() {
            const p = this.etat.planificateur;
            return !!p && p.dernier_battement_min !== null && p.dernier_battement_min <= p.attendu_max_min;
        },
        planificateurTexte() {
            const p = this.etat.planificateur;
            if (this.erreur && !p) {
                return 'mesure indisponible';
            }
            if (!p || p.dernier_battement_min === null || p.dernier_battement_min === undefined) {
                return 'aucun signe de vie';
            }
            // Les MOTS doivent dire la même chose que la COULEUR. « muettes depuis
            // 7 min » écrit en vert, c'est un écran de supervision qui se
            // contredit — et on cesse vite de regarder un écran qui se contredit.
            // En deçà du seuil, le battement est normal : on le dit normal.
            if (p.dernier_battement_min > p.attendu_max_min) {
                return `muettes depuis ${p.dernier_battement_min} min`;
            }
            return p.dernier_battement_min <= 1
                ? 'actives'
                : `actives (dernier passage il y a ${p.dernier_battement_min} min)`;
        },
    },
    mounted() {
        this.charger();
        this.chargerInterrupteurs();
        // Un écran d'état qui fige est pire que pas d'écran : il rassure à tort.
        this.minuteur = setInterval(this.charger, 60000);
    },
    beforeUnmount() {
        if (this.minuteur) clearInterval(this.minuteur);
    },
    methods: {
        async chargerInterrupteurs() {
            try {
                const { data } = await axios.get('/admin/observability/interrupteurs');
                this.interrupteurs = data.data || [];
            } catch (e) {
                this.erreur = this.erreur || "Impossible de lire les interrupteurs.";
            }
        },
        async basculer(i) {
            const verbe = i.actif ? 'désactiver' : 'activer';
            if (typeof window !== 'undefined' && typeof window.confirm === 'function') {
                const ok = window.confirm(
                    `Confirmer : ${verbe} « ${i.libelle} » ? ${i.consequence || ''}`
                );
                if (!ok) {
                    return;
                }
            }
            this.bascule = i.nom;
            this.erreurAction = null;
            try {
                const { data } = await axios.put(`/admin/observability/interrupteurs/${i.nom}`, { actif: !i.actif });
                this.interrupteurs = data.data || this.interrupteurs;
            } catch (e) {
                // Ne PAS inverser l'affichage sur un échec : montrer « Activé »
                // alors que rien n'a changé est pire que de ne rien montrer.
                this.erreurAction = "La bascule n'a pas pu être enregistrée.";
            } finally {
                this.bascule = null;
            }
        },
        async charger() {
            this.chargement = true;
            this.erreur = null;
            try {
                const { data } = await axios.get('/admin/observability/system-health');
                this.etat = data || {};
            } catch (e) {
                // Ne pas garder l'ancien état affiché : il ferait croire que tout va bien.
                this.etat = {};
                this.erreur = "Impossible de lire l'état du système.";
            } finally {
                this.chargement = false;
            }
        },
    },
};
</script>

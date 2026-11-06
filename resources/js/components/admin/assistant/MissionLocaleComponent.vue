<template>
    <section class="space-y-4" data-testid="assistant-mission">
        <header class="rounded border border-neutral-200 bg-white p-5 shadow-sm">
            <p class="text-xs font-semibold uppercase tracking-wide text-rose-700">FoodKing V1</p>
            <h1 class="mt-1 text-xl font-semibold text-slate-900">
                {{ $t('label.assistant_mission_title') }}
            </h1>
            <p class="mt-1 text-sm text-slate-500">
                {{ $t('label.assistant_mission_subtitle') }}
            </p>
        </header>

        <!--
            LE FIL. Chaque échange reste affiché : le commerçant doit pouvoir relire ce
            qu'il a demandé et ce qui a été fait, sans recharger la page.
        -->
        <div class="rounded border border-neutral-200 bg-white shadow-sm">
            <div class="max-h-[28rem] space-y-3 overflow-y-auto p-4" data-testid="assistant-fil">
                <p v-if="fil.length === 0" class="text-sm text-slate-500" data-testid="assistant-vide">
                    {{ $t('label.assistant_mission_vide') }}
                </p>

                <div v-for="(message, index) in fil" :key="index">
                    <!-- Ce que le commerçant a écrit -->
                    <p v-if="message.role === 'commercant'" class="text-right">
                        <span class="inline-block rounded-lg bg-rose-700 px-3 py-2 text-sm text-white">
                            {{ message.texte }}
                        </span>
                    </p>

                    <!-- Ce que l'assistant répond -->
                    <div v-else class="rounded-lg bg-slate-50 p-3">
                        <p class="whitespace-pre-line text-sm text-slate-700">{{ message.texte }}</p>

                        <!--
                            LE PLAN, avant toute écriture. C'est la moitié qui compte :
                            une mission touche cinquante produits d'un coup, et
                            « j'ai ajouté la sauce à vos 47 tacos » n'est pas
                            rattrapable en un clic.
                        -->
                        <div v-if="message.plan" class="mt-3">
                            <table
                                v-if="message.plan.changements.length"
                                class="w-full text-sm"
                                data-testid="assistant-plan"
                            >
                                <thead class="text-left text-xs uppercase text-slate-500">
                                    <tr>
                                        <th class="py-1">{{ $t('label.item') }}</th>
                                        <th class="py-1">{{ $t('label.assistant_avant') }}</th>
                                        <th class="py-1">{{ $t('label.assistant_apres') }}</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr v-for="ligne in message.plan.changements" :key="ligne.id" class="border-t">
                                        <td class="py-1">{{ ligne.produit }}</td>
                                        <td class="py-1 text-slate-500">{{ ligne.avant }}</td>
                                        <td class="py-1 font-semibold text-slate-900">{{ ligne.apres }}</td>
                                    </tr>
                                </tbody>
                            </table>

                            <!--
                                Les ÉCARTÉS sont affichés au même titre. Un plan qui cache
                                ses exclusions ment par omission : le commerçant croirait
                                avoir couvert toute sa catégorie.
                            -->
                            <p
                                v-if="message.plan.ecartes.length"
                                class="mt-2 text-xs text-slate-500"
                                data-testid="assistant-ecartes"
                            >
                                {{ $t('label.assistant_ecartes') }}
                                <span v-for="(e, i) in message.plan.ecartes" :key="i">
                                    {{ e.produit }} ({{ e.raison }}){{ i < message.plan.ecartes.length - 1 ? ' · ' : '' }}
                                </span>
                            </p>

                            <button
                                v-if="message.plan.applicable && !message.applique"
                                type="button"
                                class="db-btn mt-3 bg-primary py-2 px-4 text-white"
                                data-testid="assistant-confirmer"
                                :disabled="occupe"
                                @click="confirmer(message)"
                            >
                                {{ $t('label.assistant_confirmer') }}
                            </button>
                        </div>

                        <!-- Ce que l'assistant sait faire, quand il n'a pas compris -->
                        <ul v-if="message.formes" class="mt-2 space-y-1 text-xs text-slate-500">
                            <li v-for="(forme, i) in message.formes" :key="i">· {{ forme }}</li>
                        </ul>
                    </div>
                </div>
            </div>

            <form class="flex gap-2 border-t p-4" @submit.prevent="envoyer">
                <input
                    v-model="phrase"
                    type="text"
                    maxlength="300"
                    class="db-field-control flex-1"
                    data-testid="assistant-saisie"
                    :placeholder="$t('label.assistant_mission_exemple')"
                    :disabled="occupe"
                />
                <button
                    type="submit"
                    class="db-btn bg-primary py-2 px-4 text-white"
                    data-testid="assistant-envoyer"
                    :disabled="occupe || !phrase.trim()"
                >
                    {{ $t('label.assistant_envoyer') }}
                </button>
            </form>
        </div>
    </section>
</template>

<script>
// [ONB-04 2026-08-28] `axios.defaults.baseURL` vaut déjà « <hôte>/api »
// (`shared/axios-setup.js:75`) : les URL s'écrivent SANS préfixe. Les redoubler
// donne `/api/api/…` et un 404 silencieux — le défaut qui avait rendu l'écran
// d'import de carte entièrement mort.
import axios from "axios";

/**
 * [ONB-04 2026-08-28] L'assistant de missions locales.
 *
 * Le mandat le demande en toutes lettres : « chatbot de missions locales sur le
 * profil », avec pour exemple « ajoute une sauce à tous les tacos ».
 *
 * DEUX TEMPS, JAMAIS UN. `lecture` comprend et propose un plan sans rien écrire ;
 * `application` refait le plan depuis la phrase et l'exécute, après confirmation.
 * Le plan n'est jamais renvoyé au serveur : il est recalculé là-bas, pour qu'un
 * diff trafiqué en route ne puisse pas faire écrire n'importe quoi sous couvert
 * d'une confirmation humaine.
 */
export default {
    name: "MissionLocaleComponent",
    data() {
        return {
            phrase: "",
            fil: [],
            occupe: false,
        };
    },
    methods: {
        async envoyer() {
            const demande = this.phrase.trim();
            if (!demande || this.occupe) return;

            this.fil.push({ role: "commercant", texte: demande });
            this.phrase = "";
            this.occupe = true;

            try {
                const { data } = await axios.post("admin/assistant/mission/lecture", {
                    phrase: demande,
                });

                if (!data.compris) {
                    this.fil.push({
                        role: "assistant",
                        texte: data.reponse,
                        formes: data.formes,
                    });
                    return;
                }

                const plan = data.plan;

                this.fil.push({
                    role: "assistant",
                    texte: plan.avertissement || plan.resume,
                    plan: plan,
                    phrase: demande,
                    applique: false,
                });
            } catch (erreur) {
                this.fil.push({
                    role: "assistant",
                    texte: this.$t("label.assistant_erreur"),
                });
                console.error("[assistant] lecture", erreur);
            } finally {
                this.occupe = false;
            }
        },

        async confirmer(message) {
            this.occupe = true;

            try {
                const { data } = await axios.post("admin/assistant/mission/application", {
                    // On renvoie LA PHRASE, pas le plan : le serveur le refait. Un plan
                    // reçu du navigateur pourrait avoir été modifié en route.
                    phrase: message.phrase,
                    confirmation: true,
                });

                message.applique = true;

                this.fil.push({
                    role: "assistant",
                    texte: data.rapport.resume,
                });

                if (data.rapport.echecs && data.rapport.echecs.length) {
                    this.fil.push({
                        role: "assistant",
                        texte: data.rapport.echecs
                            .map((e) => `${e.produit} : ${e.raison}`)
                            .join("\n"),
                    });
                }
            } catch (erreur) {
                this.fil.push({
                    role: "assistant",
                    texte: this.$t("label.assistant_erreur"),
                });
                console.error("[assistant] application", erreur);
            } finally {
                this.occupe = false;
            }
        },
    },
};
</script>

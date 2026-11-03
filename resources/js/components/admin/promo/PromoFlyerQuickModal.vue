<template>
    <div v-if="open" class="pfq-backdrop" @click.self="close" @keydown.esc="close">
        <div class="pfq-card" role="dialog" aria-modal="true" :aria-label="$t('label.promo_flyer')">
            <div class="pfq-head">
                <h3>🎟️ {{ $t("label.promo_flyer_short") }}</h3>
                <button type="button" class="pfq-x" @click="close" :aria-label="$t('button.close')">✕</button>
            </div>

            <div class="pfq-body">
                <!-- Résultat : affiché EN GRAND et en premier. Si le papier ne
                     sort pas, l'exploitant doit pouvoir dicter le code au client. -->
                <div v-if="lastCode" class="pfq-done">
                    <div class="pfq-done-label">{{ $t("label.flyer_sent_to_pos") }}</div>
                    <div class="pfq-code">{{ lastCode }}</div>
                    <div class="pfq-done-offer">{{ offerLabel(lastOffer) }}</div>
                    <button type="button" class="pfq-again" @click="reset">
                        {{ $t("button.print_another") }}
                    </button>
                </div>

                <div v-else-if="duplicate" class="pfq-dup">
                    <div class="pfq-dup-label">{{ $t("label.flyer_duplicate_warning") }}</div>
                    <div class="pfq-code">{{ duplicate.code }}</div>
                    <div class="pfq-dup-sub">{{ duplicate.customer_name }}</div>
                    <button type="button" class="pfq-again" @click="reset">
                        {{ $t("button.close") }}
                    </button>
                    <button type="button" class="pfq-dup-force" @click="submit(true)">
                        {{ $t("button.create_anyway") }}
                    </button>
                </div>

                <form v-else @submit.prevent="submit(false)">
                    <!-- [TICKET PROMO 2026-11-03 · propriétaire] Deux offres au choix : la remise
                         historique ou la livraison offerte. Le dernier choix est retenu sur cet
                         appareil : une campagne dure un service, pas un ticket. -->
                    <div class="pfq-label">{{ $t("label.flyer_offer") }}</div>
                    <div class="pfq-offer" role="radiogroup" :aria-label="$t('label.flyer_offer')">
                        <button
                            v-for="o in offers"
                            :key="o.value"
                            type="button"
                            role="radio"
                            :aria-checked="offer === o.value"
                            class="pfq-offer-btn"
                            :class="{ 'pfq-offer-btn--on': offer === o.value }"
                            @click="chooseOffer(o.value)"
                        >{{ o.label }}</button>
                    </div>

                    <label class="pfq-label" for="pfq-name">{{ $t("label.customer_first_name") }}</label>
                    <input
                        id="pfq-name"
                        ref="nameInput"
                        v-model="name"
                        type="text"
                        maxlength="60"
                        autocomplete="off"
                        autocapitalize="words"
                        class="pfq-input"
                        :placeholder="$t('label.customer_first_name_placeholder')"
                    />

                    <div class="pfq-civ">
                        <button
                            v-for="c in civilities"
                            :key="c.value"
                            type="button"
                            class="pfq-civ-btn"
                            :class="{ 'pfq-civ-btn--on': civility === c.value }"
                            @click="civility = c.value"
                        >{{ c.label }}</button>
                    </div>

                    <p v-if="error" class="pfq-err">{{ error }}</p>

                    <button
                        type="submit"
                        class="pfq-submit"
                        :disabled="busy || !name.trim()"
                    >
                        {{ busy ? $t("label.sending") : $t("button.print_flyer") }}
                    </button>

                    <p class="pfq-hint">{{ $t("label.flyer_prints_on_pos_printer") }}</p>
                </form>
            </div>
        </div>
    </div>
</template>

<script>
/**
 * [FLYER PROMO 2026-08-08] Fenêtre rapide « ticket promo », utilisable depuis
 * la caisse comme depuis l'administration.
 *
 * Conçue pour être utilisée EN PLEIN SERVICE, souvent d'une seule main : un
 * champ, trois boutons de civilité, un gros bouton. Le code créé s'affiche
 * ensuite EN GRAND — parce que si l'imprimante est à court de papier,
 * l'exploitant doit pouvoir le dicter au client sans rouvrir un écran.
 *
 * L'impression elle-même n'a pas lieu ici : le serveur ne peut pas joindre
 * l'imprimante du restaurant. Cette fenêtre dépose l'ordre ; le composant
 * `PromoFlyerPrintListener`, monté en permanence dans la coquille admin, le
 * récupère et l'imprime.
 */
export default {
    name: "PromoFlyerQuickModal",
    props: {
        open: { type: Boolean, default: false },
        // Pré-remplissage depuis une commande (ex. nom lu sur la commande Uber).
        prefillName: { type: String, default: "" },
    },
    emits: ["close", "created"],
    data() {
        return {
            name: "",
            civility: "",
            busy: false,
            error: null,
            lastCode: null,
            lastOffer: null,
            duplicate: null,
            offer: "percent",
            discountPercent: null,
            civilities: [
                { value: "", label: "—" },
                { value: "Mme", label: "Mme" },
                { value: "M.", label: "M." },
            ],
        };
    },
    computed: {
        offers() {
            return [
                { value: "percent", label: this.offerLabel("percent") },
                { value: "free_delivery", label: this.offerLabel("free_delivery") },
            ];
        },
    },
    watch: {
        open(isOpen) {
            if (!isOpen) return;
            this.reset();
            this.offer = this.rememberedOffer();
            this.loadDiscountPercent();
            this.name = (this.prefillName || "").trim();
            // Focus différé : le champ n'existe qu'une fois la fenêtre rendue.
            this.$nextTick(() => {
                try { this.$refs.nameInput && this.$refs.nameInput.focus(); } catch (_) { /* sans gravité */ }
            });
        },
    },
    methods: {
        offerLabel(value) {
            if (value === "free_delivery") return this.$t("label.flyer_offer_free_delivery");
            return this.discountPercent
                ? "−" + this.discountPercent + " %"
                : this.$t("label.flyer_offer_percent");
        },
        chooseOffer(value) {
            this.offer = value;
            try { window.localStorage.setItem("pfq.offer", value); } catch (_) { /* navigation privée */ }
        },
        rememberedOffer() {
            try {
                const v = window.localStorage.getItem("pfq.offer");
                return v === "free_delivery" ? "free_delivery" : "percent";
            } catch (_) {
                return "percent";
            }
        },
        loadDiscountPercent() {
            // Le libellé du bouton suit le réglage réel (−10 % aujourd'hui). Si le réglage n'est
            // pas lisible pour ce rôle, le bouton dit simplement « Remise » : jamais un faux chiffre.
            if (this.discountPercent !== null) return;
            this.$store.dispatch("promoFlyerSettings")
                .then((res) => {
                    const p = Number(res && res.settings && res.settings.discount_percent);
                    if (p > 0) this.discountPercent = Number.isInteger(p) ? p : String(p).replace(".", ",");
                })
                .catch(() => { /* libellé générique */ });
        },
        reset() {
            this.lastCode = null;
            this.lastOffer = null;
            this.duplicate = null;
            this.error = null;
            this.busy = false;
            this.name = "";
            this.civility = "";
        },
        close() {
            this.$emit("close");
        },
        submit(force) {
            const name = (this.name || "").trim();
            if (name === "" || this.busy) return;

            this.busy = true;
            this.error = null;

            this.$store.dispatch("promoFlyerCreate", {
                customer_name: name,
                civility: this.civility,
                offer: this.offer,
                force: force === true,
            })
                .then((res) => {
                    // [DÉTAIL 2026-08-09] Le serveur signale qu'un code vient d'être créé pour
                    // ce prénom. On l'affiche au lieu d'en frapper un second : deux appuis ne
                    // doivent pas coûter deux fois 10 % et deux tickets. L'exploitant garde la
                    // main s'il s'agit réellement d'un autre client.
                    if (res.duplicate) {
                        this.duplicate = res.flyer;
                        return;
                    }
                    this.lastCode = (res.flyer && res.flyer.code) || null;
                    this.lastOffer = (res.flyer && res.flyer.offer) || this.offer;
                    this.$emit("created", res.flyer);
                })
                .catch((err) => {
                    const data = (err && err.response && err.response.data) || {};
                    const firstFieldError = data.errors
                        ? Object.values(data.errors)[0]
                        : null;
                    this.error = (firstFieldError && firstFieldError[0])
                        || data.message
                        || this.$t("label.flyer_create_failed");
                })
                .finally(() => { this.busy = false; });
        },
    },
};
</script>

<style scoped>
/* Styles portés par le composant : cette fenêtre s'ouvre au-dessus de la caisse
   comme de l'administration, deux feuilles de style très différentes. Dépendre
   de l'une ou l'autre la rendrait illisible sur la seconde. */
.pfq-backdrop {
    position: fixed;
    inset: 0;
    background: rgba(15, 23, 42, 0.72);
    display: flex;
    align-items: center;
    justify-content: center;
    padding: 16px;
    /* Au-dessus des tiroirs de caisse (z-index 60 constaté) sans écraser les
       alertes système. */
    z-index: 1200;
}
.pfq-card {
    width: 100%;
    max-width: 420px;
    background: #fff;
    border-radius: 16px;
    box-shadow: 0 24px 60px rgba(0, 0, 0, 0.35);
    overflow: hidden;
}
.pfq-head {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 16px 18px;
    border-bottom: 1px solid #e2e8f0;
}
.pfq-head h3 { margin: 0; font-size: 18px; font-weight: 700; color: #0f172a; }
.pfq-x {
    border: 0; background: transparent; font-size: 20px; line-height: 1;
    color: #64748b; cursor: pointer; padding: 4px 8px;
}
.pfq-body { padding: 18px; }
.pfq-label { display: block; font-size: 13px; font-weight: 600; color: #475569; margin-bottom: 6px; }
.pfq-input {
    width: 100%;
    /* 18px minimum : en dessous, iOS zoome sur le champ et déplace tout l'écran
       de caisse. 52px de haut pour un doigt, pas une souris. */
    font-size: 18px;
    height: 52px;
    padding: 0 14px;
    border: 2px solid #cbd5e1;
    border-radius: 10px;
    color: #0f172a;
}
.pfq-input:focus { outline: none; border-color: #f4501e; }
.pfq-offer { display: flex; gap: 8px; margin-bottom: 14px; }
.pfq-offer-btn {
    flex: 1; min-height: 52px; border-radius: 10px; border: 2px solid #cbd5e1;
    background: #fff; color: #334155; font-size: 16px; font-weight: 700; cursor: pointer; padding: 6px 8px;
}
.pfq-offer-btn--on { border-color: #f4501e; background: #fff1ec; color: #b3350f; }
.pfq-done-offer { margin: -8px 0 14px; font-size: 14px; font-weight: 700; color: #b3350f; }
.pfq-civ { display: flex; gap: 8px; margin-top: 12px; }
.pfq-civ-btn {
    flex: 1; height: 44px; border-radius: 10px; border: 2px solid #cbd5e1;
    background: #fff; color: #334155; font-weight: 600; cursor: pointer;
}
.pfq-civ-btn--on { border-color: #f4501e; background: #fff1ec; color: #b3350f; }
.pfq-submit {
    width: 100%; height: 54px; margin-top: 16px; border: 0; border-radius: 12px;
    background: #f4501e; color: #fff; font-size: 17px; font-weight: 700; cursor: pointer;
}
.pfq-submit:disabled { background: #cbd5e1; cursor: not-allowed; }
.pfq-hint { margin: 10px 0 0; font-size: 12px; color: #64748b; text-align: center; }
.pfq-err { margin: 10px 0 0; font-size: 13px; color: #b91c1c; }
.pfq-done { text-align: center; padding: 8px 0 4px; }
.pfq-done-label { font-size: 13px; color: #15803d; font-weight: 600; }
.pfq-code {
    font-size: 30px; font-weight: 800; letter-spacing: 2px;
    color: #0f172a; margin: 10px 0 16px; word-break: break-all;
}
.pfq-again {
    width: 100%; height: 48px; border-radius: 12px; border: 2px solid #cbd5e1;
    background: #fff; color: #334155; font-weight: 700; cursor: pointer;
}
.pfq-dup { text-align: center; padding: 8px 0 4px; }
.pfq-dup-label { font-size: 14px; font-weight: 700; color: #b45309; }
.pfq-dup-sub { margin-top: -8px; margin-bottom: 14px; font-size: 13px; color: #64748b; }
/* Créer quand même reste possible, mais discret : c'est l'exception, pas le geste attendu. */
.pfq-dup-force {
    width: 100%; margin-top: 8px; height: 40px; border: 0; background: transparent;
    color: #64748b; font-size: 13px; text-decoration: underline; cursor: pointer;
}
</style>

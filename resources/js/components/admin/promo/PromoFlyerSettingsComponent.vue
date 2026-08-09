<template>
    <div class="col-12">
        <BreadcrumbComponent />
    </div>

    <LoadingComponent :props="loading" />

    <div class="col-12">
        <div class="db-card">
            <div class="db-card-header">
                <h3 class="db-card-title">{{ $t("label.promo_flyer_settings") }}</h3>
                <router-link :to="{ name: 'admin.promoFlyer' }" class="db-btn py-2 text-white bg-primary">
                    {{ $t("label.promo_flyer") }}
                </router-link>
            </div>

            <div class="db-card-body">
                <div v-if="loaded && !couponRedemptionEnabled"
                    class="mb-4 rounded border border-amber-400 bg-amber-50 p-3 text-sm text-amber-900">
                    <strong>{{ $t("label.flyer_codes_disabled_title") }}</strong>
                    <div>{{ $t("label.flyer_codes_disabled_body") }}</div>
                </div>

                <form @submit.prevent="save">
                    <div class="form-row">
                        <div class="form-col-12 sm:form-col-6">
                            <label class="db-field-title required">{{ $t("label.flyer_headline") }}</label>
                            <input v-model="form.headline" type="text" maxlength="40" class="db-field-control" />
                            <small class="text-slate-500">{{ $t("label.flyer_headline_hint") }}</small>
                        </div>

                        <div class="form-col-6 sm:form-col-3">
                            <label class="db-field-title required">{{ $t("label.flyer_discount_percent") }}</label>
                            <input v-model="form.discount_percent" type="number" min="1" max="50" step="1"
                                class="db-field-control" />
                        </div>

                        <div class="form-col-6 sm:form-col-3">
                            <label class="db-field-title required">{{ $t("label.flyer_validity_days") }}</label>
                            <input v-model="form.validity_days" type="number" min="1" max="365" step="1"
                                class="db-field-control" />
                        </div>

                        <div class="form-col-12">
                            <label class="db-field-title required">{{ $t("label.flyer_intro") }}</label>
                            <textarea v-model="form.intro" rows="3" maxlength="400" class="db-field-control"></textarea>
                        </div>

                        <div class="form-col-12">
                            <label class="db-field-title">{{ $t("label.flyer_savings_note") }}</label>
                            <textarea v-model="form.savings_note" rows="3" maxlength="400"
                                class="db-field-control"></textarea>
                            <small class="text-slate-500">{{ $t("label.flyer_savings_hint") }}</small>
                        </div>

                        <div class="form-col-12">
                            <label class="db-field-title">{{ $t("label.flyer_strengths") }}</label>
                            <textarea v-model="form.strengths" rows="4" maxlength="600"
                                class="db-field-control"></textarea>
                            <small class="text-slate-500">{{ $t("label.flyer_strengths_hint") }}</small>
                        </div>

                        <div class="form-col-12">
                            <label class="db-field-title">{{ $t("label.flyer_footer_note") }}</label>
                            <input v-model="form.footer_note" type="text" maxlength="200" class="db-field-control" />
                        </div>

                        <div class="form-col-12 sm:form-col-6">
                            <label class="db-field-title required">{{ $t("label.flyer_site_url") }}</label>
                            <input v-model="form.site_url" type="text" maxlength="120" class="db-field-control" />
                            <small class="text-slate-500">{{ $t("label.flyer_site_url_hint") }}</small>
                        </div>

                        <div class="form-col-12 sm:form-col-6">
                            <label class="db-field-title">{{ $t("label.flyer_greeting") }}</label>
                            <input v-model="form.greeting" type="text" maxlength="30" class="db-field-control" />
                            <small class="text-slate-500">{{ $t("label.flyer_greeting_hint") }}</small>
                        </div>

                        <div class="form-col-12 sm:form-col-6">
                            <label class="db-field-title">{{ $t("label.flyer_logo_path") }}</label>
                            <input v-model="form.logo_path" type="text" maxlength="160" class="db-field-control" />
                            <small class="text-slate-500">{{ $t("label.flyer_logo_path_hint") }}</small>
                        </div>

                        <div class="form-col-12 sm:form-col-6">
                            <label class="db-field-title required">{{ $t("label.flyer_qr_url") }}</label>
                            <input v-model="form.qr_url" type="url" maxlength="200" class="db-field-control" />
                            <small class="text-slate-500">{{ $t("label.flyer_qr_url_hint") }}</small>
                        </div>

                        <div class="form-col-12">
                            <button type="submit" class="db-btn py-2 text-white bg-primary">
                                <i class="lab lab-save"></i>
                                <span>{{ $t("button.save") }}</span>
                            </button>
                        </div>
                    </div>
                </form>

                <!-- Aperçu : l'exploitant doit voir l'effet de son texte AVANT
                     de gaspiller du papier et, surtout, avant de distribuer un
                     ticket bancal à un vrai client. -->
                <div class="mt-6">
                    <h4 class="mb-2 font-semibold">{{ $t("label.flyer_preview") }}</h4>
                    <pre class="overflow-x-auto rounded bg-slate-900 p-4 font-mono text-xs leading-5 text-slate-100">{{ preview }}</pre>
                </div>
            </div>
        </div>
    </div>
</template>

<script>
/**
 * [FLYER PROMO UBER 2026-08-07] Réglages du ticket promotionnel.
 *
 * Tous les textes sont modifiables sans redéploiement : l'exploitant doit
 * pouvoir corriger une formulation ou changer son offre lui-même.
 *
 * L'aperçu est rendu ICI, en JavaScript, à 48 colonnes — la largeur réelle du
 * papier. Il ne remplace pas une impression d'essai (il ne montre ni le gras ni
 * le QR), mais il attrape le défaut le plus fréquent et le plus coûteux : un
 * texte qui déborde et se coupe au mauvais endroit.
 */
import BreadcrumbComponent from "../components/BreadcrumbComponent";
import LoadingComponent from "../components/LoadingComponent";
import alertService from "../../../services/alertService";

const WIDTH = 48;

export default {
    name: "PromoFlyerSettingsComponent",
    components: { BreadcrumbComponent, LoadingComponent },
    data() {
        return {
            loading: { isActive: false },
            loaded: false,
            couponRedemptionEnabled: true,
            form: {
                headline: "",
                intro: "",
                savings_note: "",
                footer_note: "",
                discount_percent: 10,
                validity_days: 30,
                site_url: "",
                qr_url: "",
                greeting: "",
                strengths: "",
                logo_path: "",
            },
        };
    },
    computed: {
        preview() {
            const center = (t) => {
                const s = String(t || "");
                const pad = Math.max(0, Math.floor((WIDTH - s.length) / 2));
                return " ".repeat(pad) + s;
            };
            const wrap = (t) => {
                const words = String(t || "").split(/\s+/).filter(Boolean);
                const lines = [];
                let line = "";
                for (const w of words) {
                    if ((line + " " + w).trim().length > WIDTH) { lines.push(line.trim()); line = w; }
                    else { line = (line + " " + w).trim(); }
                }
                if (line) lines.push(line);
                return lines.join("\n");
            };

            const out = [];
            out.push(center(this.form.logo_path ? "[ LOGO ]" : this.form.headline));
            out.push("");
            out.push(center(`${(this.form.greeting || "Bonsoir")} Mme Camille,`));
            out.push("");
            out.push(wrap(this.form.intro));
            out.push("");
            out.push("=".repeat(WIDTH));
            out.push(center(`-${this.form.discount_percent}%`));
            out.push(center("sur ta premiere commande"));
            out.push("=".repeat(WIDTH));
            out.push("");
            out.push(center("TON CODE PERSONNEL"));
            out.push(center("CAMILLE-7K2P"));
            out.push(center("utilisable une seule fois"));
            out.push("");
            out.push(center("[ QR CODE ]"));
            out.push(center(this.form.site_url));
            out.push("");
            out.push("-".repeat(WIDTH));
            out.push(center("POURQUOI COMMANDER EN DIRECT ?"));
            for (const l of String(this.form.strengths || "").split(/\n/).filter(Boolean)) {
                out.push("  > " + l.trim());
            }
            out.push("");
            out.push(wrap(this.form.savings_note));
            out.push(wrap(this.form.footer_note));

            return out.join("\n");
        },
    },
    mounted() {
        this.fetch();
    },
    methods: {
        fetch() {
            this.loading.isActive = true;
            this.$store.dispatch("promoFlyerSettings")
                .then((res) => {
                    this.form = { ...this.form, ...(res.settings || {}) };
                    this.couponRedemptionEnabled = res.coupon_redemption_enabled !== false;
                    this.loaded = true;
                })
                .catch((err) => alertService.error(err?.response?.data?.message))
                .finally(() => { this.loading.isActive = false; });
        },
        save() {
            this.loading.isActive = true;
            this.$store.dispatch("promoFlyerSaveSettings", {
                headline: this.form.headline,
                intro: this.form.intro,
                savings_note: this.form.savings_note,
                footer_note: this.form.footer_note,
                discount_percent: Number(this.form.discount_percent),
                validity_days: Number(this.form.validity_days),
                site_url: this.form.site_url,
                qr_url: this.form.qr_url,
                greeting: this.form.greeting,
                strengths: this.form.strengths,
                logo_path: this.form.logo_path,
            })
                .then((res) => {
                    this.form = { ...this.form, ...(res.settings || {}) };
                    alertService.success(res.message);
                })
                .catch((err) => alertService.error(err?.response?.data?.message))
                .finally(() => { this.loading.isActive = false; });
        },
    },
};
</script>

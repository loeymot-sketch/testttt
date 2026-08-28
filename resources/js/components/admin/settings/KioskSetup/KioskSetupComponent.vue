<template>
    <LoadingComponent :props="loading" />
    <div id="kiosk_setup" class="db-card db-tab-div active">
        <div class="db-card-header">
            <h3 class="db-card-title">{{ $t('menu.kiosk_setup') }}</h3>
        </div>
        <div class="db-card-body">
            <form @submit.prevent="save">
                <fieldset class="p-4 mb-6 border border-[#DBDEE0]">
                    <legend class="py-1.5 px-4 text-base font-semibold capitalize border border-[#DBDEE0] text-primary">
                        {{ $t('label.kiosk_idle_screen') }}
                    </legend>
                    <div class="form-row">
                        <div class="form-col-12">
                            <label for="kiosk_idle_video" class="db-field-title">
                                {{ $t('label.kiosk_idle_video') }}
                            </label>
                            <input
                                v-model="form.kiosk_idle_video"
                                v-bind:class="errors.kiosk_idle_video ? 'invalid' : ''"
                                type="url"
                                id="kiosk_idle_video"
                                class="db-field-control"
                                placeholder="https://example.com/video.mp4"
                            />
                            <small class="db-field-alert" v-if="errors.kiosk_idle_video">{{ errors.kiosk_idle_video[0] }}</small>
                            <p class="text-xs text-gray-400 mt-1">{{ $t('label.kiosk_idle_video_hint') }}</p>
                        </div>

                        <div class="form-col-12 sm:form-col-6">
                            <label for="kiosk_welcome_title" class="db-field-title">
                                {{ $t('label.kiosk_welcome_title') }}
                            </label>
                            <input
                                v-model="form.kiosk_welcome_title"
                                v-bind:class="errors.kiosk_welcome_title ? 'invalid' : ''"
                                type="text"
                                id="kiosk_welcome_title"
                                class="db-field-control"
                                :placeholder="$t('label.kiosk_welcome_title_placeholder')"
                            />
                            <small class="db-field-alert" v-if="errors.kiosk_welcome_title">{{ errors.kiosk_welcome_title[0] }}</small>
                        </div>

                        <div class="form-col-12 sm:form-col-6">
                            <label for="kiosk_welcome_subtitle" class="db-field-title">
                                {{ $t('label.kiosk_welcome_subtitle') }}
                            </label>
                            <input
                                v-model="form.kiosk_welcome_subtitle"
                                v-bind:class="errors.kiosk_welcome_subtitle ? 'invalid' : ''"
                                type="text"
                                id="kiosk_welcome_subtitle"
                                class="db-field-control"
                                :placeholder="$t('label.kiosk_welcome_subtitle_placeholder')"
                            />
                            <small class="db-field-alert" v-if="errors.kiosk_welcome_subtitle">{{ errors.kiosk_welcome_subtitle[0] }}</small>
                        </div>

                        <div class="form-col-12 sm:form-col-6">
                            <label for="kiosk_tap_hint" class="db-field-title">
                                {{ $t('label.kiosk_tap_hint') }}
                            </label>
                            <input
                                v-model="form.kiosk_tap_hint"
                                v-bind:class="errors.kiosk_tap_hint ? 'invalid' : ''"
                                type="text"
                                id="kiosk_tap_hint"
                                class="db-field-control"
                                :placeholder="$t('label.kiosk_tap_hint_placeholder')"
                            />
                            <small class="db-field-alert" v-if="errors.kiosk_tap_hint">{{ errors.kiosk_tap_hint[0] }}</small>
                        </div>

                        <!-- [ONB-12 2026-08-28] L'ECRAN QUI MANQUAIT.
                             Le tampon « 100 % Halal » etait ecrit en dur dans la borne :
                             tout commercant installant le produit portait sur son ecran
                             client une affirmation sur sa nourriture qu'il n'avait jamais
                             faite, et qu'aucun ecran ne permettait de retirer. -->
                        <div class="form-col-12">
                            <label class="db-field-title" for="kiosk_halal_stamp">
                                {{ $t('label.kiosk_halal_stamp') }}
                            </label>
                            <div class="flex items-center gap-2">
                                <input
                                    v-model="form.kiosk_halal_stamp"
                                    type="checkbox"
                                    id="kiosk_halal_stamp"
                                    data-testid="kiosk-halal-stamp"
                                />
                                <span class="text-sm">{{ $t('label.kiosk_halal_stamp_hint') }}</span>
                            </div>
                            <small class="db-field-alert" v-if="errors.kiosk_halal_stamp">{{ errors.kiosk_halal_stamp[0] }}</small>
                        </div>

                        <div class="form-col-12 sm:form-col-4">
                            <label for="kiosk_admin_pin" class="db-field-title">
                                {{ $t('label.kiosk_admin_pin') }}
                            </label>
                            <input
                                v-model="form.kiosk_admin_pin"
                                v-bind:class="errors.kiosk_admin_pin ? 'invalid' : ''"
                                type="password"
                                id="kiosk_admin_pin"
                                class="db-field-control"
                                maxlength="4"
                                placeholder="••••"
                                autocomplete="new-password"
                            />
                            <small class="db-field-alert" v-if="errors.kiosk_admin_pin">{{ errors.kiosk_admin_pin[0] }}</small>
                            <p class="text-xs text-gray-400 mt-1">{{ $t('label.kiosk_admin_pin_hint') }}</p>
                        </div>
                    </div>
                </fieldset>

                <button type="submit" class="db-btn text-white bg-primary">
                    <i class="lab lab-save"></i>
                    <span>{{ $t('button.save') }}</span>
                </button>
            </form>
        </div>
    </div>
</template>

<script>
import LoadingComponent from "../../components/LoadingComponent";
import alertService from "../../../../services/alertService";

export default {
    name: "KioskSetupComponent",
    components: { LoadingComponent },
    data() {
        return {
            loading: { isActive: false },
            form: {
                kiosk_idle_video:       '',
                kiosk_welcome_title:    '',
                kiosk_welcome_subtitle: '',
                kiosk_tap_hint:         '',
                kiosk_admin_pin:        '',
                kiosk_halal_stamp:      false,
            },
            errors: {}
        };
    },
    mounted() {
        try {
            this.loading.isActive = true;
            this.$store.dispatch('kioskSetup/lists').then(res => {
                const d = res.data.data;
                this.form = {
                    kiosk_idle_video:       d.kiosk_idle_video       ?? '',
                    kiosk_welcome_title:    d.kiosk_welcome_title    ?? '',
                    kiosk_welcome_subtitle: d.kiosk_welcome_subtitle ?? '',
                    kiosk_tap_hint:         d.kiosk_tap_hint         ?? '',
                    // PIN is never returned in plain text (kiosk_admin_pin_set is a boolean)
                    // Leave empty — only update if admin types a new PIN
                    kiosk_admin_pin:        '',
                    // Le choix enregistre doit revenir a l'ecran, sinon rouvrir la
                    // page et enregistrer autre chose l'effacerait en silence.
                    kiosk_halal_stamp:      Boolean(Number(d.kiosk_halal_stamp ?? 0)),
                };
                this.loading.isActive = false;
            }).catch(() => {
                this.loading.isActive = false;
            });
        } catch {
            this.loading.isActive = false;
        }
    },
    methods: {
        save() {
            try {
                this.loading.isActive = true;
                // Only include kiosk_admin_pin if admin typed a new value (don't overwrite with empty)
                const payload = { ...this.form };
                if (!payload.kiosk_admin_pin) delete payload.kiosk_admin_pin;
                payload.kiosk_halal_stamp = payload.kiosk_halal_stamp ? 1 : 0;
                this.$store.dispatch('kioskSetup/save', payload).then((res) => {
                    this.loading.isActive = false;
                    alertService.successFlip(res.config.method === 'put' ?? 0, this.$t('menu.kiosk_setup'));
                    this.errors = {};
                }).catch((err) => {
                    this.loading.isActive = false;
                    this.errors = err.response?.data?.errors ?? {};
                });
            } catch (err) {
                this.loading.isActive = false;
                alertService.error(err);
            }
        }
    }
};
</script>

<template>
    <div v-if="visible" class="dp-modal-overlay" role="dialog" aria-modal="true" :aria-labelledby="`dp-modal-title-${platform?.id || 'new'}`">
        <div class="dp-modal-dialog">
            <div class="dp-modal-header">
                <h3 :id="`dp-modal-title-${platform?.id || 'new'}`" class="dp-modal-title">
                    {{ titleText }}
                </h3>
                <button
                    type="button"
                    class="dp-modal-close fa-solid fa-xmark"
                    :aria-label="$t('button.close')"
                    @click="close"
                ></button>
            </div>

            <div class="dp-modal-body">
                <form @submit.prevent="save">
                    <!-- Webhook URL block -->
                    <div class="dp-form-row">
                        <label class="db-field-title">
                            {{ $t("delivery_platforms.webhook_url") }}
                        </label>
                        <div class="dp-webhook-row">
                            <input
                                type="text"
                                class="db-field-control dp-webhook-input"
                                :value="webhookUrl"
                                readonly
                                @focus="$event.target.select()"
                            />
                            <button
                                type="button"
                                class="db-btn-outline sm primary"
                                @click="copyWebhookUrl"
                            >
                                <i class="lab lab-copy"></i>
                                <span>{{ $t("delivery_platforms.copy") }}</span>
                            </button>
                        </div>
                        <p class="dp-help-text" v-if="webhookNote">{{ webhookNote }}</p>
                    </div>

                    <!-- Enabled toggle -->
                    <div class="dp-form-row">
                        <label class="db-field-title" :for="`dp-enabled-${platform?.id || 'new'}`">
                            {{ $t("delivery_platforms.enabled") }}
                        </label>
                        <div class="relative inline-block w-11 h-5">
                            <input
                                :id="`dp-enabled-${platform?.id || 'new'}`"
                                v-model="form.enabled"
                                type="checkbox"
                                class="peer appearance-none w-11 h-5 bg-slate-100 rounded-full checked:bg-primary cursor-pointer transition-colors duration-300"
                            />
                            <label
                                :for="`dp-enabled-${platform?.id || 'new'}`"
                                class="absolute top-0 left-0 w-5 h-5 bg-white rounded-full border border-slate-300 shadow-sm transition-transform duration-300 peer-checked:translate-x-6 cursor-pointer pointer-events-none"
                            ></label>
                        </div>
                    </div>

                    <!-- Store ID -->
                    <div class="dp-form-row">
                        <label class="db-field-title required" :for="`dp-store-${platform?.id || 'new'}`">
                            {{ $t("delivery_platforms.store_id") }}
                        </label>
                        <input
                            :id="`dp-store-${platform?.id || 'new'}`"
                            v-model="form.external_store_id"
                            type="text"
                            class="db-field-control"
                            :class="errors.external_store_id ? 'invalid' : ''"
                            maxlength="128"
                        />
                        <small class="db-field-alert" v-if="errors.external_store_id">
                            {{ errors.external_store_id[0] }}
                        </small>
                    </div>

                    <!-- Credentials section -->
                    <fieldset class="dp-fieldset">
                        <legend class="dp-fieldset-legend">{{ $t("delivery_platforms.credentials_legend") }}</legend>

                        <div class="dp-form-row">
                            <label class="db-field-title" :for="`dp-cid-${platform?.id || 'new'}`">
                                {{ $t("delivery_platforms.client_id") }}
                            </label>
                            <div class="dp-secret-row">
                                <input
                                    :id="`dp-cid-${platform?.id || 'new'}`"
                                    v-model="form.credentials.client_id"
                                    :type="show.client_id ? 'text' : 'password'"
                                    class="db-field-control"
                                    autocomplete="off"
                                    :placeholder="placeholderFor('client_id')"
                                />
                                <button
                                    type="button"
                                    class="dp-show-btn"
                                    @click="show.client_id = !show.client_id"
                                >
                                    {{ show.client_id ? $t('delivery_platforms.hide') : $t('delivery_platforms.show') }}
                                </button>
                            </div>
                        </div>

                        <div class="dp-form-row">
                            <label class="db-field-title" :for="`dp-csecret-${platform?.id || 'new'}`">
                                {{ $t("delivery_platforms.client_secret") }}
                            </label>
                            <div class="dp-secret-row">
                                <input
                                    :id="`dp-csecret-${platform?.id || 'new'}`"
                                    v-model="form.credentials.client_secret"
                                    :type="show.client_secret ? 'text' : 'password'"
                                    class="db-field-control"
                                    autocomplete="new-password"
                                    :placeholder="placeholderFor('client_secret')"
                                />
                                <button
                                    type="button"
                                    class="dp-show-btn"
                                    @click="show.client_secret = !show.client_secret"
                                >
                                    {{ show.client_secret ? $t('delivery_platforms.hide') : $t('delivery_platforms.show') }}
                                </button>
                            </div>
                        </div>

                        <div class="dp-form-row">
                            <label class="db-field-title" :for="`dp-whsecret-${platform?.id || 'new'}`">
                                {{ $t("delivery_platforms.webhook_secret") }}
                            </label>
                            <div class="dp-secret-row">
                                <input
                                    :id="`dp-whsecret-${platform?.id || 'new'}`"
                                    v-model="form.credentials.webhook_secret"
                                    :type="show.webhook_secret ? 'text' : 'password'"
                                    class="db-field-control"
                                    autocomplete="new-password"
                                    :placeholder="placeholderFor('webhook_secret')"
                                />
                                <button
                                    type="button"
                                    class="dp-show-btn"
                                    @click="show.webhook_secret = !show.webhook_secret"
                                >
                                    {{ show.webhook_secret ? $t('delivery_platforms.hide') : $t('delivery_platforms.show') }}
                                </button>
                            </div>
                        </div>
                    </fieldset>

                    <!-- Health section -->
                    <div class="dp-health-section" v-if="health">
                        <h4 class="dp-section-title">{{ $t("delivery_platforms.health_section") }}</h4>
                        <ul class="dp-health-list">
                            <li>
                                <span class="dp-health-key">OAuth:</span>
                                <span :class="health.oauth_status?.configured ? 'dp-ok' : 'dp-warn'">
                                    {{ health.oauth_status?.message || '—' }}
                                </span>
                            </li>
                            <li>
                                <span class="dp-health-key">{{ $t('delivery_platforms.last_received') }}:</span>
                                <span>{{ formatTs(health.last_webhook_received_at) }}</span>
                            </li>
                            <li>
                                <span class="dp-health-key">{{ $t('delivery_platforms.last_pushed') }}:</span>
                                <span>{{ formatTs(health.last_status_pushed_at) }}</span>
                            </li>
                            <li v-if="health.last_24h">
                                <span class="dp-health-key">24h:</span>
                                <span>
                                    {{ health.last_24h.webhooks_received }} reçus / {{ health.last_24h.webhooks_processed }} OK / {{ health.last_24h.webhooks_failed }} fail
                                </span>
                            </li>
                        </ul>
                    </div>

                    <div class="dp-action-buttons">
                        <button type="button" class="db-btn-outline" @click="testSignature">
                            <i class="lab lab-shield"></i>
                            <span>{{ $t("delivery_platforms.test_signature") }}</span>
                        </button>
                        <button type="button" class="db-btn-outline" @click="reload">
                            <i class="lab lab-refresh"></i>
                            <span>{{ $t("button.refresh") }}</span>
                        </button>
                    </div>

                    <div class="dp-modal-footer">
                        <button type="button" class="modal-btn-outline" @click="close">
                            <i class="lab lab-close"></i>
                            <span>{{ $t("delivery_platforms.cancel") }}</span>
                        </button>
                        <button type="submit" class="db-btn py-2 text-white bg-primary" :disabled="saving">
                            <i class="lab lab-save"></i>
                            <span>{{ $t("delivery_platforms.save") }}</span>
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</template>

<script>
import axios from "axios";
import alertService from "../../../services/alertService";

/**
 * [PARALLEL-TRACK-1.4 / Phase 4]
 *
 * Modal that edits a single delivery platform configuration.
 *
 * Design notes:
 *   - Credentials are NEVER pre-filled with the masked value
 *     returned by the API. Instead, the inputs start empty and
 *     placeholders show "★★★★1234" so the operator can see what
 *     is already on file. Submitting an empty value keeps the
 *     existing secret thanks to the merge logic in the controller.
 *
 *   - The "Show / Hide" button toggles the input type for that
 *     specific field only — we do not display existing secrets at
 *     any time. To inspect an existing secret, an Admin must use
 *     the dedicated `reveal` flow (gated + audited server-side),
 *     not exposed here on purpose.
 *
 *   - Webhook URL is fetched lazily when the modal opens so the
 *     page payload stays small and a stale `app.url` config never
 *     surfaces in the listing.
 */
export default {
    name: "DeliveryPlatformConfigModal",
    props: {
        visible: { type: Boolean, default: false },
        platform: { type: Object, default: null },
    },
    emits: ["close", "saved"],
    data() {
        return {
            form: this.makeEmptyForm(),
            errors: {},
            saving: false,
            webhookUrl: "",
            webhookNote: "",
            health: null,
            show: {
                client_id: false,
                client_secret: false,
                webhook_secret: false,
            },
        };
    },
    computed: {
        titleText() {
            if (!this.platform) return this.$t("delivery_platforms.config_title", { platform: "—" });
            const key = `delivery_platforms.platform_${this.platform.platform}`;
            const translated = this.$t(key);
            const platformName = translated && translated !== key ? translated : this.platform.platform;
            return this.$t("delivery_platforms.config_title", { platform: platformName });
        },
    },
    watch: {
        platform: {
            immediate: true,
            handler(newVal) {
                if (newVal) {
                    this.hydrateFromPlatform(newVal);
                    this.fetchWebhookUrl();
                    this.fetchHealth();
                } else {
                    this.form = this.makeEmptyForm();
                    this.errors = {};
                    this.health = null;
                    this.webhookUrl = "";
                }
            },
        },
    },
    methods: {
        makeEmptyForm() {
            return {
                enabled: false,
                external_store_id: "",
                credentials: {
                    client_id: "",
                    client_secret: "",
                    webhook_secret: "",
                },
            };
        },
        hydrateFromPlatform(p) {
            this.form = {
                enabled: !!p.enabled,
                external_store_id: p.external_store_id || "",
                // Credentials always start empty — placeholders hint at
                // the masked values already stored on the server.
                credentials: {
                    client_id: "",
                    client_secret: "",
                    webhook_secret: "",
                },
            };
            this.errors = {};
            this.show = { client_id: false, client_secret: false, webhook_secret: false };
        },
        placeholderFor(key) {
            const masked = this.platform?.credentials_masked?.[key];
            const isSet = this.platform?.credentials_masked?.[`${key}_set`];
            if (isSet && masked) return masked;
            return this.$t("delivery_platforms.placeholder_empty_secret");
        },
        async fetchWebhookUrl() {
            if (!this.platform) return;
            try {
                const res = await axios.get(`admin/delivery-platforms/${this.platform.id}/webhook-url`);
                this.webhookUrl = res.data?.data?.url || "";
                this.webhookNote = res.data?.data?.note || "";
            } catch (e) {
                this.webhookUrl = "";
                this.webhookNote = "";
            }
        },
        async fetchHealth() {
            if (!this.platform) return;
            try {
                const res = await axios.get(`admin/delivery-platforms/${this.platform.id}/health`);
                this.health = res.data?.data || null;
            } catch (e) {
                this.health = null;
            }
        },
        async copyWebhookUrl() {
            if (!this.webhookUrl) return;
            try {
                await navigator.clipboard.writeText(this.webhookUrl);
                alertService.success(this.$t("delivery_platforms.copied"));
            } catch (e) {
                // Fallback: highlight + suggest manual copy.
                alertService.warning(this.$t("delivery_platforms.copy_failed"));
            }
        },
        async testSignature() {
            if (!this.platform) return;
            try {
                const res = await axios.post(`admin/delivery-platforms/${this.platform.id}/test-signature`);
                const ok = res.data?.data?.ok;
                const msg = res.data?.data?.message || "";
                if (ok) {
                    alertService.success(msg || this.$t("delivery_platforms.signature_ok"));
                } else {
                    alertService.warning(msg || this.$t("delivery_platforms.signature_fail"));
                }
            } catch (e) {
                alertService.error(e?.response?.data?.message || this.$t("delivery_platforms.signature_fail"));
            }
        },
        async reload() {
            await Promise.all([this.fetchWebhookUrl(), this.fetchHealth()]);
        },
        cleanCredentialsPayload() {
            // Strip empty / null keys so the server merge keeps the
            // existing secret untouched. We never send "" — that
            // would overwrite the stored ciphertext.
            const out = {};
            for (const k of Object.keys(this.form.credentials || {})) {
                const v = this.form.credentials[k];
                if (v !== null && v !== undefined && String(v).length > 0) {
                    out[k] = String(v);
                }
            }
            return out;
        },
        async save() {
            if (!this.platform) return;
            this.errors = {};
            this.saving = true;
            try {
                const credentials = this.cleanCredentialsPayload();
                const payload = {
                    enabled: !!this.form.enabled,
                    external_store_id: this.form.external_store_id,
                };
                if (Object.keys(credentials).length > 0) {
                    payload.credentials = credentials;
                }
                const res = await axios.put(
                    `admin/delivery-platforms/${this.platform.id}`,
                    payload
                );
                alertService.success(this.$t("delivery_platforms.save_success"));
                this.$emit("saved", res.data?.data || null);
                this.close();
            } catch (err) {
                if (err?.response?.status === 422 && err?.response?.data?.errors) {
                    this.errors = err.response.data.errors;
                } else {
                    alertService.error(err?.response?.data?.message || this.$t("delivery_platforms.save_failed"));
                }
            } finally {
                this.saving = false;
            }
        },
        formatTs(value) {
            if (!value) return this.$t("delivery_platforms.never_received");
            try {
                return new Date(value).toLocaleString();
            } catch (e) {
                return value;
            }
        },
        close() {
            this.$emit("close");
        },
    },
};
</script>

<style scoped>
.dp-modal-overlay {
    position: fixed;
    inset: 0;
    background: rgba(15, 23, 42, 0.55);
    display: flex;
    align-items: center;
    justify-content: center;
    z-index: 1000;
    padding: 16px;
}
.dp-modal-dialog {
    background: #fff;
    border-radius: 12px;
    width: 100%;
    max-width: 640px;
    max-height: 92vh;
    overflow-y: auto;
    box-shadow: 0 20px 50px -10px rgba(0, 0, 0, 0.25);
}
.dp-modal-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 16px 20px;
    border-bottom: 1px solid #e5e7eb;
}
.dp-modal-title {
    font-size: 1.1rem;
    font-weight: 600;
    color: #111827;
    margin: 0;
}
.dp-modal-close {
    background: transparent;
    border: 0;
    font-size: 1.2rem;
    color: #94a3b8;
    cursor: pointer;
}
.dp-modal-close:hover {
    color: #ef4444;
}
.dp-modal-body {
    padding: 16px 20px;
}
.dp-form-row {
    margin-bottom: 14px;
}
.dp-webhook-row,
.dp-secret-row {
    display: flex;
    gap: 8px;
    align-items: center;
}
.dp-webhook-input {
    flex: 1;
    font-family: ui-monospace, SFMono-Regular, monospace;
    font-size: 0.85rem;
}
.dp-show-btn {
    padding: 6px 10px;
    border: 1px solid #cbd5e1;
    background: #fff;
    border-radius: 6px;
    font-size: 0.8rem;
    color: #334155;
    cursor: pointer;
    white-space: nowrap;
}
.dp-show-btn:hover {
    background: #f1f5f9;
}
.dp-help-text {
    font-size: 0.75rem;
    color: #64748b;
    margin-top: 4px;
}
.dp-fieldset {
    border: 1px solid #e5e7eb;
    border-radius: 8px;
    padding: 12px 16px 4px;
    margin: 18px 0;
}
.dp-fieldset-legend {
    padding: 0 8px;
    font-size: 0.85rem;
    font-weight: 600;
    color: #334155;
}
.dp-section-title {
    font-size: 0.95rem;
    font-weight: 600;
    color: #111827;
    margin: 16px 0 8px;
}
.dp-health-list {
    list-style: none;
    margin: 0;
    padding: 0;
    background: #f8fafc;
    border-radius: 8px;
    padding: 10px 14px;
}
.dp-health-list li {
    display: flex;
    justify-content: space-between;
    gap: 12px;
    padding: 4px 0;
    font-size: 0.85rem;
    color: #1f2937;
}
.dp-health-key {
    color: #475569;
    font-weight: 500;
}
.dp-ok {
    color: #047857;
}
.dp-warn {
    color: #b45309;
}
.dp-action-buttons {
    display: flex;
    gap: 8px;
    margin: 16px 0;
}
.dp-modal-footer {
    display: flex;
    justify-content: flex-end;
    gap: 10px;
    padding-top: 14px;
    border-top: 1px solid #e5e7eb;
}
</style>

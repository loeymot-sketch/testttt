<template>
    <LoadingComponent :props="loading" />
    <section class="pt-8 pb-16">
        <div class="container max-w-[360px] py-6 p-4 mb-6 sm:px-6 shadow-xs rounded-2xl bg-white">
            <!-- [SF-02 2026-06-13] `normal-case` retiré : il mettait une majuscule à
                 chaque mot (« Bon retour » → « Bon Retour »). La traduction FR
                 porte déjà la casse correcte. -->
            <h2 class="mb-6 text-center text-[22px] font-semibold leading-[34px] text-heading">
                {{ $t('label.welcome_back') }}
            </h2>
            <div v-if="errors.validation"
                class="bg-red-100 border border-red-400 text-red-700 px-3 py-3 mb-5 rounded relative flex items-start gap-2"
                role="alert">
                <span class="block sm:inline text-sm flex-auto">{{ errors.validation }}</span>
                <button type="button" @click="close" class="leading-none">
                    <i class="lab lab-close-circle-line"></i>
                </button>
            </div>
            <form @submit.prevent="login">
                <div class="mb-4">
                    <label for="formEmail" class="text-sm normal-case mb-1 text-heading">{{ $t('label.email') }}</label>
                    <input autocomplete="email" type="text" :class="errors.email ? 'invalid' : ''" v-model="form.email"
                        class="w-full h-12 rounded-lg border px-4 border-[#D9DBE9]" id="formEmail">
                    <small class="db-field-alert" v-if="errors.email">{{ errors.email[0] }}</small>
                </div>
                <div class="mb-4">
                    <label for="formPassword" class="text-sm normal-case mb-1 text-heading">{{
                        $t('label.password')
                    }}</label>
                    <input autocomplete="current-password" type="password" :class="errors.password ? 'invalid' : ''"
                        v-model="form.password" class="w-full h-12 rounded-lg border px-4 border-[#D9DBE9]"
                        id="formPassword">
                    <small class="db-field-alert" v-if="errors.password">{{ errors.password[0] }}</small>
                </div>
                <div class="flex items-center justify-between mb-6">
                    <div class="db-field-checkbox p-0">
                        <div class="custom-checkbox w-3 h-3">
                            <input type="checkbox" id="checkbox2" class="custom-checkbox-field">
                            <i
                                class="fa-solid fa-check custom-checkbox-icon leading-[9px] text-[9px] rounded-[3px] border-[#6E7191]"></i>
                        </div>
                        <label for="checkbox2" class="db-field-label text-xs text-heading">
                            {{ $t('label.remember_me') }}
                        </label>
                    </div>
                    <router-link :to="{ name: 'auth.forgetPassword' }"
                        class="normal-case text-xs font-medium transition text-primary">
                        {{ $t('button.forget_password') }}
                    </router-link>
                </div>
                <button type="submit"
                    class="w-full h-12 text-center normal-case font-medium rounded-3xl mb-6 text-white bg-primary">
                    {{ $t('button.login') }}
                </button>
                <div v-if="!staffOnlyMode" class="flex items-center justify-center gap-2 mb-4">
                    <span class="text-xs text-[#6E7191]">{{ $t('message.have_account') }}</span>
                    <router-link :to="{ name: 'auth.signupPhone' }" class="text-xs font-medium text-primary">
                        {{ $t('button.signup') }}
                    </router-link>
                </div>

                <div v-if="!staffOnlyMode && enums.activityEnum.ENABLE == setting.site_guest_login">
                    <p class="text-sm uppercase text-center mb-3 text-[#6E7191]">{{ $t('label.or') }}</p>
                    <router-link :to="{ name: 'auth.guestLogin' }"
                        class="w-full h-12 leading-[46px] text-center normal-case font-medium rounded-3xl border text-primary border-primary bg-white">
                        {{ $t('button.login_as_guest') }}
                    </router-link>
                </div>
            </form>
        </div>

        <div v-if="demo === 'true' || demo === 'TRUE' || demo === 'True' || demo === '1' || demo === 1"
            class="container max-w-[360px] py-6 p-4 sm:px-6 shadow-xs rounded-2xl bg-white">
            <h2 class="mb-6 text-center text-lg font-medium text-heading">{{ $t('message.for_quick_demo') }}</h2>
            <nav class="grid grid-cols-2 gap-3">
                <button @click.prevent="setupCredit('admin')"
                    class="click-to-prop w-full h-10 leading-10 rounded-lg text-center text-sm normal-case text-white bg-orange-500"
                    id="adminClick">
                    {{ $t('label.admin') }}
                </button>
                <button @click.prevent="setupCredit('customer')"
                    class="click-to-prop w-full h-10 leading-10 rounded-lg text-center text-sm normal-case text-white bg-emerald-500"
                    id="customerClick">
                    {{ $t('label.customer') }}
                </button>
                <button @click.prevent="setupCredit('branchManager')"
                    class="click-to-prop w-full h-10 leading-10 rounded-lg text-center text-sm normal-case text-white bg-sky-600"
                    id="branchManagerClick">
                    {{ $t('label.branch_manager') }}
                </button>
                <button @click.prevent="setupCredit('posOperator')"
                    class="click-to-prop w-full h-10 leading-10 rounded-lg text-center text-sm normal-case text-white bg-purple-500"
                    id="posOperatorClick">
                    {{ $t('label.pos_operator') }}
                </button>
                <button @click.prevent="setupCredit('chef')"
                    class="click-to-prop w-full h-10 leading-10 rounded-lg text-center text-sm normal-case text-white bg-blue-500"
                    id="chefClick">
                    {{ $t('label.chef_kitchen') }}
                </button>
            </nav>
        </div>
    </section>
</template>

<script>
import router from "../../../router";
import LoadingComponent from "../components/LoadingComponent";
import alertService from "../../../services/alertService";
import appService from "../../../services/appService";
import ENV from "../../../config/env";
import activityEnum from "../../../enums/modules/activityEnum";
import { routes } from "../../../router";

export default {
    name: "LoginComponent",
    components: { LoadingComponent },
    data() {
        return {
            loading: {
                isActive: false,
            },
            form: {
                email: "",
                password: ""
            },
            errors: {},
            permissions: {},
            firstMenu: null,
            demo: ENV.DEMO,
            enums: {
                activityEnum: activityEnum
            }
        }
    },
    computed: {
        carts: function () {
            return this.$store.getters['frontendCart/lists'];
        },
        setting: function () {
            return this.$store.getters['frontendSetting/lists']
        },
        permission: function () {
            return this.$store.getters.authPermission;
        },
        // [STAFF-ONLY-V1] Masque Signup + Guest Login sur la page login staff.
        staffOnlyMode: function () {
            return !!(window.foodkingConfig && window.foodkingConfig.staffOnlyMode);
        },
    },
    methods: {
        login: function () {
            try {
                this.loading.isActive = true;
                this.$store.dispatch('login', this.form).then((res) => {
                    this.loading.isActive = false;
                    alertService.success(res.data.message);

                    // Avant router.push : aligner meta.access (sinon ancien refus / race 1s avec setTimeout).
                    appService.recursiveRouter(routes, res.data.permission);

                    // [LOGIN-02] Redirection intelligente selon le profil
                    // [STAFF-ONLY-V1] En staff-only mode : le fallback va au dashboard admin (plus de frontend.home).
                    const defaultPermission = res.data?.defaultPermission;
                    const defaultMenu = res.data?.defaultMenu;
                    const staffOnly = !!(window.foodkingConfig && window.foodkingConfig.staffOnlyMode);

                    if (!staffOnly && this.carts.length > 0) {
                        router.push({ name: "frontend.checkout" });
                    } else if (defaultPermission?.url) {
                        router.push('/admin/' + defaultPermission.url);
                    } else if (defaultMenu?.url) {
                        router.push('/admin/' + defaultMenu.url);
                    } else if (staffOnly) {
                        router.push({ name: "admin.dashboard" });
                    } else {
                        router.push({ name: "frontend.home" });
                    }
                }).catch((err) => {
                    this.loading.isActive = false;
                    const data = err.response?.data;
                    if (data?.errors) {
                        this.errors = data.errors;
                    } else if (typeof data === 'string') {
                        this.errors = { validation: data };
                    } else {
                        this.errors = {
                            validation: data?.message || err.message || 'Network error — check API URL and x-api-key.',
                        };
                    }
                })
            } catch (err) {
                this.loading.isActive = false;
            }
        },
        close: function () {
            this.errors = {}
        },
        setupCredit: function (e) {
            // [SEC-30-2] Demo credentials read from runtime config (injected server-side)
            // Never hardcode real restaurant credentials in the JS bundle.
            const demo = window.__FOODKING_RUNTIME__?.demo || {};
            if (e === 'admin') {
                this.form.email = demo.adminEmail || 'admin@lecayenne.fr';
                this.form.password = demo.adminPassword || '123456';
            } else if (e === 'customer') {
                this.form.email = demo.customerEmail || 'walkingcustomer@example.com';
                this.form.password = demo.customerPassword || '123456';
            } else if (e === 'branchManager') {
                this.form.email = demo.branchManagerEmail || 'branchmanager@example.com';
                this.form.password = demo.branchManagerPassword || '123456';
            } else if (e === 'posOperator') {
                this.form.email = demo.posOperatorEmail || 'pos@lecayenne.fr';
                this.form.password = demo.posOperatorPassword || '123456';
            } else if (e === 'chef') {
                this.form.email = demo.chefEmail || 'chef@example.com';
                this.form.password = demo.chefPassword || '123456';
            }
        }
    }
}
</script>
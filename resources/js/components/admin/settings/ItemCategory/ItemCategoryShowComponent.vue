<template>
    <LoadingComponent :props="loading" />
    <div class="db-card">
        <div class="db-card-header">
            <h3 class="db-card-title">{{ $t('menu.item_categories') }}</h3>
        </div>
        <div class="db-card-body">
            <div class="row">
                <div class="col-12 sm:col-5">
                    <img class="db-image" alt="category" :src="itemCategory.cover">
                </div>
                <div class="col-12 sm:col-7 md:pl-8">
                    <h3 class="text-lg font-medium capitalize mb-2 text-paragraph">{{ itemCategory.name }}</h3>
                    <label class="db-badge mb-3" :class="statusClass(itemCategory.status)">
                        {{ enums.statusEnumArray[itemCategory.status] }}
                    </label>
                    <p class="db-light-text">
                        {{ itemCategory.description }}
                    </p>

                    <!-- [GAP-28-1] Kiosk wizard configuration summary -->
                    <div class="mt-4 flex flex-wrap gap-2" v-if="itemCategory.wizard_template">
                        <span class="inline-flex items-center gap-1 px-2 py-1 rounded text-xs font-medium bg-blue-50 text-blue-700 border border-blue-200">
                            🧙 Wizard : {{ itemCategory.wizard_template }}
                        </span>
                        <span v-if="itemCategory.has_menu"
                            class="inline-flex items-center gap-1 px-2 py-1 rounded text-xs font-medium bg-green-50 text-green-700 border border-green-200">
                            🍟 Menu inclus
                        </span>
                    </div>
                </div>
            </div>
        </div>
    </div>
</template>

<script>
import LoadingComponent from "../../components/LoadingComponent";
import statusEnum from "../../../../enums/modules/statusEnum";
import alertService from "../../../../services/alertService";
import appService from "../../../../services/appService";

export default {
    name: "ItemCategoryShowComponent",
    components: {
        LoadingComponent
    },
    data() {
        return {
            loading: {
                isActive: false
            },
            enums: {
                statusEnum: statusEnum,
                statusEnumArray: {
                    [statusEnum.ACTIVE]: this.$t("label.active"),
                    [statusEnum.INACTIVE]: this.$t("label.inactive")
                }
            }
        }
    },
    computed: {
        itemCategory: function () {
            return this.$store.getters['itemCategory/show'];
        }
    },
    mounted() {
        this.loading.isActive = true;
        this.$store.dispatch('itemCategory/show', this.$route.params.id).then(res => {
            this.loading.isActive = false;
        }).catch((error) => {
            this.loading.isActive = false;
        });
    },
    methods: {
        statusClass: function (status) {
            return appService.statusClass(status);
        }
    }
}
</script>

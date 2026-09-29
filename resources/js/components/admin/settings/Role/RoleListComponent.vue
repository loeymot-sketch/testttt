<template>
    <LoadingComponent :props="loading" />
    <div id="role" class="db-card db-tab-div active">
        <div class="db-card-header">
            <h3 class="db-card-title"> {{ $t('menu.role') }} &amp; {{ $t('label.permissions') }}</h3>
            <div class="db-card-filter">
                <TableLimitComponent :method="list" :search="props.search" :page="paginationPage" />
                <RoleCreateComponent :props="props" />
            </div>
        </div>
        <ul v-if="roles.length > 0">
            <li v-for="role in roles" :key="role.role"
                class="flex flex-col items-center justify-between gap-4 sm:flex-row sm:justify-between py-3 px-4 border-b last:border-none border-solid border-slate-200">
                <!-- [ONB-06 T-2.1.3 2026-08-27] Les roles etaient affiches tels qu'ils sont
                     stockes : « POS Operator », « Waiter », « Delivery Boy », « Stuff ». Un
                     patron francais decide qui a le droit de rembourser une commande en
                     lisant ca. On traduit A L'AFFICHAGE, sur le nom stocke comme cle, avec
                     repli sur ce nom : renommer en base casserait les verifications de role
                     ecrites en dur dans le code (hasRole('Admin')...).
                     `capitalize` retire : il mettait une majuscule a chaque mot. -->
                <span class="font-medium text-center sm:text-left text-sm text-slate-500">
                    {{ libelleRole(role.name) }}
                    <span class="block font-normal whitespace-nowrap">({{ role.users_count }}) {{ $t('label.members')
                    }}</span>
                </span>
                <div class="flex flex-wrap justify-center items-center sm:items-start sm:justify-end gap-1.5">
                    <router-link class="db-btn-outline sm primary modal-btn m-0.5"
                        :to="{ name: 'admin.settings.role.show', params: { id: role.id } }">
                        <i class="lab lab-key"></i>
                        <span>{{ $t("button.permissions") }}</span>
                    </router-link>
                    <SmModalEditComponent v-if="!isProtectedRole(role)" @click="edit(role)" />
                    <SmDeleteComponent @click="destroy(role.id)" v-if="!isProtectedRole(role)" />
                </div>
            </li>
        </ul>
        <div class="flex items-center justify-between border-t border-gray-200 bg-white px-4 py-6">
            <PaginationSMBox :pagination="pagination" :method="list" />
            <div class="hidden sm:flex sm:flex-1 sm:items-center sm:justify-between">
                <PaginationTextComponent :props="{ page: paginationPage }" />
                <PaginationBox :pagination="pagination" :method="list" />
            </div>
        </div>
    </div>
</template>
<script>
import LoadingComponent from "../../components/LoadingComponent";
import alertService from "../../../../services/alertService";
import RoleCreateComponent from "./RoleCreateComponent";
import PaginationTextComponent from "../../components/pagination/PaginationTextComponent";
import PaginationBox from "../../components/pagination/PaginationBox";
import PaginationSMBox from "../../components/pagination/PaginationSMBox";
import appService from "../../../../services/appService";
import TableLimitComponent from "../../components/TableLimitComponent";
import SmDeleteComponent from "../../components/buttons/SmDeleteComponent";
import SmModalEditComponent from "../../components/buttons/SmModalEditComponent";
import roleEnum from "../../../../enums/modules/roleEnum";

export default {
    name: "RoleListComponent",
    components: {
        TableLimitComponent,
        PaginationSMBox,
        RoleCreateComponent,
        PaginationBox,
        PaginationTextComponent,
        LoadingComponent,
        SmDeleteComponent,
        SmModalEditComponent,
    },
    data() {
        return {
            loading: {
                isActive: false
            },
            props: {
                form: {
                    name: "",
                },
                search: {
                    paginate: 1,
                    page: 1,
                    per_page: 10,
                    order_column: 'id',
                    order_type: 'asc',
                }
            },
            enums: {
                roleEnumArray: [
                    roleEnum.ADMIN,
                    roleEnum.CUSTOMER,
                    roleEnum.DELIVERY_BOY,
                    roleEnum.WAITER,
                    roleEnum.CHEF
                ],
                protectedRoleNames: [
                    'Admin',
                    'Customer',
                    'Delivery Boy',
                    'Waiter',
                    'Chef',
                    'Branch Manager',
                    'POS Operator',
                    'Stuff',
                ],
            },
        }
    },
    computed: {
        roles: function () {
            return this.$store.getters['role/lists'];
        },
        pagination: function () {
            return this.$store.getters['role/pagination'];
        },
        paginationPage: function () {
            return this.$store.getters['role/page'];
        }
    },
    mounted() {
        this.list();
    },
    methods: {
        isProtectedRole: function (role) {
            // Même liste que RoleService : le bouton corbeille du caissier
            // restait visible (ids 1–5 seulement) alors que POS Operator = id 7.
            return this.enums.protectedRoleNames.indexOf(role.name) !== -1;
        },
        /**
         * Le libelle metier d'un role. La cle est le nom STOCKE — on ne renomme rien en
         * base : `hasRole('Admin')` et consorts sont ecrits en dur un peu partout, et un
         * renommage les casserait tous en silence. Repli sur le nom stocke si la
         * traduction manque, pour qu'un role cree par le commercant s'affiche tel quel.
         */
        libelleRole: function (nom) {
            if (!nom) {
                return '';
            }
            const cle = 'role.' + String(nom).replace(/[.]/g, '_');
            const traduit = this.$t(cle);

            return traduit === cle ? nom : traduit;
        },
        list: function (page = 1) {
            this.loading.isActive = true;
            this.props.search.page = page;
            this.$store.dispatch('role/lists', this.props.search).then(res => {
                this.loading.isActive = false;
            }).catch((err) => {
                this.loading.isActive = false;
            });
        },
        edit: function (role) {
            appService.modalShow();
            this.loading.isActive = true;
            this.props.form = {
                name: role.name,
            };
            this.$store.dispatch('role/edit', role.id);
            this.loading.isActive = false;
        },
        destroy: function (id) {
            appService.destroyConfirmation().then((res) => {
                try {
                    this.loading.isActive = true;
                    this.$store.dispatch('role/destroy', {
                        id: id,
                        search: this.props.search
                    }).then((res) => {
                        this.loading.isActive = false;
                        alertService.successFlip(null, this.$t('menu.role'));
                    }).catch((err) => {
                        this.loading.isActive = false;
                        alertService.error(err.response.data.message);
                    })
                } catch (err) {
                    this.loading.isActive = false;
                    alertService.error(err.response.data.message);
                }
            }).catch((err) => {
                this.loading.isActive = false;
            })
        }
    }
}
</script>

<?php

namespace Database\Seeders;

use App\Libraries\AppLibrary;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;

class PermissionTableSeeder extends Seeder
{
    /**
     * Run the database seeds.
     *
     * @return void
     */
    public function run()
    {
        $permissions = [
            [
                'title'      => 'Dashboard',
                'name'       => 'dashboard',
                'guard_name' => 'sanctum',
                'url'        => 'dashboard',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'title'      => 'Items',
                'name'       => 'items',
                'guard_name' => 'sanctum',
                'url'        => 'items',
                'created_at' => now(),
                'updated_at' => now(),
                'children'   => [
                    [
                        'title'      => 'Items Create',
                        'name'       => 'items_create',
                        'guard_name' => 'sanctum',
                        'url'        => 'items/create',
                        'created_at' => now(),
                        'updated_at' => now(),
                    ],
                    [
                        'title'      => 'Items Edit',
                        'name'       => 'items_edit',
                        'guard_name' => 'sanctum',
                        'url'        => 'items/edit',
                        'created_at' => now(),
                        'updated_at' => now(),
                    ],
                    [
                        'title'      => 'Items Delete',
                        'name'       => 'items_delete',
                        'guard_name' => 'sanctum',
                        'url'        => 'items/delete',
                        'created_at' => now(),
                        'updated_at' => now(),
                    ],
                    [
                        'title'      => 'Items Show',
                        'name'       => 'items_show',
                        'guard_name' => 'sanctum',
                        'url'        => 'items/show',
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]
                ]
            ],
            [
                'title'      => 'Dining Tables',
                'name'       => 'dining-tables',
                'guard_name' => 'sanctum',
                'url'        => 'dining-tables',
                'created_at' => now(),
                'updated_at' => now(),
                'children'   => [
                    [
                        'title'      => 'Dining Tables Create',
                        'name'       => 'dining_tables_create',
                        'guard_name' => 'sanctum',
                        'url'        => 'dining-tables/create',
                        'created_at' => now(),
                        'updated_at' => now(),
                    ],
                    [
                        'title'      => 'Dining Tables Edit',
                        'name'       => 'dining_tables_edit',
                        'guard_name' => 'sanctum',
                        'url'        => 'dining-tables/edit',
                        'created_at' => now(),
                        'updated_at' => now(),
                    ],
                    [
                        'title'      => 'Dining Tables Delete',
                        'name'       => 'dining_tables_delete',
                        'guard_name' => 'sanctum',
                        'url'        => 'dining-tables/delete',
                        'created_at' => now(),
                        'updated_at' => now(),
                    ],
                    [
                        'title'      => 'Dining Tables Show',
                        'name'       => 'dining_tables_show',
                        'guard_name' => 'sanctum',
                        'url'        => 'dining-tables/show',
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]
                ]
            ],
            [
                'title'      => 'POS',
                'name'       => 'pos',
                'guard_name' => 'sanctum',
                'url'        => 'pos',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'title'      => 'POS Orders',
                'name'       => 'pos-orders',
                'guard_name' => 'sanctum',
                'url'        => 'pos-orders',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            // [OWNER 2026-08-13 « ameliore l'acces du caissier »] Ticket promo (plateformes/Uber) :
            // creer / reimprimer / annuler depuis le comptoir, sans deverrouiller `coupons_create`
            // (CRUD coupons generique). Voir migration 2026_08_13_190000_grant_pos_flyer_print_to_cashier.
            [
                'title'      => 'POS Flyer Print',
                'name'       => 'pos-flyer-print',
                'guard_name' => 'sanctum',
                'url'        => 'pos-flyer-print',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            // [POS-9.1.1] Discount permission gate: cap cashier at 10%, manager up to 50%, owner beyond
            [
                'title'      => 'POS Discount up to 10%',
                'name'       => 'pos-discount-up-to-10',
                'guard_name' => 'sanctum',
                'url'        => 'pos/discount-up-to-10',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'title'      => 'POS Discount 10%-50% (manager)',
                'name'       => 'pos-discount-over-10-requires-manager',
                'guard_name' => 'sanctum',
                'url'        => 'pos/discount-manager',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'title'      => 'POS Discount above 50% (owner)',
                'name'       => 'pos-discount-unlimited',
                'guard_name' => 'sanctum',
                'url'        => 'pos/discount-unlimited',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            // [POS-9.1.2] Destroying a PAID order requires elevated permission.
            [
                'title'      => 'POS Destroy Paid Order',
                'name'       => 'pos-destroy-paid',
                'guard_name' => 'sanctum',
                'url'        => 'pos/destroy-paid',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            // [POS-9.4.12] Fiscal management (NF525 Z/X reports) — Admin + Branch Manager only.
            [
                'title'      => 'POS Manage Fiscal (Z/X reports)',
                'name'       => 'pos-manage-fiscal',
                'guard_name' => 'sanctum',
                'url'        => 'pos/manage-fiscal',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            // [POS-9.4.12] Reopening a closed Z requires manager-level approval (audit-logged).
            [
                'title'      => 'POS Reopen Closed Z Report',
                'name'       => 'pos-reopen-z',
                'guard_name' => 'sanctum',
                'url'        => 'pos/reopen-z',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            // [LOCK_POS_LOYALTY_REDEEM_UI 2026-05-19] V1 cashier loyalty redeem
            // permission (Option B per plans/LOCK_POS_LOYALTY_REDEEM_UI_2026-05-18.md).
            // Enforced in PosLoyaltyRedeemRequest::authorize(). Assigned to
            // POS Operator + Branch Manager + Admin via RolePermissionTableSeeder.
            [
                'title'      => 'POS Redeem Loyalty Points',
                'name'       => 'pos.redeem-loyalty',
                'guard_name' => 'sanctum',
                'url'        => 'pos/redeem-loyalty',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            // [HEAL-4 / PROPOSAL-02 — V101-02 2026-05-26] POS Refund UI permission.
            // Gates the new PosRefundModal.vue counter-entry refund workflow. The
            // backend route (refund-with-counter-entry) is permission-guarded via
            // PosOrderController::refundWithCounterEntry (abort_unless can() check).
            // Granted ONLY to Admin (auto via Permission::all()) + Branch Manager
            // (explicit list). POS Operator does NOT get this permission by default
            // to prevent mass-refund vector (proposal §8 risk register #1).
            // Owner can grant manually via /admin/role/{id}/edit UI if needed.
            [
                'title'      => 'POS Refund (Counter-Entry NF525)',
                'name'       => 'pos-refund',
                'guard_name' => 'sanctum',
                'url'        => 'pos/refund',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'title'      => 'Online Orders',
                'name'       => 'online-orders',
                'guard_name' => 'sanctum',
                'url'        => 'online-orders',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'title'      => 'Table Orders',
                'name'       => 'table-orders',
                'guard_name' => 'sanctum',
                'url'        => 'table-orders',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'title'      => 'K.D.S',
                'name'       => 'kitchen-display-system',
                'guard_name' => 'sanctum',
                'url'        => 'kitchen-display-system',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'title'      => 'O.S.S',
                'name'       => 'order-status-screen',
                'guard_name' => 'sanctum',
                'url'        => 'order-status-screen',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'title'      => 'Coupons',
                'name'       => 'coupons',
                'guard_name' => 'sanctum',
                'url'        => 'coupons',
                'created_at' => now(),
                'updated_at' => now(),
                'children'   => [
                    [
                        'title'      => 'Coupons Create',
                        'name'       => 'coupons_create',
                        'guard_name' => 'sanctum',
                        'url'        => 'coupons/create',
                        'created_at' => now(),
                        'updated_at' => now(),
                    ],
                    [
                        'title'      => 'Coupons Edit',
                        'name'       => 'coupons_edit',
                        'guard_name' => 'sanctum',
                        'url'        => 'coupons/edit',
                        'created_at' => now(),
                        'updated_at' => now(),
                    ],
                    [
                        'title'      => 'Coupons Delete',
                        'name'       => 'coupons_delete',
                        'guard_name' => 'sanctum',
                        'url'        => 'coupons/delete',
                        'created_at' => now(),
                        'updated_at' => now(),
                    ],
                    [
                        'title'      => 'Coupons Show',
                        'name'       => 'coupons_show',
                        'guard_name' => 'sanctum',
                        'url'        => 'coupons/show',
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]
                ]
            ],
            [
                'title'      => 'Offers',
                'name'       => 'offers',
                'guard_name' => 'sanctum',
                'url'        => 'offers',
                'created_at' => now(),
                'updated_at' => now(),
                'children'   => [
                    [
                        'title'      => 'Offers Create',
                        'name'       => 'offers_create',
                        'guard_name' => 'sanctum',
                        'url'        => 'offers/create',
                        'created_at' => now(),
                        'updated_at' => now(),
                    ],
                    [
                        'title'      => 'Offers Edit',
                        'name'       => 'offers_edit',
                        'guard_name' => 'sanctum',
                        'url'        => 'offers/edit',
                        'created_at' => now(),
                        'updated_at' => now(),
                    ],
                    [
                        'title'      => 'Offers Delete',
                        'name'       => 'offers_delete',
                        'guard_name' => 'sanctum',
                        'url'        => 'offers/delete',
                        'created_at' => now(),
                        'updated_at' => now(),
                    ],
                    [
                        'title'      => 'Offers Show',
                        'name'       => 'offers_show',
                        'guard_name' => 'sanctum',
                        'url'        => 'offers/show',
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]
                ]
            ],
            [
                'title'      => 'Push Notifications',
                'name'       => 'push-notifications',
                'guard_name' => 'sanctum',
                'url'        => 'push-notifications',
                'created_at' => now(),
                'updated_at' => now(),
                'children'   => [
                    [
                        'title'      => 'Push Notifications Create',
                        'name'       => 'push-notifications_create',
                        'guard_name' => 'sanctum',
                        'url'        => 'push-notifications/create',
                        'created_at' => now(),
                        'updated_at' => now(),
                    ],
                    [
                        'title'      => 'Push Notifications Edit',
                        'name'       => 'push-notifications_edit',
                        'guard_name' => 'sanctum',
                        'url'        => 'push-notifications/edit',
                        'created_at' => now(),
                        'updated_at' => now(),
                    ],
                    [
                        'title'      => 'Push Notifications Delete',
                        'name'       => 'push-notifications_delete',
                        'guard_name' => 'sanctum',
                        'url'        => 'push-notifications/delete',
                        'created_at' => now(),
                        'updated_at' => now(),
                    ],
                    [
                        'title'      => 'Push Notifications Show',
                        'name'       => 'push-notifications_show',
                        'guard_name' => 'sanctum',
                        'url'        => 'push-notifications/show',
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]
                ]
            ],
            [
                'title'      => 'Messages',
                'name'       => 'messages',
                'guard_name' => 'sanctum',
                'url'        => 'messages',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'title'      => 'Subscribers',
                'name'       => 'subscribers',
                'guard_name' => 'sanctum',
                'url'        => 'subscribers',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'title'      => 'Administrators',
                'name'       => 'administrators',
                'guard_name' => 'sanctum',
                'url'        => 'administrators',
                'created_at' => now(),
                'updated_at' => now(),
                'children'   => [
                    [
                        'title'      => 'Administrators Create',
                        'name'       => 'administrators_create',
                        'guard_name' => 'sanctum',
                        'url'        => 'administrators/create',
                        'created_at' => now(),
                        'updated_at' => now(),
                    ],
                    [
                        'title'      => 'Administrators Edit',
                        'name'       => 'administrators_edit',
                        'guard_name' => 'sanctum',
                        'url'        => 'administrators/edit',
                        'created_at' => now(),
                        'updated_at' => now(),
                    ],
                    [
                        'title'      => 'Administrators Delete',
                        'name'       => 'administrators_delete',
                        'guard_name' => 'sanctum',
                        'url'        => 'administrators/delete',
                        'created_at' => now(),
                        'updated_at' => now(),
                    ],
                    [
                        'title'      => 'Administrators Show',
                        'name'       => 'administrators_show',
                        'guard_name' => 'sanctum',
                        'url'        => 'administrators/show',
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]
                ]
            ],
            [
                'title'      => 'Delivery Boys',
                'name'       => 'delivery-boys',
                'guard_name' => 'sanctum',
                'url'        => 'delivery-boys',
                'created_at' => now(),
                'updated_at' => now(),
                'children'   => [
                    [
                        'title'      => 'Delivery Boys Create',
                        'name'       => 'delivery-boys_create',
                        'guard_name' => 'sanctum',
                        'url'        => 'delivery-boys/create',
                        'created_at' => now(),
                        'updated_at' => now(),
                    ],
                    [
                        'title'      => 'Delivery Boys Edit',
                        'name'       => 'delivery-boys_edit',
                        'guard_name' => 'sanctum',
                        'url'        => 'delivery-boys/edit',
                        'created_at' => now(),
                        'updated_at' => now(),
                    ],
                    [
                        'title'      => 'Delivery Boys Delete',
                        'name'       => 'delivery-boys_delete',
                        'guard_name' => 'sanctum',
                        'url'        => 'delivery-boys/delete',
                        'created_at' => now(),
                        'updated_at' => now(),
                    ],
                    [
                        'title'      => 'Delivery Boys Show',
                        'name'       => 'delivery-boys_show',
                        'guard_name' => 'sanctum',
                        'url'        => 'delivery-boys/show',
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]
                ]
            ],
            [
                'title'      => 'Customers',
                'name'       => 'customers',
                'guard_name' => 'sanctum',
                'url'        => 'customers',
                'created_at' => now(),
                'updated_at' => now(),
                'children'   => [
                    [
                        'title'      => 'Customers Create',
                        'name'       => 'customers_create',
                        'guard_name' => 'sanctum',
                        'url'        => 'customers/create',
                        'created_at' => now(),
                        'updated_at' => now(),
                    ],
                    [
                        'title'      => 'Customers Edit',
                        'name'       => 'customers_edit',
                        'guard_name' => 'sanctum',
                        'url'        => 'customers/edit',
                        'created_at' => now(),
                        'updated_at' => now(),
                    ],
                    [
                        'title'      => 'Customers Delete',
                        'name'       => 'customers_delete',
                        'guard_name' => 'sanctum',
                        'url'        => 'customers/delete',
                        'created_at' => now(),
                        'updated_at' => now(),
                    ],
                    [
                        'title'      => 'Customers Show',
                        'name'       => 'customers_show',
                        'guard_name' => 'sanctum',
                        'url'        => 'customers/show',
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]
                ]
            ],
            [
                'title'      => 'Employees',
                'name'       => 'employees',
                'guard_name' => 'sanctum',
                'url'        => 'employees',
                'created_at' => now(),
                'updated_at' => now(),
                'children'   => [
                    [
                        'title'      => 'Employees Create',
                        'name'       => 'employees_create',
                        'guard_name' => 'sanctum',
                        'url'        => 'employees/create',
                        'created_at' => now(),
                        'updated_at' => now(),
                    ],
                    [
                        'title'      => 'Employees Edit',
                        'name'       => 'employees_edit',
                        'guard_name' => 'sanctum',
                        'url'        => 'employees/edit',
                        'created_at' => now(),
                        'updated_at' => now(),
                    ],
                    [
                        'title'      => 'Employees Delete',
                        'name'       => 'employees_delete',
                        'guard_name' => 'sanctum',
                        'url'        => 'employees/delete',
                        'created_at' => now(),
                        'updated_at' => now(),
                    ],
                    [
                        'title'      => 'Employees Show',
                        'name'       => 'employees_show',
                        'guard_name' => 'sanctum',
                        'url'        => 'employees/show',
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]
                ]
            ],
            [
                'title'      => 'Waiters',
                'name'       => 'waiters',
                'guard_name' => 'sanctum',
                'url'        => 'waiters',
                'created_at' => now(),
                'updated_at' => now(),
                'children'   => [
                    [
                        'title'      => 'Waiters Create',
                        'name'       => 'waiters_create',
                        'guard_name' => 'sanctum',
                        'url'        => 'waiters/create',
                        'created_at' => now(),
                        'updated_at' => now(),
                    ],
                    [
                        'title'      => 'Waiters Edit',
                        'name'       => 'waiters_edit',
                        'guard_name' => 'sanctum',
                        'url'        => 'waiters/edit',
                        'created_at' => now(),
                        'updated_at' => now(),
                    ],
                    [
                        'title'      => 'Waiters Delete',
                        'name'       => 'waiters_delete',
                        'guard_name' => 'sanctum',
                        'url'        => 'waiters/delete',
                        'created_at' => now(),
                        'updated_at' => now(),
                    ],
                    [
                        'title'      => 'Waiters Show',
                        'name'       => 'waiters_show',
                        'guard_name' => 'sanctum',
                        'url'        => 'waiters/show',
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]
                ]
            ],
            [
                'title'      => 'Chefs',
                'name'       => 'chefs',
                'guard_name' => 'sanctum',
                'url'        => 'chefs',
                'created_at' => now(),
                'updated_at' => now(),
                'children'   => [
                    [
                        'title'      => 'Chefs Create',
                        'name'       => 'chefs_create',
                        'guard_name' => 'sanctum',
                        'url'        => 'chefs/create',
                        'created_at' => now(),
                        'updated_at' => now(),
                    ],
                    [
                        'title'      => 'Chefs Edit',
                        'name'       => 'chefs_edit',
                        'guard_name' => 'sanctum',
                        'url'        => 'chefs/edit',
                        'created_at' => now(),
                        'updated_at' => now(),
                    ],
                    [
                        'title'      => 'Chefs Delete',
                        'name'       => 'chefs_delete',
                        'guard_name' => 'sanctum',
                        'url'        => 'chefs/delete',
                        'created_at' => now(),
                        'updated_at' => now(),
                    ],
                    [
                        'title'      => 'Chefs Show',
                        'name'       => 'chefs_show',
                        'guard_name' => 'sanctum',
                        'url'        => 'chefs/show',
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]
                ]
            ],
            [
                'title'      => 'Transactions',
                'name'       => 'transactions',
                'guard_name' => 'sanctum',
                'url'        => 'transactions',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'title'      => 'Sales Report',
                'name'       => 'sales-report',
                'guard_name' => 'sanctum',
                'url'        => 'sales-report',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'title'      => 'Items Report',
                'name'       => 'items-report',
                'guard_name' => 'sanctum',
                'url'        => 'items-report',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'title'      => 'Credit Balance Report',
                'name'       => 'credit-balance-report',
                'guard_name' => 'sanctum',
                'url'        => 'credit-balance-report',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            // [Wave O — O4 2026-05-20] Admin daily cash sessions read-only report.
            // Owner request : profil admin doit voir chaque jour début/fin caisse
            // + transactions. Permission propre (vs pos-manage-fiscal) pour que
            // le menu sidebar puisse l'auto-afficher / l'auto-cacher.
            // Granted à Admin (via Permission::all()) et Branch Manager
            // explicitement dans RolePermissionTableSeeder.
            [
                'title'      => 'Cash Sessions Report',
                'name'       => 'cash-sessions-report',
                'guard_name' => 'sanctum',
                'url'        => 'cash-sessions-report',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'title'      => 'Settings',
                'name'       => 'settings',
                'guard_name' => 'sanctum',
                'url'        => 'settings',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            // [Sprint 1D / F-4 — 2026-05-16] Cash variance override permission.
            // Required to reconcile a session whose |variance| exceeds
            // config('cash.variance_threshold_eur'). Granted to Admin (via
            // Permission::all() in RolePermissionTableSeeder) and explicitly
            // listed for Branch Manager.
            [
                'title'      => 'Cash variance override',
                'name'       => 'cash.reconcile.variance.override',
                'guard_name' => 'sanctum',
                'url'        => 'pos/cash-drawer/sessions/reconcile',
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ];

        $permissions = AppLibrary::associativeToNumericArrayBuilder($permissions);

        // [ONB-06 F-05 2026-08-27] Ce seeder n'etait pas rejouable, et sa hierarchie
        // reposait sur une coincidence.
        //
        // Avant : `Permission::insert($permissions)` — une insertion en masse qui
        // echoue sur la contrainte UNIQUE (name, guard_name) des qu'une seule
        // permission existe deja. Trois tests en dependaient et etaient ROUGES dans le
        // depot : RolePermissionSeederTest, sur les trois roles metier.
        //
        // Le defaut de fond est plus subtil que l'echec : `parent` n'est pas un
        // identifiant, c'est l'INDEX SEQUENTIEL calcule par le constructeur de tableau
        // (AppLibrary::associativeToNumericArrayBuilder, l.77-102). Ca ne fonctionnait
        // que sur une table VIDE, ou l'auto-incrementation produit justement 1..N. Sur
        // une base deja peuplee, les indices et les identifiants divergent et la
        // hierarchie parent/enfant se retrouve fausse — silencieusement.
        //
        // On corrige les deux : chaque ligne est creee ou mise a jour sur sa cle
        // naturelle (name + guard_name), et `parent` est traduit de l'index vers
        // l'identifiant REEL de la ligne parente. Les identifiants existants ne bougent
        // pas, ce qui compte : `model_has_permissions` les reference.
        $identifiantParIndex = [];

        foreach ($permissions as $index => $attributs) {
            $indexParent = (int) ($attributs['parent'] ?? 0);
            unset($attributs['parent']);

            $ligne = Permission::updateOrCreate(
                [
                    'name'       => $attributs['name'],
                    'guard_name' => $attributs['guard_name'],
                ],
                $attributs + [
                    'parent' => $indexParent === 0
                        ? 0
                        : ($identifiantParIndex[$indexParent] ?? 0),
                ]
            );

            $identifiantParIndex[$index] = $ligne->id;
        }

        // [FIX 2026-08-25 · retenu à la fusion du 2026-08-28] Spatie met les permissions
        // en cache. Sans invalidation ici, `RolePermissionTableSeeder`, qui s'exécute
        // juste après, peut se voir refuser une permission qui VIENT d'être écrite —
        // un rôle seedé se retrouve alors amputé, en silence, jusqu'au prochain vidage
        // de cache. Les deux voies avaient rendu ce seeder rejouable ; une seule avait
        // vu que rejouable ne suffit pas si le lecteur suivant lit un cache périmé.
        app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();
    }
}
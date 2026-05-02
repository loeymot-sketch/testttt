<?php

namespace Database\Seeders;

use App\Enums\Ask;
use App\Models\Address;
use App\Enums\Role as EnumRole;
use App\Models\DefaultAccess;
use Dipokhalder\EnvEditor\EnvEditor;
use Illuminate\Database\Seeder;
use App\Models\User;
use App\Enums\Status;


class UserTableSeeder extends Seeder
{
    /**
     * Run the database seeds.
     *
     * @return void
     */
    public function run()
    {
        // [AUDIT-P0-C] Safety guard: never seed default credentials in production.
        // Change passwords manually before running with --force if needed in production.
        if (app()->environment('production')) {
            \Illuminate\Support\Facades\Log::warning('[UserTableSeeder] Blocked in production environment.');
            return;
        }

        $envService = new EnvEditor();
        $admin      = User::create([
            'name'              => 'Admin Le Cayenne',
            'email'             => 'admin@lecayenne.fr',
            'phone'             => '0600000000',
            'username'          => 'admin',
            'email_verified_at' => now(),
            'password'          => bcrypt('123456'),
            'branch_id'         => 0,
            'status'            => Status::ACTIVE,
            'country_code'      => '+33',
            'is_guest'          => Ask::NO
        ]);
        $admin->assignRole(EnumRole::ADMIN);

        if ($envService->getValue('DEMO')) {
            Address::create([
                'label'     => 'Domicile',
                'address'   => 'Paris, France',
                'apartment' => rand(0, 999) . ', Rue de Rivoli',
                'latitude'  => '48.8566',
                'longitude' => '2.3522',
                'user_id'   => $admin->id,
            ]);
            Address::create([
                'label'     => 'Travail',
                'address'   => 'Paris, France',
                'apartment' => rand(0, 999) . ', Avenue des Champs-Élysées',
                'latitude'  => '48.8698',
                'longitude' => '2.3075',
                'user_id'   => $admin->id,
            ]);
        }

        $customer = User::create([
            'name'              => 'Client passage',
            'email'             => 'walkingcustomer@example.com',
            'phone'             => '0600000001',
            'username'          => 'default-customer',
            'email_verified_at' => now(),
            'password'          => bcrypt('123456'),
            'branch_id'         => 0,
            'status'            => Status::ACTIVE,
            'country_code'      => '+33',
            'is_guest'          => Ask::NO
        ]);
        $customer->assignRole(EnumRole::CUSTOMER);

        // Caissier POS — toujours créé (branche 1 = « Le Cayenne (principal) » du BranchTableSeeder)
        $posOperatorLc = User::create([
            'name'              => 'Caissier Le Cayenne',
            'email'             => 'pos@lecayenne.fr',
            'phone'             => '0600000002',
            'username'          => 'pos-lecayenne',
            'email_verified_at' => now(),
            'password'          => bcrypt('123456'),
            'branch_id'         => 1,
            'status'            => Status::ACTIVE,
            'country_code'      => '+33',
            'is_guest'          => Ask::NO
        ]);
        $posOperatorLc->assignRole(EnumRole::POS_OPERATOR);
        DefaultAccess::firstOrCreate(
            ['user_id' => $posOperatorLc->id, 'name' => 'branch_id'],
            ['default_id' => 1]
        );

        // Chef KDS — toujours créé (branche 1), même sans DEMO=true
        // (le compte chef@example.com du bloc DEMO reste un bonus démo Bangladesh)
        $chefLc = User::firstOrCreate(
            ['email' => 'chef@lecayenne.fr'],
            [
                'name'              => 'Chef Le Cayenne',
                'phone'             => '0600000003',
                'username'          => 'chef-lecayenne',
                'email_verified_at' => now(),
                'password'          => bcrypt('123456'),
                'branch_id'         => 1,
                'status'            => Status::ACTIVE,
                'country_code'      => '+33',
                'is_guest'          => Ask::NO,
            ]
        );
        if (!$chefLc->hasRole(EnumRole::CHEF)) {
            $chefLc->assignRole(EnumRole::CHEF);
        }

        if ($envService->getValue('DEMO')) {
            Address::create([
                'label'     => 'Domicile',
                'address'   => 'Paris, France',
                'apartment' => rand(0, 999) . ', Boulevard Haussmann',
                'latitude'  => '48.8738',
                'longitude' => '2.3312',
                'user_id'   => $customer->id,
            ]);
            Address::create([
                'label'     => 'Travail',
                'address'   => 'Paris, France',
                'apartment' => rand(0, 999) . ', Rue de la Paix',
                'latitude'  => '48.8689',
                'longitude' => '2.3302',
                'user_id'   => $customer->id,
            ]);
        }

        if ($envService->getValue('DEMO')) {
            $customerOne = User::create([
                'name'              => 'Will Smith',
                'email'             => 'customer@example.com',
                'phone'             => '1253333444',
                'username'          => 'will-smith',
                'email_verified_at' => now(),
                'password'          => bcrypt('123456'),
                'branch_id'         => 0,
                'status'            => Status::ACTIVE,
                'country_code'      => '+880',
                'is_guest'          => Ask::NO
            ]);
            $customerOne->assignRole(EnumRole::CUSTOMER);
            Address::create([
                'label'     => 'Home',
                'address'   => 'Dhaka Bangladesh',
                'apartment' => rand(0, 999) . ', Gulshan 2',
                'latitude'  => '23.7948',
                'longitude' => '90.4143',
                'user_id'   => $customerOne->id,
            ]);
            Address::create([
                'label'     => 'Work',
                'address'   => 'Dhaka Bangladesh',
                'apartment' => rand(0, 999) . ', Mirpur 1',
                'latitude'  => '23.7956',
                'longitude' => '90.3537',
                'user_id'   => $customerOne->id,
            ]);
            Address::create([
                'label'     => 'Another Home',
                'address'   => 'Dhaka Bangladesh',
                'apartment' => rand(0, 999) . ', Gulshan 1',
                'latitude'  => '23.7807',
                'longitude' => '90.4158',
                'user_id'   => $customerOne->id,
            ]);

            $DeliveryBoyOne = User::create([
                'name'              => 'Kawsar Uddin',
                'email'             => 'deliveryboy@example.com',
                'phone'             => '1244444333',
                'username'          => 'kawsar-uddin131',
                'email_verified_at' => now(),
                'password'          => bcrypt('123456'),
                'branch_id'         => 1,
                'status'            => Status::ACTIVE,
                'country_code'      => '+880',
                'is_guest'          => Ask::NO
            ]);
            $DeliveryBoyOne->assignRole(EnumRole::DELIVERY_BOY);
            Address::create([
                'label'     => 'Work',
                'address'   => 'Dhaka Bangladesh',
                'apartment' => rand(0, 999) . ', Mirpur 2',
                'latitude'  => '23.7873',
                'longitude' => '90.3514',
                'user_id'   => $DeliveryBoyOne->id,
            ]);
            Address::create([
                'label'     => 'Home',
                'address'   => 'Dhaka Bangladesh',
                'apartment' => rand(0, 999) . ', Mirpur 1',
                'latitude'  => '23.7956',
                'longitude' => '90.3537',
                'user_id'   => $DeliveryBoyOne->id,
            ]);

            $DeliveryBoyTwo = User::create([
                'name'              => 'Heron Khan',
                'email'             => 'heron@example.com',
                'phone'             => '1256444333',
                'username'          => 'heron-khan131',
                'email_verified_at' => now(),
                'password'          => bcrypt('123456'),
                'branch_id'         => 1,
                'status'            => Status::ACTIVE,
                'country_code'      => '+880',
                'is_guest'          => Ask::NO
            ]);
            $DeliveryBoyTwo->assignRole(EnumRole::DELIVERY_BOY);
            Address::create([
                'label'     => 'Work',
                'address'   => 'Dhaka Bangladesh',
                'apartment' => rand(0, 999) . ', Mirpur 1',
                'latitude'  => '23.7956',
                'longitude' => '90.3537',
                'user_id'   => $DeliveryBoyTwo->id,
            ]);
            Address::create([
                'label'     => 'Home',
                'address'   => 'Dhaka Bangladesh',
                'apartment' => rand(0, 999) . ', Mirpur 2',
                'latitude'  => '23.7873',
                'longitude' => '90.3514',
                'user_id'   => $DeliveryBoyTwo->id,
            ]);

            $DeliveryBoyThree = User::create([
                'name'              => 'Nur Mahmud',
                'email'             => 'nurmahmud@example.com',
                'phone'             => '1255555533',
                'username'          => 'nur-mahmud123',
                'email_verified_at' => now(),
                'password'          => bcrypt('123456'),
                'branch_id'         => 2,
                'status'            => Status::ACTIVE,
                'country_code'      => '+880',
                'is_guest'          => Ask::NO
            ]);
            $DeliveryBoyThree->assignRole(EnumRole::DELIVERY_BOY);
            Address::create([
                'label'     => 'Home',
                'address'   => 'Dhaka Bangladesh',
                'apartment' => rand(0, 999) . ', Gulshan 2',
                'latitude'  => '23.7948',
                'longitude' => '90.4143',
                'user_id'   => $DeliveryBoyThree->id,
            ]);
            Address::create([
                'label'     => 'Work',
                'address'   => 'Dhaka Bangladesh',
                'apartment' => rand(0, 999) . ', Gulshan 1',
                'latitude'  => '23.7821',
                'longitude' => '90.4161',
                'user_id'   => $DeliveryBoyThree->id,
            ]);

            $employeeOne = User::create([
                'name'              => 'Kiron Khan',
                'email'             => 'branchmanager@example.com',
                'phone'             => '1275333453',
                'username'          => 'kiron-khan1313',
                'email_verified_at' => now(),
                'password'          => bcrypt('123456'),
                'branch_id'         => 1,
                'status'            => Status::ACTIVE,
                'country_code'      => '+880',
                'is_guest'          => Ask::NO
            ]);
            $employeeOne->assignRole(EnumRole::BRANCH_MANAGER);
            Address::create([
                'label'     => 'Home',
                'address'   => 'Dhaka Bangladesh',
                'apartment' => rand(0, 999) . ', Mirpur 2',
                'latitude'  => '23.7873',
                'longitude' => '90.3514',
                'user_id'   => $employeeOne->id,
            ]);
            Address::create([
                'label'     => 'Work',
                'address'   => 'Dhaka Bangladesh',
                'apartment' => rand(0, 999) . ', Mirpur 1',
                'latitude'  => '23.7956',
                'longitude' => '90.3537',
                'user_id'   => $employeeOne->id,
            ]);

            $employeeTwo = User::create([
                'name'              => 'Shohag Ali',
                'email'             => 'shohag@example.com',
                'phone'             => '1257654433',
                'username'          => 'shohag-ali3324',
                'email_verified_at' => now(),
                'password'          => bcrypt('123456'),
                'branch_id'         => 2,
                'status'            => Status::ACTIVE,
                'country_code'      => '+880',
                'is_guest'          => Ask::NO
            ]);
            $employeeTwo->assignRole(EnumRole::BRANCH_MANAGER);
            Address::create([
                'label'     => 'Home',
                'address'   => 'Dhaka Bangladesh',
                'apartment' => rand(0, 999) . ', Gulshan 2',
                'latitude'  => '23.7948',
                'longitude' => '90.4143',
                'user_id'   => $employeeTwo->id,
            ]);
            Address::create([
                'label'     => 'Work',
                'address'   => 'Dhaka Bangladesh',
                'apartment' => rand(0, 999) . ', Gulshan 1',
                'latitude'  => '23.7821',
                'longitude' => '90.4161',
                'user_id'   => $employeeTwo->id,
            ]);

            $posOperatorOne = User::create([
                'name'              => 'Farha Israt ',
                'email'             => 'posoperator@example.com',
                'phone'             => '1568736411',
                'username'          => 'farha-istat343',
                'email_verified_at' => now(),
                'password'          => bcrypt('123456'),
                'branch_id'         => 1,
                'status'            => Status::ACTIVE,
                'country_code'      => '+880',
                'is_guest'          => Ask::NO
            ]);
            $posOperatorOne->assignRole(EnumRole::POS_OPERATOR);
            Address::create([
                'label'     => 'Home',
                'address'   => 'Dhaka Bangladesh',
                'apartment' => rand(0, 999) . ', Mirpur 2',
                'latitude'  => '23.7873',
                'longitude' => '90.3514',
                'user_id'   => $posOperatorOne->id,
            ]);
            Address::create([
                'label'     => 'Work',
                'address'   => 'Dhaka Bangladesh',
                'apartment' => rand(0, 999) . ', Mirpur 1',
                'latitude'  => '23.7956',
                'longitude' => '90.3537',
                'user_id'   => $posOperatorOne->id,
            ]);

            $posOperatorTwo = User::create([
                'name'              => 'Sahataz Afnan',
                'email'             => 'sahataz@example.com',
                'phone'             => '1249955570',
                'username'          => 'sahataz-afnan232',
                'email_verified_at' => now(),
                'password'          => bcrypt('123456'),
                'branch_id'         => 2,
                'status'            => Status::ACTIVE,
                'country_code'      => '+880',
                'is_guest'          => Ask::NO
            ]);
            $posOperatorTwo->assignRole(EnumRole::POS_OPERATOR);
            Address::create([
                'label'     => 'Home',
                'address'   => 'Dhaka Bangladesh',
                'apartment' => rand(0, 999) . ', Gulshan 2',
                'latitude'  => '23.7948',
                'longitude' => '90.4143',
                'user_id'   => $posOperatorTwo->id,
            ]);
            Address::create([
                'label'     => 'Work',
                'address'   => 'Dhaka Bangladesh',
                'apartment' => rand(0, 999) . ', Gulshan 1',
                'latitude'  => '23.7821',
                'longitude' => '90.4161',
                'user_id'   => $posOperatorTwo->id,
            ]);

            $stuffOne = User::create([
                'name'              => 'Rohim Miya',
                'email'             => 'stuff@example.com',
                'phone'             => '1222224443',
                'username'          => 'rohim-miya768',
                'email_verified_at' => now(),
                'password'          => bcrypt('123456'),
                'branch_id'         => 1,
                'status'            => Status::ACTIVE,
                'country_code'      => '+880',
                'is_guest'          => Ask::NO
            ]);
            $stuffOne->assignRole(EnumRole::STUFF);
            Address::create([
                'label'     => 'Home',
                'address'   => 'Dhaka Bangladesh',
                'apartment' => rand(0, 999) . ', Mirpur 2',
                'latitude'  => '23.7873',
                'longitude' => '90.3514',
                'user_id'   => $stuffOne->id,
            ]);
            Address::create([
                'label'     => 'Work',
                'address'   => 'Dhaka Bangladesh',
                'apartment' => rand(0, 999) . ', Mirpur 1',
                'latitude'  => '23.7956',
                'longitude' => '90.3537',
                'user_id'   => $stuffOne->id,
            ]);

            $stuffTwo = User::create([
                'name'              => 'Kala Chan',
                'email'             => 'kala@example.com',
                'phone'             => '1238426043',
                'username'          => 'kala-chan890',
                'email_verified_at' => now(),
                'password'          => bcrypt('123456'),
                'branch_id'         => 2,
                'status'            => Status::ACTIVE,
                'country_code'      => '+880',
                'is_guest'          => Ask::NO
            ]);
            $stuffTwo->assignRole(EnumRole::STUFF);
            Address::create([
                'label'     => 'Home',
                'address'   => 'Dhaka Bangladesh',
                'apartment' => rand(0, 999) . ', Gulshan 2',
                'latitude'  => '23.7948',
                'longitude' => '90.4143',
                'user_id'   => $stuffTwo->id,
            ]);
            Address::create([
                'label'     => 'Work',
                'address'   => 'Dhaka Bangladesh',
                'apartment' => rand(0, 999) . ', Gulshan 1',
                'latitude'  => '23.7821',
                'longitude' => '90.4161',
                'user_id'   => $stuffTwo->id,
            ]);

            $waiter = User::create([
                'name'              => 'Sakib Duronto',
                'email'             => 'waiter@example.com',
                'phone'             => '1275333452',
                'username'          => 'sakib-duronto',
                'email_verified_at' => now(),
                'password'          => bcrypt('123456'),
                'branch_id'         => 1,
                'status'            => Status::ACTIVE,
                'country_code'      => '+880',
                'is_guest'          => Ask::NO
            ]);
            $waiter->assignRole(EnumRole::WAITER);
            Address::create([
                'label'     => 'Home',
                'address'   => 'Dhaka Bangladesh',
                'apartment' => rand(0, 999) . ', Mirpur 2',
                'latitude'  => '23.7873',
                'longitude' => '90.3514',
                'user_id'   => $waiter->id,
            ]);
            Address::create([
                'label'     => 'Work',
                'address'   => 'Dhaka Bangladesh',
                'apartment' => rand(0, 999) . ', Mirpur 1',
                'latitude'  => '23.7956',
                'longitude' => '90.3537',
                'user_id'   => $waiter->id,
            ]);

            $chef = User::create([
                'name'              => 'Maruf Khan',
                'email'             => 'chef@example.com',
                'phone'             => '1275323453',
                'username'          => 'maruf-khan',
                'email_verified_at' => now(),
                'password'          => bcrypt('123456'),
                'branch_id'         => 1,
                'status'            => Status::ACTIVE,
                'country_code'      => '+880',
                'is_guest'          => Ask::NO
            ]);
            $chef->assignRole(EnumRole::CHEF);
            Address::create([
                'label'     => 'Home',
                'address'   => 'Dhaka Bangladesh',
                'apartment' => rand(0, 999) . ', Mirpur 2',
                'latitude'  => '23.7873',
                'longitude' => '90.3514',
                'user_id'   => $chef->id,
            ]);
            Address::create([
                'label'     => 'Work',
                'address'   => 'Dhaka Bangladesh',
                'apartment' => rand(0, 999) . ', Mirpur 1',
                'latitude'  => '23.7956',
                'longitude' => '90.3537',
                'user_id'   => $chef->id,
            ]);
        }
    }
}

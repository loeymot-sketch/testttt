<?php

namespace Database\Seeders;


use Illuminate\Database\Seeder;
use App\Models\NotificationSetting;
use Dipokhalder\EnvEditor\EnvEditor;
use Smartisan\Settings\Facades\Settings;


class NotificationTableSeeder extends Seeder
{
    /**
     * Run the database seeds.
     *
     * @return void
     */
    public function run()
    {
        $envService = new EnvEditor();
        Settings::group('notification')->set([
            'notification_fcm_public_vapid_key'    => $envService->getValue('DEMO') ? 'BKAvKJbnB3QATdp8n1aUo_uhoNK3exVKLVzy7MP8VKydjjzthdlAWdlku6LQISxm4zA7dWoRACI9AHymf4V64kA' : '',
            'notification_fcm_api_key'             => $envService->getValue('DEMO') ? 'AIzaSyDg1xBSwmHKV0usIKxTFL5a6fFTb4s3XVM' : '',
            'notification_fcm_auth_domain'         => $envService->getValue('DEMO') ? 'foodking-inilabs.firebaseapp.com' : '',
            'notification_fcm_project_id'          => $envService->getValue('DEMO') ? 'foodking-inilabs' : '',
            'notification_fcm_storage_bucket'      => $envService->getValue('DEMO') ? 'foodking-inilabs.appspot.com' : '',
            'notification_fcm_messaging_sender_id' => $envService->getValue('DEMO') ? '843456771665' : '',
            'notification_fcm_app_id'              => $envService->getValue('DEMO') ? '1:843456771665:web:fb1e3115e9e17ee1582a70' : '',
            'notification_fcm_measurement_id'      => $envService->getValue('DEMO') ? 'G-GSJPS921XW' : '',
            'notification_fcm_json_file'           => '',
        ]);

        // [GOAL-HEAL-SEC-001 2026-05-23] Demo Firebase service-account JSON
        // source moved from public/file/ (web-accessible) to storage/app/firebase/
        // (non-public). The 'notification-file' media collection is also pinned
        // to the firebase_private disk via NotificationSetting::registerMediaCollections.
        // Phase B.3 Security RED-team finding B3.2-001.
        if ($envService->getValue('DEMO') && file_exists(storage_path('app/firebase/service-account-file.json'))) {
            $setting = NotificationSetting::where('key', 'notification_fcm_json_file')->first();
            $setting->addMedia(storage_path('app/firebase/service-account-file.json'))->preservingOriginal()->usingFileName('service-account-file.json')->toMediaCollection('notification-file');
        }
    }
}

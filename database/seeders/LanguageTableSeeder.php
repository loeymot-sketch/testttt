<?php

namespace Database\Seeders;


use App\Enums\Status;
use App\Enums\DisplayMode;
use App\Models\Language;
use Illuminate\Database\Seeder;


class LanguageTableSeeder extends Seeder
{
    /**
     * Run the database seeds.
     *
     * @return void
     */
    public function run()
    {
        $englishLanguageArray = [
            'name' => 'Anglais',
            'code' => 'en',
            'display_mode' => DisplayMode::LTR,
            'status' => Status::ACTIVE
        ];

        $frenchLanguageArray = [
            'name' => 'Français',
            'code' => 'fr',
            'display_mode' => DisplayMode::LTR,
            'status' => Status::ACTIVE
        ];

        $englishLanguage = Language::create($englishLanguageArray);
        if (file_exists(public_path('/images/language/english.png'))) {
            $englishLanguage->addMedia(public_path('/images/language/english.png'))->preservingOriginal()->toMediaCollection('language');
        }

        $frenchLanguage = Language::create($frenchLanguageArray);
        // Note: Make sure the french.png or similar exists or fallback
        if (file_exists(public_path('/images/language/french.png'))) {
            $frenchLanguage->addMedia(public_path('/images/language/french.png'))->preservingOriginal()->toMediaCollection('language');
        }
    }
}

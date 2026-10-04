<?php

return [

    // [ONB-11 2026-08-28] Verrouillage apres trop d'echecs : le delai doit etre DIT.
    'trop_de_tentatives' => "Too many login attempts. Try again in :minutes minutes.",

    /*
    |--------------------------------------------------------------------------
    | Authentication Language Lines
    |--------------------------------------------------------------------------
    |
    | The following language lines are used during authentication for various
    | messages that we need to display to the user. You are free to modify
    | these language lines according to your application's requirements.
    |
    */

    'failed' => 'These credentials do not match our records.',
    'password' => 'The provided password is incorrect.',
    'throttle' => 'Too many login attempts. Please try again in :seconds seconds.',

    // [ONB-06 2026-08-28] Emitted by the 8 account FormRequests and by
    // ChangePasswordRequest. Missing here, they surfaced as the raw key
    // `auth.password_confirmation_mismatch` to any merchant whose browser
    // sends `Accept-Language: en` — the middleware honours that header.
    'old_password_mismatch' => 'The current password is incorrect.',
    'password_confirmation_mismatch' => 'The password confirmation does not match.',

];

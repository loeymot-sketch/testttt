<?php

namespace App\Rules;

use Illuminate\Contracts\Validation\Rule;
use Smartisan\Settings\Facades\Settings;
use PragmaRX\Countries\Package\Countries;

class ValidPhone implements Rule
{
    public $message = '';
    /**
     * Create a new rule instance.
     *
     * @return void
     */
    public function __construct() {}

    /**
     * Determine if the validation rule passes.
     *
     * @param  string  $attribute
     * @param  mixed  $value
     * @return bool
     */
    public function passes($attribute, $value): bool
    {
        $digitsOnly = preg_replace('/\D+/', '', (string) $value);
        $len        = strlen($digitsOnly);

        // Longueur cible (souvent 10 legacy BD) : accepter une plage pour FR / invités (+33, sans 0 initial, etc.)
        $expected = (int) Settings::group('site')->get('site_default_phone_digit_length');
        $minLen   = max(8, $expected > 0 ? $expected - 2 : 8);
        $maxLen   = min(15, $expected > 0 ? $expected + 2 : 15);

        if ($len < $minLen || $len > $maxLen) {
            $this->message = 'The :attribute must be between ' . $minLen . ' and ' . $maxLen . ' digits (national number, without country code).';

            return false;
        }

        return true;
    }

    /**
     * Get the validation error message.
     *
     * @return string
     */
    public function message(): string
    {
        return $this->message;
    }
}
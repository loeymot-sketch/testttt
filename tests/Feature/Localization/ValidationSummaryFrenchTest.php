<?php

namespace Tests\Feature\Localization;

use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * [E2E 2026-10-22 · C-101] Un refus de validation à plusieurs erreurs s'affichait au client avec un fragment ANGLAIS
 * collé à la phrase française : « … (actuel : 2). (and 1 more error) ». Laravel traduit ce fragment par la clé JSON
 * `lang/fr.json`, qui n'existait pas.
 */
class ValidationSummaryFrenchTest extends TestCase
{
    private function message(int $erreurs): string
    {
        $champs = [];
        $regles = [];
        for ($i = 0; $i < $erreurs; $i++) {
            $champs["c$i"] = null;
            $regles["c$i"] = 'required';
        }
        try {
            Validator::make($champs, $regles)->validate();
        } catch (ValidationException $e) {
            return $e->getMessage();
        }

        return '';
    }

    public function test_the_summary_of_a_two_error_refusal_is_french(): void
    {
        $m = $this->message(2);

        $this->assertStringContainsString('(et 1 autre erreur)', $m);
        $this->assertStringNotContainsString('(and', $m);
    }

    public function test_the_summary_of_a_many_error_refusal_is_french_and_plural(): void
    {
        $m = $this->message(4);

        $this->assertStringContainsString('(et 3 autres erreurs)', $m);
        $this->assertStringNotContainsString('(and', $m);
    }
}

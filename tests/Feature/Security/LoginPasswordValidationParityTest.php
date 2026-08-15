<?php

namespace Tests\Feature\Security;

use Tests\TestCase;

/**
 * Sentinel — GOAL-HEAL-SEC-002 2026-05-23 (Phase B.3 Security RED-team
 * finding B3.2-002 P1).
 *
 * Closes the LoginController vs EmployeeRequest password-policy parity gap
 * by REASONED CLOSURE rather than silence:
 *
 *   1. LoginController validation must NOT carry a `min:N` constraint on the
 *      password input at the AUTHENTICATION path. Exposing the minimum length
 *      at login is OWASP-flagged information-leak — bcrypt + rate-limiter
 *      enforce correctness server-side anyway, and a min:N gate at login
 *      would also lock out legacy users whose passwords pre-date the V1.0.1
 *      bcrypt-rounds hardening + EmployeeRequest min:12 bump. Same rationale
 *      that ChangePasswordRequest:36 documents for `old_password`.
 *
 *   2. EmployeeRequest (CREATION path) keeps `Password::min(12)->letters()
 *      ->numbers()`. New users must meet the modern bar; the authentication
 *      path stays loose to honour bcrypt as the source of truth and not lock
 *      out legacy accounts.
 *
 * Both assertions live in the same sentinel so a future PR that re-introduces
 * `min:N` at login OR weakens the creation policy is caught by one CI signal.
 *
 * @see app/Http/Controllers/Auth/LoginController.php (lines 47-56)
 * @see app/Http/Requests/EmployeeRequest.php (line 50)
 * @see CLAUDE.md §9 Auth Invariants
 */
class LoginPasswordValidationParityTest extends TestCase
{
    /**
     * SEC-002-01: LoginController must not enforce a numeric min length on
     * the `password` field at the authentication path.
     *
     * File-string + regex over instantiating the validator: the controller
     * builds the rules array inline inside `login()`, so the cheapest
     * stable observable is the source itself. Robust against runtime
     * configuration shifts.
     */
    public function test_login_controller_password_rule_does_not_expose_min_length(): void
    {
        $source = file_get_contents(
            base_path('app/Http/Controllers/Auth/LoginController.php')
        );

        $this->assertNotFalse($source, 'LoginController.php must be readable');

        // The rules block we care about is the validator inside `login()`,
        // built from `$request->all()` against an array literal. The
        // `password` rule line must not carry `min:N`.
        $this->assertMatchesRegularExpression(
            "/'password'\s*=>\s*'required\|string'/",
            $source,
            'LoginController login() must declare password as required|string '
                . 'WITHOUT a min:N constraint — OWASP guidance, do not expose '
                . 'password policy at the authentication path. Source: '
                . 'GOAL-HEAL-SEC-002 / Phase B.3 Security finding B3.2-002.'
        );

        // Belt-and-braces: refuse any `min:N` token sitting next to the
        // `password` declaration in the same logical line.
        $this->assertDoesNotMatchRegularExpression(
            "/'password'[^,;]{0,80}min:\d+/",
            $source,
            'LoginController must not carry a min:N rule on the password '
                . 'input at the login validation array.'
        );
    }

    /**
     * SEC-002-02: EmployeeRequest (creation path) must keep the modern
     * V1.0.1 hardening — `Password::min(12)->letters()->numbers()`. This
     * is the parity counterpart: creation tight, login loose.
     */
    public function test_employee_request_password_rule_enforces_min_twelve(): void
    {
        $source = file_get_contents(
            base_path('app/Http/Requests/EmployeeRequest.php')
        );

        $this->assertNotFalse($source, 'EmployeeRequest.php must be readable');

        // Use `Password::min(12)->...` fluent builder; tolerate whitespace
        // between tokens but pin the literal 12 to catch a downward drift.
        $this->assertMatchesRegularExpression(
            '/Password\s*::\s*min\s*\(\s*12\s*\)/',
            $source,
            'EmployeeRequest creation must enforce Password::min(12) for new '
                . 'employee passwords. Source: 2026-05-18 PR-D T5 V1 GO-LIVE '
                . 'staff password policy.'
        );

        $this->assertMatchesRegularExpression(
            '/Password\s*::\s*min\s*\(\s*12\s*\)\s*->\s*letters\s*\(\s*\)\s*->\s*numbers\s*\(\s*\)/',
            $source,
            'EmployeeRequest creation must require letters AND numbers in '
                . 'addition to the min:12 floor (defence-in-depth against '
                . 'dictionary attacks on weak alphabetical passphrases).'
        );
    }

    /**
     * SEC-002-03: Documents the REASONED CLOSURE of the parity gap so a
     * future reviewer reading this sentinel understands WHY login is loose
     * and creation is tight. Asserts the inline justification comment is
     * present on LoginController so the heal isn't a silent edit.
     */
    public function test_login_controller_documents_owasp_rationale_inline(): void
    {
        $source = file_get_contents(
            base_path('app/Http/Controllers/Auth/LoginController.php')
        );

        $this->assertStringContainsString(
            'GOAL-HEAL-SEC-002',
            $source,
            'LoginController must keep the GOAL-HEAL-SEC-002 inline '
                . 'comment documenting why min:N was dropped at the '
                . 'authentication path. Silent edits regress the audit '
                . 'trail; the comment is part of the deliverable.'
        );

        $this->assertStringContainsString(
            "OWASP",
            $source,
            'LoginController justification comment must reference OWASP '
                . 'guidance as the authority for the policy choice.'
        );
    }
}

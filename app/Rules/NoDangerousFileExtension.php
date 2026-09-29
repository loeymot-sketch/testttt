<?php

namespace App\Rules;

use Illuminate\Contracts\Validation\Rule;
use Illuminate\Http\UploadedFile;

/**
 * [GOAL-L2-HEAL-02 2026-05-24] Phase L7.1-V1 P1.
 *
 * Laravel's internal shouldBlockPhpUpload only blocks .php/.phar/.phtml/.pl.
 * Missing: .pht, .phps, .phpt, .phtm, .inc, .module, .php2..php7, .cgi, etc.
 *
 * Adversarial finding: a polyglot shell.pht (valid JPEG magic bytes + PHP
 * body appended) passed all 14 image upload FormRequests because:
 *   - `image` rule trusts MIME detection (sees JPEG magic, passes)
 *   - `mimes:jpeg,png,jpg` matches client MIME if forged
 *   - .pht is NOT in Laravel's shouldBlockPhpUpload list
 *
 * Live RCE risk if any webserver (nginx/Apache) maps .pht -> PHP handler
 * (common default config for many PHP packagings).
 *
 * This rule blocks ALL known server-executable extensions regardless of
 * MIME or magic-byte detection. Apply alongside `image` + `mimes:` to all
 * file upload FormRequests.
 *
 * Multi-extension defense: walks every dot-segment of the filename so
 * `shell.php.jpg` is blocked even though its trailing extension is `.jpg`.
 * This trades one false-positive (legit filenames containing a dangerous
 * token as a segment, e.g. `php_class_notes.jpg`) for zero false-negatives
 * on the documented attack surface — acceptable trade for V1 restaurant
 * SaaS where filenames are typically `image_123.jpg` or operator-named.
 */
class NoDangerousFileExtension implements Rule
{
    /**
     * Server-executable extensions blocked regardless of MIME / magic bytes.
     * Union of Laravel's known list + the .pht gap + PHP version variants
     * + common interpreted-script extensions.
     */
    private const DANGEROUS_EXTENSIONS = [
        // Laravel's internal shouldBlockPhpUpload baseline
        'php', 'phar', 'phtml', 'pl',
        // Gap closed by this rule
        'pht', 'phps', 'phpt', 'phtm', 'inc', 'module',
        // PHP version-numbered variants (some Apache configs map these)
        'php2', 'php3', 'php4', 'php5', 'php6', 'php7', 'php8',
        // Other server-executable scripts (defense-in-depth, not the
        // primary attack vector but cheap to block on an image endpoint)
        'cgi', 'py', 'rb', 'sh', 'jsp', 'asp', 'aspx',
    ];

    /**
     * Determine if the validation rule passes.
     *
     * @param  string  $attribute
     * @param  mixed   $value
     * @return bool
     */
    public function passes($attribute, $value): bool
    {
        // Let other rules (required / image / mimes) handle non-file inputs.
        if (! ($value instanceof UploadedFile) && ! is_string($value)) {
            return true;
        }

        $filename = $value instanceof UploadedFile
            ? (string) $value->getClientOriginalName()
            : (string) $value;

        if ($filename === '') {
            return true;
        }

        // Walk every dot-segment so `shell.php.jpg` is blocked even though
        // its trailing extension is `.jpg` (defends double-extension attack).
        $parts = explode('.', strtolower($filename));
        // Drop the basename (first segment) — only check extension positions.
        // A filename like `php.jpg` has parts ['php','jpg']; the basename
        // 'php' is intentionally also checked because it covers attackers
        // who omit extension entirely (e.g. relying on server fallback).
        foreach ($parts as $index => $part) {
            if ($index === 0 && count($parts) > 1) {
                // Basename — skip ONLY if there's at least one extension.
                // Allows legitimate names like `php_notes.jpg`.
                continue;
            }
            if (in_array($part, self::DANGEROUS_EXTENSIONS, true)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Get the validation error message.
     */
    /**
     * [ONB 2026-08-28] Ce message etait ECRIT EN DUR EN ANGLAIS, dans un produit
     * dont la locale FR est figee (ADR-007).
     *
     * Il se declenche quand un commercant depose un fichier au mauvais format —
     * c'est-a-dire au moment ou il a le plus besoin de comprendre ce qu'on lui
     * reproche. « The file contains a forbidden file extension. » ne lui dit ni
     * ce qui a ete refuse, ni quoi deposer a la place.
     */
    public function message(): string
    {
        return trans('all.message.extension_de_fichier_interdite');
    }
}

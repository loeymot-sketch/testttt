<?php

namespace Tests\Feature\Sentinels;

use Tests\TestCase;

/**
 * [ULTRA-AUDIT V4-DEPLOY 2026-07-02] Sentinelle anti-régression des frontières d'auth.
 *
 * VERROUILLE la classe du P0 installateur : `Redirect::to(...)->send()` ENVOIE une réponse mais
 * NE STOPPE PAS PHP → la méthode du contrôleur s'exécute quand même (installateur exécuté sur app
 * installée, NON authentifié). Le fix = middleware/abort/throw qui HALTE. Cette sentinelle échoue si
 * un contrôleur réintroduit un `->send()`/`->sendContent()` non-haltant.
 */
class AuthBoundaryRegressionSentinelTest extends TestCase
{
    /** Retire commentaires ligne + bloc pour ne matcher que du CODE réel. */
    private function stripComments(string $code): string
    {
        $code = preg_replace('#//.*$#m', '', $code);
        $code = preg_replace('#/\*.*?\*/#s', '', $code);
        return (string) $code;
    }

    private function controllerFiles(): array
    {
        $dir = app_path('Http/Controllers');
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS));
        $files = [];
        foreach ($it as $f) {
            if ($f->isFile() && $f->getExtension() === 'php') {
                $files[] = $f->getPathname();
            }
        }
        return $files;
    }

    /** @test */
    public function aucun_controleur_n_utilise_response_send_qui_ne_halte_pas(): void
    {
        $offenders = [];
        foreach ($this->controllerFiles() as $file) {
            $code = $this->stripComments(file_get_contents($file));
            // `->send()` / `->sendContent()` sur une Response = envoi SANS halt (le pattern P0).
            if (preg_match('/->send(Content)?\s*\(\s*\)/', $code)) {
                $offenders[] = str_replace(app_path('Http/Controllers') . '/', '', $file);
            }
        }

        $this->assertSame(
            [],
            $offenders,
            "Un contrôleur utilise Response->send() (envoie mais NE HALTE PAS PHP = le P0 installateur). "
            . "Utilise abort()/throw HttpResponseException/middleware qui court-circuite, JAMAIS ->send() nu. Offenders: "
            . implode(', ', $offenders)
        );
    }

    /** @test */
    public function le_garde_installateur_halte_via_middleware(): void
    {
        $code = file_get_contents(app_path('Http/Controllers/Installer/InstallerController.php'));
        $stripped = $this->stripComments($code);

        $this->assertDoesNotMatchRegularExpression(
            '/->send(Content)?\s*\(\s*\)/',
            $stripped,
            "InstallerController ne doit PLUS utiliser ->send() nu (le P0 : envoie le 302 sans stopper → la méthode installe sur app installée)."
        );
        $this->assertStringContainsString(
            '$this->middleware(function',
            $code,
            "Le garde « déjà installé » doit être un middleware de contrôleur (s'exécute à la requête + court-circuite), pas un throw en constructeur (casse le route-scan) ni un ->send() nu."
        );
    }
}

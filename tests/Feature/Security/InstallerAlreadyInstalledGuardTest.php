<?php

namespace Tests\Feature\Security;

use Tests\TestCase;

/**
 * [ULTRA-AUDIT V3 2026-07-02 — P0/P1] L'installateur legacy NE DOIT PAS s'exécuter sur une app déjà
 * installée. L'ancien garde `Redirect::to(...)->send()` envoyait un 302 SANS stopper PHP → les méthodes
 * `/install/database` (reconfig DB prod) et `/install/final-store` (réécrit .env) s'exécutaient quand
 * même, NON AUTHENTIFIÉES. Le garde corrigé (middleware de contrôleur) COURT-CIRCUITE la requête :
 * la méthode n'est jamais atteinte.
 */
class InstallerAlreadyInstalledGuardTest extends TestCase
{
    private function withInstalledMarker(callable $fn): void
    {
        $marker = storage_path('installed');
        $existed = file_exists($marker);
        if (! $existed) {
            @touch($marker);
        }
        try {
            $fn();
        } finally {
            if (! $existed) {
                @unlink($marker);
            }
        }
    }

    /** @test */
    public function une_route_installer_qui_rend_une_vue_redirige_302_quand_installe(): void
    {
        $this->withInstalledMarker(function () {
            // GET /install (index) rend NORMALEMENT une vue (200). Sur app installée, le middleware
            // court-circuite → 302 (redirection) et index() n'est JAMAIS exécutée. Un 200 (vue rendue)
            // prouverait que la méthode a tourné = régression.
            $res = $this->get('/install');
            $this->assertSame(302, $res->getStatusCode(), 'installateur installé doit rediriger, pas rendre sa vue');

            $res2 = $this->get('/install/requirement');
            $this->assertSame(302, $res2->getStatusCode(), 'même court-circuit sur /install/requirement');
        });
    }

    /** @test */
    public function la_route_destructive_final_store_ne_s_execute_pas_quand_installe(): void
    {
        $this->withInstalledMarker(function () {
            // /install/final-store (réécrit .env) : sur app installée, doit rediriger (302) sans exécuter.
            $res = $this->get('/install/final-store');
            $this->assertSame(302, $res->getStatusCode(), 'la route destructive doit être court-circuitée quand installé');
        });
    }
}
